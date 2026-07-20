/**
 * Use this file for JavaScript code that you want to run in the front-end
 * on posts/pages that contain this block.
 *
 * When this file is defined as the value of the `viewScript` property
 * in `block.json` it will be enqueued on the front end of the site.
 *
 * Example:
 *
 * ```js
 * {
 *   "viewScript": "file:./view.js"
 * }
 * ```
 *
 * If you're not making any changes to this file because your project doesn't need any
 * JavaScript running in the front-end, then you should delete this file and remove
 * the `viewScript` property from `block.json`.
 *
 * @see https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/#view-script
 */

/* eslint-disable no-console */
import React from 'react';
import { createRoot } from 'react-dom/client';
import "./view.css";
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {  faClose ,faPen  } from '@fortawesome/free-solid-svg-icons';

import "./giftCard.js";
import LoyaltyRewards from "./LoyaltyRewards.js";
import loader from './assets/loader-icon.gif';

const containeractionbutton = document.getElementById('cbs-quick-action-buttons');
const paymentLabel = containeractionbutton.getAttribute('data-paymentlabel');

const InsertCloseIcon = () => {
    return (
      <div id="existingDiv">
        <FontAwesomeIcon icon={faClose} /> {/* Font Awesome home icon */}
      </div>
    );
  };

  const InsertPenIcon = () => {
    return (
      <div id="existingDiv">
        <FontAwesomeIcon icon={faPen} /> {/* Font Awesome home icon */}
      </div>
    );
  };

  const InsertCashIcon = () => {
    return (
      <div id="existingDiv">
        hello
      </div>
    );
  }

  const redirectMenu = (slug) => {
    window.location.href = `/${slug}`;
  }

  const paymentModal = (event) => {
    const modalContainer = document.getElementById("cbs-payment-modal-container");
    var modalContent = document.querySelector('.modal-content');
    if (modalContainer.classList.contains('show')) {
      if(event.target.id === 'place_order'){
        var overlay = document.querySelector('.modal-loader');
        overlay.style.display = 'flex';
      }

      if (!modalContent.contains(event.target) && event.target.id !== 'checkout-button') {
            modalContainer.classList.remove('show');
      }

      changeButtonName;

    }else{
      if(event.target.id === 'checkout-button'){
        modalContainer.classList.add("show");
      }

      if(event.target.id === 'cancel-button'){
        console.log("cancel button clicked");
      }
      if(event.target.id === 'checkout-button-pay'){
        var buttonTarget = document.getElementById('place_order');
        var loaderIcon = document.querySelector('#checkout-button-pay img');
        loaderIcon.style.display = 'inline';
        event.target.disabled = true;
        event.target.classList.add('disabled-button');
        buttonTarget.click();
      }

    }
  }


  const ActionButton = (props) => {
    return (
      <>
        <button className="button" onClick={() => redirectMenu(props.slug)} id="cancel-button">Add more items</button>
        <button className="button" onClick={(event)=> paymentModal(event)} id="checkout-button">{paymentLabel}</button>
      </>
    );

  }

  const ActionButtonPay = (props) => {
    return (
      <>
        <button className="button" onClick={() => redirectMenu(props.slug)} id="cancel-button">Add more items</button>
        <button className="button" onClick={(event)=> paymentModal(event)} id="checkout-button-pay">{paymentLabel} <img src={loader} alt="Loading..." /></button>
      </>
    );
  }
/* const containeractionbutton = document.getElementById('cbs-quick-action-buttons');
const root = createRoot(containeractionbutton);
root.render( <ActionButton /> ); */


const gatewaysQuantity = containeractionbutton.getAttribute('data-gateways-quantity');
const redirectSlug = containeractionbutton.getAttribute('slug');

if(gatewaysQuantity > 1){
  const root = createRoot(containeractionbutton);
  root.render( <ActionButton slug={redirectSlug}/> );
}else{
  const root = createRoot(containeractionbutton);
  root.render( <ActionButtonPay slug={redirectSlug}/> );
}


const changeButtonName = () => {
  const containerModal = document.getElementById('cbs-payment-modal-container');
  const buttonName = containerModal.dataset.button;

  var placeOrderButton = document.querySelector('#place_order');

  if (placeOrderButton) {
      placeOrderButton.textContent = buttonName;
      placeOrderButton.value = buttonName;
  }
  console.log('place order button', placeOrderButton);
}


document.addEventListener('DOMContentLoaded', function () {

  var labels = document.querySelectorAll('label');
  console.log(labels);
  labels.forEach(function(label) {
      label.addEventListener('click', function(event) {
        console.click('label clicked');
          // Find the input element within the label
          var input = label.querySelector('input[type="radio"]');
          if (input) {
              console.log('Selected radio button value:', input.value);
          }
      });
  });

  setTimeout(changeButtonName, 3000);

    const editElements = document.querySelectorAll('.edit-icon');
    editElements.forEach((element) => {
      const icon = document.createElement('span');
      const root = createRoot(icon);
      root.render(<InsertPenIcon />);
      element.appendChild(icon);
    });
    const deleteElements = document.querySelectorAll('.delete-icon');
    deleteElements.forEach((element) => {
      const icon = document.createElement('span');
      const root = createRoot(icon);
      root.render(<InsertCloseIcon />);
      element.appendChild(icon);
    });

  createRoot(document.getElementById('loyalty-container')).render(<LoyaltyRewards />);
});

  document.addEventListener('click', function(event) {
    paymentModal(event);
});

//Overlay loader functionality
jQuery(document).ready(function($) {
  $(document.body).on('update_checkout', function() {
    const loader = $('.modal-loader');
    const modalContainer = document.getElementById('cbs-payment-modal-container');

    if (modalContainer && modalContainer.classList.contains('show')) {
      loader.css('display', 'flex');
    } else {
      const btn = document.getElementById('checkout-button') || document.getElementById('checkout-button-pay');
      if (btn) btn.classList.add('loading');
    }

    $(document).off('ajaxComplete.checkout').on('ajaxComplete.checkout', function(_event, _xhr, settings) {
      changeButtonName();
      $('#place_order').text('Place Order');
      $('#place_order').val('Place Order');
      if (settings.url.indexOf('wc-ajax=update_order_review') > -1) {
        loader.hide();
        $('.overlay-update-checkout').addClass('hide');
        const btn = document.getElementById('checkout-button') || document.getElementById('checkout-button-pay');
        if (btn) btn.classList.remove('loading');
      }

    });
  });
});

async function handleDeleteGiftCard(event) {
  const giftCard = event.target.dataset.cardnumber;
  const button = event.target;
  button.classList.add('pulse-deleting');
  button.style.pointerEvents = 'none';
  const response = await fetch(`/wp-json/northstaronlineordering/v1/giftcard?gc=${giftCard}`, {
    method: 'DELETE',
    headers: {
      'Content-Type': 'application/json'
    }
  });
  const data = await response.json();
  button.classList.remove('pulse-deleting');
  button.style.pointerEvents = '';
  if(response.ok) {
    jQuery(document.body).trigger('update_checkout');
    return;
  }
  console.error(data);
}

document.addEventListener('click', function(event) {
  const button = event.target.closest('.delete-giftcard');
  if (button) {
    handleDeleteGiftCard({ target: button });
  }
});

function closeModal() {
  var modalContainer = document.getElementById('cbs-payment-modal-container');
  modalContainer.classList.remove('show');
}

const closeModalBtn = document.getElementById('close-dialog');

closeModalBtn?.addEventListener('click', function() {
  closeModal();
});

document.addEventListener('DOMContentLoaded', function () {
  jQuery(document.body).on('checkout_error', function() {
    jQuery('#cbs-loader-overlay').hide();
    const btn = document.getElementById('checkout-button') || document.getElementById('checkout-button-pay');
    if (btn) {
      btn.classList.remove('loading');
      btn.disabled = false;
      btn.classList.remove('disabled-button');
    }
  });
});