import React, {useEffect, useRef, useState} from 'react';
import {ReactComponent as PromoCodeIcon} from "./assets/promo-code-icon.svg";

import AvailablePrograms from './AvailablePrograms';
import Cookies from 'js-cookie';

const LoyaltyRewards = () => {

    const dialogRef = useRef(null);
    const [isDialogOpen, setIsDialogOpen] = useState(false);
    const [rewards, setRewards] = useState([]);
    const [siteId, setSiteId] = useState(null);
    const [loading, setLoading] = useState(false);
    const [redeemCount, setRedeemCount] = useState(0);
    const [cartTotal, setCartTotal] = useState(0);
    const [isActionBusy, setIsActionBusy] = useState(false);
    const promocodeHide = document.getElementById('loyalty-container').dataset.promocode;

    const readCartTotal = () => {
        const node = document.querySelector('.order-total .woocommerce-Price-amount, .order-total .amount');
        const parsed = parseFloat((node?.textContent || '0').replace(/[^0-9.-]/g, ''));
        return Number.isFinite(parsed) ? parsed : 0;
    };

    useEffect(() => {
        setCartTotal(readCartTotal());
        const onUpdated = () => setCartTotal(readCartTotal());
        jQuery(document.body).on('updated_checkout', onUpdated);
        return () => {
            jQuery(document.body).off('updated_checkout', onUpdated);
        };
    }, []);

    useEffect(() => {
        if (!isDialogOpen) {
            return;
        }
        // Clear stale rewards from any previous dialog open so a missing
        // guestPhone or fetch failure cannot leak prior guest's data.
        setRewards([]);
        const guestPhonevalue = localStorage.getItem('guestPhone');
        if(!guestPhonevalue){
            return;
        }
        const guestPhoneNumber = guestPhonevalue.replace(/[()\s-]/g, '');
        const areaId = localStorage.getItem('AreaId');

        const siteId = Cookies.get('siteid');

        setSiteId(siteId);
        setLoading(true);
        fetch('/wp-json/northstaronlineordering/v1/loyalty/availablePrograms', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                siteId: siteId,
                phoneNumber: guestPhoneNumber,
            }),
        })
            .then(response => response.json())
            .then(data => {
                if(data.ErrorMessage){
                    console.error(data.ErrorMessage);
                    setLoading(false);
                    return;
                }
                console.log("Loyalty data:", data);
                setRewards(data);
                setLoading(false);
            })
            .catch(error => {
                console.error('Error:', error);
                setLoading(false);
            });
    }, [isDialogOpen]);

    const toggleDialog = () => {
        if(!isDialogOpen){
            dialogRef.current?.showModal();
        }
        else{
            dialogRef.current?.close();
        }
        setIsDialogOpen(!isDialogOpen);
    };

    const closeDialog = () => {
        dialogRef.current?.close();
        setIsDialogOpen(false);
    };

    return (
        <>
            <button id="checkout-promo-code" className={`button ${promocodeHide ? 'disabled' : ''}`} onClick={toggleDialog}>
                <span><PromoCodeIcon/></span><span>Promo code</span><span className="rewards-count ">{redeemCount>0? redeemCount: ''}</span>
            </button>

            <dialog id="kiosk-rewards-dialog" className="rewards-dialog" ref={dialogRef}>
                <div className="rewards-dialog-top">
                    <button onClick={closeDialog} className='rewards-dialog-close'><span className="sr-only">Close</span>&times;</button>
                </div>
                    <div className='rewards-dialog-title'>
                        <h1>Rewards</h1>
                    </div>

                    {loading && <div className='rewards-dialog-loading'>
                        <span className="loader"></span>
                    </div>}

                    {!loading && <AvailablePrograms loyalty={rewards} siteId={siteId} redeemCountHandler={setRedeemCount} cartTotal={cartTotal} onBusyChange={setIsActionBusy}/>}

                    <div className='rewards-dialog-bottom'>
                        <button className='reward-button done' onClick={toggleDialog} disabled={isActionBusy}>Done</button>
                    </div>
            </dialog>
        </>
    );
};

export default LoyaltyRewards;

