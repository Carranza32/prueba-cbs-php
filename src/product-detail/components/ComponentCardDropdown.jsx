/* eslint-disable react/prop-types */
import React, {useState, useEffect} from 'react';
import parse from 'html-react-parser';
import QuantityControl from './QuantityControl';
import {ReactComponent as RightSideIcon} from "../assets/right-side.svg";
import {ReactComponent as BothSideIcon} from "../assets/both-side.svg";
import {ReactComponent as LeftSideIcon} from "../assets/left-side.svg";

const ComponentCardDropdown = ({item, rules, updateHandler}) => {
    const name = item.componentName;
    const data = item;

    const [selected, setSelected] = useState(false);
    const [componentPosition, setComponentPosition] = useState('whole');
    const [quantity, setQuantity] = useState(0);
    const [hasPlacement, setHasPlacement] = useState(false);
    const [allowQuantity, setAllowQuantity] = useState(false);
    const [hasRules, setHasRules] = useState(false);
    const [editState, setEditState] = useState(0);

    const isMounted = React.useRef(false);

    useEffect(() => {
        if (!isMounted.current) {
            isMounted.current = true;
            return;
        }


        const newSelectedComponent = {
            id: data.componentId,
            position: componentPosition,
            quantity: quantity,
            price: data.componentPrice,
            name: data.componentName,
            info: data
        }
        updateHandler(newSelectedComponent, selected, setSelected, setQuantity);
    }, [quantity, componentPosition, editState ]);

    useEffect(() => {
        const ruleId = data.ruleId;
        const rule = rules[ruleId];
        setHasRules(ruleId !== 'Default');
        setAllowQuantity(rule?.MaxUnique !== 1);
        const isSelected = data.isDefault === '1' || data.selected;
        setSelected(isSelected);
        setQuantity(isSelected?data.quantity: 0);
    }, [data, rules]);

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
                info: data
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

    return (
        <li id={item.componentId} className={`product card ${selected? 'selected': ""}`}>
            <button onClick={handleSelectCard} className='button-holder'>
                <img src={data.image} alt={`delicious ${name}`} className='component-image'/>
                <div className='component-info-holder'>
                <h4 className='component-name'>{parse(name || '')}
                    <p>
                        <span>
                            <bdi>
                                <span className="woocommerce-Price-currencySymbol">$</span>
                                {Number(data.componentPrice).toFixed(2)}
                            </bdi>
                        </span>
                    </p>
                </h4>
                    <div className='component-quantity-holder'>
                    {selected && allowQuantity  && (<QuantityControl quantity={quantity} handleDecrement={handleReduceQuantity} handleIncrement={handleIncreaseQuantity}/>)}
                    </div>
                </div>
                <div className='component-info col-2'>
                    
  
                    
                    
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
    );
}

export default ComponentCardDropdown;