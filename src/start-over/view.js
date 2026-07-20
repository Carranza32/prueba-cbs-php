import "./view.css";
import React from 'react';
import { createRoot } from 'react-dom/client';
import {ReactComponent as StartOverIcon} from "../assets/start-over-icon.svg";
import { resetConfig } from "../../js/kioskStartOver";

const startOverButton = document.querySelector(".start-over-button");
const idlePage = startOverButton?.dataset.idlepageslug;
const restNonce = window?.olo_vars_object?.rest_nonce || window?.oloTimeslotPopup?.nonce || '';

const handleStartOver = (event=null) => {
    event?.preventDefault();
    console.log("Start Over");
    const data =  handleCartReset();
    data.then((data) => {
        if (!data?.success) {
            console.error("Start Over aborted: cart reset was not completed.");
            return;
        }
        console.log(data);
        window.location.href = `/${idlePage}`;
        resetConfig();
    });
}

const handleCartReset = async () => {
    if (!restNonce) {
        console.error("Missing REST nonce for cart reset.");
        return null;
    }

    try {
        const response = await fetch("/wp-json/northstaronlineordering/v1/cart", {
            method: "DELETE",
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": restNonce,
            },
        });

        if (!response.ok) {
            throw new Error("Failed to reset cart");
        }
        const data = await response.json();
        return data;
    } catch (error) {
        console.error(error);
        return null;
    }
}

createRoot(document.getElementById("start-over-icon")).render(<StartOverIcon />);
startOverButton?.addEventListener("click", handleStartOver);

let timeout;

function resetTimer() {
    console.log("reset timer");
    clearTimeout(timeout);
    const starover = document.querySelector(".start-over-block");
    const time = starover.getAttribute('data-time');
    timeout = setTimeout(() => {
        handleStartOver();
    }, time * 1000);
}

document.addEventListener('click', resetTimer);

window.onload = resetTimer;