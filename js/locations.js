(() => {

  // Measure the site header + admin bar and expose as --cbs-header-h
  const updateHeaderHeight = () => {
    const siteHeader = document.querySelector('header.header') || document.querySelector('header');
    const adminBar   = document.getElementById('wpadminbar');
    const headerH    = siteHeader ? siteHeader.getBoundingClientRect().height : 0;
    const adminH     = adminBar   ? adminBar.getBoundingClientRect().height   : 0;
    document.documentElement.style.setProperty('--cbs-header-h', (headerH + adminH) + 'px');
  };
  updateHeaderHeight();
  window.addEventListener('resize', updateHeaderHeight);

  console.log('locations.js');
  const config = window.CBSLocationsConfig || {};
  const root = document.querySelector("[data-cbs-locations]");

  if (!root || !config.restUrl) {
    return;
  }

  const form = root.querySelector("[data-cbs-form]");
  const resultsEl = root.querySelector("[data-cbs-results]");
  const mapContainer = root.querySelector("[data-cbs-map-container]");
  const mapToggle = root.querySelector("[data-cbs-map-toggle]");
  const keywordInput = form?.querySelector('[name="keyword"]');
  const clearBtn = form?.querySelector("[data-cbs-clear]");

  // Move the map panel to <body> and apply fixed-position styles directly via
  // JS (inline styles beat any stylesheet). This escapes ancestor CSS transforms
  // (e.g. off-canvas nav on #inner-wrap) that break position:fixed.
  const mapPanel = root.querySelector(".cbs-locations__right");
  if (mapPanel) {
    document.body.appendChild(mapPanel);

    const applyMapPanelStyles = () => {
      const headerH = parseFloat(
        getComputedStyle(document.documentElement).getPropertyValue('--cbs-header-h')
      ) || 0;
      const isMobile = window.innerWidth <= 770;

      if (isMobile) {
        mapPanel.style.cssText = 'position:relative;top:0;right:0;width:100%;height:300px;overflow:hidden;z-index:1;';
      } else {
        mapPanel.style.cssText = `position:fixed;top:${headerH}px;right:0;width:50%;height:${window.innerHeight - headerH}px;overflow:hidden;z-index:1;`;
      }

      const mapEl = document.getElementById('map');
      if (mapEl) mapEl.style.cssText = 'width:100%;height:100%;';

      const mapContainerEl = document.getElementById('map-container');
      if (mapContainerEl) mapContainerEl.style.cssText = 'width:100%;height:100%;overflow:hidden;';
    };

    applyMapPanelStyles();
    window.addEventListener('resize', applyMapPanelStyles);
  }

  let map = null;
  let markers = [];

  const escapeHtml = (value) => {
    const div = document.createElement("div");
    div.textContent = value ?? "";
    return div.innerHTML;
  };

  // A site is gated only for ASAP ordering: never when timeslots are enabled (a
  // closed-now kitchen can still take a scheduled future slot) and never for
  // "Coming" sites (already disabled). is_open is the server flag (site timezone).
  const isClosedAsap = (item) =>
    item.menu_type !== "Coming" && !config.tsNavEnabled && item.is_open === false;

  let toastTimer;

  // Lightweight, dependency-free toast shown when a closed location is tapped.
  const showToast = (message) => {
    let toast = document.querySelector(".olo-toast");
    if (!toast) {
      toast = document.createElement("div");
      toast.className = "olo-toast";
      toast.setAttribute("role", "status");
      toast.setAttribute("aria-live", "polite");
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    // Force reflow so re-triggering restarts the show transition.
    void toast.offsetWidth;
    toast.classList.add("is-visible");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("is-visible"), 4000);
  };

  const buildCard = (item, index) => {
    const orderLabel =
      item.menu_type === "Coming"
        ? config.strings.comingSoon
        : config.strings.orderOnline;

    const closed = isClosedAsap(item);
    const disabledClass = item.menu_type === "Coming" ? " disabled_link" : "";
    const closedClass = closed ? " olo-closed" : "";
    const ariaDisabled = (item.menu_type === "Coming" || closed) ? "true" : "false";
    // Open/Closed badge driven by the server is_open flag (site timezone). No badge
    // for "Coming" sites, when the flag is absent (older plugin), or when timeslots are
    // enabled — there "open now" is irrelevant and a "Closed" badge would wrongly
    // discourage a valid future-slot order (OE-26385).
    const statusBadge =
      item.menu_type !== "Coming" && !config.tsNavEnabled && typeof item.is_open === "boolean"
        ? `<span class="olo-location-card__status" data-status="${item.is_open ? "open" : "closed"}">${escapeHtml(item.is_open ? (config.strings.open || "Open") : (config.strings.closed || "Closed"))}</span>`
        : "";
    const tsAttrs = (item.menu_type !== "Coming" && config.tsNavEnabled)
      ? ` olo-ts-trigger" data-ts-siteid="${escapeHtml(item.siteid || "")}" data-ts-areaid="${escapeHtml(item.areaid || "")}`
      : "";
    const distanceHtml =
      item.distance !== null && item.distance !== undefined
        ? `<div class="search-loc-distance" data-testid="location-card-distance">${escapeHtml(String(item.distance))} ${escapeHtml(config.strings.milesAway)}</div>`
        : "";

    return `
      <div class="item" data-testid="location-card-item" data-testid-site="location-card-site-${escapeHtml(String(item.siteid))}">
        <div id="res_info_${index + 1}" class="search-list search-list2" data-testid="location-card-wrapper">
          <div class="search-innner" data-testid="location-card-inner">
            <div class="search-info search_div_address" data-testid="location-card-info">
              <span data-testid="location-card-area-name">${escapeHtml(item.area_name || "")}</span>
              <div class="search-loc-name" tabindex="0" data-testid="location-card-name-wrapper">
                <span class="serial-n" data-testid="location-card-index">${index + 1}</span>
                <p class="loc_name" data-testid="location-card-name">${escapeHtml(item.site_name)}</p>
              </div>
              <div class="search-loc-addres address" data-testid="location-card-address">
                <i class="fa fa-map-marker-alt" aria-hidden="true"></i>
                ${escapeHtml(`${item.address1} ${item.city}, ${item.state}, ${item.zipcode}, ${item.countrycode}`)}
              </div>
              <div class="search-loc-addres phone" data-testid="location-card-phone">
                <i class="fa fa-phone-alt" aria-hidden="true"></i>
                ${escapeHtml(item.phone || "--")}
              </div>
              <div class="search-loc-addres time" data-testid="location-card-hours">
                <i class="fa fa-clock" aria-hidden="true"></i>
                <div class="search-loc-time time" data-testid="location-card-hours-text">${escapeHtml(item.kitchenopentime)} - ${escapeHtml(item.kitchenclosetime)}</div>
                ${statusBadge}
              </div>
              ${distanceHtml}
              <div class="search-loc-action" data-testid="location-card-action">
                <a class="btn-view1${disabledClass}${closedClass}${tsAttrs}" href="${escapeHtml(item.url)}" siteid="${escapeHtml(String(item.siteid))}" data-shipping="${escapeHtml(item.shipping_control || "")}" role="button" aria-disabled="${ariaDisabled}" data-testid="location-card-order-btn">
                  ${escapeHtml(orderLabel)}
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    `;
  };

  const renderEmpty = () => {
    resultsEl.innerHTML = `
      <div class="scroll-container" data-testid="locations-empty-container">
        <div class="search-list2" data-testid="locations-empty-wrapper">
          <div class="search-innner-infor" data-testid="locations-empty-inner">
            <div class="search-message" data-testid="locations-empty-message">
              <div class="search-loc-name" data-testid="locations-empty-title">${escapeHtml(config.strings.noResultsTitle)}</div>
              <div class="search-loc-time" data-testid="locations-empty-text">${escapeHtml(config.strings.noResultsMessage)}</div>
            </div>
          </div>
        </div>
      </div>
    `;
  };

  const renderResults = (items) => {
    if (!Array.isArray(items) || items.length === 0) {
      renderEmpty();
      renderMap([]);
      return;
    }

    resultsEl.innerHTML = `<div class="scroll-container">${items.map(buildCard).join("")}</div>`;
    renderMap(items);
    console.log('Map rendered with items: ', items);
  };

  let mapLoadingPromise = null;
  const loadGoogleMaps = () => {
    if (window.google?.maps) {
      return Promise.resolve();
    }

    if (mapLoadingPromise) {
      return mapLoadingPromise;
    }

    mapLoadingPromise = new Promise((resolve, reject) => {
      const callbackName = "cbsInitMapLoader_" + Date.now();

      window[callbackName] = () => {
        delete window[callbackName];
        resolve();
      };

      const script = document.createElement("script");
      const keyParam = config.googleMapsKey ? `key=${encodeURIComponent(config.googleMapsKey)}&` : "";
      script.src = `https://maps.googleapis.com/maps/api/js?${keyParam}loading=async&libraries=marker&callback=${callbackName}`;
      script.defer = true;
      script.onerror = () => reject(new Error("Failed to load Google Maps script"));
      document.head.appendChild(script);
    });

    return mapLoadingPromise;
  };

  const clearMarkers = () => {
    markers.forEach((marker) => {
      marker.map = null;
    });
    markers = [];
  };

  const renderMap = async (items) => {
    console.log('renderMap called with items: ', items);
    console.log('Map container: ', mapContainer);
    console.log('Google Maps Key: ', config.googleMapsKey);

    await loadGoogleMaps();

    const mapEl = document.getElementById("map");
    console.log('Map element: ', mapEl);
    console.log('Google Maps API: ', window.google?.maps);
    if (!mapEl || !window.google?.maps) {
      console.log('Google Maps API or map element missing', {mapEl, globalObj: window.google});
      return;
    }

    console.log('Rendering Map container: ', mapEl);
    console.log('Rendering Map items: ', items);

    const center =
      items.length > 0 && items[0].latitude && items[0].longitude
        ? { lat: Number(items[0].latitude), lng: Number(items[0].longitude) }
        : { lat: 37.0902, lng: -95.7129 };

    if (!map) {
      console.log('Initializing new map instance', { zoom: items.length > 0 ? 12 : 4, center});
      map = new google.maps.Map(mapEl, {
        zoom: items.length > 0 ? 12 : 4,
        center,
        mapId: "d34c272a99808261",
        controlSize: 24,
      });
    } else {
      map.setCenter(center);
      map.setZoom(items.length > 0 ? 12 : 4);
    }

    clearMarkers();

    let AdvancedMarkerElement, PinElement;
    if (google.maps.importLibrary) {
      const markerLib = await google.maps.importLibrary("marker");
      AdvancedMarkerElement = markerLib.AdvancedMarkerElement;
      PinElement = markerLib.PinElement;
    } else {
      AdvancedMarkerElement = google.maps.marker.AdvancedMarkerElement;
      PinElement = google.maps.marker.PinElement;
    }

    items.forEach((item, index) => {
      if (!item.latitude || !item.longitude) {
        return;
      }

      const glyph = document.createElement("span");
      glyph.innerText = String(index + 1);
      glyph.style.fontSize = "18px";
      glyph.style.fontWeight = "bold";
      glyph.style.backgroundColor = "#2175c8";
      glyph.tabIndex = -1;
      glyph.setAttribute("aria-hidden", "true");

      const pin = new PinElement({
        glyph,
        background: "#2175c8",
        glyphColor: "#ffffff",
        borderColor: "#2175c8",
      });

      pin.element.style.cursor = "pointer";

      const marker = new AdvancedMarkerElement({
        position: {
          lat: parseFloat(item.latitude),
          lng: parseFloat(item.longitude),
        },
        map,
        title: `${index + 1} - ${item.site_name}`,
        content: pin.element,
      });

      marker.addListener("click", () => {
        if (!item.url) return;
        if (item.menu_type === "Coming") return;
        if (isClosedAsap(item)) {
          showToast(config.strings.closedMessage || "This location is currently closed and cannot accept orders at this time.");
          return;
        }
        if (config.tsNavEnabled && window.OloTimeslotPopup) {
          window.OloTimeslotPopup.open(item.url, item.siteid || "", item.areaid || "");
        } else {
          window.location.href = item.url;
        }
      });

      markers.push(marker);
    });
  };

  const fetchLocations = async (params = {}) => {
    const url = new URL(config.restUrl, window.location.origin);

    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== "") {
        url.searchParams.set(key, String(value));
      }
    });

    const response = await fetch(url.toString(), {
      method: "GET",
      headers: {
        "X-WP-Nonce": config.nonce || "",
      },
      credentials: "same-origin",
    });

    if (!response.ok) {
      throw new Error("Failed to fetch locations");
    }

    return response.json();
  };

  // Sequence guard: only the most recent fetch is allowed to render, so a
  // slower in-flight request (e.g. a search cleared mid-flight) cannot
  // overwrite the results of a newer one.
  let renderSeq = 0;
  const renderLatest = (seq, items) => {
    if (seq === renderSeq) {
      renderResults(items);
    }
  };

  const submitSearch = async () => {
    const formData = new FormData(form);
    const keyword = String(formData.get("keyword") || "").trim();
    /* Unncomment to implement radius search */
    /* const radius = String(formData.get("radius") || "5").trim(); */
    const radius = 50;

    const payload = { keyword, radius };

    const seq = ++renderSeq;
    const data = await fetchLocations(payload);
    console.log('Data received from fetchLocations: ', data);

    renderLatest(seq, data.items || []);
  };

  const loadDefaultLocations = () => {
    const seq = ++renderSeq;
    return fetchLocations({ radius: 5 })
      .then((data) => renderLatest(seq, data.items || []))
      .catch(() => {
        if (seq === renderSeq) renderEmpty();
      });
  };

  const toggleClearButton = () => {
    const hasText = !!(keywordInput && keywordInput.value.trim());
    clearBtn?.classList.toggle("is-visible", hasText);
  };

  const resetSearch = () => {
    if (keywordInput) keywordInput.value = "";
    toggleClearButton();
    loadDefaultLocations();
  };

  form?.addEventListener("submit", async (event) => {
    event.preventDefault();

    try {
      await submitSearch();
    } catch (error) {
      renderEmpty();
    }
  });

  keywordInput?.addEventListener("input", () => {
    toggleClearButton();
    if (!keywordInput.value.trim()) {
      resetSearch();
    }
  });

  clearBtn?.addEventListener("click", () => {
    resetSearch();
    keywordInput?.focus();
  });

  mapToggle?.addEventListener("click", () => {
    if (!mapContainer) {
      return;
    }

    mapContainer.classList.toggle("show");
    mapToggle.textContent = mapContainer.classList.contains("show")
      ? config.strings.hideMap
      : config.strings.showMap;
  });

  // Closed location: intercept the click so it neither navigates nor selects —
  // surface the "currently closed" toast instead. Closed cards are never timeslot
  // links, so this does not interfere with the timeslot popup delegation.
  resultsEl?.addEventListener("click", (event) => {
    const link = event.target.closest(".btn-view1.olo-closed");
    if (!link) return;
    event.preventDefault();
    showToast(config.strings.closedMessage || "This location is currently closed and cannot accept orders at this time.");
  });

  // OE-26385: when redirected here from a closed location's menu page, surface the
  // "currently closed" toast, then strip the flag so a refresh or Back press does not
  // repeat it.
  if (new URLSearchParams(window.location.search).get("oloClosed") === "1") {
    showToast(config.strings.closedMessage || "This location is currently closed and cannot accept orders at this time.");
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("oloClosed");
    window.history.replaceState({}, document.title, cleanUrl.toString());
  }

  toggleClearButton();
  loadDefaultLocations();
})();