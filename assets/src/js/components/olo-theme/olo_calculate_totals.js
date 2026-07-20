/**
 * @type globalSelectedComponents is defined in the file olo_render.js
 *
 */

let total_components = 0;
let servingOptionPrice = 0;
// add listener for the event with jquery
// prepareDataOfComponent is a function in rules.js
jQuery(document).on('addItemComponent', (event) => {
    let newComponentElement = jQuery(event.elementAdded);
    let newComponentData = newComponentElement.data();

    const preparedComponentData = prepareDataOfComponent(newComponentData);
    if(globalSelectedComponents[newComponentData.category]){
        globalSelectedComponents[newComponentData.category].push(preparedComponentData)
    }else {
        globalSelectedComponents[newComponentData.category] = [];
        globalSelectedComponents[newComponentData.category].push(preparedComponentData)
    }
    let rowComponentElement = newComponentElement.parents().eq(2);
    rowComponentElement.find('.counter_category').html(globalSelectedComponents[newComponentData.category].length)
    // Keep symmetric with removeitems: repaint the whole category so sibling labels stay in sync
    // with their current positional FreeUpTo state (OE-26717).
    refreshCategoryComponentPrices(preparedComponentData.category);
    calculateTotalProduct();
});

jQuery(document).on('removeitems', (event) => {
    const componentElementRaw = event?.elementRemoved ?? event?.originalEvent?.detail?.elementRemoved;
    const componentElement = componentElementRaw?.jquery ? componentElementRaw : jQuery(componentElementRaw);
    if (!componentElement || componentElement.length === 0) {
        console.warn('removeitems event fired without elementRemoved', event);
        return;
    }

    const componentRemoved = componentElement.data() || {};
    const categoryKey = componentRemoved.category;
    if (!categoryKey || !globalSelectedComponents?.[categoryKey]) {
        console.warn('removeitems: missing category bucket', { componentRemoved, globalSelectedComponents });
        calculateTotalProduct();
        return;
    }

    const categoryComponents = globalSelectedComponents[categoryKey];
    const componentIdToRemove = componentRemoved.componentid;

    // get the index of the last item with the same component id
    const indexToRemove = categoryComponents.map(component => component.componentid).lastIndexOf(componentIdToRemove);
    if (indexToRemove < 0) {
        console.warn('removeitems: component not found in category bucket', { componentIdToRemove, categoryKey });
        calculateTotalProduct();
        return;
    }

    // remove the item from the array
    categoryComponents.splice(indexToRemove, 1);
    globalSelectedComponents[categoryKey] = categoryComponents;
    let rowComponentElement = componentElement.parents().eq(2);
    rowComponentElement.find('.counter_category').html(globalSelectedComponents[categoryKey].length)
    // Removal shifts every later sibling's position, which can flip its FreeUpTo free/charged
    // state, so repaint the whole category's labels — not just the removed one (OE-26717).
    refreshCategoryComponentPrices(categoryKey);
    calculateTotalProduct();
});

jQuery(document).on('selectposition', (event) => {
    let componentUpdatedElement = jQuery(event.elementSelected);
    let componentUpdatedData = componentUpdatedElement.data();
    let categoryElements = globalSelectedComponents[componentUpdatedData.category];
    categoryElements.forEach(component =>{
        if(component.componentid === componentUpdatedData.componentid){
            component.position = componentUpdatedData.position;
        }
    })
    globalSelectedComponents[componentUpdatedData.category] = categoryElements;
    calculateTotalProduct();
});

// Sticky footer quantity buttons live in the OLO theme, whose direct-bound
// handler rewrites the footer price as base × qty only (it has no knowledge
// of components/serving options). This DELEGATED handler fires after the
// theme's direct binding for the same click — element listeners run before
// the event bubbles to document — so calculateTotalProduct() reads the
// already-updated quantity and wins the final write with the full
// (base + components + serving options) × qty total the cart will charge.
jQuery(document).on('click', '.js-qty-plus, .js-qty-minus', () => {
    calculateTotalProduct();
});

// Manual edits to the WooCommerce quantity input bypass the theme's buttons,
// which are the only thing keeping div.quantity-number (the value
// getProductQuantity() reads) in sync. Mirror the input into the footer
// counter before recalculating so both quantity displays agree.
// The footer-presence guard matters because this bundle loads site-wide and
// input.qty also exists on the cart page, where there is no footer bar and
// getProductQuantity() would log an error on every quantity edit.
jQuery(document).on('change', 'input.qty', function () {
    if (!document.querySelector('.sticky-footer-product-bar')) {
        return;
    }
    const enteredQty = parseInt(this.value, 10);
    const quantityDisplay = document.querySelector('div.quantity-number');
    if (quantityDisplay && !isNaN(enteredQty) && enteredQty >= 1) {
        quantityDisplay.textContent = enteredQty;
    }
    calculateTotalProduct();
});

function handleCheckboxChange(optionRules) {
    const checkboxSection = '#servingoptions_section';
    const categoryId = this.dataset.categoryid;
    const checkedCount = jQuery(`${checkboxSection} input[data-categoryid="${categoryId}"]:checked`).length;
    const maxAllowed = optionRules.getRule(this.dataset.categoryid).MaxAllowed;
    const minRequired = optionRules.getRule(categoryId).MinRequired;

    if (minRequired === 1 && !this.checked && checkedCount === 0) {
        this.checked = true;
        return;
    }
    if (checkedCount > maxAllowed && maxAllowed>1) {
        this.checked = false;
        const warningContainer = document.querySelector(`[data-warning-for="${categoryId}"]`);
        showErrorSummary(warningContainer, `You can only select up to ${maxAllowed} option(s).`);
        return;
    }

    if (maxAllowed === 1) {
        const previouslyChecked = jQuery(`${checkboxSection} input[data-categoryid="${categoryId}"]:checked:not([value="${this.value}"])`);
        if(previouslyChecked.length > 0 && previouslyChecked[0] !== this) {
            previouslyChecked.prop('checked', false);
        }
        jQuery(`${checkboxSection} input[data-categoryid="${categoryId}"]`).not(this).prop('checked', false);
    }
    calculateTotalProduct();

    const minreqValidated = validateMinRequiredServingOptions();
    const addToCartButton  = document.querySelector('.single_add_to_cart_button');

    addToCartButton.disabled = !minreqValidated;
}

function handleComponentServingOptionCheckbox(selectedOptionId, componentElement, replaceOptionId =null) {
    const selectedComponent = jQuery(componentElement);
    const category = selectedComponent.attr('data-category');
    const componentId = selectedComponent.attr('data-componentid');

    let currentSelectedOptions = [];
    try {
        const currentOptionsStr = selectedComponent.attr('data-selectedservingoptions');
        if (currentOptionsStr) {
            currentSelectedOptions = JSON.parse(currentOptionsStr);
        }
    } catch (e) {
        console.warn('Error parsing selected serving options:', e);
        currentSelectedOptions = [];
    }
    // Check if the option is already selected
    const optionIndex = currentSelectedOptions.indexOf(selectedOptionId);

    if (optionIndex > -1) {
        currentSelectedOptions.splice(optionIndex, 1);
    } else {
        if(replaceOptionId !== null){
            const replaceIndex = currentSelectedOptions.indexOf(replaceOptionId);
            if(replaceIndex > -1){
                currentSelectedOptions.splice(replaceIndex, 1);
            }
        }
        currentSelectedOptions.push(selectedOptionId);
    }

    selectedComponent.attr('data-selectedservingoptions', JSON.stringify(currentSelectedOptions));

    if (globalSelectedComponents[category]) {
        globalSelectedComponents[category].forEach(component => {
            if (component.componentid == componentId) {
                component.selectedservingoptions =[...currentSelectedOptions];
            }
        });
    }
    persistComponentServingOptions(componentElement, currentSelectedOptions);
    updateSelectedServingOptionsDisplay(componentElement);
}

/**
 * Calculates the total price of selected serving options.
 *
 * This function iterates over all checked checkboxes on the page,
 * retrieves their data-price attribute, and sums up the prices.
 *
 * @returns {number} The total price of the selected serving options.
 */
function calculateTotalServingOptions (){
    let variationPrice = 0;
    document.querySelectorAll('#servingoptions_section input[type="checkbox"]:checked').forEach(function(checkbox) {
        variationPrice += parseFloat(checkbox.getAttribute('data-price')) || 0;
    });
    return variationPrice;
}

function calculateTotalProduct() {
    const footerPriceSelector = ( olo_vars_object && olo_vars_object.stickyFooterPriceSelector )
        || '.sticky-footer-product-bar .price';
    const footerPriceElement = document.querySelector(footerPriceSelector);
    const footerBarElement = document.querySelector('.sticky-footer-product-bar');

    if (!footerBarElement || !footerPriceElement || !document.querySelector('#product_base_input')) {
        return;
    }

    // get serving options selected total
    //calculateTotal is a function in rules.js

    servingOptionPrice = 0;
    servingOptionPrice = calculateTotalServingOptions();

    let total_components = calculateTotal(globalSelectedComponents);

    let basicProduct = isNaN(jQuery('#product_base_input').val()) ? 0 : parseFloat(jQuery('#product_base_input').val())

    if(total_components > 0){
        // show the price section below the component section
        jQuery('.productcomponentprice').show();
        jQuery('#selComponentsPrice').val(total_components.toFixed(2));
        jQuery('#customprice').html(total_components.toFixed(2));
        if(servingOptionPrice > 0){
            jQuery('#servingoptionprice-row').show();
            jQuery('#servingoptionprice').html(servingOptionPrice.toFixed(2));
        } else {
            jQuery('#servingoptionprice-row').hide();
        }
    }else{
        jQuery('.productcomponentprice').hide();
        jQuery('#servingoptionprice-row').hide();
        jQuery('#selComponentsPrice').val(0.0.toFixed(2));
    }

    let total = total_components + basicProduct + servingOptionPrice;
    const quantity = getProductQuantity();
    total = total * quantity;

    jQuery('#pro_price').html(basicProduct);
    jQuery('#product_price_input').val(basicProduct);

    jQuery('#custom_subtotal').html(total.toFixed(2));

    // update the sticky footer product bar price — selector passed from PHP via olo_vars_object with fallback
    const currencySymbol = ( olo_vars_object && olo_vars_object.symbol_product ) || '$';
    jQuery( footerPriceSelector ).text( currencySymbol + total.toFixed(2) );
    computeSelectedComponents();
    computeSelectedServingOptions();
}

function computeSelectedServingOptions() {
    let servingOptions = [];

    jQuery('#servingoptions_section input[type="checkbox"]:checked').each(function() {
        let option = {
            "optionName": this.dataset.optionname,
            "optionPrice": this.dataset.price,
            "optionId": this.dataset.optionid,
            "categoryId": this.dataset.categoryid,
            "isDefault": this.dataset.isdefault == 1,
            "siteId": this.dataset.siteid,
        };

        servingOptions.push(option);
    });

    if (servingOptions.length !== 0) {
        jQuery("#selServingOptions").val(JSON.stringify(servingOptions));
        jQuery("#selServingOptionsPrice").val(servingOptionPrice.toFixed(2));
    }
}

function computeSelectedComponents() {
    let componentSelected = [];
    let componentSelectedQuantity = [];
    let activeComponents = [];

    for (let categories in globalSelectedComponents) {
        let categoryComponents = globalSelectedComponents[categories];
        activeComponents.push(...categoryComponents);
    }

    activeComponents = mergeComponents(activeComponents);
    activeComponents.forEach(function (componentData) {

        let position = componentData.position;
        let componentId = (position ==="") ? componentData.componentid : componentData.componentid + "_" + position;
        const servingOptions = Object.values(componentData.servingoptions ?? {});
        const selectedServingOptionIds = componentData.selectedservingoptions || [];

        const allServingItems = servingOptions
            .map((option) => Object.values(option.items))
            .flat();

        const selectedServingOptions = allServingItems.filter((item) =>
            selectedServingOptionIds.includes(item.servingOptionId)
        );

        // Rule-adjusted total for this component (sums newPrice across every selected instance
        // sharing this componentId in the category), so items covered by a free rule (FreeUpTo,
        // DefaultComponentsAreFree, etc.) report $0 instead of their raw tier/base price.
        const rulePrice = getComponentDisplayPrice(componentData.category, componentData.componentid);

        let componentObject = {
            "componentName": componentData.component,
            "componentprice": componentData.price ?? "",
            "rulePrice": rulePrice,
            "isDefault": componentData.isdefault == 1 ? true : false,
            "ruleId": componentData.rule,
            "siteId": componentData.siteid,
            "componentId": componentId,
            "categoryName": componentData.category,
            "key": componentData.componentkey,
            "servingOptions": selectedServingOptions || [],
            "pricingLevels": componentData.pricingLevels ||[],

        };

        let componentQuantityObject = {
            "componentName": componentData.component,
            "componentprice": componentData.price ?? "",
            "isDefault": componentData.isdefault == 1 ? true : false,
            "ruleId": componentData.rule,
            "siteId": componentData.siteid,
            "componentId": componentId,
            "categoryName": componentData.category,
            "key": componentData.componentkey,
            "quantity": componentData.quantity,
            "servingOptions": selectedServingOptions || [],
            "pricingLevels": componentData.pricingLevels ||[],
        };

        componentSelected.push(componentObject);
        componentSelectedQuantity.push(componentQuantityObject);
    });

    jQuery("#selComponents").val(JSON.stringify(componentSelected));
    jQuery("#selComponentsQty").val(JSON.stringify(componentSelectedQuantity));
}

function mergeComponents(components) {
    const map = new Map();

    components.forEach((component) => {
        if (map.has(component.compname)) {
            map.get(component.compname).quantity++;
        } else {
            map.set(component.compname, { ...component, quantity: 1 });
        }
    });

    return Array.from(map.values());
}

function countDefaultItems(components) {
    let count = 0;
    for (let componentName in components) {
        if (components.hasOwnProperty(componentName)) {
            let component = components[componentName];
            if (component.isDefault || component.isDefault === "1") {
                count++;
            }
        }
    }
    return count;
}


jQuery(document).ready(function($) {

    $(document.body).on('updated_checkout', function() {
        setTimeout(getTotalElement, 500);
    });
});

function getTotalElement() {
    jQuery.ajax({
        url: session_obj.ajax_url,
        data: { action: 'get_cart_total' },
        cache: false,
        success: function(response) {
            const totalFooter = document.querySelector('.price');
            if (totalFooter) {
                totalFooter.innerHTML = response;
            } else {
                console.error('Total footer element not found');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.error('AJAX request failed:', textStatus, errorThrown);
        }
    });
}

function handleDialogCheckboxChange(optionRules, componentElement) {
    const dialog = this.closest('dialog');
    const categoryId = this.dataset.categoryid;
    const optionId = this.dataset.optionid;
    const checkedCount = dialog.querySelectorAll(`input[data-categoryid="${categoryId}"]:checked`).length;
    const maxAllowed = optionRules.getRule(this.dataset.categoryid).MaxAllowed;
    const minRequired = optionRules.getRule(categoryId).MinRequired;
    const warningTarget = dialog.querySelector(`.serving-options-warnings[data-warning-for="${categoryId}"]`);

    const setWarning = (message) => {
        if (!warningTarget) return;
        warningTarget.textContent = message || '';
        warningTarget.classList.toggle('active', Boolean(message));
    };

    if (minRequired === 1 && !this.checked && checkedCount === 0) {
        this.checked = true;
        setWarning('You must have at least one option selected. Please select another option before removing this one.');
        return;
    }

    if (checkedCount > maxAllowed && maxAllowed>1) {
        this.checked = false;
        setWarning(`You can only select up to ${maxAllowed} option(s).`);
        return;
    }

    let replaceOptionId = null;

    if (maxAllowed === 1) {
        const previouslyChecked = dialog.querySelector(`input[data-categoryid="${categoryId}"]:checked:not([data-optionid="${optionId}"])`);
        if (previouslyChecked && previouslyChecked !== this) {
            replaceOptionId = previouslyChecked.dataset.optionid;
        }
        dialog.querySelectorAll(`input[data-categoryid="${categoryId}"]`).forEach(cb => {
            if (cb !== this && cb.checked) {
                cb.checked = false;
            }
        });
    }
    if (componentElement) {
        handleComponentServingOptionCheckbox(optionId, componentElement, replaceOptionId);
    }
    calculateTotalProduct();

    const minreqValidated = validateMinRequiredServingOptions();
    const addToCartButton = document.querySelector('.single_add_to_cart_button');
    addToCartButton.disabled = !minreqValidated;
}

/**
 * Gets the current product quantity from the DOM
 * @returns {number} The quantity value, defaults to 1 if not found
 */
function getProductQuantity() {
    /* this quantity div is on the theme */
    const quantityElement = document.querySelector('div.quantity-number, span.quantity-number');
    if (!quantityElement) {
        return 1;
    }
    const quantity = parseInt(quantityElement.textContent);
    return isNaN(quantity) ||(quantity < 1)  ? 1 : quantity;
}

/**
 * Find the total price of a component based on quantity and pricing levels.
 * Each quantity (1, 2, 3...) has its own price according to the defined layer.
 *
 * @param {Object} component - Component data with pricingLevels and quantity
 * @returns {number} price for the current quantity layer
 */
function getComponentPriceByLayer(component) {

    const pricingLevels = component.pricingLevels || component.pricinglevels || {};
    const quantity = component.quantity || 0;

    // If no pricing levels defined, use base component price.
    // jQuery converts data-price attribute to 'price' key, not 'componentPrice',
    // so check both to cover API objects (componentPrice) and DOM data (price).
    if (Object.keys(pricingLevels).length === 0) {
        if (typeof component.componentPrice === 'number') {
            return component.componentPrice;
        }
        if (typeof component.price === 'number') {
            return component.price;
        }
        return 0;
    }

    // OE-26649: once quantity exceeds the highest configured layer, every extra unit
    // keeps that last layer's price instead of falling back to the flat/base price.
    // Keys arrive as strings ("1".."N") from jQuery .data()/JSON; non-numeric keys are
    // ignored. A missing MID-RANGE layer (sparse config) intentionally keeps the
    // existing flat-price fallback below — the clamp fires only past the last layer.
    const definedLayers = Object.keys(pricingLevels)
        .filter(key => /^\d+$/.test(key))
        .map(Number);
    const maxLayer = definedLayers.length > 0 ? Math.max(...definedLayers) : NaN;
    const layer = (!Number.isNaN(maxLayer) && quantity > maxLayer) ? maxLayer : quantity;
    if (pricingLevels[layer] && typeof pricingLevels[layer].price === 'number') {
        return pricingLevels[layer].price;
    }

    // Layer not defined in pricingLevels — fall back to flat component price
    if (typeof component.price === 'number') return component.price;
    if (typeof component.componentPrice === 'number') return component.componentPrice;
    return 0;
}

/**
 * Sums the rule-adjusted price (newPrice) of every selected instance of a component in a
 * category, so instances that are free per the category's rule (FreeUpTo, DefaultComponentsAreFree,
 * etc.) show no price instead of their raw pricing-layer tier price.
 *
 * @param {string} category - The category key in globalSelectedComponents
 * @param {string} componentId - The componentid to sum
 * @returns {number} The rule-adjusted total price for this component's selected instances
 */
function getComponentDisplayPrice(category, componentId) {
    const categoryComponents = globalSelectedComponents[category] || [];
    const priced = calculatePriceOfEachComponent({ [category]: categoryComponents })[category] || [];
    return priced
        .filter(component => component.componentid === componentId)
        .reduce((sum, component) => sum + (parseFloat(component.newPrice) || 0), 0);
}

/**
 * Repaints the price label of every distinct component in one category from its current
 * rule-adjusted price. Removing an earlier component shifts every later sibling's position, which
 * can flip its positional FreeUpTo (or FreeAfter) free/charged state, so the whole category's
 * labels must be re-evaluated, not just the one component that changed (OE-26717).
 *
 * @param {string} category - The category key in globalSelectedComponents
 */
function refreshCategoryComponentPrices(category) {
    const components = globalSelectedComponents[category] || [];
    const componentIds = new Set(components.map(component => component.componentid));
    componentIds.forEach(componentId => {
        const displayPrice = getComponentDisplayPrice(category, componentId);
        updateComponetPriceUI({ componentid: componentId, originalPrice: displayPrice });
    });
}

/**
 * Corrects the initial price label of every default-selected component right after page load,
 * so components covered by a free rule (FreeUpTo, DefaultComponentsAreFree, etc.) don't briefly
 * show their raw tier/base price — drawComponents() prints componentPrice with no rule check
 * since globalSelectedComponents isn't populated yet at that point.
 */
function refreshDefaultComponentPrices() {
    for (const category in globalSelectedComponents) {
        refreshCategoryComponentPrices(category);
    }
}