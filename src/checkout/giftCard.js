import React from 'react';
import { createRoot } from 'react-dom/client';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBackspace } from '@fortawesome/free-solid-svg-icons';
import { Spinner } from "@wordpress/components";

const BackSpaceIcon = () => <FontAwesomeIcon icon={faBackspace} />

createRoot(document.getElementById('backspace-button')).render(<BackSpaceIcon />);
createRoot(document.getElementById('giftcard-spinner')).render(<Spinner />);

const giftcardButton = document.getElementById('checkout-gift-card');
const giftcardModal = document.getElementById('gift-card-modal');
const giftCardNumberInput = document.getElementById('gift-card');
const giftCardErrorMesssage = document.querySelector('.validation-message');
const NumericPadButtons = document.querySelectorAll('.numeric-pad-button');
const closeBtn = document.getElementById('close-gift-card-modal');
const submitGiftCardBtn = document.getElementById('submit-gift-card');
const giftCardSpinner = document.getElementById('giftcard-spinner');

const handleShowGifCardModal = (e) => {
  giftcardModal?.showModal();
}

const handleCloseGiftCardModal = (e) => {
  giftcardModal?.close();
  giftCardNumberInput.value = '';
  giftCardErrorMesssage?.classList.add('hide');
}

const handleNumberClick = (e) => {
  let inputValue = giftCardNumberInput.value;
  inputValue = inputValue.replace(/\D/g, '');

  if (e.target.value === 'backspace') {
      giftCardNumberInput.value = inputValue.slice(0, -1);
      giftCardErrorMesssage.classList.add('hide');
      return;
  }

  inputValue += e.target.value;
  giftCardNumberInput.value = inputValue;
}

const handleGifCardVerify = async (e) => {
  giftCardSpinner.classList.remove('hide');
  giftCardErrorMesssage.classList.add('hide');
  const response = await fetch(`/wp-json/northstaronlineordering/v1/giftcard`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ giftCardNumber: giftCardNumberInput.value })
  });

  giftCardSpinner.classList.add('hide');
  const data = await response.json();
  if(response.ok) {
    handleGiftCardSuccess(data);
    return;
  }

  handleBadRequest(data);
}

const handleGiftCardSuccess = (data) => {
    console.log(data);
    jQuery(document.body).trigger('update_checkout');
    giftcardModal?.close();
    giftCardNumberInput.value = '';
}

const handleBadRequest = (data) => {
  giftCardErrorMesssage.textContent = data.message;
  giftCardErrorMesssage.classList.remove('hide');
  console.error(data.message);
}

NumericPadButtons.forEach((button) => {
  button.addEventListener('click', handleNumberClick);
});

giftCardNumberInput?.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && giftCardNumberInput.value.trim() !== '') {
    e.preventDefault();
    handleGifCardVerify();
  }
});

closeBtn?.addEventListener('click', handleCloseGiftCardModal);
giftcardButton?.addEventListener('click', handleShowGifCardModal);
submitGiftCardBtn?.addEventListener('click', handleGifCardVerify);