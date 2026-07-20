/**
 * Time-Slot Navigation Popup
 *
 * Depends on:
 *   - oloTimeslotPopup (wp_localize_script) for restUrl, restBase, maxDaysAhead, siteId, daypartPollMs
 *   - REST endpoint GET /wp-json/northstaronlineordering/v1/timeslots
 *   - REST endpoint GET /wp-json/northstaronlineordering/v1/daypart-menu
 *   - REST endpoint DELETE /wp-json/northstaronlineordering/v1/cart
 *   - REST endpoint GET  /wp-json/northstaronlineordering/v1/active-daypart-menu   (OE-26492 watcher)
 *   - REST endpoint GET  /wp-json/northstaronlineordering/v1/cart/menu-transition  (OE-26492 preview)
 *   - REST endpoint POST /wp-json/northstaronlineordering/v1/cart/menu-transition  (OE-26492 apply)
 */
(function () {
    'use strict';

    function _init() {

    /* ── DOM refs ──────────────────────────────────────────── */
    var dialog      = document.getElementById('olo-ts-dialog');
    var closeBtn    = document.getElementById('olo-ts-close');
    var dateInput   = document.getElementById('olo-ts-date');
    var selectEl    = document.getElementById('olo-ts-select');
    var confirmBtn  = document.getElementById('olo-ts-confirm');
    var loadingEl   = document.getElementById('olo-ts-loading');
    var errorEl     = document.getElementById('olo-ts-error');
    var warningEl   = document.getElementById('olo-ts-warning');
    var warnBackBtn = document.getElementById('olo-ts-warn-back');
    var warnContBtn = document.getElementById('olo-ts-warn-continue');
    var daypartWarnEl    = document.getElementById('olo-ts-daypart-warning');
    var daypartRemovedEl = document.getElementById('olo-ts-daypart-removed');
    var daypartContBtn   = document.getElementById('olo-ts-daypart-continue');
    // Capture the Continue buttons' original (localized) labels so they can be
    // restored after a click handler swaps them for a spinner.
    var daypartContBtnLabel = daypartContBtn ? daypartContBtn.textContent : 'Continue';
    var warnContBtnLabel    = warnContBtn ? warnContBtn.textContent : 'Continue';

    if (!dialog || !dateInput || !selectEl || !confirmBtn) {
        return;
    }

    /* ── Config from PHP ───────────────────────────────────── */
    var cfg      = window.oloTimeslotPopup || {};
    var restUrl  = cfg.restUrl  || '/wp-json/northstaronlineordering/v1/timeslots';
    var restBase = cfg.restBase || '/wp-json/northstaronlineordering/v1';
    var maxDays  = parseInt(cfg.maxDaysAhead, 10) || 0;

    /* ── State ─────────────────────────────────────────────── */
    var pendingHref     = '';
    var pendingSiteId   = '';
    var pendingAreaId   = '';
    var pendingShipping = '';
    var pendingSlotId   = '';
    var pendingSlotTime = '';
    var pendingSlotDate = '';

    var forcedMode      = false;

    var reservedSlot          = '';
    var reservedDate          = '';
    var reservedOrderId       = '';
    var reservedSiteId        = '';
    var reservedSlotTime      = '';
    var reservedTimeSlotId    = '';

    /* Daypart-change watcher state (OE-26492) */
    var daypartWatcherTimer  = null;
    var daypartChecking      = false;
    var daypartHandled       = false;
    var pendingDaypartMenuId = '';
    // Perf: the new daypart's first ASAP slot is prefetched the moment a change is
    // detected, so the (slow, CBS-backed) /timeslots call overlaps the preview, the
    // user reading the modal, and the apply POST instead of sitting on the
    // post-Continue critical path.
    var daypartSlotsPromise  = null;
    var daypartAsapArea      = '';

    /* ── Helpers ───────────────────────────────────────────── */
    function todayStr() {
        var d = new Date();
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    function addDays(dateStr, n) {
        var d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() + n);
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return d.getFullYear() + '-' + mm + '-' + dd;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + (days * 86400000));
        var secure = location.protocol === 'https:' ? ';Secure' : '';
        document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/' + secure;
    }

    // The WC cart hash cached by wc-cart-fragments.js only reflects cart
    // contents, not the no_shipping cookie, so switching a site's shipping
    // control leaves a stale cached .cart-collaterals fragment (rendered under
    // the old value) that repaints over the fresh server render on next load.
    // Same mechanism as OE-26317; purge on every shipping-affecting change.
    function purgeShippingFragmentsCache() {
        Object.keys(sessionStorage).forEach(function (k) {
            if (k.indexOf('wc_cart_') === 0 || k.indexOf('wc_fragments_') === 0) {
                sessionStorage.removeItem(k);
            }
        });
        try { localStorage.removeItem('woocommerce_cart_hash'); } catch (e) {}
    }

    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    function getCartCount() {
        var el = document.querySelector('.cart-basket-nav');
        return el ? parseInt(el.textContent, 10) || 0 : 0;
    }

    /* ── UI state helpers ──────────────────────────────────── */
    function showLoading(on) {
        loadingEl.classList.toggle('olo-ts-active', on);
        selectEl.parentElement.style.display = on ? 'none' : '';
    }

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.toggle('olo-ts-active', !!msg);
    }

    function showWarning(on) {
        warningEl.hidden = !on;
        confirmBtn.style.display = on ? 'none' : '';
        selectEl.parentElement.style.display = on ? 'none' : '';
        var dateRows = dialog.querySelectorAll('.olo-ts-date-row');
        dateRows.forEach(function (row) {
            row.style.display = on ? 'none' : '';
        });
        // Reset Continue whenever the warning is (re-)shown so a panel reopened after
        // a previous Continue click — incl. the Go Back path, which only restored
        // Confirm — never inherits the disabled+spinner button. textContent= also
        // removes the spinner span.
        if (on && warnContBtn) {
            warnContBtn.disabled = false;
            warnContBtn.textContent = warnContBtnLabel;
        }
    }

    /**
     * Replace the dialog content with a spinner while the page navigates.
     * The <dialog> stays modal so all interaction is blocked.
     */
    function showNavigatingState(message) {
        // Hide every direct child of the dialog
        Array.from(dialog.children).forEach(function (child) {
            child.style.display = 'none';
        });

        // Avoid stacking spinners if called more than once.
        var existing = dialog.querySelector('.olo-ts-navigating');
        if (existing) { existing.remove(); }

        var wrap = document.createElement('div');
        wrap.className = 'olo-ts-navigating';

        var spinner = document.createElement('div');
        spinner.className = 'olo-ts-spinner';
        wrap.appendChild(spinner);

        // Optional informative line explaining WHY the page is reloading (e.g. the
        // daypart auto-switch to ASAP), so the spinner never appears unexplained.
        if (message) {
            var info = document.createElement('p');
            info.className = 'olo-ts-navigating-info';
            info.textContent = message;
            wrap.appendChild(info);
        }

        var loading = document.createElement('p');
        loading.textContent = 'Loading menu\u2026';
        wrap.appendChild(loading);

        dialog.appendChild(wrap);
    }

    /**
     * Undo showNavigatingState(): remove the spinner overlay and restore the
     * dialog's normal children. openPopup() and the bfcache restore handler both
     * call this, so a picker re-opened after the navigating spinner was shown
     * (e.g. auto-ASAP found no slot) is never left with the spinner overlaying it
     * and its title/controls hidden (OE-26492).
     */
    function clearNavigatingState() {
        var nav = dialog.querySelector('.olo-ts-navigating');
        if (nav) {
            nav.remove();
            Array.from(dialog.children).forEach(function (child) {
                child.style.display = '';
            });
        }
    }

    /**
     * Lift the forced (modal) lock so the popup can be closed.
     * Used when no time slots are available, letting the user leave the menu
     * to reach other sections (e.g. My Account).
     */
    function releaseForcedLock() {
        if (!forcedMode) { return; }
        forcedMode = false;
        dialog.classList.remove('olo-ts-forced');
    }

    /* ── Fetch slots ───────────────────────────────────────── */
    function fetchSlots(siteId, areaId, date, preselect) {
        showLoading(true);
        showError('');
        confirmBtn.disabled = true;
        selectEl.innerHTML = '';

        var url = restUrl + '?siteId=' + encodeURIComponent(siteId) +
                  '&areaId=' + encodeURIComponent(areaId) +
                  '&date=' + encodeURIComponent(date);

        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showLoading(false);
                if (!data.success || !data.options) {
                    showError('No time slots available for this date.');
                    releaseForcedLock();
                    return;
                }

                var keys = Object.keys(data.options);
                if (keys.length <= 1) {
                    showError('No time slots available for this date.');
                    releaseForcedLock();
                    return;
                }

                keys.forEach(function (key) {
                    var opt = document.createElement('option');
                    opt.value = key;
                    opt.textContent = data.options[key];
                    selectEl.appendChild(opt);
                });

                // Restore previously selected slot when reopening the popup.
                if (preselect && selectEl.querySelector('option[value="' + preselect.replace(/"/g, '\\"') + '"]')) {
                    selectEl.value = preselect;
                    confirmBtn.disabled = false;
                }
            })
            .catch(function () {
                showLoading(false);
                showError('Could not load time slots. Please try again.');
                releaseForcedLock();
            });
    }

    // Lock/unlock background page scroll while the modal is open. showModal()
    // does not do this on its own, and the page's scroll container is <html>
    // (plus iOS Safari ignores overflow:hidden), so a position:fixed lock is
    // applied via html/body classes (styled in timeslot-popup.css). The current
    // scroll offset is fed to CSS via --olo-ts-scroll-y and restored on release
    // so the page does not jump to the top when the modal closes. Idempotent:
    // guarded so the several close call-sites cannot double-restore the scroll.
    // The theme's scroll container varies by page and breakpoint: the document on
    // some layouts, but a nested overflow container on others (the app-shell keeps
    // body at height:100vh and scrolls an inner wrapper). We must lock — and later
    // restore — whichever element actually holds the scroll offset.
    let savedScrollY   = 0;      // document-scroll offset (for the body-shift path)
    let scrollLocked   = false;  // JS guard: idempotent across the several close call-sites
    let lockedScroller = null;   // { el, prevOverflow } when a nested element is frozen

    // Nested scroll containers used by the OLO themes, highest priority first.
    const NESTED_SCROLLERS = ['#cbp-so-scroller', '.section-main-content-wrap'];

    // Resolve the element that currently holds the scroll offset. Returns
    // { el: null, top } when the document itself is the scroller.
    function resolveScroller() {
        const docTop = window.pageYOffset
            || (document.scrollingElement && document.scrollingElement.scrollTop)
            || document.documentElement.scrollTop
            || document.body.scrollTop
            || 0;
        let best = { el: null, top: docTop };
        NESTED_SCROLLERS.forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                if (el.scrollTop > best.top) { best = { el: el, top: el.scrollTop }; }
            });
        });
        return best;
    }

    function setBodyScrollLock(on) {
        const root = document.documentElement;
        const body = document.body;
        if (on) {
            if (scrollLocked) { return; }
            scrollLocked = true;
            const scroller = resolveScroller();
            if (scroller.el) {
                // Nested scroller: freeze it in place. overflow:hidden preserves its
                // scrollTop, so the background stays put with NO body shift (shifting
                // the fixed app-shell body would move the header off-screen). The
                // !important beats the theme's `overflow: auto !important` on these
                // wrappers. The body-shift class is intentionally NOT added here.
                lockedScroller = { el: scroller.el, prevOverflow: scroller.el.style.getPropertyValue('overflow') };
                scroller.el.style.setProperty('overflow', 'hidden', 'important');
            } else {
                // Document scroller: position:fixed body-shift (the only case that
                // needs it; also the path iOS Safari requires since it ignores
                // overflow:hidden on the document). CSS keeps the locked body at
                // content height so a large negative shift still paints.
                lockedScroller = null;
                savedScrollY = scroller.top;
                body.style.setProperty('--olo-ts-scroll-y', '-' + savedScrollY + 'px');
                root.classList.add('olo-ts-scroll-lock');
                body.classList.add('olo-ts-scroll-lock');
            }
        } else {
            if (!scrollLocked) { return; }
            scrollLocked = false;
            if (lockedScroller) {
                if (lockedScroller.prevOverflow) {
                    lockedScroller.el.style.setProperty('overflow', lockedScroller.prevOverflow);
                } else {
                    lockedScroller.el.style.removeProperty('overflow');
                }
                lockedScroller = null;
            } else {
                root.classList.remove('olo-ts-scroll-lock');
                body.classList.remove('olo-ts-scroll-lock');
                body.style.removeProperty('--olo-ts-scroll-y');
                window.scrollTo(0, savedScrollY);
            }
        }
    }

    /* ── Open popup ────────────────────────────────────────── */
    function openPopup(href, siteId, areaId, forced) {
        // Re-opening the picker after the navigating spinner was shown (auto-ASAP
        // no-slot / error) must clear that overlay first, else it stays on top.
        clearNavigatingState();

        pendingHref   = href;
        pendingSiteId = siteId;
        pendingAreaId = areaId;
        pendingSlotId = '';
        pendingSlotTime = '';
        pendingSlotDate = '';

        // Forced mode locks the popup as a modal: no close button, ESC disabled.
        forcedMode = !!forced;
        dialog.classList.toggle('olo-ts-forced', forcedMode);

        var today = todayStr();

        var savedDate = getCookie('oloNavTimeslotDate');
        var savedSlot = getCookie('oloNavTimeslot');
        var fetchDate = (savedDate && savedDate >= today) ? savedDate : today;

        dateInput.value = fetchDate;
        dateInput.min   = today;
        dateInput.max   = maxDays > 0 ? addDays(today, maxDays) : today;

        var dateRows = dialog.querySelectorAll('.olo-ts-date-row');
        dateRows.forEach(function (row) {
            row.classList.toggle('olo-ts-hidden', maxDays === 0);
        });

        reservedSlot       = savedSlot || '';
        reservedDate       = (savedDate && savedDate >= today) ? savedDate : (savedSlot ? today : '');
        reservedOrderId    = getCookie('oloNavTimeslotOrderId') || '';
        reservedSiteId     = getCookie('oloNavTimeslotSiteId')  || siteId || '';
        reservedSlotTime   = getCookie('oloNavTimeslotTime')    || '';
        reservedTimeSlotId = getCookie('oloNavTimeslotId')      || '';

        showWarning(false);
        // Clear any leftover daypart-warning state (panel visible, title/required
        // text hidden, Continue button disabled+spinner) so a forced-picker fallback
        // after repeated apply failures / no ASAP slot reopens a clean picker.
        showDaypartWarning(false);
        showError('');
        confirmBtn.disabled = true;
        confirmBtn.style.display = '';

        // Guard against re-opening an already-open dialog (e.g. bfcache restore),
        // which would throw InvalidStateError.
        if (!dialog.open) {
            dialog.showModal();
        }

        // Disable background scroll while the modal is open.
        setBodyScrollLock(true);

        fetchSlots(siteId, areaId, fetchDate, savedSlot || '');
    }

    /* ── Reserve slot then navigate ────────────────────────── */
    function reserveAndNavigate(slotId, slotTime, slotDate, href, siteId) {
        // If the WC session already holds this exact slot, skip the API call.
        if (reservedSlot === slotId + '|' + slotTime && reservedDate === slotDate) {
            applySlotAndNavigate(slotId, slotTime, slotDate, href);
            return Promise.resolve();
        }

        confirmBtn.disabled = true;
        if (warnContBtn) warnContBtn.disabled = true;
        showError('');
        showLoading(true);

        return fetch(restBase + '/timeslots/reserve', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-WP-Nonce':   cfg.nonce || '',
            },
            body: JSON.stringify({
                date:       slotDate,
                timeSlotId: slotId,
                slotTime:   slotTime,
                siteId:     siteId || '',
            }),
        })
        .then(function (r) {
            return r.json().then(function (d) { return { ok: r.ok, data: d }; });
        })
        .then(function (res) {
            if (!res.ok || !res.data.success) {
                showLoading(false);
                showError((res.data && res.data.message) || 'This time slot is no longer available. Please choose another.');
                confirmBtn.disabled = false;
                if (warnContBtn) { warnContBtn.disabled = false; warnContBtn.textContent = warnContBtnLabel; }
                return;
            }
            var rsv = res.data.reservation || {};
            reservedSlot       = slotId + '|' + slotTime;
            reservedDate       = slotDate;
            reservedOrderId    = rsv.timeSlotsOrderId ? String(rsv.timeSlotsOrderId) : '';
            reservedSiteId     = rsv.siteId           || siteId || '';
            reservedSlotTime   = slotTime;
            reservedTimeSlotId = slotId;
            applySlotAndNavigate(slotId, slotTime, slotDate, href);
        })
        .catch(function () {
            showLoading(false);
            showError('Unable to reserve time slot. Please try again.');
            confirmBtn.disabled = false;
            if (warnContBtn) { warnContBtn.disabled = false; warnContBtn.textContent = warnContBtnLabel; }
        });
    }

    /* ── Apply selection and navigate ──────────────────────── */
    function applySlotAndNavigate(slotId, slotTime, slotDate, href) {
        setCookie('oloNavTimeslot',        slotId + '|' + slotTime, 1);
        setCookie('oloNavTimeslotId',      slotId, 1);
        setCookie('oloNavTimeslotTime',    slotTime, 1);
        setCookie('oloNavTimeslotDate',    slotDate, 1);
        setCookie('oloNavTimeslotOrderId', reservedOrderId, 1);
        setCookie('oloNavTimeslotSiteId',  reservedSiteId, 1);

        // Store areaId so header edit/ASAP buttons can reopen the popup
        if (pendingAreaId) {
            setCookie('oloNavAreaId', pendingAreaId, 1);
        }

        // Set site/shipping cookies that custom.js handleOrderClick normally sets
        if (pendingSiteId) {
            setCookie('siteid', pendingSiteId, 30);
            // OE-26330: picking a time slot is an explicit location choice, so mark it like
            // handleOrderClick does. Session cookie (no expiry) — must NOT persist across
            // browser sessions (OE-25933); dies on browser close.
            var secure = location.protocol === 'https:' ? '; secure' : '';
            document.cookie = 'locationSelected=1; path=/; samesite=lax' + secure;
        }
        if (pendingShipping) {
            setCookie('no_shipping', pendingShipping === 'Disabled' ? '1' : '0', 30);
            if (pendingShipping === 'Enabled') {
                setCookie('orderType', '2', 30);
            }
            purgeShippingFragmentsCache();
        }

        // Show spinner inside the modal while the page navigates
        if (!dialog.open) {
            dialog.showModal();
        }
        showNavigatingState();
        window.location.href = href;
    }

    /* ── Strip category params from a URL ─────────────────── */
    function stripCategoryParams(url) {
        try {
            var u = new URL(url);
            u.searchParams.delete('cat_slug');
            u.searchParams.delete('cat_name');
            return u.toString();
        } catch (e) {
            return url;
        }
    }

    /* ── Check menu change and cart ────────────────────────── */
    // The warning panel lives inside <dialog id="olo-ts-dialog">, so it only
    // paints when the dialog is open. The in-dialog Confirm flow already has it
    // open (via openPopup); the header ASAP button calls this directly with the
    // dialog CLOSED, so we open it here for the warning branch. asapWarningMode
    // records that we opened it — so Go Back can dismiss (no picker sits behind it).
    var asapWarningMode = false;
    function checkAndProceed(slotId, slotTime, slotDate, href, siteId) {
        var currentMenu = getCookie('currentMenu');
        var cartCount   = getCartCount();

        // Seed the site the warning's Continue handler will reserve with. The ASAP
        // path never runs openPopup(), the only other place pendingSiteId is set;
        // for the Confirm flow this re-assigns the same value (no-op).
        if (siteId) { pendingSiteId = siteId; }

        if (!currentMenu) {
            return reserveAndNavigate(slotId, slotTime, slotDate, href, siteId);
        }

        return fetch(restBase + '/daypart-menu?siteId=' + encodeURIComponent(siteId) +
              '&date=' + encodeURIComponent(slotDate) +
              '&time=' + encodeURIComponent(slotTime), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.menuId && String(data.menuId) !== String(currentMenu)) {
                    pendingHref = cfg.menuItemsUrl || stripCategoryParams(href);

                    if (cartCount > 0) {
                        // Store pending slot and show the menu-change warning.
                        pendingSlotId   = slotId;
                        pendingSlotTime = slotTime;
                        pendingSlotDate = slotDate;
                        // ASAP path: dialog is closed, so open it (and lock the
                        // background scroll like openPopup) or the warning never
                        // shows. Recomputed each time, so it is always accurate
                        // when Go Back fires right after.
                        asapWarningMode = !dialog.open;
                        if (asapWarningMode) {
                            dialog.showModal();
                            setBodyScrollLock(true);
                        }
                        showWarning(true);
                    } else {
                        return reserveAndNavigate(slotId, slotTime, slotDate, pendingHref, siteId);
                    }
                } else {
                    return reserveAndNavigate(slotId, slotTime, slotDate, href, siteId);
                }
            })
            .catch(function () {
                return reserveAndNavigate(slotId, slotTime, slotDate, href, siteId);
            });
    }

    // Close button and ESC both dismiss the popup and release any held slot.
    // Exception: while the ASAP warning is up, dismissing only declines the ASAP
    // switch — the user's existing reservation must survive, same as Go Back
    // (reservedSlot can still hold the current slot after a bfcache restore).
    function dismissPopup() {
        // In forced mode the popup must stay open until a slot is selected.
        if (forcedMode) { return; }
        if (asapWarningMode) {
            asapWarningMode = false;
            showWarning(false);
        } else {
            releaseReservation();
        }
        dialog.close();
        setBodyScrollLock(false);
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', dismissPopup);
    }

    dialog.addEventListener('cancel', function (e) {
        // Block ESC dismissal while the popup is locked.
        if (forcedMode) {
            e.preventDefault();
            return;
        }
        if (asapWarningMode) {
            asapWarningMode = false;
            showWarning(false);
        } else {
            releaseReservation();
        }
        // ESC closes the dialog via the native default action; release the lock here.
        setBodyScrollLock(false);
    });

    function releaseReservation() {
        if (!reservedSlot) { return; }
        fetch(restBase + '/timeslots/reserve', {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce || '',
            },
            body: JSON.stringify({
                timeSlotsOrderId: reservedOrderId,
                timeSlotId:       reservedTimeSlotId,
                slotTime:         reservedSlotTime,
                businessDate:     reservedDate,
                siteId:           reservedSiteId,
            }),
        });
        reservedSlot       = '';
        reservedDate       = '';
        reservedOrderId    = '';
        reservedSiteId     = '';
        reservedSlotTime   = '';
        reservedTimeSlotId = '';
        setCookie('oloNavTimeslotOrderId', '', -1);
        setCookie('oloNavTimeslotSiteId',  '', -1);
    }

    dateInput.addEventListener('change', function () {
        if (pendingSiteId && pendingAreaId) {
            releaseReservation();
            fetchSlots(pendingSiteId, pendingAreaId, dateInput.value);
        }
    });

    selectEl.addEventListener('change', function () {
        confirmBtn.disabled = !selectEl.value;

        if (selectEl.value && reservedSlot && selectEl.value !== reservedSlot) {
            releaseReservation();
        }
    });

    confirmBtn.addEventListener('click', function () {
        if (!selectEl.value || confirmBtn.disabled) return;

        // Immediate feedback: disable now so a slow checkAndProceed (the daypart
        // lookup runs before reserveAndNavigate) never looks like the click was
        // ignored. reserveAndNavigate keeps it disabled and re-enables on error;
        // the warning panel hides it; Go Back restores it.
        confirmBtn.disabled = true;

        var parts   = selectEl.value.split('|');
        var slotId  = parts[0] || '';
        var slotTime = parts[1] || '';
        var slotDate = dateInput.value;

        checkAndProceed(slotId, slotTime, slotDate, pendingHref, pendingSiteId);
    });

    if (warnBackBtn) {
        warnBackBtn.addEventListener('click', function () {
            if (asapWarningMode) {
                // ASAP opened the dialog solely for this warning — there is no
                // picker behind it, so Go Back dismisses rather than revealing an
                // empty picker. The cart is untouched (only Continue clears it).
                asapWarningMode = false;
                showWarning(false);
                dialog.close();
                setBodyScrollLock(false);
                return;
            }
            showWarning(false);
            // Re-enable Confirm (the click handler disabled it before showing the
            // warning) so the restored picker is interactive again.
            confirmBtn.disabled = !selectEl.value;
        });
    }

    if (warnContBtn) {
        warnContBtn.addEventListener('click', function () {
            if (warnContBtn.disabled) return;
            // Immediate feedback: the DELETE /cart + reserve take ~1-2s before the
            // navigating spinner appears, so show a spinner in the button right away
            // (restored by reserveAndNavigate on a reserve error).
            warnContBtn.disabled = true;
            var btnSpinner = document.createElement('span');
            btnSpinner.className = 'olo-ts-btn-spinner';
            btnSpinner.setAttribute('aria-hidden', 'true');
            warnContBtn.textContent = '';
            warnContBtn.appendChild(btnSpinner);
            fetch(restBase + '/cart', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': cfg.nonce || '' },
            })
            .finally(function () {
                reserveAndNavigate(pendingSlotId, pendingSlotTime, pendingSlotDate, pendingHref, pendingSiteId);
            });
        });
    }

    /* ── Intercept "Order Online" links ─────────────────────
     *  Links with class "olo-ts-trigger" carry:
     *    data-ts-siteid   – Site ID
     *    data-ts-areaid   – Area ID
     *    href             – Original destination
     */
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.olo-ts-trigger');
        if (!link) return;

        var siteId = link.getAttribute('data-ts-siteid');
        var areaId = link.getAttribute('data-ts-areaid');
        var href   = link.getAttribute('href');

        if (!siteId || !areaId || !href) return;

        e.preventDefault();
        pendingShipping = link.getAttribute('data-shipping') || '';
        openPopup(href, siteId, areaId);
    });

    /* ── Header edit button ─────────────────────────────────
     * Re-opens the popup so the user can change their slot.
     */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.olo-ts-header-edit')) return;

        var siteId = getCookie('siteIdJs') || getCookie('siteid');
        var areaId = getCookie('oloNavAreaId') || getCookie('area_id');
        if (siteId && areaId) {
            openPopup(window.location.href, siteId, areaId);
        }
    });

    /* ── Header ASAP button ─────────────────────────────────
     * Picks today's first available slot without opening the popup.
     */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.olo-ts-header-asap');
        if (!btn) return;

        var siteId = getCookie('siteIdJs') || getCookie('siteid');
        var areaId = getCookie('oloNavAreaId') || getCookie('area_id');
        if (!siteId || !areaId) return;

        btn.disabled = true;
        btn.textContent = '...';

        var today = todayStr();

        fetch(restUrl + '?siteId=' + encodeURIComponent(siteId) +
              '&areaId=' + encodeURIComponent(areaId) +
              '&date=' + encodeURIComponent(today), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success || !data.options) {
                    openPopup(window.location.href, siteId, areaId);
                    return;
                }

                var keys = Object.keys(data.options).filter(function (k) { return k !== ''; });
                if (!keys.length) {
                    openPopup(window.location.href, siteId, areaId);
                    return;
                }

                var firstKey  = keys[0];
                var parts     = firstKey.split('|');
                var slotId    = parts[0] || '';
                var slotTime  = parts[1] || '';

                // Seed the area (openPopup normally does this; the ASAP button
                // bypasses it) so applySlotAndNavigate refreshes oloNavAreaId.
                pendingAreaId = areaId;
                return checkAndProceed(slotId, slotTime, today, window.location.href, siteId);
            })
            .catch(function () {
                openPopup(window.location.href, siteId, areaId);
            })
            .finally(function () {
                btn.disabled = false;
                btn.textContent = 'ASAP';
            });
    });

    /* ── Reset dialog on bfcache restore ──────────────────
     * If the browser restores the page from back/forward cache
     * (e.g. user hits "Back"), the spinner would still be visible.
     */
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) {
            clearNavigatingState();
            // Also clear a daypart warning frozen on-screen before navigation, else
            // bfcache restores its stale state (panel up, title/Continue button stuck).
            showDaypartWarning(false);

            // The daypart watcher state was frozen when the page navigated away;
            // restart it on bfcache restore so a later daypart change is still caught.
            daypartHandled       = false;
            daypartChecking      = false;
            pendingDaypartMenuId = '';
            daypartSlotsPromise  = null;
            daypartAsapArea      = '';
            if (closeBtn) { closeBtn.style.display = ''; }
            if (daypartSiteId && !daypartWatcherTimer) {
                daypartWatcherTimer = setInterval(checkDaypart, daypartPollMs);
            }

            // Re-lock on bfcache restore if the menu still needs a slot,
            // otherwise the browser Back button would bypass the modal.
            if (dialog.hasAttribute('data-ts-autoopen') && !getCookie('oloNavTimeslot')) {
                var bfSiteId = getCookie('siteIdJs') || getCookie('siteid');
                var bfAreaId = getCookie('area_id');
                if (bfSiteId && bfAreaId) {
                    openPopup(window.location.href, bfSiteId, bfAreaId, true);
                    return;
                }
            }

            dialog.close();
            setBodyScrollLock(false);
        }
    });

    if (dialog.hasAttribute('data-ts-autoopen')) {
        var cookieSiteId = getCookie('siteIdJs') || getCookie('siteid');
        var cookieAreaId = getCookie('area_id');
        var existingSlot = getCookie('oloNavTimeslot');

        if (cookieSiteId && cookieAreaId && !existingSlot) {
            // No slot on a menu page → lock the popup as a modal.
            openPopup(window.location.href, cookieSiteId, cookieAreaId, true);
        }
    }

    /* ── Daypart-change watcher (OE-26492) ──────────────────
     * While an order is open with a selected slot, the live daypart can roll over
     * (e.g. breakfast → lunch at noon). The rendered menu stays pinned to the
     * selected slot's daypart, so the server never auto-clears the cart for this
     * case. This watcher polls the genuine current daypart menu and, when it no
     * longer matches the menu the cart was built on, migrates the cart: surviving
     * items are re-priced, items absent from the new menu are listed for removal,
     * then the expired slot is auto-switched to ASAP (the first available slot in
     * the new daypart) — the modal tells the user their time switched to ASAP.
     */
    var daypartSiteId = cfg.siteId || getCookie('siteIdJs') || getCookie('siteid') || '';
    // Floor the poll interval at 15s so a bad/misconfigured value (e.g. 1 or -1)
    // can never turn this into near-continuous polling.
    var daypartPollMs = Math.max(parseInt(cfg.daypartPollMs, 10) || 60000, 15000);

    function showDaypartWarning(on, hasRemovals) {
        if (!daypartWarnEl) { return; }

        // The "switched to ASAP" message is always shown; the generic removal notice
        // only when at least one item is dropped (a list of names could be huge).
        if (daypartRemovedEl) {
            daypartRemovedEl.hidden = !(on && hasRemovals);
        }

        // Lock the modal while the change is pending: no close button, no ESC — the
        // daypart already changed, the user must acknowledge with Continue.
        if (closeBtn) { closeBtn.style.display = on ? 'none' : ''; }
        if (on) { forcedMode = true; }

        daypartWarnEl.hidden = !on;

        // Hide the normal slot-picking UI (and the sibling slot warning) behind the
        // panel, mirroring showWarning(); openPopup() restores these on re-pick.
        confirmBtn.style.display = on ? 'none' : '';
        selectEl.parentElement.style.display = on ? 'none' : '';
        dialog.querySelectorAll('.olo-ts-date-row').forEach(function (row) {
            row.style.display = on ? 'none' : '';
        });
        if (warningEl) { warningEl.hidden = true; }

        var titleEl = dialog.querySelector('h3');
        var reqMsg  = document.getElementById('olo-ts-required-msg');
        if (titleEl) { titleEl.style.display = on ? 'none' : ''; }
        if (reqMsg)  { reqMsg.style.display  = on ? 'none' : ''; }

        // When hiding the panel, undo the Continue click's disabled+spinner state so
        // a re-shown warning — or a picker reopened via openPopup() after a forced
        // fallback — never inherits the stale button (OE-26492).
        if (daypartContBtn && !on) {
            daypartContBtn.disabled = false;
            daypartContBtn.textContent = daypartContBtnLabel;
        }
    }

    function stopDaypartWatcher() {
        if (daypartWatcherTimer) {
            clearInterval(daypartWatcherTimer);
            daypartWatcherTimer = null;
        }
    }

    /* Cap on consecutive reload-to-recover attempts. currentMenu is only re-pinned
     * on a successful apply, so a persistent server failure would otherwise reload →
     * re-detect the same mismatch → reload forever. After the cap we stop reloading
     * and drop the user into the forced slot picker instead of looping. */
    var DAYPART_MAX_RELOADS = 2;

    /**
     * Recover from a failed daypart preview/apply by reloading so the server can
     * re-resolve session state — but bounded. Past the cap, fall back to the forced
     * picker so a persistent failure never traps the user in a reload loop (OE-26492).
     */
    function daypartRecoverReload() {
        var attempts = 0;
        try { attempts = parseInt(sessionStorage.getItem('oloDaypartReloadAttempts'), 10) || 0; } catch (e) { attempts = 0; }

        if (attempts >= DAYPART_MAX_RELOADS) {
            clearDaypartReloadGuard();
            var areaId = getCookie('oloNavAreaId') || getCookie('area_id') || pendingAreaId || '';
            var href   = cfg.menuItemsUrl || window.location.href;
            openPopup(href, daypartSiteId, areaId, true);
            return;
        }

        try { sessionStorage.setItem('oloDaypartReloadAttempts', String(attempts + 1)); } catch (e) {}
        window.location.reload();
    }

    function clearDaypartReloadGuard() {
        try { sessionStorage.removeItem('oloDaypartReloadAttempts'); } catch (e) {}
    }

    function checkDaypart() {
        if (daypartChecking || daypartHandled) { return; }

        var currentMenu = getCookie('currentMenu');
        // Only relevant for an in-progress, slot-pinned order: no slot/menu/site →
        // nothing to protect (ASAP orders are handled server-side on navigation).
        if (!currentMenu || !getCookie('oloNavTimeslot') || !daypartSiteId) { return; }

        // Pass the selected slot so the server can tell whether it has actually
        // passed. The cart's menu is pinned to the SELECTED slot's daypart, which
        // legitimately differs from the current daypart whenever the user scheduled
        // a future slot in another daypart (or just changed their slot manually).
        // Only an expired slot should migrate the cart — never a future one.
        var slotDate = getCookie('oloNavTimeslotDate');
        var slotTime = getCookie('oloNavTimeslotTime');

        // Defensive: applySlotAndNavigate() now writes the slot id/time/date cookies as
        // one atomic group, but a pin left by an older build may carry oloNavTimeslot
        // without its date/time pair. Such an orphan can never be evaluated server-side
        // (slotExpired stays null), silently disabling both this watcher and the checkout
        // backstop. Clear the stale group so the next navigation re-opens the picker for
        // a fresh, complete selection instead of polling forever on a dead pin.
        if (!slotDate || !slotTime) {
            setCookie('oloNavTimeslot',        '', -1);
            setCookie('oloNavTimeslotId',      '', -1);
            setCookie('oloNavTimeslotTime',    '', -1);
            setCookie('oloNavTimeslotDate',    '', -1);
            setCookie('oloNavTimeslotOrderId', '', -1);
            setCookie('oloNavTimeslotSiteId',  '', -1);
            return;
        }

        daypartChecking = true;
        fetch(restBase + '/active-daypart-menu?siteId=' + encodeURIComponent(daypartSiteId) +
              '&slotDate=' + encodeURIComponent(slotDate) +
              '&slotTime=' + encodeURIComponent(slotTime), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                daypartChecking = false;
                // No active daypart (off-hours) or unchanged → leave the cart alone.
                if (!data || !data.menuId) { return; }
                if (String(data.menuId) === String(currentMenu)) { return; }
                // Daypart differs, but only migrate when the selected slot has truly
                // expired. A future/manual slot in another daypart is left untouched
                // (slotExpired false/null) — the checkout backstop still guards it.
                if (true !== data.slotExpired) { return; }
                handleDaypartChange(String(data.menuId));
            })
            .catch(function () { daypartChecking = false; });
    }

    /**
     * Fetch the first available ASAP slot for an area. Always RESOLVES (never rejects)
     * to a result object so a cached prefetch promise never leaves an unhandled
     * rejection and the caller can tell apart the three outcomes:
     *   { ok: true,  slot: {slotId, slotTime} }  — a slot was found
     *   { ok: true,  slot: null }                — definitive: the area has no slot
     *   { ok: false, slot: null }                — could not determine (HTTP/network error)
     * Distinguishing the error case lets autoSwitchToAsap() retry once instead of
     * reusing a poisoned null from a transient blip during the parallel prefetch.
     */
    function fetchFirstAsapSlot(areaId, dateStr) {
        if (!daypartSiteId || !areaId) { return Promise.resolve({ ok: true, slot: null }); }
        return fetch(restUrl + '?siteId=' + encodeURIComponent(daypartSiteId) +
              '&areaId=' + encodeURIComponent(areaId) +
              '&date=' + encodeURIComponent(dateStr), { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) { return { ok: false, slot: null }; }
                return r.json().then(function (data) {
                    var keys = (data && data.success && data.options)
                        ? Object.keys(data.options).filter(function (k) { return k !== ''; })
                        : [];
                    if (!keys.length) { return { ok: true, slot: null }; }
                    var parts = keys[0].split('|');
                    return { ok: true, slot: { slotId: parts[0] || '', slotTime: parts[1] || '' } };
                });
            })
            .catch(function () { return { ok: false, slot: null }; });
    }

    function handleDaypartChange(newMenuId) {
        if (daypartHandled) { return; }
        daypartHandled = true;          // claim the transition; stop further polling
        stopDaypartWatcher();
        pendingDaypartMenuId = newMenuId;

        // Kick the ASAP-slot prefetch immediately (parallel with the preview + modal),
        // so autoSwitchToAsap() reuses the result instead of paying the CBS round-trip
        // after Continue. The transition always proceeds once here, so this never wastes.
        daypartAsapArea     = getCookie('oloNavAreaId') || getCookie('area_id') || pendingAreaId || '';
        daypartSlotsPromise = fetchFirstAsapSlot(daypartAsapArea, todayStr());

        fetch(restBase + '/cart/menu-transition?siteId=' + encodeURIComponent(daypartSiteId) +
              '&newMenuId=' + encodeURIComponent(newMenuId), { credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                // A non-2xx error body still parses as JSON with no survivors/missing,
                // which would look like an empty cart and wrongly skip to apply. Route
                // server errors to the bounded reload recovery instead.
                if (!res.ok) {
                    daypartRecoverReload();
                    return;
                }
                var data      = res.data;
                var survivors = (data && data.survivors) || [];
                var missing   = (data && data.missing) || [];

                // Empty cart: no order to protect — switch to ASAP fluidly, no modal,
                // no confirmation. Show the navigating state immediately so the POST
                // does not leave a 1-2s blank gap.
                if (survivors.length + missing.length === 0) {
                    if (!dialog.open) { dialog.showModal(); }
                    showNavigatingState('Your time has switched to ASAP since the original time selected has passed.');
                    applyDaypartTransition(newMenuId);
                    return;
                }

                // Non-empty cart: surface the modal with the "switched to ASAP" notice,
                // plus a generic removal warning when any item is dropped. Continue
                // applies the transition and re-times to ASAP.
                if (!dialog.open) { dialog.showModal(); }
                showDaypartWarning(true, missing.length > 0);
            })
            .catch(function () {
                // Preview failed — reload so the server re-resolves the session state,
                // bounded so a persistent failure does not loop (currentMenu is only
                // re-pinned on a successful apply).
                daypartRecoverReload();
            });
    }

    function applyDaypartTransition(newMenuId) {
        fetch(restBase + '/cart/menu-transition', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce || '',
            },
            body: JSON.stringify({ siteId: daypartSiteId, newMenuId: newMenuId }),
        })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
            .then(function (res) {
                if (!res.ok) {
                    daypartRecoverReload();
                    return;
                }
                // Apply succeeded — currentMenu re-pinned server-side, so clear the
                // bounded-reload guard before navigating into the new daypart.
                clearDaypartReloadGuard();
                autoSwitchToAsap();
            })
            .catch(function () { daypartRecoverReload(); });
    }

    function autoSwitchToAsap() {
        // The server has re-pinned currentMenu to the new daypart and cleared the
        // expired slot cookies. Instead of asking the user to re-pick, auto-select
        // the first available slot in the new daypart (ASAP) and navigate into the
        // new menu with the migrated cart. The "switched to ASAP" message was shown
        // in the modal; here we just carry it out.
        if (daypartWarnEl) { daypartWarnEl.hidden = true; }

        var areaId = daypartAsapArea || getCookie('oloNavAreaId') || getCookie('area_id') || pendingAreaId || '';
        var href   = cfg.menuItemsUrl || window.location.href;
        var today  = todayStr();

        // No area to fetch slots — fall back to the forced picker so the user is not stranded.
        if (!daypartSiteId || !areaId) {
            openPopup(href, daypartSiteId, areaId, true);
            return;
        }

        if (!dialog.open) { dialog.showModal(); }
        showNavigatingState('Your time has switched to ASAP since the original time selected has passed.');

        function useSlot(res) {
            if (res && res.slot && res.slot.slotId) {
                pendingAreaId = areaId; // applySlotAndNavigate persists oloNavAreaId from this
                reserveAndNavigate(res.slot.slotId, res.slot.slotTime, today, href, daypartSiteId);
            } else {
                // Definitive "no slot" → let the user pick another day in the forced picker.
                openPopup(href, daypartSiteId, areaId, true);
            }
        }

        // Reuse the prefetch started in handleDaypartChange (already in flight or done);
        // only fetch here as a fallback (e.g. empty-cart path raced ahead of the prefetch).
        var slotsP = daypartSlotsPromise || fetchFirstAsapSlot(areaId, today);
        slotsP.then(function (res) {
            // Retry once on a prefetch error so a transient blip during the parallel
            // prefetch doesn't strand the user in the picker when slots actually exist.
            if (res && !res.ok) {
                fetchFirstAsapSlot(areaId, today).then(useSlot);
                return;
            }
            useSlot(res);
        });
    }

    if (daypartContBtn) {
        daypartContBtn.addEventListener('click', function () {
            if (daypartContBtn.disabled) { return; }
            // Immediate feedback: the apply POST takes ~1-2s before the navigating
            // spinner appears, so show a spinner in the button right away.
            daypartContBtn.disabled = true;
            var btnSpinner = document.createElement('span');
            btnSpinner.className = 'olo-ts-btn-spinner';
            btnSpinner.setAttribute('aria-hidden', 'true');
            daypartContBtn.textContent = '';
            daypartContBtn.appendChild(btnSpinner);
            applyDaypartTransition(pendingDaypartMenuId);
        });
    }

    // Kick off: an initial near-term check (catches a tab left open past the
    // boundary), a steady poll, and a re-check whenever the tab regains focus.
    if (daypartSiteId) {
        setTimeout(checkDaypart, 4000);
        daypartWatcherTimer = setInterval(checkDaypart, daypartPollMs);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { checkDaypart(); }
        });
    }

    /* ── Public API (for map markers and external code) ─────*/
    window.OloTimeslotPopup = {
        open: function (href, siteId, areaId) {
            openPopup(href, siteId, areaId);
        }
    };

    } // end _init

    if (document.readyState !== 'loading') {
        _init();
    } else {
        document.addEventListener('DOMContentLoaded', _init);
    }
})();
