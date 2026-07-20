/**
 * This file is used to call the component request
 */

/**
 * Get cart edit mode data from global northstarCartEdit object
 * @returns {Object|null} Cart edit data or null if not in edit mode
 */
function getCartEditModeData() {
    if (typeof northstarCartEdit !== 'undefined' && northstarCartEdit.isEditing) {
        return {
            cartItemKey: northstarCartEdit.cartItemKey,
            productId: northstarCartEdit.productId,
            quantity: northstarCartEdit.quantity,
            components: northstarCartEdit.components || [],
            servingOptions: northstarCartEdit.servingOptions || [],
            cartItemData: northstarCartEdit.cartItemData
        };
    }
    return null;
}

let rulesGlobal = [];
let globalCategoryRules = [];
let globalSelectedComponents = [];
let callToSubmit = false;

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

    getMinRequireds() {
        return Object.values(this.rules).map(rule => rule.MinRequired);
    }

    getMaxAlloweds() {
        return Object.values(this.rules).map(rule => rule.MaxAllowed);
    }
}

function loadComponentsQuery($productID) {
    if (jQuery('.single-product').length) {
        let product;
        jQuery.ajax({
            type: 'POST',
            url: olo_vars_object.ajax_url,
            data: {
                action: 'load_component_action_cbs',
                product_id: $productID
            },
            success: function (response) {
                product = response.data;
            }
        }).done(function () {
            createComponentContainer(product,olo_vars_object.symbol_product);
            setEvents();
            globalSelectedComponents = getActiveDefaultComponents()
            refreshDefaultComponentPrices();
            calculateTotalProduct();
            computeSelectedComponents();
        })
    }
}

/**
 * This function is used to fetch the component data
 */
jQuery(document).ready(function ($) {
    const productID = getProductId();
    loadComponentsQuery(productID ?? null);
});

jQuery(document).ready(function ($) {
    jQuery('.product-name').on('click', function ($) {
        setTimeout(() => {
            let productId;
            const productElement = document.querySelector('div.product');
            // Check if the product element exists
            if (productElement) {
                // Extract the product ID from the ID attribute
                productId = productElement.id.split('-')[1];
                console.log("Product ID:", productId);
            } else {
                console.log("Product element not found.");
            }
            loadComponentsQuery(productId);
        }, 2000);
    });
});


/**
 * This function is used to get the product id from the HTML
 *
 * @returns {*|string|jQuery|null}
 */
function getProductId() {
    let elementID = jQuery('#this_page_id');

    return elementID.val() ?? null;
}

let eventsBound = false;

function setEvents (){

    if(eventsBound) {return;}
    eventsBound = true;

    jQuery(".component-dropdown").click(function () {

        toggleComponentDropdown(jQuery(this));

        //scroll to component
        jQuery('html, body').animate({
            scrollTop: jQuery(this).offset().top - 100
        }, 500);
    });

    // edit component serving options button and selected component serving options list
    jQuery('.component_section').on(
        'click',
        '.edit-serving-options-btn, .selected-serving-options',
        function(event) {
            event.preventDefault();
            event.stopPropagation();

            const componentElement = jQuery(this)
                .closest('[class*="component-item-"]')[0];
            const componentData = JSON.parse(
                jQuery(componentElement).attr('data-servingoptions') || '{}'
            );

            if (componentData && Object.keys(componentData).length > 0) {
                createComponentServingOptions(
                    componentData,
                    olo_vars_object.symbol_product,
                    componentElement
                );
            }
        }
    );

    jQuery('.component_section').on('click','[class*="component-item-"]', function(event) {
        const isActive = jQuery(this).hasClass('active');
        const isPlusButton = jQuery(event.target).hasClass('fa-square-plus');
        const isMinusButton = jQuery(event.target).hasClass('fa-square-minus');
        const isPositionButton = jQuery(event.target).hasClass('position-selector');
        const hasDataUnique = jQuery(this).is('[data-unique]');

        if (isPositionButton || isMinusButton) {
            return;
        }

        if (isPlusButton || !hasDataUnique) {
            addItemComponent(this, callToSubmit, event);
            return;
        }

        if(isActive){
            addItemComponent(this, callToSubmit, event);
            return;
        }

        addItemComponent(this, callToSubmit, event);
    });

    /**
     * Helper function to handle keyboard events (Enter/Space) for clickable elements
     * @param {Event} event - The keyboard event
     * @param {Function} triggerCallback - Callback function to execute the desired action
     */
    const handleKeyboardActivation = (event, triggerCallback) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            event.stopPropagation();
            triggerCallback();
        }
    };

    // Add keyboard support for all interactive elements in component section
    jQuery('.component_section').on('keydown', function(event) {
        const $target = jQuery(event.target);

        // Component items
        if ($target.is('[class*="component-item-"]')) {
            handleKeyboardActivation(event, () => $target.trigger('click'));
        }
        // Components wrapper
        else if ($target.is('.components')) {
            handleKeyboardActivation(event, () => $target.find('.component-dropdown').trigger('click'));
        }
        // Serving options
        else if ($target.is('.selected-serving-options')) {
            handleKeyboardActivation(event, () => $target.trigger('click'));
        }
        // Dropdown arrow buttons
        else if ($target.is('.component-dropdown i[role="button"]')) {
            handleKeyboardActivation(event, () => $target.parent('.component-dropdown').trigger('click'));
        }
        // Quantity control buttons
        else if ($target.is('.component-qty-btn[role="button"]')) {
            handleKeyboardActivation(event, () => $target.trigger('click'));
        }
    });

    jQuery("form.cart").on('submit',function(event, data) {
        let addToCart = jQuery('.single_add_to_cart_button');
        addToCart.addClass('add_to_cart_disabled');

        const componentsValid     = HandleUnfulfilledRules();
        const servingOptionsValid = validateMinRequiredServingOptions();

        if(!componentsValid || !servingOptionsValid || data?.preventDefault) {
            event.preventDefault();
            addToCart.removeClass('add_to_cart_disabled');
        }
    });
}

/**
 * Toggles the visibility of a component dropdown and its associated items container.
 * Closes other open dropdowns and updates arrow icons accordingly.
 *
 * @param {jQuery} $dropdown - The jQuery object representing the dropdown to toggle.
 */
function toggleComponentDropdown($dropdown) {
    $dropdown.toggleClass("dropdown-close");
    $dropdown.parent()
        .find(".components-items-container")
        .toggleClass("hide_components");

    jQuery(".component-dropdown").not($dropdown).addClass("dropdown-close");
    jQuery(".components-items-container")
        .not($dropdown.parent().find(".components-items-container"))
        .addClass("hide_components");

    $dropdown.find("i")
        .toggleClass("fa-circle-arrow-up")
        .toggleClass("fa-circle-arrow-down");

    jQuery(".component-dropdown").not($dropdown)
        .find("i")
        .removeClass("fa-circle-arrow-up")
        .addClass("fa-circle-arrow-down");
}

/**
 * Handles unfulfilled rules for a component selection.
 * @returns {boolean} The control flag value.
 */
function HandleUnfulfilledRules() {
    let productID = parseInt(getProductId());
    const componentSelection = validateComponentSelection(globalCategoryRules, productID);
    // OE-26471: also enforce MinRequired on COMPONENT serving options (item serving options are
    // validated separately by validateMinRequiredServingOptions).
    const componentServingOptions = validateComponentServingOptions();

    let flag = componentSelection.controlflag && componentServingOptions.controlflag;
    let unfulfilledForScrolling = [
        ...componentSelection.unfulfilledForScrolling,
        ...componentServingOptions.unfulfilledForScrolling,
    ];

    if (unfulfilledForScrolling.length > 0) {
        const divId = unfulfilledForScrolling.shift();
        callToSubmit = true;
        const scrolled = ScrollToRequiredComponent(divId);
        if(scrolled){
            toggleComponentDropdown(jQuery(divId));
        }
    }
    else{
        callToSubmit = false;
    }
    return flag;
}

function ScrollToRequiredComponent(componentDivId) {
    const SCROLL_DELAY = 350; // Delay in milliseconds
    const SCROLL_OFFSET_DESKTOP = 20; // Offset in pixels for desktop
    const elementId = componentDivId.startsWith('#') ? componentDivId.slice(1) : componentDivId;
    const element = document.getElementById(elementId);

    if (!element) return false;

    const isMobile = window.innerWidth <= 1200;

    // Delay scroll to allow dropdown collapse/expand animation to complete
    setTimeout(() => {
        if (isMobile) {
            // Mobile: use scrollIntoView with CSS scroll-margin-top
            element.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        } else {
            // Desktop: scroll inside .summary container with offset
            const summaryContainer = document.querySelector('.summary');
            if (summaryContainer) {
                const containerRect = summaryContainer.getBoundingClientRect();
                const elementRect = element.getBoundingClientRect();
                const offset = SCROLL_OFFSET_DESKTOP; // Space from top of container
                const targetPosition = summaryContainer.scrollTop + (elementRect.top - containerRect.top) - offset;

                summaryContainer.scrollTo({
                    top: Math.max(0, targetPosition),
                    behavior: 'smooth'
                });
            }
            else {
                // Fallback to window scroll if .summary not found
                element.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }
    }, SCROLL_DELAY);

    return true;
}

/**
 *
 * @param product
 * @returns {*|jQuery}
 */
function createComponentContainer(product,symbolProduct){
    const addToCartButton  = document.querySelector('.single_add_to_cart_button');
    addToCartButton?.classList.remove('add_to_cart_disabled');
    console.log("running components container");

    let componentContainer = jQuery('.component_section');
    let output = '';
    globalCategoryRules = product.components;
    const editModeData = getCartEditModeData();

    jQuery.each(product.components, function (categoryKey, valueCategory) {

        let components = drawComponents(valueCategory, categoryKey, product,symbolProduct);
        let rules = valueCategory.info.rules;
        // get first rule on the object
        let firstRule = Object.keys(rules)[0];
        let rule = rules[firstRule];
        let rulesLabel = getRulesLabel(rule);

        let defaultItems = 0;
        if (editModeData) {
            defaultItems = Object.values(valueCategory.items || {}).reduce((sum, item) => {
                const matchingComponent = editModeData.components.find(c => c.componentid === item.componentId);
                return sum + (matchingComponent ? (matchingComponent.quantity || 0) : 0);
            }, 0);
        } else {
            defaultItems = countDefaultItems(valueCategory.items);
        }


        output += `
        <div class="row components" tabindex="0" role="listbox" aria-label="Component category: ${escapeHtmlAttr(categoryKey)}" >
            <div id="${valueCategory.info.componentCatId}-${product.id}" class="component-dropdown dropdown-close">
                <div>
                    <div class="category_name_container">
                        <p class="category_name">${categoryKey} <span class="counter_category"> ${defaultItems} </span></p>
                        <p class="component-warning"></p>
                    </div>
                    ${rulesLabel}
                </div>
                <i class="fa-solid fa-circle-arrow-down" tabindex="0" role="button" aria-label="open components list"></i>
            </div>
            <div class="compo-${valueCategory.info.componentCatId}-${product.id} warning-container">
                <div class="components-items-container hide_components" id="compo-${valueCategory.info.componentCatId}-${product.id}">
                    ${components}
                </div>
            </div>    
        </div> 
        `;
    });

    output += `
        <div class="productcomponentprice price-section" style="display:none">
            <table>
                <tr>
                    <td>Product Base Price</td>
                    <td>
                        <label>
                            <span>${symbolProduct}</span>
                            <span id="pro_price">0.0</span>
                        </label>
                    </td>
                </tr>
                <tr id="servingoptionprice-row" style="display:none">
                    <td> Serving options Price</td>
                    <td>
                        <label>
                            <span>${symbolProduct}</span>
                            <span id="servingoptionprice"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td> Custom Component Price</td>
                    <td>
                        <label>
                            <span>${symbolProduct}</span>
                            <span id="customprice"></span>
                        </label>
                    </td>
                </tr>
                <tr>
                    <td> Subtotal</td>
                    <td>
                        <label>
                            <span>${symbolProduct}</span>
                            <span id="custom_subtotal"></span>
                        </label>
                    </td>
                </tr>
            </table>
        </div>
        <input type="hidden" name="selComponents" id="selComponents" value="" />
        <input type="hidden" name="selComponentsPrice" id="selComponentsPrice" value="" />
        <input type="hidden" name="selComponentsQty" id="selComponentsQty" value="" />
        <input type="hidden" name="product_price_input" id="product_price_input" value="" />
        <input type="hidden" name="selServingOptions" id="selServingOptions" value="" />
        <input type="hidden" name="selServingOptionsPrice" id="selServingOptionsPrice" value="" />
        <input type="hidden" name="product_base_price" id="product_base_input" data-isVariable="${product.is_variable}" value="${product.price}" />
    `;


    return componentContainer.append(output)
}

function drawComponents(valueCategory, keyCategorydata, product, symbolProduct) {
    let output = '';
    const editModeData = getCartEditModeData();

    jQuery.each(valueCategory.items, function (keyComponent, valueComponent) {
        const rules = valueCategory.info.rules[valueComponent.ruleId];
        rulesGlobal[valueComponent.ruleId] = rules;

        const maxUnique = rules?.MaxUnique;
        const dataUnique = rules?.MaxUnique === 1 ? 'data-unique = "true"' : "";

        // Check if component is selected in edit mode
        const selectedComponentInEdit = editModeData?.components?.find(
            c => c.componentid === valueComponent.componentId
        );
        const isSelectedInEdit = !!selectedComponentInEdit;

        const active = editModeData ? (isSelectedInEdit ? "active" : "") : (valueComponent.isDefault > 0 ? "active" : "");
        const quantity = editModeData
            ? (isSelectedInEdit ? selectedComponentInEdit.quantity : 0)
            : (valueComponent.isDefault ? 1 : 0);
        const componentPosition = editModeData && selectedComponentInEdit?.position
            ? selectedComponentInEdit.position
            : '';
        // Rough first paint only — printed before globalSelectedComponents exists, so no rule check
        // here. refreshDefaultComponentPrices() (called right after seeding) rewrites every selected
        // pill through the rule engine for both the normal and edit-mode flows.
        const calculatedPrice = valueComponent.componentPrice ?? 0;
        const individualPrice = valueComponent.componentPrice || 0;

        const componentName = valueComponent.componentName;
        const maxUniqueLabel = (maxUnique >= 1 ? `<small><b>(Max: ${maxUnique})</b></small>` : "");

        const servingOptionsArr = Array.isArray(valueComponent.componentServingOptions)
            ? valueComponent.componentServingOptions
            : Object.values(valueComponent.componentServingOptions || {});

        // Use serving options from edit mode if available, otherwise use defaults
        const defaultServingOptions = editModeData && selectedComponentInEdit?.servingOptionIds
            ? selectedComponentInEdit.servingOptionIds
            : servingOptionsArr.flatMap(option =>
                Object.values(option.items)
                .filter(item => item.isDefault === true || item.isDefault === 1)
                .map(item => item.servingOptionId)
            );

        const selectedServingOptionsNames = getSelectedServingOptionsNames(
            valueComponent.componentServingOptions,
            defaultServingOptions
        );

        const selectedOptionsDisplay = createServingOptionsHTML(selectedServingOptionsNames, symbolProduct);

        const getQuantityControls = (isDefault, maxUnique, componentId, productId, quantity=1) => {
            if (!isDefault) return "";

            if (maxUnique === 1) {
                return `<div class="quantity-controls">
                    <i class="fa-solid fa-circle-check"></i>
                </div>`;
            }

            return `
                <div class="quantity-controls"> 
                    <i class="fa-solid fa-square-minus component-qty-btn" data-componentid="${componentId}-${productId}" onclick="removeitems(this)" tabindex="0" aria-label="Remove item" role="button"></i>
                    <div class="component-qty">${quantity}</div>
                    <i class="fa-solid fa-square-plus component-qty-btn" tabindex="0" aria-label="Add item" role="button"></i>
                </div>
            `;
        };

        const getEditButton = (hasServingOptions) => {
            if (!hasServingOptions) return "";

            return `
                <button class="edit-serving-options-btn" type="button" data-componentid="${valueComponent.componentId}-${product.id}" title="Edit Serving Options">
                    <i class="fa-solid fa-pen"></i>
                </button>
            `;
        };

        const getPositionControls = (isDefault, componentId, productId, selectedPosition = '') => {
            if (!isDefault) return "";

            const leftActive = selectedPosition === 'left' ? 'active' : '';
            const wholeActive = (!selectedPosition || selectedPosition === 'whole') ? 'active' : '';
            const rightActive = selectedPosition === 'right' ? 'active' : '';

            return `
                <button class="position-selector ${leftActive}" type="button" data-componentid="${componentId}-${productId}" onClick="selectposition(this)" data-position="left">Left</button> 
                <button class="position-selector ${wholeActive}" type="button" data-componentid="${componentId}-${productId}" onClick="selectposition(this)" data-position="whole">Whole</button>
                <button class="position-selector ${rightActive}" type="button" data-componentid="${componentId}-${productId}" onClick="selectposition(this)" data-position="right">Right</button>
            `;
        };

        const commonDataAttributes = `
            data-compname="${escapeHtmlAttr(valueComponent.componentName)}"
            data-maxUnique="${maxUnique}"
            id="${valueComponent.componentId}-${product.id}"
            data-wcid="${product.id}" 
            ${dataUnique}
            data-action=""
            class="${active} component-item-${valueCategory.info.componentCatId}-${product.id}"
            data-quantity="${quantity}"
            data-price="${individualPrice}"
            data-category="${escapeHtmlAttr(keyCategorydata)}"
            data-componentid="${valueComponent.componentId}"
            data-catid="${valueCategory.info.componentCatId}-${product.id}"
            data-rule="${valueComponent.ruleId}"
            data-component="${escapeHtmlAttr(valueComponent.componentName)}"
            data-isDefault="${valueComponent.isDefault}"
            data-componentKey="${valueComponent.key}"
            data-siteId="${valueComponent.siteId}"
            data-placements="${valueComponent.NumberOfPlacements}"
            data-servingoptions='${JSON.stringify(valueComponent.componentServingOptions)}'
            data-selectedservingoptions='${JSON.stringify(defaultServingOptions)}'
            data-pricinglevels='${JSON.stringify(valueComponent.pricingLevels)}'
            tabindex="0"
            role="option"
            aria-selected="${active ? 'true' : 'false'}"
        `;

        const mainContent = `
            <div class="col-12 item-main-container">
                <div>
                    <p class="component_name">
                        ${componentName}
                        ${maxUniqueLabel}
                    </p>
                    <p class="component_price">${calculatedPrice > 0 ? symbolProduct + calculatedPrice : ''}</p>
                    ${selectedOptionsDisplay}
                </div>
                <div class="component-controls-container">
                    <div class="qty-controls-container">
                        ${getQuantityControls(active, maxUnique, valueComponent.componentId, product.id, quantity)}
                    </div>
                    <div class="edit-serving-options-container">
                        ${getEditButton(servingOptionsArr.length > 0)}
                    </div>
                </div>
            </div>
        `;

        if (valueComponent.NumberOfPlacements == 1) {
            output += `<div ${commonDataAttributes} data-position=''>${mainContent}</div>`;
        } else {
            const positionControls = getPositionControls(active, valueComponent.componentId, product.id, componentPosition);
            output += `
                <div ${commonDataAttributes} data-position='${componentPosition}'>
                    ${mainContent}
                    <div class="position-container col-12">${positionControls}</div>
                </div>
            `;
        }
    });

    return output;
}

const toggleCheckbox = document.getElementById('toggle');

toggleCheckbox?.addEventListener('change', () => {
    if (toggleCheckbox.checked) {
        document.cookie = "orderType=0; path=/; max-age=2592000";
    } else {
        document.cookie = "orderType=2; path=/; max-age=2592000";
    }

    window.location.reload();


});

function getSelectedServingOptionsNames(componentServingOptions, selectedIds) {
    if (!componentServingOptions || !selectedIds || selectedIds.length === 0) return [];

    const options = [];

    Object.values(componentServingOptions).forEach(category => {
        if (category.items) {
            Object.values(category.items).forEach(item => {
                if (selectedIds.includes(item.servingOptionId)) {
                    options.push({
                        name: item.servingOptionName,
                        id: item.servingOptionId,
                        price: item.servingOptionPrice || 0
                    });
                }
            });
        }
    });

    return options;
}

function updateSelectedServingOptionsDisplay(componentElement) {
    const selectedIds = JSON.parse(componentElement.getAttribute('data-selectedservingoptions') || '[]');
    const servingOptions = JSON.parse(componentElement.getAttribute('data-servingoptions') || '{}');

    const options = getSelectedServingOptionsNames(servingOptions, selectedIds);

    let optionsContainer = componentElement.querySelector('.selected-serving-options');

    if (options.length > 0) {
        const symbol = olo_vars_object?.symbol_product;
        const optionsHTML = createServingOptionsHTML(options,symbol).replace('<div class="selected-serving-options">', '').replace('</div>', '');

        if (optionsContainer) {
            optionsContainer.innerHTML = optionsHTML;
        } else {
            optionsContainer = document.createElement('div');
            optionsContainer.className = 'selected-serving-options';
            optionsContainer.innerHTML = optionsHTML;

            const nameContainer = componentElement.querySelector('.component_price');
            nameContainer.parentNode.insertBefore(optionsContainer, nameContainer.nextSibling);

        }
    } else if (optionsContainer) {
        optionsContainer.remove();
    }
}

function createServingOptionsHTML(options, symbol='') {
    if (!options || options.length === 0) return '';

    return `
        <div class="selected-serving-options" role="button" tabindex="0" aria-label="Edit Serving Options" title="Click to edit serving options">
            <ul class="serving-options-list">
                ${options.map(option => {
                    const price = option.price && option.price !== '0' && option.price !== 0
                        ? `<span class="option-price">${symbol}${option.price}</span>`
                        : '';
                    return `<li class="serving-option-item">
                        <span class="option-name">${option.name}</span>
                        ${price}
                    </li>`;
                }).join('')}
            </ul>
        </div>
    `;
}


/**
 *
 * @param {object} componentData the data of the component
 */
function updateComponetPriceUI(componentData) {
    if(!componentData) {
        return;
    }
    const {originalPrice, componentid} = componentData;
    const priceElement = document.querySelector(`[data-componentid="${componentid}"] .component_price`);
    if(!priceElement) {
        return;
    }

    if(originalPrice === 0 || isNaN(originalPrice)) {
        priceElement.textContent = '';
        return;
    }
    const symbolProduct = olo_vars_object?.symbol_product || '';
    priceElement.textContent = symbolProduct + originalPrice.toFixed(2);
}

document.addEventListener('DOMContentLoaded', function () {
	setTimeout(function(){
	   document.querySelectorAll('.woocommerce-message').forEach(function (msg) {
		   const btn = msg.querySelector('span.dizmizz');
		   if (btn) {
           		btn.click();
		   } else {
            	msg.remove();
		   }
	   });
	}, 5000);
});

// this file handle the serving option functionality

// handleCheckboxChange function is defined in the olo_calculate_totals.js file
// handleDialogCheckboxChange function is defined in the olo_calculate_totals.js file
// getCartEditModeData function is defined in the olo_render.js file (loaded first via webpack.mix.js)

document.addEventListener('DOMContentLoaded', function () {
    const productId = getProductId();
    loadServingOptionsQuery(productId ?? null);
});

function loadServingOptionsQuery(productId) {
    if(!productId) return;

    fetch(olo_vars_object.ajax_url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: 'load_serving_options_action_cbs',
            product_id: productId
        })
    }).then(response => response.json())
        .then(data => {
            let servingOptionsContainer = jQuery('#servingoptions_section');
            if(data?.data?.servingOptions){
                createServingOptions(data.data.servingOptions,olo_vars_object.symbol_product, servingOptionsContainer);
            }
        })
        .catch(error => {
            console.error('Error:', error);
        });
}

function createServingOptions(data, symbol, container) {
    if (!data) {
        container.html('');
        return;
    }
    if(container.length === 0) {
        return;
    }
    let servingOptions = data;
    let servingOptionsHTML = '';
    const ruleInstance = ServingOptionsRules.getInstance();
    const servingOptionsContainer = container;
    let optionRules = {};

    // Check if we're in edit mode and get cart item data
    const editModeData = getCartEditModeData();
    const selectedServingOptions = editModeData?.servingOptions || [];

    Object.entries(servingOptions)
    .forEach(function ([category,servingOption]) {
        const {items, info} = servingOption;
        const optionRule = info?.rules[info.categoryId];
        optionRules[info.categoryId] = optionRule;
        ruleInstance.addRule(info.categoryId, optionRule);
        servingOptionsHTML += `
        <section class="serving-category" data-categoryid="${info.categoryId}">
            <h4>${category}</h4>
            <div class="serving-options-warnings" data-warning-for="${info.categoryId}" aria-live="polite"></div>
        </section>`;
        Object.entries(items).forEach(function([_name,item] ){
            const pricingLevels = encodeURIComponent(JSON.stringify(item.pricingLevels || {}));
            const id = info.componentId
            ? `serving_option_${item.servingOptionId}-${info.componentId}`
            :`serving_option_${item.servingOptionId}`;

            // Check if this option is selected in edit mode
            const isSelectedInEdit = editModeData && selectedServingOptions.some(
                opt => opt.optionId === item.servingOptionId
            );
            const shouldBeChecked = editModeData ? isSelectedInEdit : item.isDefault;
            servingOptionsHTML += `
                <div class="form-check value">
                    <input 
                        class="form-check"
                        type="checkbox" 
                        id="${id}"
                        name="${item.servingOptionName}"
                        value="${item.servingOptionId}"
                        data-price="${item.servingOptionPrice}"
                        data-pricingLevels='${pricingLevels}'
                        data-displayorder="${item.displayOrder}"
                        data-categoryid="${info.categoryId}"
                        data-optionName="${item.servingOptionName}"
                        data-optionId="${item.servingOptionId}"
                        ${shouldBeChecked ? 'checked' : ''}>
                    <label 
                        class="form-check-label" 
                        for="${id}"
                        role="checkbox"
                        aria-checked="${shouldBeChecked ? 'true' : 'false'}"
                        tabindex="0"
                        aria-label="${item.servingOptionName} ${item.servingOptionPrice && item.servingOptionPrice !== '0' ? `${symbol}${item.servingOptionPrice}` : ''}">
                        ${item.servingOptionName}
                    </label>
                </div>`;
        });
    });
    servingOptionsContainer.html(servingOptionsHTML);
    servingOptionsContainer.on('change','input[type="checkbox"]', function(event) {
        handleCheckboxChange.call(this, ruleInstance);
        const label = servingOptionsContainer.find(`label[for="${this.id}"]`)[0];
        if (label) {
            label.setAttribute('aria-checked', this.checked ? 'true' : 'false');
        }
    });
    servingOptionsContainer.on('keydown','label.form-check-label', function(event) {
        const target = event.target;
        // Handle Space/Enter on labels to toggle checkbox
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            const checkbox = document.getElementById(target.getAttribute('for'));
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                target.setAttribute('aria-checked', checkbox.checked ? 'true' : 'false');
            }
        }
    });

}

function createComponentServingOptions(data, symbol, componentElement) {
    if (!data) return;

    const dialog = populateDialog(data, symbol, componentElement);
    dialog.showModal();
}

let globalServingOptionsDialog = null;

function createGlobalDialog() {
    if (globalServingOptionsDialog) return globalServingOptionsDialog;

    const dialogHTML = `
        <div>
            <button class="close-button">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <h1 class="component-serving-option-header" id="dialog-title"></h1>
        <div class="serving-options-scrollable-container" id="dialog-content"></div>
        <div class="dialog-controls">
            <button class="btn btn-primary save-serving-options">Done</button>
        </div>
    `;

    globalServingOptionsDialog = document.createElement('dialog');
    globalServingOptionsDialog.classList.add('serving-options-dialog');
    globalServingOptionsDialog.innerHTML = dialogHTML;
    document.body.appendChild(globalServingOptionsDialog);

    globalServingOptionsDialog.querySelector('.close-button').addEventListener('click', () => {
        globalServingOptionsDialog.close();
    });

    globalServingOptionsDialog.querySelector('.save-serving-options').addEventListener('click', () => {
        globalServingOptionsDialog.close();
    });

    return globalServingOptionsDialog;
}

document.addEventListener('DOMContentLoaded', function () {
    // Only create dialog on single product pages
    if (document.body.classList.contains('single-product')) {
        createGlobalDialog();
    }
});

let currentDialogController= null;

function populateDialog(data, symbol, componentElement) {
    const dialog = createGlobalDialog();
    const title = dialog.querySelector('#dialog-title');
    const content = dialog.querySelector('#dialog-content');

    if (currentDialogController) {
        currentDialogController.abort();
    }

    currentDialogController = new AbortController();

    const componentName = componentElement.getAttribute('data-compname');
    const componentId = componentElement.getAttribute('data-componentid');
    const category = componentElement.getAttribute('data-category');
    title.textContent = `${componentName} Serving Options`;

    let currentSelectedOptions = [];
    if (globalSelectedComponents[category]) {
        const componentData = globalSelectedComponents[category].find(comp => comp.componentid == componentId);
        if (componentData?.selectedservingoptions) {
            currentSelectedOptions = [...componentData.selectedservingoptions];
        }
    }

    if (currentSelectedOptions.length === 0) {
        try {
            currentSelectedOptions = JSON.parse(componentElement.getAttribute('data-selectedservingoptions') || '[]');
        } catch (e) {
            console.warn('Error parsing selected serving options:', e);
            currentSelectedOptions = [];
        }
    }

    let fullContentHTML = '';
    const ruleInstance = ServingOptionsRules.getInstance();

    Object.entries(data).forEach(function ([category, servingOption]) {
        const {items, info} = servingOption;
        const optionRule = info?.rules[info.categoryId];
        ruleInstance.addRule(info.categoryId, optionRule);
        let contentHTML = '';


        Object.entries(items).forEach(function([_name, item]) {
            const id = info.componentId
                ? `serving_option_${item.servingOptionId}-${info.componentId}`
                : `serving_option_${item.servingOptionId}`;
            const servingOptionPrice = item.servingOptionPrice && item.servingOptionPrice !== '0'
                ? `${symbol}${item.servingOptionPrice}` : '';

            const isCurrentlySelected = currentSelectedOptions.includes(item.servingOptionId);
            const pricingLevels = encodeURIComponent(JSON.stringify(item.pricingLevels || {}));

            contentHTML += `
                <div class="form-check value">
                    <input 
                        class="form-check"
                        type="checkbox" 
                        id="${id}"
                        name="${item.servingOptionName}"
                        value="${item.servingOptionId}"
                        data-price="${item.servingOptionPrice}"
                        data-pricingLevels='${pricingLevels}'
                        data-displayorder="${item.displayOrder}"
                        data-categoryid="${info.categoryId}"
                        data-optionName="${item.servingOptionName}"
                        data-optionId="${item.servingOptionId}"
                        ${isCurrentlySelected ? 'checked' : ''}>
                    <label 
                        class="form-check-label" 
                        for="${id}"
                        aria-label="${item.servingOptionName} ${servingOptionPrice}"
                        aria-checked="${isCurrentlySelected ? 'true' : 'false'}"
                        role="checkbox"
                        tabindex="0">
                        <span class="option-name">${item.servingOptionName}</span>
                        <span class="option-price">${servingOptionPrice}</span>
                    </label>
                </div>`;
        });
        fullContentHTML += `
            <section class="serving-category" data-categoryid="${info.categoryId}">
                <h4>${category}</h4>
                <div class="serving-options-warnings" data-warning-for="${info.categoryId}" aria-live="polite"></div>
                ${contentHTML}
            </section>`;
    });

    content.innerHTML = fullContentHTML;

    content.addEventListener('change', function(event) {
        if (event.target.type === 'checkbox') {
            handleDialogCheckboxChange.call(event.target, ruleInstance, componentElement);
            const label = content.querySelector(`label[for="${event.target.id}"]`);
            if (label) {
                label.setAttribute('aria-checked', event.target.checked ? 'true' : 'false');
            }
        }
    },{signal: currentDialogController.signal});

    // Keyboard navigation support
    content.addEventListener('keydown', function(event) {
        const target = event.target;

        // Handle Space/Enter on labels to toggle checkbox
        if ((event.key === ' ' || event.key === 'Enter') && target.classList.contains('form-check-label')) {
            event.preventDefault();
            const checkbox = document.getElementById(target.getAttribute('for'));
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
                target.setAttribute('aria-checked', checkbox.checked ? 'true' : 'false');
            }
            return;
        }

        // Handle Arrow navigation between options
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const allLabels = Array.from(content.querySelectorAll('.form-check-label'));
            const currentIndex = allLabels.indexOf(target);

            if (currentIndex === -1) return;

            let nextIndex;
            if (event.key === 'ArrowDown') {
                nextIndex = (currentIndex + 1) % allLabels.length;
            } else {
                nextIndex = currentIndex === 0 ? allLabels.length - 1 : currentIndex - 1;
            }

            allLabels[nextIndex].focus();
            return;
        }

        // Handle Escape to close dialog
        if (event.key === 'Escape') {
            dialog.close();
            return;
        }
    }, {signal: currentDialogController.signal});

    return dialog;
}

function persistComponentServingOptions(componentElement, selectedIds) {
    const $component = jQuery(componentElement);
    $component.data('selectedservingoptions', selectedIds);
}

function showErrorSummary(container, message) {
    if (!container) {
        alert(message);
        return;
    }
    container.textContent = message;
}
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
//handle giftcards in online ordering

(function() {
    // Exit early if in kiosk mode
    if (document.querySelector('.checkout-block.kiosk')) {
        return;
    }

    const giftCardNumberInput = document.getElementById('gift-card');
    const giftCardErrorMesssage = document.querySelector('.validation-message');
    const submitGiftCardBtn = document.getElementById('submit-gift-card');
    const giftCardSpinner = document.getElementById('giftcard-spinner');


    const handleGifCardVerify = async (e) => {
        e.preventDefault();
        if(!giftCardNumberInput.value.trim()) {
            handleBadRequest({message: 'Please enter a gift card number'});
            return;
        }

        giftCardSpinner.style.display = 'flex';
        giftCardErrorMesssage.style.display = 'none';
        const bodyData = {
            action: 'add_gift_card_action',
            giftcard: giftCardNumberInput.value.trim(),
        };
        try {
            const response = await fetch(olo_vars_object.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(bodyData)
            });

            giftCardSpinner.style.display = 'none';
            const data = await response.json();
            if(response.ok) {
                const {error} = data.data;
                if(!error){
                    handleGiftCardSuccess(data);
                    return;
                }
            }

            handleBadRequest(data.data);
        } catch (error) {
            giftCardSpinner.style.display = 'none';
            handleBadRequest({message: 'An unexpected error occurred. Please try again.', error});
        }
    }
    const handleGiftCardSuccess = (data) => {
        console.log(data);
        jQuery(document.body).trigger('update_checkout');
        giftCardNumberInput.value = '';
    }

    jQuery(document).ready(function($) {
        $(document.body).on('update_checkout', function() {
            $('.overlay-update-checkout').removeClass('hide');
            console.log('update_checkout triggered');
        });
    });


    const handleBadRequest = (data) => {
        giftCardErrorMesssage.textContent = data.message;
        giftCardErrorMesssage.style.display = 'block';
        console.error(data.message, data.error);
    }

    submitGiftCardBtn?.addEventListener('click', handleGifCardVerify);
    giftCardNumberInput?.addEventListener('input', (e) => {
        if(e.target.value.trim()) {
            submitGiftCardBtn.disabled = false;
        } else {
            submitGiftCardBtn.disabled = true;
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        if(giftCardSpinner) {
            giftCardSpinner.style.display = 'none';
        }
        if(giftCardErrorMesssage) {
            giftCardErrorMesssage.style.display = 'none';
        }
    });

    async function handleDeleteGiftCard(event) {
        if(!event.target || !event.target.dataset.cardnumber) {
            console.error('Gift card number not found on delete button.');
            return;
        }
        const giftCard = event.target.dataset.cardnumber;

        const bodyData = {
            action: 'delete_gift_card_action',
            giftcard: giftCard,
        };
        event.target.innerHTML = '<span id="loader-spinner"></span>';
        try {
            const response = await fetch(olo_vars_object.ajax_url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: new URLSearchParams(bodyData)
            });
            const data = await response.json();
            if(response.ok) {
                jQuery(document.body).trigger('update_checkout');
                return;
            }
            console.error(data);
        } catch (error) {
            console.error('Network error occurred. Please try again.', error);
        }
    }

    function setupDeleteGiftcardButtons() {
        const deleteGiftCard = document.querySelectorAll('.delete-giftcard');
        deleteGiftCard?.forEach((element) => {
            element.addEventListener('click', handleDeleteGiftCard);
        });

    }

    jQuery(document).ready(function($) {
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url.indexOf('wc-ajax=update_order_review') > -1) {
                setupDeleteGiftcardButtons();
            }
        });
    });
})();
jQuery(document).on('click', '#olo-apply-coupon', function(e){
            e.preventDefault();

            jQuery(document.body).one('updated_checkout', function() {
                const couponInput = document.querySelector('input[name="olo_coupon_code"]');
                if (couponInput) couponInput.value = '';
            });

            jQuery(document.body).trigger('update_checkout');
        });



    document.addEventListener('click', function (e) {
    const btn = e.target.closest('.olo-remove-coupon');
    if (!btn) return;

    const coupon = (btn.dataset.coupon || '').trim();

    const form = document.querySelector('form.checkout');
    if (!form) return;

    const couponInput = form.querySelector('input[name="olo_coupon_code"]');
    if (couponInput) {
        couponInput.value = '';
    }


    let input = form.querySelector('input[name="olo_coupon_delete"]');
    if (!input) {
        input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'olo_coupon_delete';
        form.appendChild(input);
    }
    input.value = coupon;

    if (window.jQuery) {
        jQuery(document.body).one('updated_checkout', function () {
        input.value = '';
        btn.disabled = false;
        });

        jQuery('body').trigger('update_checkout');
    } else {
        window.location.reload();
    }
    });

    jQuery(document.body).on('updated_checkout', function() {
        jQuery('.woocommerce-error li').each(function() {
            const $li = jQuery(this);
            const text = $li.clone().children().remove().end().text().trim();
            // olo-coupon-error-seen is a non-visual marker (no dismiss button --
            // the error clears itself on the next successful update_checkout, same
            // as any other WooCommerce notice) used only to gate the scroll below
            // to once per fresh error occurrence.
            if ((text.includes('Invalid Coupon') || text.includes('Coupon cannot be applied')) && !$li.hasClass('olo-coupon-error-seen')) {
                $li.addClass('olo-coupon-error-seen');

                // The custom coupon widget triggers WooCommerce's own update_checkout
                // event rather than the native coupon form's submit, and on this page
                // that path's own scroll_to_notices() call isn't visibly scrolling the
                // customer to the resulting error. Trigger it explicitly here.
                //
                // Uses scrollIntoView(), not jQuery.scroll_to_notices()/offset()-based
                // math: this theme renders .woocommerce-error as `position: fixed`
                // (a floating toast, see _checkout.scss), and offset() is unreliable
                // for fixed-position elements -- confirmed live, it measured a
                // nonsensical negative value here, which produces a scrollTop below
                // the browser's minimum of 0 and so never visibly moves the page.
                // scrollIntoView() is correct regardless of fixed positioning or any
                // transformed ancestor.
                //
                // Gated by the same "not already seen" check above, so this only
                // fires once per fresh error -- not on every later update_checkout
                // cycle while a stale error is still on the page.
                const noticeEl = document.querySelector('.woocommerce-NoticeGroup-updateOrderReview, .woocommerce-error');
                if (noticeEl) {
                    noticeEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    });

//this file handle the login popup that show before the checkout page
document.addEventListener('DOMContentLoaded', function() {
    const loginDialog = document.getElementById('login-dialog');
    const continueAsGuestButton = document.getElementById('continue-as-guest');
    const closeLoginDialogButton = document.getElementById('close-login-dialog');
    const togglePasswordButton = document.getElementById('toggle-password');
    const passwordInput = document.getElementById('password');
    const usernameInput = document.getElementById('username');
    const errorMessage = document.getElementById('username-error-message');
    const spinner = document.getElementById('spinner');

    // Redirect target captured per popup-open; the submit listener below is
    // bound once and reads this closure variable so it can't stack across
    // repeated popup opens (cart fragments can re-trigger the Pay anchor click
    // multiple times per page-load).
    let pendingRedirect = null;

    // Delegate so the listener survives cart-fragment swaps (wc_fragment_refresh
    // replaces .cart-collaterals, which contains the Pay anchor).
    document.addEventListener('click', function(e) {
        if (!loginDialog) return;
        const checkoutButton = e.target.closest('.checkout-button');
        if (!checkoutButton) return;
        checkoutButton.removeAttribute('disabled');
        showLoginPopup(e, checkoutButton);
    });

    function showLoginPopup(e, checkoutButton) {
        pendingRedirect = checkoutButton.href;
        e.preventDefault();
        loginDialog.showModal();
        usernameInput.focus();
    }

    const loginForm = document.getElementById('login-form');
    loginForm?.addEventListener('submit', function(e) {
        e.preventDefault();
        const username = usernameInput.value;
        const password = passwordInput.value;
        const rememberme = document.getElementById('rememberme').checked ? 'true' : 'false';

        const data = {
            action: 'ajax_login',
            username: username,
            password: password,
            remember: rememberme,
            security: olo_vars_object.ajax_nonce
        };
        spinner.style.display = 'inline-block';
        errorMessage.textContent = '';

        fetch(olo_vars_object.ajax_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: new URLSearchParams(data)
        })
        .then(response => response.json())
        .then(res => {
            spinner.style.display = 'none';
            if(res === -1) {
                errorMessage.textContent = 'Security check failed. Please refresh the page and try again.';
                return;
            }
            const { data } = res;
            if (data?.loggedin) {
                spinner.style.display = 'inline-block';
                // Force a fresh page load and regenerates nonces for the logged-in user
                // Adding a cache-busting parameter prevents browser from serving cached checkout page
                const url = new URL(pendingRedirect, window.location.origin);
                url.searchParams.set('_', Date.now());
                window.location.href = url.toString();
            }
            else {
                errorMessage.textContent = data?.message;
            }
        })
        .catch(() => {
            spinner.style.display = 'none';
            errorMessage.textContent = 'Login failed. Please try again.';
        });
    });

    continueAsGuestButton?.addEventListener('click', function() {
        window.location.href = '/checkout';
    });

    closeLoginDialogButton?.addEventListener('click', function() {
        usernameInput.value = '';
        passwordInput.value = '';
        loginDialog.close();

    });

    togglePasswordButton?.addEventListener('click', function() {
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            togglePasswordButton.innerHTML = '<i id="eye-icon" class="fas fa-eye-slash"></i>';
        } else {
            passwordInput.type = 'password';
            togglePasswordButton.innerHTML= '<i id="eye-icon" class="fas fa-eye"></i>';
        }
    });
});
// this file handle the loyalty ui functionality

const loyaltyButton = document.getElementById('loyalty-button');
const loyaltyDialog = document.getElementById('loyalty-dialog');
const loyaltyCloseButton = document.getElementById('rewards-close');
const loader = document.getElementById('loader-box');
const rewardsDoneButton = document.getElementById('rewards-dialog-done');

const rewardButtonTexts = {
    done: 'Done',
    ok: 'OK',
}

let currentLoyaltyData = null;
let programsById = {};
let rewardsListDelegationBound = false;
let rewardChanged = false;

let loading = false;
let redeemState = 0;
let isDialogOpen = false;
let phoneDialogListenersAdded = false;
let loyaltyActionInFlight = false;
let loyaltyBusySnapshot = null;

function setLoyaltyActionsBusy(activeButton) {
    // Snapshot disabled state so a later clearLoyaltyActionsBusy() restores
    // pre-action state instead of indiscriminately enabling buttons that
    // were already disabled (e.g. by canRedeem=false).
    loyaltyBusySnapshot = new Map();
    document.querySelectorAll('#rewards-content .reward-button').forEach(btn => {
        loyaltyBusySnapshot.set(btn, btn.disabled);
        if (btn !== activeButton) btn.disabled = true;
    });
}

function clearLoyaltyActionsBusy() {
    if (!loyaltyBusySnapshot) return;
    loyaltyBusySnapshot.forEach((wasDisabled, btn) => {
        if (document.body.contains(btn)) {
            btn.disabled = wasDisabled;
        }
    });
    loyaltyBusySnapshot = null;
}
const formatPhoneNumber = (phoneNumberString) => {
    const cleaned = ('' + phoneNumberString).replace(/\D/g, '');
    return cleaned.replace(/(\d{0,3})(\d{0,3})(\d{0,4})/, function (_, areaCode, centralOfficeCode, lineNumber) {
        let result = '';
        if (areaCode) result += `(${areaCode}`;
        if (centralOfficeCode) result += `) ${centralOfficeCode}`;
        if (lineNumber) result += `-${lineNumber}`;
        return result;
    });
}

function dinamicRewardsListDelegation() {
    if (rewardsListDelegationBound) return;

    const rewardsList = document.getElementById('rewards-content');
    if (!rewardsList) return;

    rewardsList.addEventListener('click', async (e) => {
        const button = e.target.closest('button');
        if (!button) return;

        if (!button.classList.contains('reward-button')) return;

        e.preventDefault();

        const mode = button.dataset.mode; // "redeem" | "undo"
        const uniqueId = button.dataset.uniqueId;

        if (!uniqueId) return;

        if (mode === 'undo') {
            await deleteRedeem(e, uniqueId);
            return;
        }

        // default to redeem
        const program = programsById[uniqueId];
        if (!program || !currentLoyaltyData) return;

        await redeemReward(e, program, currentLoyaltyData);
    });

    rewardsListDelegationBound = true;
}

function handlePhoneNumberInput (e) {
    if (e.data === null) {
        return;
    }

    const inputValue = e.target.value;
    let cleaned = inputValue.replace(/\D/g, '');
    e.target.value = formatPhoneNumber(cleaned);
}

loyaltyButton?.addEventListener('click', async (e) => {
    e.preventDefault();
    const guestPhonevalue = sessionStorage.getItem('guestPhone');

    if (guestPhonevalue === null) {
        requestCustomerPhoneNumber();
        return;
    }

    handleDisplayLoyaltyDialog();
});

loyaltyCloseButton?.addEventListener('click', closeLoyaltyDialog);
rewardsDoneButton?.addEventListener('click', handleDisplayLoyaltyDialog);

function closeLoyaltyDialog(e) {
    e.preventDefault();
    loyaltyDialog.close();
    handleLoyaltyStateChange();
    isDialogOpen = !isDialogOpen;
}

function handleDisplayLoyaltyDialog() {
    if(!isDialogOpen){
        rewardsDoneButton.innerText = rewardButtonTexts.ok;
        loyaltyDialog?.showModal();
        getLoyaltyData();
    }
    else{
        loyaltyDialog?.close();
        handleLoyaltyStateChange();
    }

    isDialogOpen = !isDialogOpen;
}

function requestCustomerPhoneNumber() {
    const phoneNumberDialog = document.getElementById('phone-number-dialog');
    phoneNumberDialog.showModal();

    if (phoneDialogListenersAdded) {
        return;
    }

    const customerPhoneNumber = document.getElementById('customerPhoneNumber');
    const closebutton = document.getElementById('phone-number-dialog-close');
    const doneButton = document.getElementById('phone-number-done');
    const errorMessage = document.getElementById('phone-error-message');

    phoneDialogListenersAdded = true;

    closebutton?.addEventListener('click', (e) => {
        e.preventDefault();
        phoneNumberDialog.close();
    });

    customerPhoneNumber?.addEventListener('input', handlePhoneNumberInput);
    doneButton?.addEventListener('click', (e) => {
        e.preventDefault();
        const cleaned = customerPhoneNumber.value.replace(/\D/g, '');
        if (cleaned.length < 10) {
            errorMessage.style.display = 'block';
            return;
        }
        errorMessage.style.display = 'none';
        sessionStorage.setItem('guestPhone', customerPhoneNumber.value);
        customerPhoneNumber.value = '';
        phoneNumberDialog.close();
        handleDisplayLoyaltyDialog();
    });
}

async function getLoyaltyData() {
    const guestPhonevalue = sessionStorage.getItem('guestPhone');
    loading = true;

    const cleanedPhone = guestPhonevalue.replace(/\D/g, '');
    const hidePhone = cleanedPhone.slice(-4).padStart(cleanedPhone.length, '*');
    console.log(`Fetching loyalty data for phone number: ${hidePhone}`);
    loader.style.display = 'block';


    const siteId = getCookie('siteIdJs');

    const payload = {
        siteId: siteId,
        phoneNumber: cleanedPhone,
    };

    const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/availablePrograms';

    const rewardsList = document.getElementById('rewards-content');
    rewardsList.innerHTML = '';

    try {

        const response = await fetch(baseUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            const error = await response.json();
            console.error('Server error:', error.message);
            throw new Error(error.message);
        }
            const data = await response.json();
            loading = false;
            loader.style.display = 'none';
            createRewardsList(data['AvailablePrograms'], data);
            return data;
	} catch (error) {
        loading = false;
        loader.style.display = 'none';

        handleNoRewards(error.message || 'An unexpected error occurred.');
	}
}

function createRewardsList(programs, data) {
    const rewardsList = document.getElementById('rewards-content');
    rewardsList.innerHTML = '';

    dinamicRewardsListDelegation();

    if (!programs || programs.length === 0) {
        rewardsList.innerHTML = '<p>No rewards available</p>';
        resetLoyaltyPhoneNumber();
        return;
    }


    currentLoyaltyData = data;
    programsById = {};
    programs.forEach(p => { programsById[p.uniqueKey] = p; });

    rewardsList.innerHTML = `
        <div class="rewards-row rewards-header" style="display: none;">
            <div class="rewards-column">Product</div>
            <div class="rewards-column"></div>
            <div class="rewards-column"></div>
        </div>`;

        const canRedeem = data.canRedeem;
    programs.forEach(reward => {
        const div = document.createElement('div');

        const isRedeemed = !!reward.redeemed;
        const buttonText = isRedeemed ? 'Undo' : 'Redeem';

        const classes = [
            'reward-button',
            'reward-action',
            isRedeemed ? 'undo' : 'redeem-button',
            isRedeemed ? 'done' : ''
        ].filter(Boolean).join(' ');

        div.className = 'rewards-row ticket';
        div.innerHTML = `
            <div class="rewards-column">${reward.name}</div>
            <div class="rewards-column"></div>
            <div class="rewards-column">
                <button
                    class="${classes}"
                    data-program-id="${reward.ProgramId}"
                    data-unique-id="${reward.uniqueKey}"
                    data-mode="${isRedeemed ? 'undo' : 'redeem'}"
                    ${!isRedeemed && !canRedeem ? 'disabled' : ''}
                >
                    ${buttonText}
                </button>
            </div>
        `;

        rewardsList.appendChild(div);
    });

    rewardsList.style.display = 'block';
}

async function redeemReward(e, program, data) {
    e.preventDefault();
    const button = e.target.closest('button');
    if (!button) return;
    if (loyaltyActionInFlight) return;

    loyaltyActionInFlight = true;
    redeemState = 1;
    button.disabled = true;
    button.innerHTML = `<span class="loader"></span>`;
    setLoyaltyActionsBusy(button);

    if (redeemState === 1) {
        const prevButtonContent = e.target.innerHTML;
        e.target.disabled = true;
        e.target.innerHTML= `<span class="loader"></span>`;
        const points = program.points

        const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/redeem';
        const siteId = getCookie('siteIdJs');

        const bodyData = {
            siteId: siteId,
            program: program,
            loyalty: data,
            points: points,
        };

        try {
            const response = await fetch(baseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(bodyData)
            });
            if (!response.ok) {
                const error = await response.json();
                console.error('Server error:', error.message);
                throw new Error(error.message);
            }

            if (response.ok) {
                const data = await response.json();
                console.info(data);
                clearLoyaltyActionsBusy();
                handleSuccessRedeem(data, button);
                redeemState= 0;
                return data;
            }
        } catch (error) {
            console.error('Error:', error);
            redeemState= 0;
            button.disabled = false;
            button.innerHTML = prevButtonContent;
            clearLoyaltyActionsBusy();
            console.error('POST request failed:', error);
        } finally {
            loyaltyActionInFlight = false;
        }
    }
}

async function deleteRedeem(e, uniqueId) {
    e.preventDefault();

    const button = e.target.closest('button');
    if (!button) return;
    if (loyaltyActionInFlight) return;

    loyaltyActionInFlight = true;
    const prevButtonContent = button.innerHTML;
    button.disabled = true;
    button.innerHTML = `<span class="loader"></span>`;
    setLoyaltyActionsBusy(button);

    const baseUrl = '/wp-json/northstaronlineordering/v1/loyalty/undoRedeem';

    const bodyData = {
        programId: button.dataset.programId,
        uniqueKey: uniqueId,
    };

        try {
            const response = await fetch(baseUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(bodyData)
            });

            if (response.ok) {
                const data = await response.json();
                clearLoyaltyActionsBusy();
                handleSuccessUndo(button.dataset.programId, button);
                updateRewardButtons(data);
                return data;
            } else {
                throw new Error(`Error undoing reward redemption: ${response.status}`);
            }
        } catch (error) {
            console.error('Error undoing redemption:', error);
            e.target.disabled = false;
            e.target.innerHTML = prevButtonContent;
            clearLoyaltyActionsBusy();
        } finally {
            loyaltyActionInFlight = false;
        }
}

function handleSuccessRedeem(responseData, button) {
    button.innerHTML = 'Undo';
    button.disabled = false;

    button.dataset.mode = 'undo';
    button.classList.remove('redeem-button');
    button.classList.add('undo', 'done');

    const programId = button.dataset.programId || responseData.programId;
    const uniqueId = button.dataset.uniqueId;
    if (programId && programsById[uniqueId]) {
        programsById[uniqueId].redeemed = true;
    }
    rewardChanged = true;
    updateRewardButtons(responseData);

}

function handleSuccessUndo(programId, button) {
    button.innerHTML = 'Redeem';
    button.disabled = false;

    button.dataset.mode = 'redeem';

    button.classList.remove('undo', 'done');
    button.classList.add('redeem-button');

    const uniqueId = button.dataset.uniqueId;
    if(uniqueId && programsById[uniqueId]) {
        programsById[uniqueId].redeemed = false;
    }
    rewardChanged = true;
}

function handleNoRewards(errorMessage = '') {
    const rewardsList = document.getElementById('rewards-content');
    rewardsList.innerHTML = '';
    const paragraph = document.createElement('p');
    paragraph.textContent = `No rewards available: ${errorMessage}`;
    rewardsList.appendChild(paragraph);
    rewardsList.style.display = 'block';
    resetLoyaltyPhoneNumber();
}

jQuery(document).ready(function($) {
    $(document.body).on('update_checkout', function() {
        $(document).ajaxComplete(function(event, xhr, settings) {
            if (settings.url.indexOf('wc-ajax=update_order_review') > -1) {
                if (loading) {
                    console.log('updating');

                }
            }
        });
    });
});

function getCookie(name) {
  const cookies = document.cookie.split('; ');
  for (const cookie of cookies) {
    const [key, value] = cookie.split('=');
    if (key === name) {
      return decodeURIComponent(value);
    }
  }
  return null;
}

function resetLoyaltyPhoneNumber() {
    sessionStorage.removeItem('guestPhone');
}

function updateRewardButtons(rewardData) {
    if (!rewardData) return;

    const rawBalance = rewardData.Balance ?? rewardData.BalanceAfterUndo;

    if (rawBalance === null) return;
    const balance = Number(rawBalance);
    if(isNaN(balance)) return;

    const buttons = document.querySelectorAll('.reward-button');
    buttons.forEach(button => {
        if (button.dataset.mode === 'redeem') {
            button.disabled = balance <= 0;
        }
    });
}

function handleLoyaltyStateChange()  {
    if(rewardChanged) {
        jQuery(document.body).trigger('update_checkout');
        rewardChanged = false;
        rewardsDoneButton.innerText = rewardButtonTexts.done;
    }

}
document.addEventListener("DOMContentLoaded", () => {
  const stickyItems = document.querySelectorAll("#sticky-category-bar .category-item");
  // let — re-queried after scaffold restore so bounding rects stay accurate
  let sections = document.querySelectorAll(".product-category-section");

  const wrapper = document.querySelector('.products-sections-wrapper');
  const categoryBar = document.querySelector('.category-sections-container');

  // Flag to prevent observer updates during programmatic scroll
  let isScrollingProgrammatically = false;

  // View swap state
  let savedScaffoldHTML = null;
  let savedScrollTop = 0;
  let isSwapping = false;

  const CARD_HEIGHT = 360; // skeleton-thumb (250px) + button (50px) + title/price/padding (~60px)
  const GRID_GAP = 16;
  const SECTION_OVERHEAD = 80; // h2 title + section padding

  function calculatePlaceholderHeights() {
    if (!wrapper) return;

    const containerWidth = wrapper.clientWidth;
    const minCardWidth = containerWidth >= 800 ? 250 : 160;
    const columns = Math.max(1, Math.floor((containerWidth + GRID_GAP) / (minCardWidth + GRID_GAP)));

    sections.forEach(section => {
      if (section.classList.contains('loaded')) return;
      const productCount = parseInt(section.dataset.productCount, 10) || 4;
      const rows = Math.ceil(productCount / columns);
      const height = rows * (CARD_HEIGHT + GRID_GAP) + SECTION_OVERHEAD;
      section.style.minHeight = height + 'px';
    });
  }


  calculatePlaceholderHeights();

  sections.forEach(section => {
    const container = section.querySelector(".products-container");
    if (!container.dataset.page) container.dataset.page = "1";
  });

  // Set the first category as active on load before the observer fires.
  if (stickyItems[0]) stickyItems[0].classList.add('active');


  function updateActiveCategory() {
    if (isScrollingProgrammatically) return;

    const scrollContainer = document.querySelector('.products-sections-wrapper');
    if (!scrollContainer) return;
    const containerTop = scrollContainer.getBoundingClientRect().top;

    // Use live query so restored scaffold nodes are picked up
    const liveSections = scrollContainer.querySelectorAll('.product-category-section');
    let current = null;
    liveSections.forEach(section => {
      const sectionTop = section.getBoundingClientRect().top;
      if (sectionTop <= containerTop + 10) {
        current = section;
      }
    });

    if (!current && liveSections[0]) current = liveSections[0];
    if (!current) return;

    const category = current.dataset.categorySlug;
    stickyItems.forEach(item => {
      const isActive = item.dataset.targetCategory === category;
      item.classList.toggle('active', isActive);
      if (isActive) {
        item.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
      }
    });
  }


  const infiniteObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        const section = entry.target.closest(".product-category-section");
        loadMoreProducts(section);
      }
    });
  }, {
    root: null,
    rootMargin: "800px 0px",
    threshold: 0
  });

  document.querySelectorAll(".infinite-trigger").forEach(t => infiniteObserver.observe(t));

  // Infinite loop: when the user scrolls to the bottom of the scroll
  // container jump back to the first category instantly.
  // The scroll container is .products-sections-wrapper (overflow-y:scroll),
  // not window — so we listen on that element.
  const scrollContainer = document.querySelector('.products-sections-wrapper');
  if (scrollContainer) {
    let loopTriggered = false;

    scrollContainer.addEventListener('scroll', () => {
      // Update the active category tab based on scroll position
      updateActiveCategory();

      // Skip infinite-loop logic while a view swap is active
      if (isScrollingProgrammatically || isSwapping) return;

      const liveSections = scrollContainer.querySelectorAll('.product-category-section');
      if (liveSections.length <= 1) return;

      // Infinite loop: jump back to first section when bottom is reached
      const atBottom = scrollContainer.scrollTop + scrollContainer.clientHeight >= scrollContainer.scrollHeight - 50;
      if (!atBottom || loopTriggered) return;

      loopTriggered = true;
      isScrollingProgrammatically = true;

      stickyItems.forEach(i => i.classList.remove('active'));
      if (stickyItems[0]) {
        stickyItems[0].classList.add('active');
        stickyItems[0].scrollIntoView({ behavior: 'instant', inline: 'center', block: 'nearest' });
      }

      if (liveSections[0]) liveSections[0].scrollIntoView({ behavior: 'instant' });

      setTimeout(() => {
        isScrollingProgrammatically = false;
        loopTriggered = false;
      }, 1000);
    }, { passive: true });
  }

  async function loadMoreProducts(section) {
    const container = section.querySelector(".products-container");
    const trigger = section.querySelector(".infinite-trigger");

    let page = parseInt(container.dataset.page || "1", 10);

    if (container.dataset.loading === "1") return;
    container.dataset.loading = "1";

    const slug = section.dataset.categorySlug;

    const hasRealProducts = container.querySelector("li:not(.skeleton-card)");
    const rect = section.getBoundingClientRect();
    const nearViewport = rect.top < (window.innerHeight + 250); // tweak 250

    if (!hasRealProducts && nearViewport) {
      addSkeleton(container, parseInt(section.dataset.productCount, 10) || 4);
    }

    try {
      // Scope to the active site (emitted on the scroll container) so the
      // endpoint never returns items from another site sharing this category.
      const siteId = document.querySelector(".infinite-scroll-menu-container")?.dataset?.siteId || "";
      const loadMoreUrl = `/wp-json/northstaronlineordering/v1/loadmore?category=${slug}&page=${page}`
        + (siteId ? `&site_id=${encodeURIComponent(siteId)}` : "");
      const response = await fetch(loadMoreUrl);
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const html = await response.text();

      removeSkeleton(container);

      if (html.trim() === "") {
        infiniteObserver.unobserve(trigger);
        return;
      }

      container.insertAdjacentHTML("beforeend", html);
      container.dataset.page = String(page + 1);
      section.classList.add('loaded');

    } catch (error) {
      console.error("Error loading more products:", error);
      removeSkeleton(container);
    } finally {
      container.dataset.loading = "0";
      section.classList.add('loaded');
      section.style.minHeight = '';
    }
  }


  const firstSection = sections[0];
  if (firstSection) {
    loadMoreProducts(firstSection);

    setTimeout(() => {
      const container = firstSection.querySelector(".products-container");
      const hasRealProducts = container.querySelector("li:not(.skeleton-card)");
      if (!hasRealProducts && container.dataset.loading === "1") {
        addSkeleton(container, parseInt(firstSection.dataset.productCount, 10) || 4);
      }
    }, 250);
  }

  stickyItems.forEach(item => {
    item.addEventListener("click", () => {
      const target = document.querySelector(`#section-${item.dataset.targetCategory}`);
      if (target) {
        isScrollingProgrammatically = true;
        stickyItems.forEach(i => i.classList.remove("active"));
        item.classList.add("active");

        const allSections = Array.from(sections);
        const targetIndex = allSections.indexOf(target);
        const scrollCont = document.querySelector('.products-sections-wrapper');


        for (let i = 0; i < targetIndex; i++) {
          const sec = allSections[i];
          if (!sec.classList.contains('loaded')) {
            const productsContainer = sec.querySelector('.products-container');
            if (productsContainer) {
              addSkeleton(productsContainer, parseInt(sec.dataset.productCount, 10) || 4);
              loadMoreProducts(sec);
            }
          }
        }


        let targetScrollTop = 0;
        for (let i = 0; i < targetIndex; i++) {
          targetScrollTop += allSections[i].offsetHeight;
        }

        if (scrollCont) {
          scrollCont.scrollTop = targetScrollTop;
        } else {
          target.scrollIntoView({ behavior: 'instant' });
        }

        // 3. Load the target section so its skeleton appears immediately.
        if (!target.classList.contains('loaded')) {
          loadMoreProducts(target);
        }
        // Re-enable observer after scroll completes
        setTimeout(() => {
          isScrollingProgrammatically = false;
        }, 1000);
      }
    });
  });


  if (wrapper && typeof ResizeObserver !== 'undefined') {
    let resizeDebounce = null;
    const resizeObserver = new ResizeObserver(() => {
      clearTimeout(resizeDebounce);
      resizeDebounce = setTimeout(() => {
        const hasUnloaded = Array.from(sections).some(s => !s.classList.contains('loaded'));
        if (!hasUnloaded) {
          resizeObserver.disconnect();
          return;
        }
        calculatePlaceholderHeights();
      }, 150);
    });
    resizeObserver.observe(wrapper);
  }

  function addSkeleton(container, count = 4) {
    if (container.querySelector(".skeleton-card")) return;

    const skeletonHtml = Array.from({ length: count }).map(() => `
      <li class="product skeleton-card" aria-hidden="true">
        <div class="skeleton-thumb"></div>
        <div class="skeleton-line title"></div>
        <div class="skeleton-line"></div>
        <div class="skeleton-line price"></div>
        <div class="skeleton-button"></div>
      </li>
    `).join("");

    container.insertAdjacentHTML("beforeend", skeletonHtml);
  }

  function removeSkeleton(container) {
    container.querySelectorAll(".skeleton-card").forEach(el => el.remove());
  }

  // ----------------------------------------------------------------
  // Sticky category bar hide / show
  // ----------------------------------------------------------------

  function hideCategoryBar() {
    if (!categoryBar) return;
    categoryBar.classList.remove('bar-showing');
    categoryBar.classList.add('bar-hiding');

    const collapse = () => {
      categoryBar.classList.remove('bar-hiding');
      // display:none is the only bulletproof way to remove layout contribution
      // regardless of margin-top, padding, or breakpoint quirks
      categoryBar.style.display = 'none';
    };

    categoryBar.addEventListener('animationend', function onHidden(e) {
      if (e.target !== categoryBar) return;
      categoryBar.removeEventListener('animationend', onHidden);
      collapse();
    });
    // Fallback for reduced-motion (animationend won't fire)
    setTimeout(() => {
      if (categoryBar.classList.contains('bar-hiding')) collapse();
    }, 220);
  }

  function showCategoryBar() {
    if (!categoryBar) return;
    // Restore display before animating in so the element has layout
    categoryBar.style.display = '';
    categoryBar.classList.remove('bar-hiding');
    categoryBar.classList.add('bar-showing');

    categoryBar.addEventListener('animationend', function onShown(e) {
      if (e.target !== categoryBar) return;
      categoryBar.removeEventListener('animationend', onShown);
      categoryBar.classList.remove('bar-showing');
    });
    setTimeout(() => {
      if (categoryBar.classList.contains('bar-showing')) {
        categoryBar.classList.remove('bar-showing');
      }
    }, 280);
  }

  // ----------------------------------------------------------------
  // Hidden-category view swap
  // ----------------------------------------------------------------

  function scrollToSection(slug) {
    const target = document.querySelector(`#section-${slug}`);
    if (!target || !wrapper) return;
    const allSecs = Array.from(wrapper.querySelectorAll('.product-category-section'));
    const idx = allSecs.indexOf(target);
    let top = 0;
    for (let i = 0; i < idx; i++) top += allSecs[i].offsetHeight;
    wrapper.scrollTop = top;
  }

  // Runs outClass animation, calls onSwap mid-transition, then runs inClass.
  // Works even when animation is disabled (reduced-motion) via setTimeout fallback.
  function animateWrapper(outClass, inClass, onSwap) {
    if (!wrapper) return;

    let swapped = false;
    const doSwap = (e) => {
      if (e && e.target !== wrapper) return; // ignore bubbled animationend from children
      if (swapped) return;
      swapped = true;
      wrapper.removeEventListener('animationend', doSwap);
      wrapper.classList.remove(outClass);
      onSwap();
      wrapper.classList.add(inClass);
    };

    wrapper.classList.add(outClass);
    wrapper.addEventListener('animationend', doSwap);
    // Fallback: fire after out-duration if animationend never fires
    setTimeout(() => doSwap(null), 250);
  }

  async function openCategoryView(slug, name) {
    if (isSwapping || !wrapper) return;
    isSwapping = true;

    savedScaffoldHTML = wrapper.innerHTML;
    savedScrollTop = wrapper.scrollTop;

    hideCategoryBar();

    animateWrapper('swapping-out', 'swapping-in', () => {
      const title = name || slug;

      const header = document.createElement('div');
      header.className = 'category-view-header';

      const backBtn = document.createElement('button');
      backBtn.className = 'category-view-back';
      backBtn.type = 'button';
      backBtn.textContent = '← Back';

      const heading = document.createElement('h2');
      heading.className = 'category-view-title';
      heading.textContent = title;

      header.appendChild(backBtn);
      header.appendChild(heading);

      const list = document.createElement('ul');
      list.className = 'products products-container category-view-products';
      list.dataset.page = '1';

      wrapper.innerHTML = '';
      wrapper.appendChild(header);
      wrapper.appendChild(list);

      // Always start the view from the top
      wrapper.scrollTop = 0;
      fetchCategoryProducts(slug);
    });

    setTimeout(() => { isSwapping = false; }, 800);
  }

  function closeCategoryView() {
    if (isSwapping || !wrapper || savedScaffoldHTML === null) return;
    isSwapping = true;

    const htmlToRestore = savedScaffoldHTML;
    const scrollToRestore = savedScrollTop;

    animateWrapper('swapping-out', 'swapping-in', () => {
      wrapper.innerHTML = htmlToRestore;
      wrapper.scrollTop = scrollToRestore;
      savedScaffoldHTML = null;
      savedScrollTop = 0;

      // Re-query sections so updateActiveCategory and scroll logic see live DOM nodes
      sections = wrapper.querySelectorAll('.product-category-section');

      // Re-attach IntersectionObserver to restored trigger elements
      wrapper.querySelectorAll('.infinite-trigger').forEach(t => infiniteObserver.observe(t));

      // Show the sticky bar
      showCategoryBar();

      // Sync active tab to restored scroll position
      requestAnimationFrame(() => updateActiveCategory());
    });

    setTimeout(() => { isSwapping = false; }, 800);
  }

  async function fetchCategoryProducts(slug) {
    const container = wrapper && wrapper.querySelector('.category-view-products');
    if (!container) return;

    addSkeleton(container, 4);

    try {
      const response = await fetch(`/wp-json/northstaronlineordering/v1/loadmore?category=${slug}&page=1`);
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      const html = await response.text();
      removeSkeleton(container);
      if (html.trim()) {
        container.insertAdjacentHTML('beforeend', html);
      }
    } catch (err) {
      console.error('Error loading category products:', err);
      removeSkeleton(container);
    }
  }

  // Delegated click: intercept _link_to_category product links.
  // Only attach when the infinity scroll container is present — prevents
  // this interceptor from running on other pages (e.g. product detail).
  const menuContainer = document.querySelector('.infinite-scroll-menu-container');
  if (menuContainer) menuContainer.addEventListener('click', (e) => {
    const link = e.target.closest('a[href*="cat_slug="]');
    if (!link) return;

    const url = new URL(link.href, window.location.origin);
    const slug = url.searchParams.get('cat_slug');
    const name = url.searchParams.get('cat_name') || slug;

    if (!slug) return;

    e.preventDefault();

    const sectionExists = !!document.querySelector(`#section-${slug}`);
    if (sectionExists) {
      scrollToSection(slug);
    } else {
      openCategoryView(slug, name);
    }
  });

  // Delegated click: Back button inside view swap
  if (wrapper) {
    wrapper.addEventListener('click', (e) => {
      if (e.target.closest('.category-view-back')) {
        closeCategoryView();
      }
    });
  }

  // URL-on-load: trigger view swap or scroll for ?cat_slug
  const urlParams = new URLSearchParams(window.location.search);
  const urlSlug = urlParams.get('cat_slug');
  const urlName = urlParams.get('cat_name') || urlSlug;
  if (urlSlug) {
    setTimeout(() => {
      const sectionExists = !!document.querySelector(`#section-${urlSlug}`);
      if (sectionExists) {
        scrollToSection(urlSlug);
      } else {
        openCategoryView(urlSlug, urlName);
      }
    }, 300);
  }
});


jQuery( function ( $ ) {
	const cfg   = window.oloTimeSlotsConfig || {};
	const REST  = ( cfg.restUrl || '/wp-json/northstaronlineordering/v1' ).replace( /\/$/, '' );
	const NONCE = cfg.nonce || '';


	let loadCtrl     = null;
	let reserveCtrl  = null;
	let reserveTimer = null;


	function $dateField()    { return $( 'input#olo_slot_date' ); }
	function $displayField() { return $( 'input#olo_slot_date_display' ); }
	function $slotField()    { return $( 'select#olo_time_slot' ); }

	function getSiteId() { return String( $dateField().data( 'site-id' ) || '' ); }
	function getAreaId() { return String( $dateField().data( 'area-id' ) || '' ); }

	// -------------------------------------------------------------------------
	// Notice area
	// -------------------------------------------------------------------------

	function showNotice( msg, type /* 'error'|'info' */ ) {
		var isError  = ( type === 'error' );
		var bgColor  = isError ? '#ffe0e0' : '#e0f0ff';
		var txtColor = isError ? '#c00'    : '#006';
		var border   = isError ? '#c00'    : '#006';

		let $n = $( '#olo-timeslot-notice' );
		if ( ! $n.length ) {
			$n = $( '<p id="olo-timeslot-notice" aria-live="polite" role="alert"></p>' )
				.insertAfter( $slotField().closest( '.form-row' ) );
		}
		$n.css( {
			display:       'block',
			margin:        '8px 0',
			padding:       '10px 14px',
			background:    bgColor,
			color:         txtColor,
			border:        '1px solid ' + border,
			borderRadius:  '3px',
			fontWeight:    'bold',
			fontSize:      '14px',
		} ).text( msg );
	}

	function clearNotice() {
		$( '#olo-timeslot-notice' ).css( 'display', 'none' ).text( '' );
	}

	// -------------------------------------------------------------------------
	// Utilities
	// -------------------------------------------------------------------------

	function formatDate( yyyyMmDd ) {
		if ( ! yyyyMmDd ) { return ''; }
		const parts = yyyyMmDd.split( '-' ).map( Number );
		return new Date( parts[ 0 ], parts[ 1 ] - 1, parts[ 2 ] )
			.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
	}

	/**
	 * Parse "{timeSlotId}|{slotTime}" — mirrors TimeSlotValueParser::parse().
	 *
	 * @param  {string} raw
	 * @return {{timeSlotId: string, slotTime: string}|null}
	 */
	function parseSlotValue( raw ) {
		if ( ! raw ) { return null; }
		const idx = raw.indexOf( '|' );
		if ( idx < 0 ) { return null; }
		const timeSlotId = raw.substring( 0, idx );
		const slotTime   = raw.substring( idx + 1 );
		if ( ! timeSlotId || ! slotTime ) { return null; }
		return { timeSlotId, slotTime };
	}

	function escAttr( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' )
			.replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	}



	/** Shared handler — re-queries value from the live element each time. */
	function handleSlotChange() {
		var raw     = $slotField().val();
		var dateVal = $dateField().val();
		if ( raw && dateVal ) {
			scheduleReserve( dateVal, raw );
		}
	}

	function bindSlotSelect() {
		// 1. Document-level delegation — survives DOM replacement and works
		//    when select2:select bubbles (Select2 / SelectWoo 4.x).
		$( document )
			.off( 'change.oloSlotDel select2:select.oloSlotDel', 'select#olo_time_slot' )
			.on(  'change.oloSlotDel select2:select.oloSlotDel', 'select#olo_time_slot', handleSlotChange );

		// 2. Direct binding on the element — catches native change events even
		//    when SelectWoo prevents bubbling.
		var $s = $slotField();
		if ( $s.length ) {
			$s.off( '.oloSlotDir' );
			$s.on( 'change.oloSlotDir select2:select.oloSlotDir', handleSlotChange );
		}
	}



	function restoreSessionSlot( loadedDate ) {
		if ( ! cfg.selectedSlot ) { return; }
		if ( cfg.selectedDate && cfg.selectedDate !== loadedDate ) { return; }

		var $s   = $slotField();
		var safe = cfg.selectedSlot.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' );

		if ( $s.find( 'option[value="' + safe + '"]' ).length ) {
			$s.val( cfg.selectedSlot );

			// Tell SelectWoo to update its display without triggering our handler.
			if ( $s.data( 'select2' ) ) {
				$s.trigger( 'change.select2' );
			}
		}

		// Clear so a manual date change never restores the stale slot.
		cfg.selectedSlot = '';
		cfg.selectedDate = '';
	}

	// -------------------------------------------------------------------------
	// Load available slots
	// -------------------------------------------------------------------------

	async function loadSlots( keepNotice ) {
		const dateVal = $dateField().val();
		if ( ! dateVal ) { return; }

		$displayField().val( formatDate( dateVal ) );
		if ( ! keepNotice ) { clearNotice(); }

		if ( loadCtrl ) { loadCtrl.abort(); }
		loadCtrl = new AbortController();

		$slotField().prop( 'disabled', true ).html( '<option value="">Loading\u2026</option>' );

		const params = new URLSearchParams( {
			siteId: getSiteId(),
			areaId: getAreaId(),
			date:   dateVal,
		} );

		try {
			const res  = await fetch( REST + '/timeslots?' + params.toString(), {
				signal:  loadCtrl.signal,
				headers: { Accept: 'application/json' },
			} );
			const data = await res.json();

			if ( ! res.ok || ! data.success ) {
				$slotField().html( '<option value="">No time slots available</option>' ).prop( 'disabled', false );
				bindSlotSelect();
				return;
			}

			const options = data.options || {};
			let html = '';
			Object.entries( options ).forEach( ( [ val, label ] ) => {
				html += '<option value="' + escAttr( val ) + '">' + escHtml( String( label ) ) + '</option>';
			} );

			$slotField()
				.html( html || '<option value="">No time slots available</option>' )
				.prop( 'disabled', false );

			restoreSessionSlot( dateVal );

			// Re-bind directly to the freshly-populated select element.
			bindSlotSelect();

		} catch ( err ) {
			if ( err.name === 'AbortError' ) { return; }
			$slotField().html( '<option value="">Error loading time slots</option>' ).prop( 'disabled', false );
			bindSlotSelect();
		}
	}

	// -------------------------------------------------------------------------
	// Reserve selected slot
	// -------------------------------------------------------------------------

	async function reserveSlot( dateVal, raw ) {
		const parsed = parseSlotValue( raw );
		if ( ! parsed ) { return; }

		if ( reserveCtrl ) { reserveCtrl.abort(); }
		reserveCtrl = new AbortController();

		clearNotice();
		$slotField().prop( 'disabled', true );

		try {
			const res = await fetch( REST + '/timeslots/reserve', {
				method:  'POST',
				signal:  reserveCtrl.signal,
				headers: {
					'Content-Type': 'application/json',
					'Accept':       'application/json',
					'X-WP-Nonce':   NONCE,
				},
				body: JSON.stringify( {
					date:       dateVal,
					timeSlotId: parsed.timeSlotId,
					slotTime:   parsed.slotTime,
					siteId:     getSiteId(),
				} ),
			} );

			const data = await res.json();

			if ( ! res.ok || ! data.success ) {
				console.error( '[olo_timeslots] reserve failed', res.status, data );
				showNotice(
					( data && data.message ) || 'This time slot is no longer available. Please choose another.',
					'error'
				);
				$slotField().val( '' );
				if ( $slotField().data( 'select2' ) ) { $slotField().trigger( 'change' ); }
				loadSlots( true );
			} else {
				clearNotice();
			}

		} catch ( err ) {
			if ( err.name === 'AbortError' ) { return; }
			showNotice( 'Unable to reserve time slot. Please try again.', 'error' );
			$slotField().val( '' );
			if ( $slotField().data( 'select2' ) ) { $slotField().trigger( 'change' ); }
			loadSlots( true );
		} finally {
			$slotField().prop( 'disabled', false );
		}
	}

	/**
	 * Debounce rapid changes (keyboard navigation) so only one reserve request
	 * fires 400 ms after the last change.
	 */
	function scheduleReserve( dateVal, raw ) {
		clearTimeout( reserveTimer );
		reserveTimer = setTimeout( function () {
			reserveSlot( dateVal, raw );
		}, 400 );
	}

	// -------------------------------------------------------------------------
	// Event binding
	// -------------------------------------------------------------------------

	function bindEvents() {
		// Date field: delegation is fine — it's a plain <input type="date">.
		$( document )
			.off( 'change.oloDate', 'input#olo_slot_date' )
			.on(  'change.oloDate', 'input#olo_slot_date', function () {
				// Changing date invalidates any prior reservation.
				clearTimeout( reserveTimer );
				if ( reserveCtrl ) { reserveCtrl.abort(); }
				$slotField().val( '' );
				loadSlots();
			} );
			console.log( 'bindEvents' );

		// Slot field: bind directly to the element (not delegated) to work
		// correctly with WooCommerce's Select2 integration.
		bindSlotSelect();
	}

	// After WooCommerce replaces checkout fragments, rebind everything.
	$( document.body ).on( 'updated_checkout', function () {
		bindEvents();
	} );

	// Initial setup.
	console.log( 'olo_timeslots.js' );
	bindEvents();
	if ( $dateField().length ) {
		loadSlots();
	}
} );
