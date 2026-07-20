import React, {useState, useEffect} from "react";
import ReactDOM from 'react-dom';
import {Spinner} from '@wordpress/components';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faTimesCircle, faCheckCircle } from "@fortawesome/free-solid-svg-icons";
import Cookies from 'js-cookie';

import "./view.css";
import { set } from "lodash";

const StatusIcon = ({loading, isValid, isEmpty}) => {
    if(isEmpty){
        return null;
    }

    if(loading){
        return <Spinner />
    }
    const textClass = isValid ? 'text-success' : 'text-danger';
    const icon = isValid ? faCheckCircle : faTimesCircle;

    return(
        <div className={`status-icon-container ${textClass}`}>
            <FontAwesomeIcon icon={icon} />
        </div>
    );
  };

const DevicePinForm = ({redirectUrl, disabled}) => {
    const [loading, setLoading] = useState(false);
    const [isValid, setIsValid] = useState(false);
    const [isEmpty, setIsEmpty] = useState(true);
    const [isDisabled, setIsDisabled] = useState(true);
    const [isRedirecting, setIsRedirecting] = useState(false);
    const [pin, setPin] = useState('');
    const [sites, setSites] = useState([]);
    const [selectedSite, setSelectedSite] = useState('');

    useEffect(() => {
        if(pin.length <6){
            setIsEmpty(true);
            return;
        }
        setLoading(true);
        setIsEmpty(false);
        setIsValid(false);

        fetch(`/wp-json/northstaronlineordering/v1/validate-pin/${pin}`)
        .then(response => response.json())
        .then(json => {
            if(json?.Error){
            console.error(json.Error);
            setLoading(false);
            setIsValid(false);
            return;
            }
            setIsValid(true);
            setLoading(false);
            setIsDisabled(false);
            handleDeviceConfigured();
        })
        .catch(error => {
            console.error('Error:', error);
            setLoading(false);
            setIsValid(false);
            setIsDisabled(true);
        });
    }, [pin]);

        useEffect(() => {
            fetch(`/wp-json/northstaronlineordering/v1/sites`)
            .then(response => response.json())
            .then(json => {
                if(json?.Error){
                    console.error(json.Error);
                    return;
                }
                console.log(json);
                const siteId  = Cookies.get('siteid');
                if(siteId){
                    setSelectedSite(siteId);
                }
                setSites(json);
            })
            .catch(error => {
                console.error('Error fetching sites:', error);
            });

        }, [])

    const handleDeviceConfigured = () => {
        localStorage.setItem('deviceConfigured', true);
        const daysToExpire = 365; // Set the cookie to expire in 365 days
        const expiryDate = new Date();
        expiryDate.setTime(expiryDate.getTime() + (daysToExpire * 24 * 60 * 60 * 1000)); // Convert days to milliseconds
        const expires = "expires=" + expiryDate.toUTCString();
        document.cookie = "deviceConfigured=true;" + expires + ";path=/;";
    }
    const handleDeviceChange = (e) => {
        const selectedDeviceId = e.target.value;
        setSelectedSite(selectedDeviceId);
        Cookies.set('siteid', selectedDeviceId, { expires: 365 });
    };

    const handlePinChange = (e) => {
        const pin = e.target.value;
        setPin(pin);
    };

    const handleSubmit = (e) => {
        e?.preventDefault();
        if(!isValid){
            return;
        }
        setIsDisabled(true);
        setIsRedirecting(true);
        const url = new URL(redirectUrl, window.location.origin);
        if (url.origin !== window.location.origin) {
            console.error('Invalid redirect URL');
            return;
        }
        window.location.href = redirectUrl;
    };

    useEffect(() => {
        if(localStorage.getItem('deviceConfigured')){
            setIsValid(true);
            handleSubmit(null);
        }
        if(disabled){
            handleDeviceConfigured();
            setIsValid(true);
            handleSubmit(null);
        }
    }
    ,[]);

    return (
        <form id="kiosk-device-pin__form" className="kiosk-device-pin__form" onSubmit={handleSubmit}>
            <div>
                <label htmlFor="device-select" className="kiosk-device-pin__label">Select Device</label>
                <div className="custom-input">
                    <select 
                        id="device-select" 
                        name="device" 
                        className="kiosk-device-pin__select" 
                        onChange={handleDeviceChange}
                        value={selectedSite}
                        required
                    >
                    {
                        !selectedSite && (
                            <option value="" disabled>Please Select a Site</option>
                        )
                    }
                    {sites.length > 0 ? (
                        sites.map((site) => (
                            <option key={site.siteid} value={site.siteid}>
                                {site.site_name}
                            </option>
                        ))
                    ) : (
                        <option value="">No sites available</option>
                    )}
                    </select>
                </div>
            </div>
            <div>
                <label htmlFor="device-pin" className="kiosk-device-pin__label">Enter Device Pin</label>
                <div className="custom-input">
                    <input type="text" id="device-pin" name="pin" className="kiosk-device-pin__input loading"  value={pin} onChange={handlePinChange}required/>
                    <div id="spinner-container">
                        <StatusIcon loading={loading} isValid={isValid} isEmpty={isEmpty} />
                    </div>
                </div>
                {(!isValid && !isEmpty && !loading) && <small className="text-danger">Invalid Device Pin. Please try again.</small>}
            </div>
            <button type="submit" className="button wp-element-button kiosk-device-pin__submit" disabled={isDisabled}>
                <div>{isRedirecting && <Spinner/>} Launch</div></button>
        </form>
    );
};

const devicePinForm = document.getElementById('device-pin-form-component');
const redirectUrl = devicePinForm.dataset.redirecturl;
const devicePinDisabled = devicePinForm.dataset.disableddevicepin;

ReactDOM.render(<DevicePinForm redirectUrl={redirectUrl} disabled={devicePinDisabled}/>, document.getElementById('device-pin-form-component'))