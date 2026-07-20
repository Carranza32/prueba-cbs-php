jQuery(document).ready(function(){
   /* jQuery("html").animate({
     //  scrollTop:jQuery("#menu_title_desc").offset().top - 100
       });*/
       console.log("custom js loaded");
});

window.addEventListener('beforeunload', () => {
  const scrollingSection = document.getElementById('cbs_menu_category');
  if (scrollingSection) {
    localStorage.setItem('scrollPositionX', scrollingSection.scrollLeft);
  }
});

window.addEventListener('load', () => {
  const scrollingSection = document.getElementById('cbs_menu_category');
  const savedScrollPositionX = localStorage.getItem('scrollPositionX');
  if (scrollingSection && savedScrollPositionX) {
    scrollingSection.scrollLeft = parseInt(savedScrollPositionX);
    localStorage.removeItem('scrollPositionX');
  }
});


document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-view1');

    if (!btn) return;

    // When the time-slot popup is handling this link, let it manage navigation
    if (btn.classList.contains('olo-ts-trigger')) {
        return;
    }

    // Closed location (OE-26385): block navigation entirely. The surface that
    // rendered the button (Pick Up modal / Locations page) shows the
    // "currently closed" toast; this guard just stops the programmatic redirect.
    if (btn.classList.contains('olo-closed')) {
        e.preventDefault();
        return;
    }

    e.preventDefault();

    handleOrderClick(btn);
});

function handleOrderClick(btn) {
  if (!btn.href || btn.tagName !== 'A') {
      console.error('btn-view1 must be an anchor tag with href');
      return;
  }
    const siteId = btn.getAttribute('siteid');
  if (!siteId) {
    console.error('siteid attribute is required');
    return;
  }
    const shipping = btn.dataset.shipping;
    const url = btn.href;

    console.log({ siteId, shipping, url });

    setCookie('no_shipping', shipping === 'Disabled' ? '1' : '0', 30);
    if( shipping === 'Enabled' ) {
      setCookie('orderType', '2', 30);
    }
    purgeShippingFragmentsCache();

    setCookie('siteid', siteId, 30);
    setSessionCookie('locationSelected', '1');

    window.location.href = url;
}
  function getCookie(name) {
    return document.cookie.split('; ').find(row => row.startsWith(name + '='))?.split('=')[1];
  }

  function setCookie(name, value, days) {
    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value}; path=/; max-age=${maxAge}`;
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

  // Session cookie: no max-age / expires so it dies when the browser closes.
  // HttpOnly is impossible from JS; Secure added when page is served over HTTPS.
  function setSessionCookie(name, value) {
    const secure = location.protocol === 'https:' ? '; secure' : '';
    document.cookie = `${name}=${value}; path=/; samesite=lax${secure}`;
  }

// OE-25933: sync visibility of #location-info with the locationSelected cookie.
// Runs on DOMContentLoaded AND pageshow so bfcache restores stay in sync.
function syncLocationSelectedGate() {
    const el = document.getElementById('location-info');
    if (!el) return;
    const selected = document.cookie
        .split('; ')
        .some(c => c.startsWith('locationSelected=1'));
    el.classList.toggle('location-info--hidden', !selected);
}

document.addEventListener('DOMContentLoaded', syncLocationSelectedGate);
window.addEventListener('pageshow', syncLocationSelectedGate);



document.addEventListener('click', function (e) {
    const btn = e.target.closest('.olo-go-home');
    if (!btn) return;

    window.location.href = btn.dataset.redirect;
});