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
import "./view.css";
import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faPhone , faUser , faClose } from '@fortawesome/free-solid-svg-icons';
import ReactDOM from 'react-dom';
import { handleShowGuestIdentifier } from "../guest-identification/view.js";

const launchGuestIdentifier = () => {
  handleShowGuestIdentifier({
      preventDefault: () => {},
      stopPropagation: () => {},
      target: document.getElementById("checkout-button") || {},
  });
}

const closeModal = () => {
  const modal = document.getElementById('upsell-modal');
  modal.classList.remove('show');
}

const openModal = () => {
  const modal = document.getElementById('upsell-modal');
  const hasRecommendations = modal.querySelector('.wc-prl-recommendations');
  const modalHasProducts = hasRecommendations? hasRecommendations.querySelector('.products'): null;
  if(hasRecommendations && modalHasProducts && modalHasProducts.children.length > 0) {
    modal.classList.add('show');
    handlingModalState();
  }
  else {
    // open Guest identifier modal
    const mockEvent = {
      preventDefault: () => {},
      stopPropagation: () => {},
      target: document.getElementById('checkout-button') || {}
    };
    handleShowGuestIdentifier(mockEvent);
  }

}

const InsertIconPhoneIcon = () => {
    return (
      <div id="existingDiv">
        <FontAwesomeIcon icon={faPhone} /> {/* Font Awesome home icon */}
      </div>
    );
};

const InsertIconUserIcon = () => {
  return (
    <div id="existingDiv">
      <FontAwesomeIcon icon={faUser} />
    </div>
  );
};

const InsertCloseIcon = () => {
  return (
    <button className="close-icon" onClick={closeModal}>
      <FontAwesomeIcon icon={faClose} />
    </button>
  );
};

const CheckoutButton = () => {

  const [cartIsEmpty, setCartIsEmpty] = React.useState(() => {
    const el = document.getElementById('checkout-button');
    if (!el) return true;
    const val = el.dataset.isempty;
    return val === 'true';
  });

  React.useEffect(() => {
    const handleCartUpdate = (e) => {
      const { count } = e.detail || {};
      setCartIsEmpty(count === 0);
    };

    document.addEventListener('cbsCartUpdated', handleCartUpdate);

    return () => {
      document.removeEventListener('cbsCartUpdated', handleCartUpdate);
    };
  }, []);

  return (
    <button disabled={cartIsEmpty} onClick={openModal} className={`checkout-button ${cartIsEmpty ? 'disabled-button' : ''}`} aria-disabled={cartIsEmpty}>
      Checkout
    </button>
  );
}

const NothanksButton = () => {
  return (
    <button onClick={launchGuestIdentifier} className="nothanks-button">
      Proceed to Checkout
    </button>
  );
}


/* eslint-disable */
ReactDOM.render(<InsertIconPhoneIcon />, document.getElementById('cbsphone'));
ReactDOM.render(<InsertIconUserIcon />, document.getElementById('cbsuser'));
ReactDOM.render(<InsertCloseIcon /> , document.getElementById('close-icon'));
ReactDOM.render(<CheckoutButton />, document.getElementById('checkout-button'));
ReactDOM.render(<NothanksButton />, document.getElementById('upsells-modal-footer'));
/* eslint-enable */
/* eslint-enable no-console */


function handlingModalState () {
  const customizeButtons = document.querySelectorAll('#upsell-modal .customize-button, #upsell-modal .quick-add');

  const registerActivity = () => {
    localStorage.setItem('upsellModal', 'true');
  }

  customizeButtons.forEach((button) => {
    button.addEventListener('click', registerActivity);
  });
}

document.addEventListener("DOMContentLoaded", function() {
  const autoDisplayModal = localStorage.getItem('upsellModal');
  if(autoDisplayModal) {
    openModal();
    localStorage.removeItem('upsellModal');
  }
});