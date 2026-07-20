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
