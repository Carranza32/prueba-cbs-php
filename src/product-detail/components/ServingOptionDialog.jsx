import React from 'react';
import ReactDOM from 'react-dom';
import parse from 'html-react-parser';

class ServingOptionsRules {
    constructor() {
        if(ServingOptionsRules.instance) {
            throw new Error("Use ServingOptionsRules.getInstance() to get an instance of this class.");
        }
        this.rules = {};
        ServingOptionsRules.instance = this;
    }

    static getInstance() {
        if (!ServingOptionsRules.instance) {
            ServingOptionsRules.instance = new ServingOptionsRules();
        }
        return ServingOptionsRules.instance;
    }

    addRule(key, value) {
        this.rules[key] = value;
    }

    getRule(key) {
        return this.rules[key];
    }
}

const ServingOptionDialog = ({ 
    isOpen, 
    onClose, 
    componentName, 
    servingOptions,
    setServingOptions,
    onOptionChanged 
}) => {
    if (!isOpen || !servingOptions || Object.keys(servingOptions).length === 0) {
        return null;
    }

    const [validationErrors, setValidationErrors] = React.useState({});

    const ruleInstance = ServingOptionsRules.getInstance();
    Object.values(servingOptions).forEach(category => {
        if (category.info && category.info.rules) {
            const categoryId = category.info.categoryId;
            const rule = category.info.rules[categoryId];
            ruleInstance.addRule(categoryId, rule);
        }
    });

    const handleServingOptionChange = (optionId, categoryId, isChecked) => {
        const dialog = document.querySelector('.serving-options-dialog');
        const checkedCount = dialog.querySelectorAll(`input[data-categoryid="${categoryId}"]:checked`).length;
        const rule = ruleInstance.getRule(categoryId);
        const maxAllowed = rule?.MaxAllowed || Infinity;

        if (isChecked && checkedCount > maxAllowed) {
            setValidationErrors(prev => ({
                ...prev,
                [categoryId]: `You can only select up to ${maxAllowed} options.`
            }));
            return;
        }

        if (isChecked && maxAllowed === 1) {
            setServingOptions(prevOptions => {
                const updatedOptions = { ...prevOptions };
                
                Object.keys(updatedOptions).forEach(categoryKey => {
                    const category = updatedOptions[categoryKey];
                    if (category.info && category.info.categoryId === categoryId) {
                        Object.keys(category.items).forEach(itemKey => {
                            updatedOptions[categoryKey].items[itemKey] = {
                                ...updatedOptions[categoryKey].items[itemKey],
                                isDefault: false
                            };
                        });
                    }
                });
                
                return updatedOptions;
            });
        }

        setServingOptions(prevOptions => {
            const updatedOptions = { ...prevOptions };
            
            Object.keys(updatedOptions).forEach(categoryKey => {
                const category = updatedOptions[categoryKey];
                if (category.info && category.info.categoryId === categoryId) {
                    Object.keys(category.items).forEach(itemKey => {
                        const item = category.items[itemKey];
                        if (item.servingOptionId === optionId) {
                            updatedOptions[categoryKey].items[itemKey] = {
                                ...item,
                                isDefault: isChecked
                            };
                        }
                    });
                }
            });
            
            return updatedOptions;
        });

        setValidationErrors(prev => ({
            ...prev,
            [categoryId]: null
        }));

        onOptionChanged && onOptionChanged();
    };

    const validateServingOptions = () => {
        let isValid = true;
        const newErrors = {};

        Object.values(servingOptions).forEach(category => {
            if (category.info && category.info.rules) {
                const categoryId = category.info.categoryId;
                const rule = ruleInstance.getRule(categoryId);
                
                if (rule) {
                    const selectedCount = Object.values(category.items).filter(item => item.isDefault).length;
                    
                    if (rule.MinRequired && selectedCount < rule.MinRequired) {
                        isValid = false;
                        newErrors[categoryId] = `You must select at least ${rule.MinRequired} option${rule.MinRequired > 1 ? 's' : ''}.`;
                    }
                    
                    if (rule.MaxAllowed && selectedCount > rule.MaxAllowed) {
                        isValid = false;
                        newErrors[categoryId] = `You can select up to ${rule.MaxAllowed} option${rule.MaxAllowed > 1 ? 's' : ''}.`;
                    }
                }
            }
        });
        
        setValidationErrors(newErrors);
        return isValid;
    };

    const handleDone = () => {
        const isValid = validateServingOptions();
        
        if (!isValid) {
            return;
        }
        
        onClose();
    };

    const modalContent = (
        <div className="overlay" onClick={onClose}>
            <dialog open className="serving-options-dialog" onClick={(e) => e.stopPropagation()}>
                <div>
                    <button className="close-button" onClick={onClose}>
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <h1 className="component-serving-option-header">
                    {parse(componentName || '')} Serving Options
                </h1>
                <div className="serving-options-scrollable-container">
                    {Object.entries(servingOptions).map(([categoryKey, category]) => {
                        const { items, info } = category;
                        const rule = info.rules[info.categoryId];
                        
                        return (
                            <div key={categoryKey}>
                                <h4>{parse(categoryKey || '')}</h4>

                                {rule && (
                                    <div className="category-rules">
                                        {rule.MinRequired > 0 && (
                                            <small>Minimum required: {rule.MinRequired}</small>
                                        )}
                                        {rule.MaxAllowed && (
                                            <small>Maximum allowed: {rule.MaxAllowed}</small>
                                        )}
                                    </div>
                                )}
                                
                                {validationErrors[info.categoryId] && (
                                    <div className="validation-error">
                                        {validationErrors[info.categoryId]}
                                    </div>
                                )}

                                {Object.entries(items).map(([itemKey, item]) => {
                                    const servingOptionPrice = item.servingOptionPrice && item.servingOptionPrice !== '0' 
                                        ? `$${Number(item.servingOptionPrice).toFixed(2)}` : '';
                                    
                                    return (
                                        <div key={item.servingOptionId} className="form-check value">
                                            <input 
                                                className="form-check"
                                                type="checkbox" 
                                                id={`serving_option_${item.servingOptionId}-${info.componentId}`}
                                                name={item.servingOptionName}
                                                value={item.servingOptionId}
                                                checked={item.isDefault || false}
                                                data-categoryid={info.categoryId}
                                                data-optionid={item.servingOptionId}
                                                onChange={(e) => handleServingOptionChange(
                                                    item.servingOptionId, 
                                                    info.categoryId, 
                                                    e.target.checked
                                                )}
                                            />
                                            <label 
                                                className="form-check-label" 
                                                htmlFor={`serving_option_${item.servingOptionId}-${info.componentId}`}
                                            >
                                                <span className="option-name">{parse(item.servingOptionName || '')}</span>
                                                <span className="option-price">{servingOptionPrice}</span>
                                            </label>
                                        </div>
                                    );
                                })}
                            </div>
                        );
                    })}
                </div>
                <div className="dialog-controls">
                    <button className="btn btn-primary save-serving-options" onClick={handleDone}>
                        Done
                    </button>
                </div>
            </dialog>
        </div>
    );

    return ReactDOM.createPortal(modalContent, document.body);
};

export default ServingOptionDialog;