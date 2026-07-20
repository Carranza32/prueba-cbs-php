/* eslint-disable react/prop-types */
import React, {useState, useEffect} from 'react';
import parse from 'html-react-parser';
import QuantityControl from './QuantityControl';
import ServingOptionDialog from './ServingOptionDialog';
import {ReactComponent as RightSideIcon} from "../assets/right-side.svg";
import {ReactComponent as BothSideIcon} from "../assets/both-side.svg";
import {ReactComponent as LeftSideIcon} from "../assets/left-side.svg";
import {ReactComponent as EditIcon} from "../assets/edit-btn.svg";

const ComponentCard = ({item, rules, updateHandler}) => {
    const name = item.componentName;
    const data = item;

    const [selected, setSelected] = useState(false);
    const [componentPosition, setComponentPosition] = useState('whole');
    const [quantity, setQuantity] = useState(0);
    const [hasPlacement, setHasPlacement] = useState(false);
    const [allowQuantity, setAllowQuantity] = useState(false);
    const [hasRules, setHasRules] = useState(false);
    const [editState, setEditState] = useState(0); // 0: default, 1: edited
    const [servingOptions, setServingOptions] = useState(item.componentServingOptions || data.servingOptions || []);
    const [showServingDialog, setShowServingDialog] = useState(false);
    const [servingOptionsPrice, setServingOptionsPrice] = useState(0);
    const [hasServingOptions, setHasServingOptions] = useState(Object.keys(servingOptions).length > 0);

    useEffect(() => {
        if (item.componentServingOptions) {
            setServingOptions(item.componentServingOptions);
            setHasServingOptions(Object.keys(item.componentServingOptions).length > 0);
        }
    }, [item.componentServingOptions]);

    const isMounted = React.useRef(false);

    useEffect(() => {
        if (!isMounted.current) {
            isMounted.current = true;
            return;
        }
        const basePrice = parseFloat(data.componentPrice) || 0;
        const totalPrice = basePrice + servingOptionsPrice;

        const newSelectedComponent = {
            id: data.componentId,
            position: componentPosition,
            quantity: quantity,
            price: totalPrice,
            basePrice: basePrice,
            servingOptionsPrice: servingOptionsPrice,
            name: data.componentName,
            info: {
                ...data,
                servingOptions: getDefaultServingOptions(),
                // OE-26471: carry the live serving-options structure (rules + per-item isDefault)
                // so the parent can validate component serving-option MinRequired at add-to-cart.
                componentServingOptionsState: servingOptions,
            }
        };
        updateHandler(newSelectedComponent, selected, setSelected, setQuantity);
    }, [quantity, componentPosition, editState, servingOptionsPrice, selected,servingOptions]);

    useEffect(() => {
        const ruleId = data.ruleId;
        const rule = rules[ruleId];
        setHasRules(ruleId !== 'Default');
        setAllowQuantity(rule?.MaxUnique !== 1);
        const isSelected = data.isDefault === '1' || data.selected;
        setSelected(isSelected);
        setQuantity(isSelected?data.quantity: 0);
    }, [data, rules]);

    useEffect(() => {
        const newServingOptionsPrice = calculateServingOptionsPrice();
        setServingOptionsPrice(newServingOptionsPrice);
    }, [servingOptions]);

    const handleSelectCard = (e) => {
        const validTags = ['H4', 'P', 'BDI'];
        if(e.currentTarget === e.target || validTags.includes(e.target.tagName) || e.target.classList.contains('component-image')){
            data.NumberOfPlacements >1 && setHasPlacement(true);
            const newSelected = !selected;
            setSelected(newSelected);
            setQuantity(newSelected ? 1 : 0);

            const newSelectedComponent = {
                id: data.componentId,
                position: componentPosition,
                quantity: newSelected ? 1 : 0,
                price: data.componentPrice,
                name: data.componentName,
                // OE-26471: include the live serving-options structure for MinRequired validation.
                info: { ...data, componentServingOptionsState: servingOptions }
            }

            setEditState(1);
            updateHandler(newSelectedComponent, newSelected, setSelected, setQuantity);
        }

    };

    const componentPositionData = [
        { name:'left', value:'left', label: <LeftSideIcon/>},
        { name:'whole', value:'whole', label: <BothSideIcon/>},
        { name:'right', value:'right', label: <RightSideIcon/>}
    ];

    const handlePositionChange = (e) => {
        const target = e.target;
        setComponentPosition(target.value);
        setEditState(1);
    };

    const handleReduceQuantity = () => {
        if((quantity-1) === 0) {
            item.selected = false;
            setQuantity(0);
            setSelected(false);
            return
        };
        setQuantity(quantity - 1);
    };

    const handleIncreaseQuantity = () => {
        if(!hasRules) {
            setQuantity(quantity + 1);
            return;
        }
        const ruleId = data.ruleId;
        const rule = rules[ruleId];
        rule?.MaxUnique === 1 && setQuantity(1) 
        quantity < rule?.MaxUnique && setQuantity(quantity + 1);
    };

    const getDefaultServingOptions = () => {
        if(!servingOptions || Object.keys(servingOptions).length === 0) return [];
        
        const defaultOptions = [];
        
        Object.values(servingOptions).forEach(category => {
            if (category.items) {
                Object.values(category.items).forEach(item => {
                    if (item.isDefault === true || item.isDefault === '1') {
                        defaultOptions.push({
                            servingOptionId: item.servingOptionId,
                            servingOptionName: item.servingOptionName,
                            price: item.servingOptionPrice || 0
                        });
                    }
                });
            }
        });
        
        return defaultOptions;
    };

    const openEditServingOptions = () => {
        setShowServingDialog(true);
    }

    const closeServingDialog = () => {
        setShowServingDialog(false);
    };

    const handleServingOptionChange = () => {
        setEditState(1);
    };

    const calculateServingOptionsPrice = () => {
        if (!servingOptions || Object.keys(servingOptions).length === 0) {
            return 0;
        }

        let totalPrice = 0;

        Object.values(servingOptions).forEach(category => {
            if (category.items) {
                Object.values(category.items).forEach(item => {
                    if (item.isDefault === true || item.isDefault === '1') {
                        const price = parseFloat(item.servingOptionPrice) || 0;
                        totalPrice += price;
                    }
                });
            }
        });

        return totalPrice;
    };

    const getTotalPrice = () => {
        const basePrice = parseFloat(data.componentPrice) || 0;
        return basePrice + servingOptionsPrice;
    };

    return (
        <>
        <li id={item.componentId} className={`product card ${selected? 'selected': ""}`}>
            <button onClick={handleSelectCard} className='button-holder'>
                <img src={data.image} alt={`delicious ${name}`} className={`component-image ${Object.keys(servingOptions).length>0 ? 'with-options': ''}`}/>
                <h4 className='component-name'>{parse(name || '')}</h4>
                <div className='component-info col-2'>
                    <p>
                        <span>
                            <bdi>
                                <span className="woocommerce-Price-currencySymbol">$</span>
                                {Number(getTotalPrice()).toFixed(2)}
                            </bdi>
                        </span>
                    </p>
                    {selected && hasServingOptions && (
                    <div>
                        <ul className='serving-options-list' onClick={openEditServingOptions}>
                            {getDefaultServingOptions().map((option, index) => (
                                <li key={index} className='component-serving-option'>
                                    <span className='option-name'>{parse(option.servingOptionName || '')}</span>
                                    {option.price > 0 && (
                                        <span className='option-price'>
                                            <span className="woocommerce-Price-currencySymbol">$</span>
                                            {Number(option.price).toFixed(2)}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                    )}
                    <div className="card-controls">
                        {selected && allowQuantity  && (<QuantityControl quantity={quantity} handleDecrement={handleReduceQuantity} handleIncrement={handleIncreaseQuantity}/>)}
                        {selected && hasServingOptions && (
                        <div className={`edit-serving-options-section ${allowQuantity ? 'with-gap' : ''}`}>
                            <button className="edit-serving-option-btn" type='button' onClick={openEditServingOptions}>
                                <EditIcon />
                            </button>
                        </div>
                    )}
                    </div>
                </div>
                {(selected && hasPlacement) && (
                    <div className='component-position'>
                            {componentPositionData.map((position) => (
                                <label key={position.value} className={`position-button ${position.value === componentPosition? 'checked': ''}`} >
                                    <input type='radio' name='component-position' value={position.value} onChange={handlePositionChange} checked={position.value === componentPosition}/>
                                    {position.label}
                                </label>
                            ))}
                    </div>
                )}

            </button>
        </li>
        <ServingOptionDialog
            isOpen={showServingDialog}
            onClose={closeServingDialog}
            componentName={name}
            servingOptions={servingOptions}
            setServingOptions={setServingOptions}
            onOptionChanged={handleServingOptionChange}
            />
        </>
    );
}

export default ComponentCard;