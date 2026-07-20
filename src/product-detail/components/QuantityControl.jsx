import React from 'react';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import { faCircleMinus, faCirclePlus } from '@fortawesome/free-solid-svg-icons';

const QuantityControl = ({quantity, handleDecrement, handleIncrement}) => {
    
    return (
        <div className="holder component-quantity">
            <button className="button" onClick={handleDecrement} title="reduce quantity"><FontAwesomeIcon icon={faCircleMinus}/></button>
            <div className="quantity-number">{quantity}</div>
            <button className="button" onClick={handleIncrement} title="increase quantity"><FontAwesomeIcon icon={faCirclePlus}/></button>
        </div>
    );
};

export default QuantityControl;