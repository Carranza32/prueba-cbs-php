window.addEventListener('DOMContentLoaded', (event) => {
  console.log('DOM fully loaded and parsed');
  const table = document.querySelector('.woocommerce-cart-form');
  if(table)
  {
    let inner = table.querySelectorAll('.remove');
    if(inner.length > 0 ){
      const submitButton = document.querySelector('#submit_items_kitchen');
      if (submitButton) {
        submitButton.disabled = false;
      }
    }

  }
});



jQuery(document).ready(function() {
  var wpcotTipValueElements = jQuery(".wpcot-tip-value .woocommerce-Price-amount");
var textlog = wpcotTipValueElements.text();
var targetDiv = wpcotTipValueElements.closest(".wpcot-tip-value");
var priceNumber = parseFloat(textlog.replace(/[^\d.-]/g, ''));
targetDiv.attr("data-value", priceNumber);
});
