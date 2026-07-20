import "./view.css";

import React from 'react';
import { ReactComponent as DineInIcon } from '../assets/dine-in-icon.svg';
import { ReactComponent as TakeOutIcon } from '../assets/to-go-icon.svg';
import { ReactComponent as DeliveryIcon } from '../assets/delivery-icon.svg';
import { createRoot } from 'react-dom/client';
import Cookies from 'js-cookie';
import OrderType from '../constants/orderType.js';


const orderTypeBlock = document.getElementById('kiosk-order-type__container');
const redirectPage = orderTypeBlock.dataset.redirectpage;
const idlePage = orderTypeBlock.dataset.idlepage;

const handleOrderTypeButtonClick = (e) => {
    const orderType = e.target.dataset.ordertypeid;
    const areaId = e.target.dataset.areaid;
    localStorage.setItem('OrderType', orderType);
    Cookies.set('orderType', OrderType[orderType]);
    localStorage.setItem('AreaId', areaId);
    Cookies.set('areaId', areaId);
    window.location.href = slugToUrl(redirectPage);
};

const slugToUrl = (slug) => {
    const baseUrl = window.location.origin;
    const url = new URL(slug, baseUrl);

    if (url.origin !== baseUrl) {
        console.error('Invalid URL');
        return;
    }

    return `${baseUrl}/${slug}`;
}
const orderTypeButtons = document.querySelectorAll('.kiosk-order-type__button');
orderTypeButtons?.forEach((OrderTypeButton) => {
    OrderTypeButton.addEventListener('click', (e) => {
        handleOrderTypeButtonClick(e);
    });
});

const closeButton = document.getElementById('kiosk-order-type__close');
closeButton?.addEventListener('click', () => {
    window.location.href = slugToUrl(idlePage);
});

const dineInOrderIcon = document.getElementById('DineIn-icon');
const toGoOrderIcon = document.getElementById('ToGo-icon');
const preOrderIcon = document.getElementById('Delivery-icon');

dineInOrderIcon && createRoot(dineInOrderIcon).render(<DineInIcon />);
toGoOrderIcon && createRoot(toGoOrderIcon).render(<TakeOutIcon />);
preOrderIcon && createRoot( preOrderIcon).render(<DeliveryIcon />);
