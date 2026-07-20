import "./view.css";

import React, { useState, useEffect, Fragment,useRef, useCallback } from 'react';
import useNearScreen from "./hooks/useNearScreen"
import parse from 'html-react-parser';
import SelectedComponentList from "./components/SelectedComponentList";
import QuantityControl from "./components/QuantityControl";
import {createRoot} from 'react-dom';
import ComponentsSwitcher from "./components/ComponentSwitcher";

const initialState = {
    productSlug: null,
    product: null,
    showModal: false,
    selectedServingOption: [],
    editProductId: null,
    canAddToCart: false,
    isCustomized: false,
    componentsAll: [],
    variationId: null,
    selectedComponents: [],
    saveButtonText: 'Add to cart',
    requiredCategories: [],
};

export default function ProductDetail() {
    const [showModal, setShowModal] = useState(false);
    const [loading, setLoading] = useState(false);
    const [defaultSlug, setDefaultSlug] = useState(null);
    const [productSlug, setProductSlug] = useState(null);
    const [product, setProduct] = useState(null);
    const [quantity, setQuantity] = useState(1);
    const [variationId, setVariationId] = useState(null);
    const [total, setTotal] = useState(0);
    const [itemPrice, setItemPrice] = useState(0);
    const [componentCategories, setComponentCategories] = useState([]);
    const [activeTab, setActiveTab] = useState(null);
    const [selectedComponents, setSelectedComponents] = useState([]);
    const [componentsAll, setComponentsAll] = useState([]);
    const [canAddToCart, setCanAddToCart] = useState(false);
    const [editProductId, setEditProductId] = useState(null);
    const [itemMetaData, setItemMetaData] = useState(null);
    const [saveButtonText, setSaveButtonText] = useState('Add to cart');
    const [taxonomy, setTaxonomy] = useState(null);
    const [servingOptions, setServingOptions] = useState([]);
    const [selectedServingOption, setSelectedServingOption] = useState([]);
    const [servingOptionsPrice, setServingOptionsPrice] = useState(0);
    const [requiredCategories, setRequiredCategories] = useState([]);
    const [isCustomized, setIsCustomized] = useState(false);
    const [componentsDropdown, setComponentsDropdown] = useState(false);
    const [selectedComponentsPrice, setSelectedComponentsPrice] = useState(0);
    const [categoryValidationError, setCategoryValidationError] = useState(null);

    const rootRef = useRef();
    const validationErrorTimeoutRef = useRef(null);
    const {isNearScreen, activeIndex} = useNearScreen({rootRef:loading? null: rootRef});

    const handleClick = (e) => {

        if(e.target.classList.contains('quick-add')){
            return;
        }
        const target = e.target.closest('a');
        if (!target) {
            return;
        }
        if(target.classList.contains('linkto')){
            return;
        }
        if (!target.classList.contains('woocommerce-LoopProduct-link') && !target.classList.contains('customize-button')) {
            return;
        }
        e.preventDefault();
        const productUrl = target.getAttribute('href');
        const defaultSlug = target.getAttribute('data-slug');
        const url = new URL(productUrl);
        const pathSegments = url.pathname.split('/');
        const slug = pathSegments[pathSegments.length - 2];
        setDefaultSlug(defaultSlug);
        setProductSlug(slug);
    };

    const handleEdit = (e) => {
        e.preventDefault();
        const productId = e.target.getAttribute('product-id');
        const metadata = e.target.getAttribute('data-item');
        if (!productId) {

            return;
        }
        setItemMetaData(metadata);
        setEditProductId(productId);
        setSaveButtonText('Update');
    };

    const handleAddToCart = () => {

        // OE-26471: safety net — the add-to-cart button is already disabled when invalid, but
        // re-check component serving-option MinRequired here and surface the message if reached.
        const componentServingOptionsValid = validateComponentServingOptions(selectedComponents);
        if (!componentServingOptionsValid.valid) {
            setCanAddToCart(false);
            navigateToMissingCategory(componentServingOptionsValid.missingCatIds);
            if (typeof window.showErrorMessage === 'function' && componentServingOptionsValid.messages.length > 0) {
                window.showErrorMessage(componentServingOptionsValid.messages[0]);
            }
            return;
        }

        const selectedComponentsInfo = selectedComponents.map(item => item.info);

        const selectedComponentsData = selectedComponents.reduce((acc, item) => {
            acc[item.id] = {
                quantity: item.quantity,
                servingOptions: item.servingOptions || {},
                price: item.price,
                basePrice: item.basePrice,
                servingOptionsPrice: item.servingOptionsPrice,
            };
            return acc;
        }, {});

        const selectedComponentsInfoAndQty = selectedComponents.map(item => {
            return {
                ...item.info,
                quantity: item.quantity,
            };
        });

        const cartItemKey = editProductId ? JSON.parse(itemMetaData).cartItemKey : '';
        let formData = new FormData();
        formData.append('action', 'add_to_cart_action_cbs');
        formData.append('product_id', product.id);
        formData.append('quantity', quantity);
        formData.append('item_selected_for', '');
        formData.append('product_price_input', parseFloat(itemPrice));
        formData.append('variation_id', variationId);
        formData.append('selServingOptionsPrice', servingOptionsPrice);
        formData.append('selServingOptions', JSON.stringify(selectedServingOption));
        formData.append('selComponents', JSON.stringify(selectedComponentsInfo));
        formData.append('selComponentsQty', JSON.stringify(selectedComponentsInfoAndQty));
        formData.append('selComponentsPrice', selectedComponentsPrice);
        formData.append('selectedComponents', JSON.stringify(selectedComponentsData));
        formData.append('cart_item_key', cartItemKey);

        setLoading(true);
        setShowModal(false);
        fetch(`/wp-json/northstaronlineordering/v1/cart`, {
            method: 'POST',
            body: formData,
        })
        .then(response => response.json().then(data => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            if (!ok) {
                // WOAPI /validate rejected this item (HTTP 409). The server removed
                // only the offending item and kept the rest of the cart (OE-25549).
                // Close the modal and show the error as the menu top toast (consistent
                // with the quick-add path) instead of a message inside the modal.
                const msg = data?.message || 'This item can\'t be added to your order right now.';
                setLoading(false);
                // Full reset (not just setShowModal) so re-selecting the SAME product
                // changes productSlug/editProductId from null and retriggers the fetch
                // effect — otherwise the modal would not reopen on retry (OE-25549).
                resetState();
                if (typeof window.showErrorMessage === 'function') {
                    window.showErrorMessage(msg);
                }
                return;
            }
            if(defaultSlug){
                window.location.href = `/${defaultSlug}/`;
            }else{
                location.reload();
            }
        })
        .catch((error) => {
            console.error('Error:', error);
            setLoading(false);
            // Full reset so a retry on the same product reopens the modal (OE-25549).
            resetState();
            if (typeof window.showErrorMessage === 'function') {
                window.showErrorMessage('Something went wrong. Please try again.');
            }
        });
    };

    const handleIncreaseQuantity = () => {
        setQuantity(quantity + 1);
        setTotal(total + Number(product.price));
    };

    const handleReduceQuantity = () => {
        if (quantity === 1) {
            return;
        }
        setQuantity(quantity - 1);
        setTotal(total - Number(product.price));
    };

    const handleServingOptionChange = (e) => {
        const ds = e.target.dataset;
        const option = {
            optionId:     ds.optionId,
            optionName:   ds.optionName,
            optionPrice:  parseFloat(ds.optionPrice),
            isDefault:    ds.isDefault === '1',
            siteId:       ds.siteId,
            categoryId:   ds.categoryId,
        };
        setSelectedServingOption(prev => {
            const prevArr = Array.isArray(prev) ? prev : [];
            if (e.target.checked) {
            return [...prevArr, option];
            } else {
            return prevArr.filter(o => o.optionId !== option.optionId);
            }
        });

    };

    function validateServingOptionsSelection(selectedOptions, categoriesData) {

        if (!categoriesData && selectedOptions.length === 0) {
            return {
                valid: true,
                errors: []
            };
        }

        const counts = selectedOptions.reduce((acc, { categoryId }) => {
            acc[categoryId] = (acc[categoryId] || 0) + 1;
            return acc;
        }, {});

        const errors = [];

        Object.values(categoriesData).forEach(({ info: { categoryName, categoryId, rules } }) => {
            const { MinRequired, MaxAllowed } = rules[categoryId] || {};
            const picked = counts[categoryId] || 0;

            if (MinRequired != null && picked < MinRequired) {
            errors.push(
                `"${categoryName}" requires at least ${MinRequired} selection(s), but you have ${picked}.`
            );
            }
            if (MaxAllowed != null && picked > MaxAllowed) {
            errors.push(
                `"${categoryName}" allows at most ${MaxAllowed} selection(s), but you have ${picked}.`
            );
            }
        });
        updateSubTotal();
        return {
            valid: errors.length === 0,
            errors
        };
    }

    // OE-26471: validate COMPONENT serving options against MinRequired. Independent of item
    // serving options (validateServingOptionsSelection) — only inspects each selected component's
    // own serving-option categories. Returns the component category ids that are unmet so the
    // caller can block add-to-cart and scroll to them.
    function validateComponentServingOptions(selected) {
        const missingCatIds = [];
        const messages = [];
        // Messages keyed by component category id so they can be rendered in that category's
        // rules/alert area (alongside RulesList) in ComponentsSwitcher.
        const errorsByCategory = {};

        (selected || []).forEach(component => {
            // Only validate the live, user-editable structure. Components rendered without the
            // serving-option dialog (e.g. accordion view) have no way to satisfy the rule, so they
            // are intentionally skipped here.
            const servingOptions = component?.info?.componentServingOptionsState;
            if (!servingOptions || typeof servingOptions !== 'object') {
                return;
            }
            const componentName = component?.info?.componentName || component?.name || '';
            const componentCatId = component?.info?.componentCatId;

            Object.entries(servingOptions).forEach(([servingOptionCategory, category]) => {
                const info = category?.info || {};
                const categoryId = info.categoryId;
                const rule = (info.rules && info.rules[categoryId]) || {};
                const minRequired = rule.MinRequired || 0;
                if (minRequired <= 0) {
                    return;
                }

                const selectedCount = Object.values(category.items || {})
                    .filter(item => item.isDefault === true || item.isDefault === '1').length;

                if (selectedCount < minRequired) {
                    const message = `Select at least ${minRequired} ${minRequired > 1 ? 'items' : 'item'} from ${servingOptionCategory} for ${componentName}`;
                    missingCatIds.push(componentCatId);
                    messages.push(message);
                    (errorsByCategory[componentCatId] = errorsByCategory[componentCatId] || []).push(message);
                }
            });
        });

        return {
            valid: missingCatIds.length === 0,
            missingCatIds,
            messages,
            errorsByCategory
        };
    }

    const handleTabClick = (tabIndex) => {
        setActiveTab(tabIndex);
        const panel = document.getElementById(tabIndex);
        panel.scrollIntoView({behavior: "smooth"});
    }

    const getDefaultComponents = (components, selectedComponentsData) => {
        const filterItems = (items) => {
            return Object.keys(items).filter(key => {
                if (selectedComponentsData) {
                    return selectedComponentsData.hasOwnProperty(items[key].componentId);
                }
                return items[key].isDefault;
            });
        };
        const createNewItem = (item, componentData) => {
            let quantity = 1;
            let servingOptions = [];
            if(componentData) {
                if(typeof componentData === 'object' && componentData.quantity) {
                    quantity = componentData.quantity;
                    servingOptions= reconstructServingOptionsFromIds(item.componentServingOptions, componentData.servingOptionIds);
                }
            }

            return {
                id: item.componentId,
                name: item.componentName,
                quantity: quantity,
                price: item.componentPrice,
                position: 'whole',
                info: {...item, servingOptions}
            };
        };

        const reconstructServingOptionsFromIds = (componentServingOptions, servingOptionIds) => {
        if (!servingOptionIds || servingOptionIds.length === 0) {
            return componentServingOptions || {};
        }

        const reconstructed = { ...componentServingOptions };

        Object.keys(reconstructed).forEach(categoryKey => {
            const category = reconstructed[categoryKey];
            if (category.items) {
                Object.keys(category.items).forEach(itemKey => {
                    reconstructed[categoryKey].items[itemKey] = {
                        ...reconstructed[categoryKey].items[itemKey],
                        isDefault: false
                    };
                });
            }
        });

        servingOptionIds.forEach(optionId => {
            Object.keys(reconstructed).forEach(categoryKey => {
                const category = reconstructed[categoryKey];
                if (category.items) {
                    Object.keys(category.items).forEach(itemKey => {
                        const item = category.items[itemKey];
                        if (item.servingOptionId === optionId) {
                            reconstructed[categoryKey].items[itemKey] = {
                                ...item,
                                isDefault: true
                            };
                        }
                    });
                }
            });
        });

        return reconstructed;
    };

        let defaultComponents = components.map(obj => {
            let items = obj.items;
            let filteredItems = filterItems(items);
            let newItems= [];
            filteredItems.forEach(key => {
                const componentData = selectedComponentsData ? selectedComponentsData[items[key].componentId] : null;
                const newItem = createNewItem(items[key], componentData);
                newItems.push(newItem);
            });
            return [...newItems];
        });

        return [...defaultComponents.flat()];
    }

    const getComponetsArray = (components) => {
        const componentsArray = [];
        for (const [key, value] of Object.entries(components)) {
            componentsArray.push({ name: key, info: value.info, items: value.items});
        }
        return componentsArray;
    }

    function updateSelectedServingOption(newSelected) {
        const defaultOptions = [];
            for (const categoryKey in newSelected) {
                const category = newSelected[categoryKey];
                const items = category.items;

                for (const itemKey in items) {
                    const item = items[itemKey];
                    if (item.isDefault) {
                    defaultOptions.push({
                        optionId: item.servingOptionId,
                        optionName: item.servingOptionName,
                        optionPrice: item.servingOptionPrice !== null ? parseFloat(item.servingOptionPrice) : 0,
                        isDefault: item.isDefault,
                        siteId: item.siteId,
                        categoryId: item.categoryId
                    });
                    }
                }
            }
            setSelectedServingOption(defaultOptions);
    }

    function updateDefaultServingOptions(dataObject, defaultOptions) {

        if(!defaultOptions || defaultOptions.length === 0) {
            return dataObject;
        }
        const updatedData = { ...dataObject };
        const defaultIds = new Set(defaultOptions.map(opt => opt.optionId));

        for (const categoryKey in updatedData) {
            const category = updatedData[categoryKey];
            const items = category.items;


            for (const itemKey in items) {
            const item = items[itemKey];
            item.isDefault = defaultIds.has(item.servingOptionId);
            }
        }


        return updatedData;
    }

    // Helpers for updateSelectedComponents
    const getCategoryRules = (categoryId, ruleId) => {
        const category = componentCategories.find(cat => cat.info?.componentCatId === categoryId);
        const rules = category?.info?.rules?.[ruleId];
        return {
            maxAllowed: rules?.MaxAllowed ?? Infinity,
            minRequired: rules?.MinRequired || 0
        };
    };
    const getCategoryStats = (categoryId) => {
        const components = selectedComponents.filter(item => item?.info?.componentCatId === categoryId);
        const totalQuantity = components.reduce((acc, item) => acc + item.quantity, 0);
        return { components, totalQuantity };
    };

    const updateComponentInList = (component) => ({
        ...component,
        quantity: component.quantity,
        price: component.price,
        basePrice: component.basePrice,
        servingOptionsPrice: component.servingOptionsPrice,
        info: {
            ...component.info,
            servingOptions: component.info.servingOptions || {},
            // OE-26471: keep the live serving-options structure for MinRequired validation.
            componentServingOptionsState: component.info.componentServingOptionsState || {}
        }
    });

    const updateSelectedComponents = (component, selected, setSelected, setQuantity) => {
        const categoryId = component?.info?.componentCatId;
        const ruleId = component?.info?.ruleId;
        const { maxAllowed, minRequired } = getCategoryRules(categoryId, ruleId);
        const { components: componentsInCategory, totalQuantity } = getCategoryStats(categoryId);
        const existingComponent = selectedComponents.find(item => item.id === component.id);

        if (component.quantity === 0 && existingComponent) {
            const newTotal = totalQuantity - existingComponent.quantity;

            // prevent empty category if MinRequired > 0
            if (minRequired > 0 && newTotal < minRequired) {
                setQuantity(1);
                setSelected(true);
                return;
            }

            setSelectedComponents(prev => prev.filter(item => item.id !== component.id));
            return;
        }

        if (component.quantity === 0) return;

        const passValidation = verifyComponentRules(component);

        if (existingComponent) {
            // Check quantity reduction vs MinRequired
            if (component.quantity < existingComponent.quantity) {
                const diff = existingComponent.quantity - component.quantity;
                if (minRequired > 0 && (totalQuantity - diff) < minRequired) {
                    const minAllowedQty = minRequired - (totalQuantity - existingComponent.quantity);
                    setQuantity(Math.max(1, minAllowedQty));
                    setSelected(true);
                    return;
                }
            }

            // Deselect component
            if (!selected) {
                const newTotal = totalQuantity - existingComponent.quantity;
                if (minRequired > 0 && newTotal < minRequired) {
                    setQuantity(1);
                    setSelected(true);
                    return;
                }
                setSelectedComponents(prev => prev.filter(item => item.id !== component.id));
                return;
            }

            // Valid update
            if (passValidation) {
                setSelectedComponents(prev =>
                    prev.map(item => item.id === component.id ? updateComponentInList(component) : item)
                );
                return;
            }

            // Validation failed
            const otherComponents = componentsInCategory.filter(item => item.id !== component.id);

            if (maxAllowed === 1 && otherComponents.length > 0) {
                // Swap: only valid when the category allows exactly 1 item
                const toExclude = otherComponents.at(-1);
                setSelectedComponents(prev =>
                    prev
                        .filter(item => item.id !== toExclude.id)
                        .map(item => item.id === component.id ? updateComponentInList(component) : item)
                );
            } else {
                // MaxAllowed > 1: revert quantity instead of displacing other components
                setQuantity(existingComponent.quantity);
            }
            return;
        }

        if (passValidation || totalQuantity < maxAllowed) {
            setSelectedComponents(prev => [...prev, component]);
            return;
        }

        // Category full with maxAllowed > 1: show error instead of swapping
        if (maxAllowed > 1) {
            setCategoryValidationError(categoryId);
            if (validationErrorTimeoutRef.current) {
                clearTimeout(validationErrorTimeoutRef.current);
            }
            validationErrorTimeoutRef.current = setTimeout(() => {
                setCategoryValidationError(null);
            }, 2000);
            setSelected(false);
            return;
        }

        // Category full with maxAllowed === 1: swap with the last component
        if (maxAllowed === 1 && componentsInCategory.length > 0) {
            const toExclude = componentsInCategory.at(-1);
                // Replace the last with the new one
                setSelectedComponents(prev => [
                    ...prev.filter(item => item.id !== toExclude.id),
                    component
                ]);
            return;
        }

        setSelected(false);
    };

    /**
     * Builds a Map of categories indexed by componentCatId for O(1) lookup.
     */
    function buildCategoryMap(categories) {
        return new Map(
            (Array.isArray(categories) ? categories : [])
                .map(c => [c?.info?.componentCatId, c])
        );
    }

    /**
     * Groups components by their category ID for batch processing.
     * Preserves original isDefault from product data for pricing rules.
     */
    function groupComponentsByCategory(components) {
        const grouped = {};

        components.forEach((component, index) => {
            const categoryId = component?.info?.componentCatId;
            const quantity = Number(component.quantity) || 0;

            if (quantity <= 0) return;

            if (!grouped[categoryId]) grouped[categoryId] = [];

            // isDefault comes from original product data (can be '1', true, or 1)
            const originalIsDefault = component?.info?.isDefault;
            const isDefault = originalIsDefault === '1' || originalIsDefault === true || originalIsDefault === 1;

            grouped[categoryId].push({
                componentId: component.id,
                price: Number(component.price) || 0,
                quantity,
                order: index,
                isDefault,
                ruleId: component?.info?.ruleId,
            });
        });

        return grouped;
    }

    /**
     * Determines if a component instance is free based on pricing rules.
     *
     * @param {Object} item - The component item
     * @param {Object} rule - The pricing rule object
     * @param {number} position - Position in category (1-based)
     * @param {number} instanceNumber - Instance number of this component (1-based)
     */
    function isInstanceFree(item, rule, position, instanceNumber) {
        // Default components are always free, at any quantity/position
        if (rule.DefaultComponentsAreFree && item.isDefault) {
            return true;
        }

        // First N items in the category are free, default or not (positional)
        if (rule.FreeUpTo && position <= rule.FreeUpTo) {
            return true;
        }

        // First N instances of each default component are free
        if (rule.FirstDefaultComponentsLevelsFree && item.isDefault && instanceNumber <= rule.FirstDefaultComponentsLevelsFree) {
            return true;
        }

        // Items after position N are free
        if (rule.FreeAfter && position > rule.FreeAfter) {
            return true;
        }

        return false;
    }

    /**
     * Calculates total price for a single category applying pricing rules.
     */
    function calculateCategoryTotal(items, category) {
        const rules = category?.info?.rules;

        if (!rules) {
            return items.reduce((sum, item) => sum + item.price * item.quantity, 0);
        }

        items.sort((a, b) => a.order - b.order);

        let total = 0;
        let position = 0;
        const instanceCounts = {};

        for (const item of items) {
            const rule = rules[item.ruleId] || {};

            for (let i = 0; i < item.quantity; i++) {
                position++;
                instanceCounts[item.componentId] = (instanceCounts[item.componentId] || 0) + 1;

                if (!isInstanceFree(item, rule, position, instanceCounts[item.componentId])) {
                    total += item.price;
                }
            }
        }

        return total;
    }

    /**
     * Calculates the total price of components applying pricing rules.
     *
     * Supported rules:
     * - DefaultComponentsAreFree: Default components are always free, at any quantity
     * - FreeUpTo: First N items in the category are free
     * - FirstDefaultComponentsLevelsFree: First N instances of each default are free
     * - FreeAfter: Items after position N are free
     */
    function calculateComponentsTotalWithRules(components, categories) {
        if (!Array.isArray(components) || components.length === 0) {
            return 0;
        }

        const categoryMap = buildCategoryMap(categories);
        const componentsByCategory = groupComponentsByCategory(components);

        let total = 0;

        for (const [categoryId, items] of Object.entries(componentsByCategory)) {
            total += calculateCategoryTotal(items, categoryMap.get(categoryId));
        }

        return total;
    }

    const updateSubTotal = () => {
        const totalComponentSelected = calculateComponentsTotalWithRules(selectedComponents, componentCategories);
        setSelectedComponentsPrice(totalComponentSelected);

        const servingOptionPrice = selectedServingOption.reduce((sum, item) => {
                const price = parseFloat(item.optionPrice);
                return sum + (isNaN(price) ? 0 : price);
            }, 0);

        setServingOptionsPrice(servingOptionPrice);
        const productAndComponents = parseFloat(itemPrice) + totalComponentSelected + servingOptionPrice;
        const newTotal = productAndComponents * quantity;
        setTotal(newTotal);
    }

    useEffect(function() {
        if(isNearScreen) {setActiveTab(activeIndex);}
    }, [isNearScreen, activeIndex])

    useEffect(() => {
        setComponentsDropdown(document.getElementById("product-detail").dataset.componentsDropdown === "true");
        if (!product) {
            return;
        }

        const { components, servingOptions, price: productPrice } = product;
        const componentsArray = getComponetsArray(components);
        const currentTab = componentsArray[0]?.info.componentCatId;
        let selectedComponentsData = null;
        let selectedVariationId = null;
        let selectedServingOptions = [];
        let selectedTaxonomy = null;
        let selectedQuantity = quantity;
        if(editProductId) {
            const itemData = JSON.parse(itemMetaData);
            selectedComponentsData = itemData.selectedComponents
            ? JSON.parse(itemData.selectedComponents)
            : null;
            selectedServingOptions = itemData.selectedServingOptions;
            selectedVariationId = itemData.variationId;
            selectedTaxonomy = itemData.taxonomy;
            selectedQuantity = itemData.quantity;
            setQuantity(Number(selectedQuantity));
        }

        const defaultServingOptions = updateDefaultServingOptions(servingOptions, selectedServingOptions);

        updateSelectedServingOption(defaultServingOptions);

        const defaultComponents = getDefaultComponents(componentsArray, selectedComponentsData);
        const componentsPrice = calculateComponentsTotalWithRules(defaultComponents, componentsArray);

        const componentsAll = componentsArray.map(category => ({
            ...category,
            items: Object.entries(category.items).map(([key,item]) => {
                let componentServingOptions = item.componentServingOptions || {};

                if (selectedComponentsData &&
                    typeof selectedComponentsData === 'object' &&
                    selectedComponentsData[item.componentId] &&
                    selectedComponentsData[item.componentId].servingOptions) {

                    componentServingOptions = selectedComponentsData[item.componentId].servingOptions;
                }

                return {...item,
                id: item.componentId,
                name: item.componentName,
                quantity: selectedComponentsData &&
                    typeof selectedComponentsData === 'object' &&
                    selectedComponentsData[item.componentId]
                    ? selectedComponentsData[item.componentId].quantity || 1
                    : 1,
                price: item.componentPrice,
                position: 'whole',
                info: item,
                selected: selectedComponentsData &&
                typeof selectedComponentsData === 'object'
                ? selectedComponentsData.hasOwnProperty(item.componentId)
                : item.isDefault,
                componentServingOptions: componentServingOptions,
                }
            })
        }));
        const requiredCategories = getRequiredCategories(componentsArray);
        setComponentsAll(componentsAll);
        setServingOptions(defaultServingOptions);
        const defaultVariationId = getDefaultServingOption(servingOptions);
        const subTotal = parseFloat(productPrice) + componentsPrice;
        setTotal(subTotal * selectedQuantity);
        setItemPrice(parseFloat(productPrice));
        setActiveTab(currentTab);
        setComponentCategories(componentsArray);
        setSelectedComponents(defaultComponents);
        setVariationId(selectedVariationId || defaultVariationId);
        setTaxonomy(selectedTaxonomy);
        setRequiredCategories(requiredCategories);
    }, [product]);

    useEffect(() => {
        const handleGlobalClick = (e) => {
            if (e.target.closest("#products-block-wrapper .woocommerce .products")) {
                handleClick(e);
            }
            if (e.target.closest(".edit-icon")) {
                handleEdit(e);
            }
            if (e.target.closest("#slider .quick-order-buttons")) {
                handleClick(e);
            }
            if (
                e.target.closest("#upsell-modal .wc-prl-recommendations .products") ||
                e.target.closest("#cart-list .wc-prl-recommendations")
            ) {
                handleClick(e);
            }
        };
        document.addEventListener("click", handleGlobalClick);
        return () => {
            document.removeEventListener("click", handleGlobalClick);
        };
    }, []);

    useEffect(() => {
        if (!productSlug && !editProductId) {
            return;
        }
        console.log("fetch product", productSlug, editProductId);
        const baseUrl ='/wp-json/northstaronlineordering/v1/product';
        const fetchUrl = editProductId
        ? `${baseUrl}?product_id=${editProductId}`
        : `${baseUrl}?slug=${productSlug}`;
        setLoading(true);
        fetch(fetchUrl)
            .then(response => response.json())
            .then(data => {
                setProduct(data);
                setTotal(Number(data.price));
                setItemPrice(Number(data.price));
                setShowModal(true);
                setLoading(false);
            });


    }, [productSlug, editProductId]);

    useEffect(() => {


        if(!product) {
            return;
        }
        if(componentCategories.length === 0 && servingOptions.length === 0) {
            setCanAddToCart(true);
            setIsCustomized(true);
            return;
        }

        if(componentCategories.length === 0 ) {
            const servingOptionValid = validateServingOptionsSelection(selectedServingOption, servingOptions);


            if(servingOptionValid.valid) {
                setCanAddToCart(true);
                setIsCustomized(true);
            }
            setIsCustomized(true);
            return;
        }
    if (selectedComponents.length === 0) {
                updateSubTotal();
                setCanAddToCart(false);
                setIsCustomized(false);
                if (requiredCategories.length > 0) {
                    navigateToMissingCategory(requiredCategories.map(c => c.info.componentCatId));
                }
                return;
        }


        const missingCategories = [];
        componentsAll.forEach(category => {
            if (!meetsRequiredRule(category)) {
                missingCategories.push(category.info.componentCatId);
            }
        });
        updateSubTotal();

        if (missingCategories.length === 0) {
            const servingOptionValid = validateServingOptionsSelection(selectedServingOption, servingOptions);
            // OE-26471: block add-to-cart when a selected component's serving options miss MinRequired.
            const componentServingOptionsValid = validateComponentServingOptions(selectedComponents);
            if(servingOptionValid.valid && componentServingOptionsValid.valid) {
                setCanAddToCart(true);
            } else if (!componentServingOptionsValid.valid) {
                setCanAddToCart(false);
                navigateToMissingCategory(componentServingOptionsValid.missingCatIds);
            }
            setIsCustomized(true);
            return;
        }



        setCanAddToCart(false);
        setIsCustomized(false);
        navigateToMissingCategory(missingCategories);

    }, [selectedComponents]);

    useEffect( () => {
        if(!taxonomy || !variationId || !product) {
            return;
        }

        const servingOption = servingOptions.find(option => option.taxonomy === taxonomy);
        if (!servingOption) {
            return;
        }

        const { slugs } = servingOption;
        const selectedSlug = slugs.find(slug => slug.id === variationId);

        if (!selectedSlug) {
            return;
        }

        const newSlugs = slugs.map(slug => ({
            ...slug,
            default: slug.id === variationId,
        }));

        const newPrice = parseFloat(selectedSlug.price);
        if (newPrice > 0) {
            setItemPrice(newPrice);
        }

        const newServingOptions = servingOptions.map(option =>
            option.taxonomy === taxonomy ? { ...option, slugs: newSlugs } : option
        );

        setServingOptions(newServingOptions);
    },[variationId])

    useEffect(() => {

        if (!product ) {
            return;
        }
        console.log("validate serving options");

        const servingOptionValid = validateServingOptionsSelection(selectedServingOption, servingOptions);
        // OE-26471: keep component serving-option MinRequired in the gate so this effect does not
        // re-enable add-to-cart while a component still has unmet serving options.
        const componentServingOptionsValid = validateComponentServingOptions(selectedComponents);


        if( showModal && isCustomized && (!servingOptionValid.valid || !componentServingOptionsValid.valid)) {
            // alert('Please select a serving option');
            setCanAddToCart(false);
        }
        console.log("set serving options");
        console.log("is costumized",isCustomized );
        if(isCustomized && servingOptionValid.valid && componentServingOptionsValid.valid) {
            setCanAddToCart(true);
        }
    }, [servingOptions, isCustomized , selectedServingOption, selectedComponents]);

    useEffect(() => {

        updateSubTotal();
    },[itemPrice , quantity])

    function findSelectedComponent(selectedComponents, componentId) {
        return selectedComponents.find(item => item.id === componentId);
    }

    function getMinRequiredRuleValue(categoryRules) {
        return Object.values(categoryRules).find(rule => rule.MinRequired > 0)?.MinRequired || 0;
    }

    function navigateToMissingCategory(missingCategories) {
        if (missingCategories.length === 0) {
            return;
        }
        const missingCategory = missingCategories[0];
        const panel = document.getElementById(missingCategory);
        panel.scrollIntoView({behavior: "smooth"});
    }

    function verifyComponentRules(component) {
        const componentCategoryId = component?.info?.componentCatId;
        const componentRuleId = component?.info?.ruleId;

        if(componentRuleId === 'Default') {
            return true;
        }

        const category = componentCategories.find(category => category.info?.componentCatId === componentCategoryId);
        if (!category) {
            return false;
        }
        const categoryRules = category.info.rules;
        const componentsPerCategory = selectedComponents.filter(item => item?.info?.componentCatId === componentCategoryId);
        const totalQuantityPerCategory = componentsPerCategory.reduce((acc, item) => acc + item.quantity, 0);
        const existingComponent = selectedComponents.find(item => item.id === component.id);
        const newTotalQuantityPerCategory = existingComponent ?
        totalQuantityPerCategory + component.quantity - existingComponent.quantity :
        totalQuantityPerCategory + component.quantity;

        return component.quantity <= categoryRules[componentRuleId].MaxUnique &&
        newTotalQuantityPerCategory <= categoryRules[componentRuleId].MaxAllowed;
    }

    const handleCloseModal = () => {
        resetState();
    };

    const resetState = () => {
        setProductSlug(initialState.productSlug);
        setProduct(initialState.product);
        setShowModal(initialState.showModal);
        setSelectedServingOption(initialState.selectedServingOption);
        setEditProductId(initialState.editProductId);
        setCanAddToCart(initialState.canAddToCart);
        setComponentsAll(initialState.componentsAll);
        setVariationId(initialState.variationId);
        setSelectedComponents(initialState.selectedComponents);
        setSaveButtonText(initialState.saveButtonText);
        setRequiredCategories(initialState.requiredCategories);
        setIsCustomized(initialState.isCustomized);
        setCategoryValidationError(null);
        setQuantity(1);
    };

    function getRequiredCategories(componentCategories) {
        const requiredCategories = componentCategories.filter(category => {
            const rules = category.info.rules;
            return Object.values(rules).some(rule => rule.MinRequired > 0);
        });
        return requiredCategories;
    }

    function meetsRequiredRule(category) {
        const requiredCategory = requiredCategories.find(item => item.info.componentCatId === category.info.componentCatId);
        let meetsRequiredRule = true;
        if (!requiredCategory) {
            return meetsRequiredRule;
        }
        const rules = requiredCategory.info.rules;
        const componentsPerCategory = selectedComponents.filter(item => item.info.componentCatId === category.info.componentCatId);
        const totalQuantityPerCategory = componentsPerCategory.reduce((acc, item) => acc + item.quantity, 0);
        const minRequiredRuleValue = getMinRequiredRuleValue(rules);
        if (totalQuantityPerCategory < minRequiredRuleValue) {
            meetsRequiredRule = false;
        }

        return meetsRequiredRule;
    }

    function getDefaultServingOption(servingOptions) {
/*         if(servingOptions.length === 0) {
            return null;
        }
        const defaultSlugs = servingOptions[0]?.slugs.filter(slug => slug.default);
        const defaultIds = defaultSlugs.map(slug => slug.id);

        if (defaultIds.length > 1) {
            console.warn('Multiple default slugs found:', defaultIds);
        } */

        return  null;
    }

    if (loading) {
        return (<div className="overlay" >
            <div className="spinner"></div>
        </div>);
    }

    return (
        <div>
            {showModal && (
                <div className="overlay">
                    <dialog id="popup" className="modal">
                        <div>
                            <div className="flex">
                                <button className="btn-close" onClick={handleCloseModal}>⨉</button>
                            </div>
                            <div className="col-2">
                                <div className="product-image-container">{parse(product?.image)}</div>

                                <div className="product-detail">
                                    <div className="flex">
                                        <h2>{parse(product?.title || '')}</h2>
                                        <p><span className="woocommerce-Price-amount amount">
                                        <bdi>
                                            <span className="woocommerce-Price-currencySymbol">$</span>
                                            {total.toFixed(2)}
                                        </bdi>
                                    </span></p>
                                    </div>
                                    <p>{parse(product?.description || '')}</p>

                                    <SelectedComponentList componentList={selectedComponents} />
                                </div>
                            </div>
                            <hr />
                            <div>
                                <div className="serving-options">
                                {Object.entries(servingOptions).map(([categoryKey, category]) => {
                                if (!category || !category.info) {
                                            return null;
                                        }
                                    const { info: { categoryName, categoryId , rules }, items } = category;
                                    const { MinRequired, MaxAllowed } = rules[categoryId];
                                    const selectedInCat = selectedServingOption.filter(o => o.categoryId === categoryId).length;
                                    const showMaxAllowed =  selectedInCat >= MaxAllowed;
                                    const showMinRequired = MinRequired > 0 && selectedInCat < MinRequired;


                                    return (
                                    <fieldset key={categoryId} className="serving-option-list">
                                        <legend>{parse(categoryName || '')}</legend>
                                         <div className="serving-option-container">
                                            <span
                                                className={showMinRequired ? 'show' : ''}
                                            >
                                                Please select at least {MinRequired} serving option{MinRequired > 1 ? 's' : ''} from this category
                                            </span>
                                            <span
                                                className={showMaxAllowed ? 'show' : ''}
                                            >
                                                Maximum reached—only  {MaxAllowed} serving option{MaxAllowed > 1 ? 's' : ''} allowed from this category
                                            </span>
                                                <div className="chips">
                                                    {Object.values(items).map(item => {

                                                    const isSelected = selectedServingOption.some(o => o.optionId === item.servingOptionId);
                                                    const disableAdd    = !isSelected && selectedInCat >= MaxAllowed;
                                                    const disableRemove = isSelected && selectedInCat <= MinRequired;
                                                    const isDisabled    = disableAdd ;


                                                    return (
                                                        <div key={item.servingOptionId} className="serving-item">
                                                        <input
                                                            type="checkbox"
                                                            id={item.servingOptionId}
                                                            name={categoryId}
                                                            value={item.servingOptionName}

                                                            data-option-id={item.servingOptionId}
                                                            data-option-name={item.servingOptionName}
                                                            data-option-price={item.servingOptionPrice}
                                                            data-is-default={item.isDefault ? '1' : '0'}
                                                            data-site-id={item.siteId}
                                                            data-category-id={categoryId}
                                                            data-is-selected-in-category={selectedInCat}

                                                            // ← controlled checked/disabled
                                                            checked={isSelected}
                                                            onChange={handleServingOptionChange}
                                                            disabled={isDisabled}
                                                        />

                                                        <label
                                                            htmlFor={item.servingOptionId}
                                                            className={isDisabled ? 'disabled' : ''}
                                                        >
                                                            {parse(item.servingOptionName || '')}
                                                        </label>
                                                        </div>
                                                    );
                                                    })}
                                                </div>
                                         </div>
                                    </fieldset>
                                    );
                                })}
                                </div>
                            </div>
                            <div className="woocommerce">
                                <div className="product">
                                    <div className="woocommerce-tabs wc-tabs-wrapper">
                                        <ul className="tabs wc-tabs">
                                            {
                                                componentCategories.map((category, index) => (
                                                    <li key={category.info.componentCatId} id={`tab-title-${category.info.componentCatId}`} aria-controls={`tab-${category.info.catName}`}>
                                                        <button onClick={()=>handleTabClick(category.info.componentCatId)} className={activeTab === category.info.componentCatId ? 'active': ''}>{parse(category.info.catName || '')}</button>
                                                    </li>
                                                ))
                                            }
                                        </ul>
                                            <ComponentsSwitcher
                                            componentsAll={componentsAll}
                                            updateSelectedComponents={updateSelectedComponents}
                                            rootRef={rootRef}
                                            view={componentsDropdown ? "accordion" : "tabs"}
                                            accordionAllowMultiple={true}
                                            categoryValidationError={categoryValidationError}
                                            componentServingOptionsErrors={validateComponentServingOptions(selectedComponents).errorsByCategory}
                                            />
                                    </div>
                                </div>

                            </div>
                        </div>
                        <div className="flex bottom-bar">
                            <div className="first-section"></div>
                            <div className="second-section">

                            </div>
                            <QuantityControl quantity={quantity} handleDecrement={handleReduceQuantity} handleIncrement={handleIncreaseQuantity}/>
                            <div className="total">
                                <span className="woocommerce-Price-amount amount">
                                    <bdi>
                                        <span className="woocommerce-Price-currencySymbol">$</span>
                                        {total.toFixed(2)}
                                    </bdi>
                                </span>
                            </div>
                            <button className="button single_add_to_cart_button button alt wp-element-button" onClick={handleAddToCart} disabled={!canAddToCart}>{saveButtonText}</button>
                        </div>
                    </dialog>
                </div>
            )}
        </div>
    );
}

createRoot(document.getElementById('product-detail')).render(<ProductDetail />);
