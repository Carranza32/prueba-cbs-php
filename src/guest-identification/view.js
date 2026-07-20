import "./view.css";

import React, { createElement } from 'react';
import {createRoot} from 'react-dom';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faBackspace } from '@fortawesome/free-solid-svg-icons';
import Cookies from 'js-cookie';
import loader from './assets/loader-icon.gif';

const BackSpaceIcon = () => <FontAwesomeIcon icon={faBackspace} />
createRoot(document.getElementById('backspace-button')).render(<BackSpaceIcon />);

const checkoutBtn = document.querySelector('.nothanks-button');
const guestIdentifierDialog = document.querySelector('.guest-identifier-dialog');
const closeBtn = document.querySelector('.close-button-guest-identifier');
const phoneicon = document.getElementById('cbsphone');
const userIcon  = document.getElementById('cbsuser');
const guestPhoneInput = document.getElementById('guest-phone');
const guestNameInput = document.getElementById('guest-name');
const NumericPadButtons = document.querySelectorAll('.numeric-pad-button');
const submitGuestIdentifier = document.getElementById('submit-guest-identifier');
const checkoutButton = document.getElementById("checkout-button");
const disablePhoneField = checkoutButton?.getAttribute("data-prompt-phone-field")==='true';
let noRedirect= false;

export function handleShowGuestIdentifier(e) {
    e.preventDefault();
    e.stopPropagation();
    const button = e.target;
    const checkoutButton = document.getElementById("checkout-button");
    const disablePhonePrompt = checkoutButton?.getAttribute("data-prompt-phone")==='true';

    const existingLoader = button.querySelector('.loader-icon');
    if (existingLoader) {
        existingLoader.remove();
    }

    const iconLoader = document.createElement('img');
    iconLoader.src = loader;
    iconLoader.alt = 'Loading...';
    iconLoader.className = 'loader-icon';
    iconLoader.style.marginLeft = '8px';
    iconLoader.style.height = '16px';


    const name = localStorage.getItem('guestName');
    const phone = localStorage.getItem('guestPhone');
    if(name) {
        guestNameInput.value = name;
    }
    if(phone) {
        guestPhoneInput.value = formatPhoneNumber(phone);
    }

    noRedirect = e.target.id === 'cbsuser';

    if(disablePhonePrompt){
        closeUpsellsModal();
        handleCheckoutButtonLoadingState(button, iconLoader);
        return;
    }

    if(!phone || noRedirect ) {
        closeUpsellsModal();
        guestIdentifierDialog.showModal();
    }


    if(phone && !noRedirect) {
        handleCheckoutButtonLoadingState(button, iconLoader);
    }
}

const closeUpsellsModal = () => {
    const modal = document.getElementById('upsell-modal');
    modal?.classList.remove('show');
}

const isValidUSPhoneNumber = (phoneNumber) => {
    const cleaned = phoneNumber.replace(/\D/g, "");
    const validUSNumberPattern = /^[2-9]{1}[0-9]{2}[2-9]{1}[0-9]{6}$/;
    return validUSNumberPattern.test(cleaned);
};

function handleCloseGuestIdentifier(e) {
    e.preventDefault();
    const inputs = guestIdentifierDialog.querySelectorAll("input");
    inputs.forEach((input) => {
        input.value = "";
    });
    noRedirect = false;
    guestIdentifierDialog.close();
}

function handleSubmitGuestIdentifier(e) {
    e.preventDefault();
    const button = e.target;
    const form = document.getElementById("guest-identifier-form");
    if (!disablePhoneField) {
        if (!form.reportValidity()) return;
        if (!isValidUSPhoneNumber(guestPhoneInput.value)) {
            alert("Please enter a valid US phone number");
            return;
        }
        localStorage.setItem("guestPhone", guestPhoneInput.value);
        Cookies.set("guestPhone", guestPhoneInput.value, { path: "/" });
    } else {
        localStorage.setItem("guestPhone", "");
        Cookies.set("guestPhone", "", { path: "/" });
    }

    if (guestNameInput && guestNameInput.value) {
        localStorage.setItem("guestName", guestNameInput.value);
        Cookies.set("guestName", guestNameInput.value, { path: "/" });
    }

    if (noRedirect) {
        guestIdentifierDialog.close();
        noRedirect = false;
        return;
    }

    const existingLoader = button.querySelector(".loader-icon");
    if (existingLoader) {
        existingLoader.remove();
    }
    const iconLoader = document.createElement("img");
    iconLoader.src = loader;
    iconLoader.alt = "Loading...";
    iconLoader.className = "loader-icon";
    iconLoader.style.marginLeft = "8px";
    iconLoader.style.height = "16px";

    button.disabled = true;
    button.appendChild(iconLoader);
    const href = button.getAttribute("href") || "/checkout";
    window.location.href = href;
}

const formatPhoneNumber = (phoneNumberString) => {
    const cleaned = ("" + phoneNumberString).replace(/\D/g, "");
    return cleaned.replace(
        /(\d{0,3})(\d{0,3})(\d{0,4})/,
        function (_, areaCode, centralOfficeCode, lineNumber) {
            let result = "";
            if (areaCode) result += `(${areaCode}`;
            if (centralOfficeCode) result += `) ${centralOfficeCode}`;
            if (lineNumber) result += `-${lineNumber}`;
            return result;
        }
    );
};

const handleNumberClick = (e) => {
    e.preventDefault();
    e.stopPropagation();

    const button = e.currentTarget;
    let inputValue = guestPhoneInput.value;
    inputValue = inputValue.replace(/\D/g, "");

    if (button && button.value === "backspace") {
        guestPhoneInput.value = formatPhoneNumber(inputValue.slice(0, -1));
        return;
    }

    if (inputValue.length >= 10) {
        return;
    }

    inputValue += button.value;
    guestPhoneInput.value = formatPhoneNumber(inputValue);
};

guestPhoneInput?.addEventListener("input", function (e) {
    if (e.data === null) {
        return;
    }

    const inputValue = e.target.value;
    let cleaned = inputValue.replace(/\D/g, "");
    e.target.value = formatPhoneNumber(cleaned);
});

guestNameInput?.addEventListener("input", function (e) {
    if (e.data === null) {
        return;
    }

    const inputValue = e.target.value;
    let cleaned = inputValue.replace(/[^a-zA-Z0-9\s'-]/g, "");
    if (cleaned.length > 50) {
        cleaned = cleaned.substring(0, 50);
    }
    e.target.value = formatGuestName(cleaned);
});

// Prevent multiple registrations using a flag
if (!window.__guestIdentificationInitialized) {
    window.__guestIdentificationInitialized = true;

    closeBtn?.addEventListener("click", handleCloseGuestIdentifier);
    phoneicon?.addEventListener("click", handleShowGuestIdentifier);
    userIcon?.addEventListener("click", handleShowGuestIdentifier);
    checkoutBtn?.addEventListener("click", handleShowGuestIdentifier);
    submitGuestIdentifier?.addEventListener(
        "click",
        handleSubmitGuestIdentifier
    );
    if (!disablePhoneField) {
        NumericPadButtons.forEach((button) => {
            button.addEventListener("click", handleNumberClick, {
                once: false,
            });
        });
    }
}

function formatGuestName(name) {
    return name.replace(/\b\w/g, (char) => char.toUpperCase());
}

function handleCheckoutButtonLoadingState(buttonContainer, iconLoader) {

    const realButton = buttonContainer?.closest?.('button') ||
        (buttonContainer?.tagName ==='BUTTON' ? buttonContainer : buttonContainer?.querySelector('button'));
    if(realButton) {
        realButton.disabled = true;
        realButton.textContent = "Loading...";
        realButton.appendChild(iconLoader);
    }
    window.location.href = '/checkout';
}
