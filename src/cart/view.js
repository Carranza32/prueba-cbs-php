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
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCartShopping, faClose, faPen, faCaretDown } from '@fortawesome/free-solid-svg-icons';
import "./view.css";
import { Spinner } from '@wordpress/components';
import Cookies from 'js-cookie';
import OrderType from '../constants/orderType.js';


const InsertCartIcon = () => (
  <FontAwesomeIcon icon={faCartShopping} />
);

const InsertDropdownIcon = () => (
  <FontAwesomeIcon icon={faCaretDown} />
);

const InsertCloseIcon = () => (
  <FontAwesomeIcon icon={faClose} />
);

const InsertPenIcon = () => (
  <FontAwesomeIcon icon={faPen} />
);


function renderReactIcons() {
  const cartIconElement = document.getElementById('cart-icon');
  const dropdownArrowElement = document.getElementById('dropdown-arrow');


  if (cartIconElement) {
    createRoot(cartIconElement).render(<InsertCartIcon />);
  }


  if (dropdownArrowElement) {
    createRoot(dropdownArrowElement).render(<InsertDropdownIcon />);
  }


  const editElements = document.querySelectorAll('.edit-icon');
  editElements.forEach((element) => {
    const iconContainer = document.createElement('span');
    createRoot(iconContainer).render(<InsertPenIcon />);
    element.innerHTML = '';
    element.appendChild(iconContainer);
  });


  const deleteElements = document.querySelectorAll('.delete-icon');
  deleteElements.forEach((element) => {
    const iconContainer = document.createElement('span');
    createRoot(iconContainer).render(<InsertCloseIcon />);
    element.innerHTML = '';
    element.appendChild(iconContainer);
  });
}


function handleDeleteItem(event) {
  const productId = event.currentTarget.getAttribute('product-id');
  console.log(`Deleting item with product ID: ${productId}`);

}

function syncCartCount(count) {
  const normalizedCount = Number(count ?? 0);
  jQuery('.cart-number').html(normalizedCount);
  document.dispatchEvent(new CustomEvent('cbsCartUpdated', {
    detail: {
      count: normalizedCount,
    }
  }));

  // Keep Woo fragments (theme header/floating badges) consistent with kiosk cart changes.
  jQuery(document.body).trigger('wc_fragment_refresh');
}

function restoreDeleteIcon(buttonElement) {
  if (!buttonElement) {
    return;
  }

  const iconContainer = document.createElement('span');
  createRoot(iconContainer).render(<InsertCloseIcon />);
  buttonElement.innerHTML = '';
  buttonElement.appendChild(iconContainer);
}

const deleteQueue = [];
const queuedDeleteKeys = new Set();
let deleteRequestInFlight = false;

function setDeleteButtonLoading(cartItemKey, isLoading) {
  const selector = `.delete-icon[product-id="${cartItemKey}"]`;
  const deleteButtons = document.querySelectorAll(selector);

  deleteButtons.forEach((button) => {
    if (isLoading) {
      button.innerHTML = '...';
      button.style.pointerEvents = 'none';
      return;
    }

    button.style.pointerEvents = '';
    restoreDeleteIcon(button);
  });
}

function processDeleteQueue() {
  if (deleteRequestInFlight || deleteQueue.length === 0) {
    return;
  }

  const cartItemKey = deleteQueue.shift();
  if (!cartItemKey) {
    processDeleteQueue();
    return;
  }

  deleteRequestInFlight = true;

  jQuery.ajax({
    url: olo_vars_object.ajax_url,
    type: 'POST',
    data: {
      action: 'load_delete_action_cbs',
      cart_item_key: cartItemKey,
      nonce: olo_vars_object.ajax_nonce,
    },
    success: function(response) {
      if (response.success) {
        const payload = response.data || {};
        const cartCount = Number(payload.cartCount ?? payload.count ?? 0);
        const cartTotal = payload.cartTotal || payload.total || '$0.00';

        jQuery(`.delete-icon[product-id="${cartItemKey}"]`).closest('.cart-item').remove();
        jQuery('.cart-total').html(`Total: ${cartTotal}`);
        syncCartCount(cartCount);
        return;
      }

      setDeleteButtonLoading(cartItemKey, false);
      console.log('Error to delete item');
    },
    error: function() {
      setDeleteButtonLoading(cartItemKey, false);
      console.log('Error Ajax');
    },
    complete: function() {
      queuedDeleteKeys.delete(cartItemKey);
      deleteRequestInFlight = false;
      processDeleteQueue();
    }
  });
}


function reinitializeCartBlock() {
  console.log('Reinitializing cart block JavaScript');


  renderReactIcons();


  const cartLabel = document.getElementById('cart-label');
  const optionsList = document.getElementById('cart-list');
  const cartContainer = document.getElementById('cart-block-cbs');
  const dropIcon = document.getElementById('dropdown-arrow');

  if (cartLabel && optionsList && cartContainer && dropIcon) {
    cartLabel.removeEventListener('click', toggleCart);
    cartLabel.addEventListener('click', toggleCart);

    if (sessionStorage.getItem('cartOpen') === 'true') {
      optionsList.classList.add('show');
      cartContainer.classList.add('show');
      dropIcon.classList.add('show');
    }
  }


  const deleteIcons = document.querySelectorAll('.delete-icon');
  deleteIcons.forEach((icon) => {
    icon.removeEventListener('click', handleDeleteItem);
    icon.addEventListener('click', handleDeleteItem);
  });


  const toggleCheckbox = document.getElementById('toggle');
  if (toggleCheckbox) {
    toggleCheckbox?.addEventListener('change', function() {
      localStorage.setItem('OrderType', toggleCheckbox.checked ? 'ToGo' : 'DineIn');
      if (toggleCheckbox.checked) {
          // Handle "To go" state
          localStorage.setItem('OrderType', 'ToGo');
          Cookies.set('orderType', OrderType.ToGo );

      } else {
          // Handle "Dine In" state
          localStorage.setItem('OrderType', 'DineIn');
          Cookies.set('orderType', OrderType.DineIn);
      }
    });


    function setOrderTypeSelected() {
      const storedOption = localStorage.getItem('OrderType');
      const orderType = Cookies.get('orderType');

    if (orderType === '1') {

        toggleCheckbox.checked = true;
    }
      if (storedOption === 'ToGo') {
          toggleCheckbox.checked = true;
      }
    }

  setOrderTypeSelected();
  }

  jQuery(document).off('click.cbsDeleteCart', '.delete-icon').on('click.cbsDeleteCart', '.delete-icon', function(e) {
    e.preventDefault();
    const cartItemKey = this.getAttribute('product-id');
    if (!cartItemKey || queuedDeleteKeys.has(cartItemKey)) {
      return;
    }

    queuedDeleteKeys.add(cartItemKey);
    setDeleteButtonLoading(cartItemKey, true);
    deleteQueue.push(cartItemKey);
    processDeleteQueue();
  });
}
function toggleCart() {
  const optionsList = document.getElementById('cart-list');
  const cartContainer = document.getElementById('cart-block-cbs');
  const dropIcon = document.getElementById('dropdown-arrow');

  if (optionsList && cartContainer && dropIcon) {
    optionsList.classList.toggle('show');
    cartContainer.classList.toggle('show');
    dropIcon.classList.toggle('show');
    sessionStorage.setItem('cartOpen', optionsList.classList.contains('show'));
  }
}


window.reinitializeCartBlock = reinitializeCartBlock;


document.addEventListener('DOMContentLoaded', reinitializeCartBlock);