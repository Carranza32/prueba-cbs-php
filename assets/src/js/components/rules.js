//**  Component Functionality **//

/**
 * Escapes a string for safe use inside HTML double-quoted attribute values.
 * Prevents category names like "MILKS" (with literal quotes) from breaking
 * the HTML attribute boundary — the browser will decode the entities back
 * to the original characters when reading the attribute.
 *
 * @param {string} str
 * @returns {string}
 */
function escapeHtmlAttr(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

function selectposition(e){
    let event = window.event || event;
    event.stopPropagation();

    let componentId = jQuery(e).data('componentid');
    let position = jQuery(e).data('position') === "whole" ? "" : jQuery(e).data('position');
    jQuery('#' + componentId).data('position' , position );
    jQuery('#' + componentId).attr('data-position', position);
    jQuery('#' + componentId).data('action' , "position");
    jQuery('#' + componentId).find('.position-container .position-selector').removeClass('active');
    jQuery(e).toggleClass('active');

    let customEvent = jQuery.Event('selectposition',{elementSelected: '#' + componentId});
    jQuery(document).trigger(customEvent);
}

function removeitems(e){
    let event = window.event || event;
    event.stopPropagation();
    // The minus icon itself has data-componentid; we need the outer component container
    // that contains category/price/rule/etc. In OLO it's a <div>, in Kiosk it may be <a>.
    let componentElement = jQuery(e).closest('[data-componentid][data-category]');
    if (!componentElement || componentElement.length === 0) {
        console.warn("removeitems: Could not find component element.");
        return;
    }

    let currentQuantity = componentElement.data('quantity');
    let newQuantity = currentQuantity - 1;

    if(newQuantity === 0){
        componentElement.find('.position-container').html("");
        componentElement.removeClass('active');
        componentElement.find('.item-main-container .qty-controls-container').html("");
    }

    componentId= jQuery(e).data('componentid');
    componentElement.attr('data-quantity', newQuantity);
    componentElement.data('quantity', newQuantity);
    jQuery('#'+ componentId).find('.component-qty').html(newQuantity);

    var componentprice = jQuery('#'+ componentId).data('price');
    var containerTotal = jQuery(".total-price-container").data("price");
    var componentstotal = jQuery(".total-price-container").data("componentstotalamount");

    if(componentstotal !== undefined){

        var currenttotal = parseFloat(containerTotal) - parseFloat(componentprice);
        var currentcomponents = parseFloat(componentstotal) - parseFloat(componentprice);

        UpdateTotalContainer(componentprice, currenttotal, currentcomponents);
    }

    catid = jQuery('#'+ componentId).data('catid');
    items = jQuery(".active.component-item-" + catid);
    totalItems = 0
    jQuery.each(items , function (key , value ){
        totalItems += parseFloat(value.attributes['data-quantity'].value);
    })
    componentRule = jQuery('#'+ componentId).data('rule');
    rules = rulesGlobal[componentRule];

    if (totalItems < rules.MaxAllowed) {
        jQuery("#"+catid + " .component-warning").html("");
        jQuery(".compo-"+catid+".warning-container").removeClass('active');
        jQuery("#add-to-cart-button").removeClass("disabled");
    }
    if(rules.MinRequired){
        if(rules.MinRequired > totalItems){
            jQuery("#"+catid + " .component-warning").html("(Please Select at least " + rules.MinRequired + " item(s))");
            jQuery(".compo-"+catid+".warning-container").addClass('active');
            jQuery("#add-to-cart-button").addClass("disabled");
        }
    }

    let customEvent = jQuery.Event('removeitems',{elementRemoved: componentElement});
    jQuery(document).trigger(customEvent);

}

//Adds the quantity of components to the total.
function addItemComponent(e, calltoSubmit, event){
    if (event.target.type === 'checkbox' || event.target.tagName.toLowerCase() === 'label')  {
        return;
    }


    let items, itemslast, totalItems, totalItemslast;
    let componentRule = jQuery(e).data('rule');
    let rules = rulesGlobal[componentRule];
    let id = jQuery(e).attr('id');
    let catid = jQuery(e).data('catid');
    let qty = jQuery(e).data('quantity');
    let maxUnique = jQuery(e).data('maxunique');
    let action = jQuery(e).data('action');

    // A component without the active class is not actually selected, no matter what
    // data-quantity says. Edit-mode rendering (and any future path) can leave a stale,
    // non-zero quantity on an inactive item; trusting it here misroutes the click into
    // an "already selected, now adjusting" branch instead of a fresh selection.
    if (!jQuery(e).hasClass('active')) {
        qty = 0;
        jQuery(e).data('quantity', 0);
        jQuery(e).attr('data-quantity', 0);
    }

    let totalcontainerinsert = jQuery(".total-price-container");
    const componentprice = jQuery(e).data('price');
    const {totalwithcomponent,componentstotalcurrent, totalwithcomponentminusresult, componentMinusResult} = CalculateTotalPrice(componentprice);

    items = jQuery(".active.component-item-" + catid);
    totalItems = 0
    jQuery.each(items , function (key , value ){
        totalItems += parseFloat(value.attributes['data-quantity'].value);
    })

    let wasInactive = qty === 0;
    if(maxUnique === 1){
        if(qty === 0){
            if(rules.MaxAllowed === 1){

                jQuery(".active.component-item-" + catid).each(function(){
                    let activeComponentId = jQuery(this).attr('id');
                    jQuery(this).removeClass('active');
                    jQuery(this).find('.item-main-container .qty-controls-container').html("");
                    jQuery(this).find('.position-container').html("");
                    jQuery(this).data('action', "");
                    jQuery(this).attr('data-quantity', 0);
                    jQuery(this).data('quantity', 0);
                    let customEvent = jQuery.Event('removeitems',{elementRemoved: jQuery(this)});
                    jQuery(document).trigger(customEvent);
                    UpdateTotalContainer(componentprice, totalwithcomponentminusresult, componentMinusResult);
                    jQuery(this).attr('aria-selected', 'false');
                });
                totalItems = 0;
            }
            if (totalItems < rules.MaxAllowed) {
                qty = qty + 1;
                jQuery(e).attr('data-quantity', qty);
                jQuery(e).data('quantity', qty);
                jQuery(e).addClass('active');
                jQuery(e).attr('aria-selected', 'true');
                controls = `<div class="quantity-controls"> <i class="fa-solid fa-circle-check"></i> </div>`
                jQuery(e).find('.item-main-container .qty-controls-container').html(controls);
                positionControls = `
                <button class="position-selector"
                        type="button"
                        data-componentid="${id}"
                        onClick="selectposition(this)"
                        data-position="left"> Left </button> 
                <button class="position-selector active"
                        type="button"
                        data-componentid="${id}"
                        onClick="selectposition(this)"
                        data-position="whole"> Whole </button> 
                <button class="position-selector"
                        type="button"
                        data-componentid="${id}"
                        onClick="selectposition(this)"
                        data-position="right"> Right </button>
            `;
                jQuery(e).find('.position-container').html(positionControls);

                UpdateTotalContainer(componentprice, totalwithcomponent, componentstotalcurrent);
                let customEvent = jQuery.Event('addItemComponent',{elementAdded: e});
                jQuery(document).trigger(customEvent);

                if(wasInactive) {
                    try {
                        const componentData = JSON.parse(jQuery(e).attr('data-servingoptions') || '{}');
                        const hasServingOptions = componentData && Object.keys(componentData).length > 0;
                        if(hasServingOptions) {
                            createComponentServingOptions(componentData, olo_vars_object.symbol_product, e);
                        }
                    } catch (error) {
                        console.error('Error parsing serving options:', error);
                    }
                }
            }
            else{
                jQuery("#"+catid + " .component-warning").html("(just " + rules.MaxAllowed + " items allowed)");
                jQuery(".compo-"+catid+".warning-container").addClass('active');
                jQuery(e).attr('aria-selected', 'false');
            }
        }
        else{
            qty = qty - 1;
            (qty === 0) ? jQuery(e).find('.position-container').html("") : null;
            jQuery(e).removeClass('active');
            jQuery(e).attr('aria-selected', 'false');
            jQuery(e).find('.item-main-container .qty-controls-container').html("");
            jQuery(e).data('action', "");
            jQuery(e).attr('data-quantity', qty);
            jQuery(e).data('quantity', qty);

            let customEvent = jQuery.Event('removeitems',{elementRemoved: jQuery(e)});
            jQuery(document).trigger(customEvent);
            UpdateTotalContainer(componentprice, totalwithcomponentminusresult, componentMinusResult);
            totalItems = 0
            jQuery.each(items , function (key , value ){
                totalItems += parseFloat(value.attributes['data-quantity'].value);
            })


            if (totalItems < rules.MaxAllowed) {
                jQuery("#"+catid + " .component-warning").html("");
                jQuery(".compo-"+catid+".warning-container").removeClass('active');
                jQuery("#add-to-cart-button").removeClass("disabled");

            }
            if(rules.MinRequired){
                if(rules.MinRequired > totalItems){
                    jQuery("#"+catid + " .component-warning").html("(Please Select at least " + rules.MinRequired + " item(s))");
                    jQuery(".compo-"+catid+".warning-container").addClass('active');
                    jQuery("#add-to-cart-button").addClass("disabled");

                }

            }
        }
    }
    else{
        if(qty === 0){
            if(action != "subtract" && action != "position"){
                if (totalItems < rules.MaxAllowed) {
                    qty = qty + 1;
                    jQuery(e).attr('data-quantity', qty);
                    jQuery(e).data('quantity', qty);
                    jQuery(e).addClass('active');
                    controls = `
                        <div class="quantity-controls">
                            <i  class="fa-solid fa-square-minus"
                                data-componentid="${id}"
                                onclick="removeitems(this)"></i> 
                            <div class="component-qty "> ${qty} </div> 
                            <i class="fa-solid fa-square-plus" "=""></i> </div>`;
                    positionControls = `
                        <button class="position-selector"
                                type="button"
                                data-componentid="${id}"
                                onClick="selectposition(this)"
                                data-position="left"> Left </button>
                        <button class="position-selector active"
                                type="button"
                                data-componentid="${id}"
                                onClick="selectposition(this)"
                                data-position="whole"> Whole </button> 
                        <button class="position-selector"
                                type="button"
                                data-componentid="${id}" 
                                onClick="selectposition(this)" 
                                data-position="right"> Right </button>
                    `;
                    jQuery(e).find('.position-container').html(positionControls);
                    jQuery(e).find('.item-main-container .qty-controls-container').html(controls);
                    UpdateTotalContainer(componentprice, totalwithcomponent, componentstotalcurrent);
                    let customEvent = jQuery.Event('addItemComponent',{elementAdded: e});
                    jQuery(document).trigger(customEvent);
                }
                else{
                    jQuery("#"+catid + " .component-warning").html("(just " + rules.MaxAllowed + " items allowed)");
                    jQuery(".compo-"+catid+".warning-container").addClass('active');
                }
            }else{
                jQuery(e).removeClass('active');
                jQuery(e).find('.item-main-container .qty-controls-container').html("");
                jQuery(e).find('.position-container').html("");
                jQuery(e).data('action', "");
            }
        }else if(qty >= 1){
            // if action is add items
            if(action != "subtract" && action != "position"){
                qty = qty + 1;

                // if max unique is set
                if(maxUnique > 0){
                    if(qty >= maxUnique){
                        jQuery(e).find('.quantity-controls .fa-circle-plus').addClass('disabled')
                    }

                    if(qty > maxUnique){
                        return false;
                    }
                }

                if (totalItems < rules.MaxAllowed) {
                    jQuery(e).attr('data-quantity', qty);
                    jQuery(e).data('quantity', qty);
                    jQuery(e).find('.component-qty').html(qty);
                    UpdateTotalContainer(componentprice, totalwithcomponent, componentstotalcurrent);
                    let customEvent = jQuery.Event('addItemComponent',{elementAdded: e});
                    jQuery(document).trigger(customEvent);
                }
                else{
                    jQuery("#"+catid + " .component-warning").html("(just " + rules.MaxAllowed + " items allowed)");
                    jQuery(".compo-"+catid+".warning-container").addClass('active');

                }
            }else{
                jQuery(e).data('action', "");
            }
        }
    }

    itemslast= jQuery(".active.component-item-" + catid);
    totalItemslast = 0
    jQuery.each(itemslast , function (key , value ){
        totalItemslast += parseFloat(value.attributes['data-quantity'].value);
    })
    if (rules.MinRequired != 0) {
        if (totalItemslast > rules.MinRequired) {
        }else if(totalItemslast === rules.MinRequired){
            infoitem = jQuery("#"+catid + " .component-warning").text();
            if(infoitem === "(Please Select at least " + rules.MinRequired + " item(s))"){
                jQuery("#"+catid + " .component-warning").html("");
                jQuery(".compo-"+catid+".warning-container").removeClass('active');
                jQuery("#add-to-cart-button").removeClass("disabled");

            }
            MoveToNextRequiredComponent(calltoSubmit);
        }
    }

}

function MoveToNextRequiredComponent(callToSubmit) {
    if(!callToSubmit){ return}
    const formCart = jQuery("form.cart");
    const addToCartButton = jQuery("#add-to-cart-button");
    if(formCart.length > 0){
        jQuery("form.cart").trigger("submit",{ preventDefault: true });
    }
    if(addToCartButton.length > 0){
        addItemToCart(true);
    }
}

function CalculateTotalPrice(componentprice) {
    let componentstotalcurrent = '';
    let totalwithcomponent = '';
    let componentMinusResult = '';
    let totalwithcomponentminusresult = '';

    let servingoptionstotal = jQuery(".total-price-container").attr("data-servingtotalamount");
    let componentstotal = jQuery(".total-price-container").data("componentstotalamount");

    if (componentstotal !== undefined) {
        componentstotalcurrent = parseFloat(componentstotal) + parseFloat(componentprice);
        componentMinusResult = parseFloat(componentstotal) - parseFloat(componentprice);
    } else {
        componentstotalcurrent = componentprice;
    }
    if (servingoptionstotal !== undefined) {
        totalwithcomponent = parseFloat(servingoptionstotal) + parseFloat(componentstotalcurrent);
        totalwithcomponentminusresult = parseFloat(servingoptionstotal) + parseFloat(componentMinusResult);
    } else {
        totalwithcomponent = componentstotalcurrent;
        totalwithcomponentminusresult = parseFloat(componentMinusResult);
    }

    return {totalwithcomponent, componentstotalcurrent, totalwithcomponentminusresult, componentMinusResult};
}

function UpdateTotalContainer(componentprice,totalwithcomponent, componentstotalcurrent) {
    const totalcontainerinsert = jQuery(".total-price-container");
    if (componentprice != 0) {
        const totalWithComponentRounded = parseFloat(totalwithcomponent).toFixed(2);
        totalcontainerinsert.data('price', totalWithComponentRounded);
        totalcontainerinsert.attr('data-price', totalWithComponentRounded);
        totalcontainerinsert.text('$' + totalWithComponentRounded);
        totalcontainerinsert.data('componentstotalamount', parseFloat(componentstotalcurrent).toFixed(2));
    }
}

function validateMinRequiredServingOptions(){
    const rules = ServingOptionsRules.getInstance();
    const dialog = document.querySelector('dialog.serving-options-dialog[open]');
    const container = dialog || document.querySelector('#servingoptions_section');

    if (!container) {
        return true; // no serving-option UI on this product → nothing to enforce
    }

    let isValid = true;

    // Validate EVERY serving-option category rendered in this container — not only the ones that
    // already have a selection — so a required category with zero selections is still enforced.
    container.querySelectorAll('.serving-category[data-categoryid]').forEach(function (section) {
        const categoryId  = section.getAttribute('data-categoryid');
        const rule        = rules.getRule(categoryId) || {};
        const minRequired = rule.MinRequired || 0;
        if (minRequired <= 0) {
            return;
        }
        const selectedCount = container.querySelectorAll(
            `input[type="checkbox"][data-categoryid="${categoryId}"]:checked`
        ).length;
        const warningContainer = container.querySelector(`[data-warning-for="${categoryId}"]`);
        if (selectedCount < minRequired) {
            isValid = false;
            if (warningContainer) {
                showErrorSummary(warningContainer, `Please select at least ${minRequired} serving option(s).`);
                warningContainer.classList.add('active');
            }
        } else if (warningContainer) {
            // Clear a previously shown required-warning once the category is satisfied.
            warningContainer.textContent = '';
            warningContainer.classList.remove('active');
        }
    });

    return isValid;
}

//Checks if the rules are valid for the selected components on the click event.
function validateComponentSelection(data ,prodid){
    let controlflag = true;
    const defaultItemsPerCategory = {};
    const minRequiredPerCategory = {};
    const unfulfilledForScrolling = [];

    for (const category in data) {

        const categoryData = data[category];
        let defaultItemsCount = 0;
        let rulemincount = 0;
        const rules = categoryData.info.rules;

        if (rules) {
            for (const ruleId in rules) {
                const rule = rules[ruleId];
                rulemincount += rule.MinRequired || 0;
            }
        }

        for (const item in categoryData.items) {
            const itemData = categoryData.items[item];
            if (itemData.isDefault === "1") {
                defaultItemsCount++;
            }
        }

        let component_quantity = 0;

        jQuery("#compo-" + categoryData.info.componentCatId + "-" + prodid).each(function (event) {
            // Count only active component items — NOT the default's active ".position-selector"
            // (Whole/Left/Right) which carries no data-quantity and would turn the sum into NaN,
            // making "component_quantity < rulemincount" false and silently skip the requirement.
            jQuery(this).find('.active[data-quantity]').each(function (e) {
                const quantity = parseFloat(jQuery(this).data("quantity")) || 0;
                component_quantity += quantity;
            });
        });
        if (component_quantity < rulemincount) {
            controlflag = false;
            jQuery("#" + categoryData.info.componentCatId + "-" + prodid + " .component-warning").html("(Please Select at least " + rulemincount + " item(s))");
            jQuery(".compo-" + categoryData.info.componentCatId + "-" + prodid + ".warning-container").addClass('active');
            const componentDivId = `#${categoryData.info.componentCatId}-${prodid}`;
            unfulfilledForScrolling.push(componentDivId);
        }
        defaultItemsPerCategory[categoryData.info.componentCatId] = defaultItemsCount;
        minRequiredPerCategory[categoryData.info.componentCatId] = rulemincount;
    }
    return {controlflag, unfulfilledForScrolling};
}

/**
 * Build the message shown when a component's serving-option category does not meet MinRequired.
 * Mirrors the wording defined in OE-26471.
 *
 * @param {number} minRequired             Minimum number of serving options required.
 * @param {string} servingOptionCategory   Name of the component serving-option category.
 * @param {string} componentName           Name of the component the category belongs to.
 * @returns {string}
 */
function componentServingOptionMinRequiredMessage(minRequired, servingOptionCategory, componentName) {
    return `Select at least ${minRequired} ${minRequired > 1 ? 'items' : 'item'} from ${servingOptionCategory} for ${componentName}`;
}

/**
 * Validate that every selected component meets the MinRequired rule of each of its
 * serving-option categories (OE-26471).
 *
 * This is independent of ITEM serving options (validateMinRequiredServingOptions) — it only
 * inspects COMPONENT serving options. The component serving-option dialog is rebuilt on open
 * and removed from the DOM when closed, so this reads the live selection state from
 * globalSelectedComponents (the same source used to build the cart) instead of scanning the DOM.
 *
 * @returns {{controlflag: boolean, unfulfilledForScrolling: string[]}}
 */
function validateComponentServingOptions() {
    let controlflag = true;
    const unfulfilledForScrolling = [];

    for (const category in globalSelectedComponents) {
        const components = globalSelectedComponents[category] || [];

        components.forEach(function (component) {
            const servingOptions = component.servingoptions;
            if (!servingOptions || typeof servingOptions !== 'object') {
                return;
            }
            const selectedIds = component.selectedservingoptions || [];
            const componentName = component.component || component.compname || '';

            Object.entries(servingOptions).forEach(function ([servingOptionCategory, optionCategory]) {
                const info = optionCategory.info || {};
                const optionCategoryId = info.categoryId;
                const rule = (info.rules && info.rules[optionCategoryId]) || {};
                const minRequired = rule.MinRequired || 0;
                if (minRequired <= 0) {
                    return;
                }

                const idsInCategory = Object.values(optionCategory.items || {}).map(item => item.servingOptionId);
                const selectedCount = selectedIds.filter(id => idsInCategory.includes(id)).length;

                if (selectedCount < minRequired) {
                    controlflag = false;
                    const message = componentServingOptionMinRequiredMessage(minRequired, servingOptionCategory, componentName);
                    // component.catid is "${componentCatId}-${productId}" — the same id used by
                    // validateComponentSelection to scroll/warn at the component-category level.
                    const catId = component.catid;
                    if (catId) {
                        jQuery(`#${catId} .component-warning`).html(`(${message})`);
                        jQuery(`.compo-${catId}.warning-container`).addClass('active');
                        unfulfilledForScrolling.push(`#${catId}`);
                    }
                }
            });
        });
    }

    return {controlflag, unfulfilledForScrolling};
}

/**
 * Calculate components prices and totals
 */

function getActiveDefaultComponents()
{
    let activeComponents = Array.from(document.querySelectorAll('.components-items-container .active'));

    // Rule-based pricing (FreeUpTo, FreeAfter, ...) is ordinal: which instance falls outside the
    // free window depends on the order components sit in each category array here — the normal
    // add-to-cart flow builds that order by click sequence (addItemComponent pushes). The DOM,
    // however, always lists components in fixed catalog order, so in edit mode (all selections are
    // pre-active on load, no clicks) this scan would seed the wrong order and mis-apply the rule.
    // northstarCartEdit.components preserves the original selection order (it round-trips from the
    // selComponents payload through the product_component_id cart-item meta), so reorder the active
    // nodes to match it before seeding — reproducing the same order the original add-to-cart built.
    const editModeData = getCartEditModeData();
    if (editModeData && Array.isArray(editModeData.components)) {
        const orderIndex = new Map();
        editModeData.components.forEach((component, index) => {
            orderIndex.set(String(component.componentid), index);
        });
        const rankOf = element => {
            const id = String(jQuery(element).data('componentid'));
            return orderIndex.has(id) ? orderIndex.get(id) : Number.MAX_SAFE_INTEGER;
        };
        activeComponents.sort((a, b) => rankOf(a) - rankOf(b));
    }

    let selectedComponents = [];
    // for each active component get the price and quantity and sum it
    activeComponents.forEach(function (element) {
        let componentData = jQuery(element).data()
        let quantity = parseInt(componentData.quantity);
        for (let i = 1; i <= quantity; i++) {
            selectedComponents.push(prepareDataOfComponent({...componentData, quantity: i}));
        }    });

    let components =  groupByCategory(selectedComponents);

    return calculatePriceOfEachComponent(components);
}

function groupByCategory(components)
{
    return components.reduce((group, item) => {

        const {category} = item;

        if (!group[category]) {
            group[category] = [];
        }

        group[category].push(item);
        return group;
    }, {});
}

function calculatePriceOfEachComponent(componentsCategory)
{
    for (let category in componentsCategory) {

        let categoryComponents = componentsCategory[category];

        let componentQuantity = {};

        // Count quantity of each component
        categoryComponents.forEach(component => {
            let componentId = component["componentid"];
            componentQuantity[componentId] = (componentQuantity[componentId] || 0) + component["quantity"];
        });

        // Track instance count per component (how many times each specific componentId has appeared)
        let componentInstanceCount = {};

        // Track ordinal rank among all default components in this category — increments once per
        // default component encountered, regardless of componentId. Used for FirstDefaultComponentsLevelsFree.
        let defaultOrderCount = 0;

        componentsCategory[category] = categoryComponents.map((component, index) => {
            let quantity = index + 1;
            let componentIndex = findFirstIndexOfComponent(categoryComponents, component.componentid);

            // Calculate the instance number for this specific component (1st, 2nd, 3rd time it appears)
            let componentId = component.componentid;
            componentInstanceCount[componentId] = (componentInstanceCount[componentId] || 0) + 1;
            let instanceNumber = componentInstanceCount[componentId];

            if (component.isdefault) {
                defaultOrderCount++;
            }


            let newPrice = calculatePriceBaseOnRules({
                quantity,
                price: component.originalPrice,
                rule: component.rules,
                isDefault: component.isdefault,
                firstIndex: componentIndex,
                instanceNumber,
                defaultOrderCount
            });

            const servingOptionPrice = calculateServingOptionsPrice(component);
            if(servingOptionPrice){
                newPrice = parseFloat(newPrice) + parseFloat(servingOptionPrice);
            }

            return {...component, newPrice};
        });
    }

    return componentsCategory;
}

function findFirstIndexOfComponent(components, componentId) {
    for (let i = 0; i < components.length; i++) {
        if (components[i].componentid === componentId) {
            return i;
        }
    }
    return -1;
}

function prepareDataOfComponent(componentData)
{
    const price = getComponentPriceByLayer(componentData);
    let rules = rulesGlobal[componentData.rule];
    return {...componentData, quantity: 1, originalPrice:price, rules};
}

/**
 * Calculate component price based on pricing rules
 * @param {Object} params - The parameters object
 * @param {number} params.quantity - Position in the category (1-based)
 * @param {number} params.price - Original price of the component
 * @param {Object} params.rule - The pricing rule object
 * @param {boolean} params.isDefault - Whether the component is a default selection
 * @param {number} params.firstIndex - First index where this component appears (0-based)
 * @param {number} params.instanceNumber - Instance number of this specific component (1-based)
 * @param {number} params.defaultOrderCount - Ordinal rank of this component among all defaults in the category (1-based)
 * @returns {number} The calculated price (0 if free, otherwise original price)
 */
function calculatePriceBaseOnRules({ quantity, price, rule, isDefault = false, firstIndex: _firstIndex = 0, instanceNumber: _instanceNumber = 1, defaultOrderCount = 1 }) {
    // DefaultComponentsAreFree: defaults are free at any quantity/position — independent of
    // (and never combined with) FirstDefaultComponentsLevelsFree, per ECM mutual exclusivity.
    if (rule?.DefaultComponentsAreFree && isDefault) {
        return 0;
    }

    // FreeUpTo is positional: first N components in the category are free, default or not.
    if (rule?.FreeUpTo && quantity <= rule.FreeUpTo) {
        return 0;
    }

    // If FirstDefaultComponentsLevelsFree is set, the first N default components (by ordinal rank across
    // the category) are free. Uses defaultOrderCount — not instanceNumber — because the rule applies
    // to the Nth default in the category, not to repeated selections of the same component.
    if (rule?.FirstDefaultComponentsLevelsFree && isDefault && defaultOrderCount <= rule.FirstDefaultComponentsLevelsFree) {
        return 0;
    }

    // If FreeAfter is set and quantity exceeds the threshold
    if (rule?.FreeAfter && quantity > rule.FreeAfter) {
        return 0;
    }

    return price;
}

function calculateServingOptionsPrice(component) {
    const selectedServingOptions = component.selectedservingoptions || [];
    const servingOptions = Object.values(component.servingoptions)
    .map(option => Object.values(option.items))
    .flat();
    const servingOptionsPrice = servingOptions.reduce((total, item) => {

        if(selectedServingOptions.includes(item.servingOptionId)) {
            const price = parseFloat(item.servingOptionPrice) || 0;
            return total + price;
        }
        return total;
    }, 0);

    return servingOptionsPrice;
}

function calculateTotal(selectedComponents) {
    let total_components = 0;
    let components  = calculatePriceOfEachComponent(selectedComponents);
    for (let category in components) {
        let categoryComponents = components[category];
        categoryComponents.forEach(component => {
            let price = parseFloat(isNaN(component.newPrice) ? 0.0 : component.newPrice)
            total_components += price;
        });
    }

    return total_components;
}


function getRulesLabel(rule){
    let
        freeUpTo,
        freeAfter,
        minRequired,
        maxUnique,
        maxAllowed  = null;
    let label = '';


    if(rule?.FreeUpTo){
        // if freeUpTo is equals to 1 then the label should be "First component is free"
        if(rule.FreeUpTo === 1){
            freeUpTo = `First component is free`;
        }else {
            freeUpTo = `First ${rule.FreeUpTo} components are free`;
        }
        label += `<li class="rule-label">${freeUpTo}</li>`;

    }
    if(rule?.FreeAfter){
        freeAfter = `Components after ${rule.FreeAfter} are free `;
        label += `<li class="rule-label">${freeAfter}</li>`;
    }
    if(rule?.MinRequired){
        minRequired = `Required`;
        label += `<li class="rule-label">${minRequired}</li>`;

    }
    if(rule?.MaxUnique){
        maxUnique = `Allow for each components ${rule.MaxUnique}`;
        label += `<li class="rule-label">${maxUnique}</li>`;

    }
    if(rule?.MaxAllowed && rule?.MaxAllowed !== 1000){
        maxAllowed = `Max allowed ${rule.MaxAllowed}`;
        label += `<li class="rule-label">${maxAllowed}</li>`;

    }

    return `<ul class="rule-label-container">${label}</ul>`;
}

/**
 * Update third-party cart counter/widget after cart changes
 */
function updateThirdPartyCartCounter() {
    if (typeof jQuery !== 'undefined' && jQuery.fn) {
        jQuery(document.body).trigger('wc_fragment_refresh');
    }
}

function showSuccessMessage(message) {
    const isMobileMenuToast = window.matchMedia && window.matchMedia('(max-width: 770px)').matches;
    const MOBILE_SCROLL_CONTAINERS = [
        '.section-main-content-wrap',
        '#cbp-so-scroller',
        '.products-sections-wrapper',
        '.menu_with_menuitems',
    ];

    function forceInstantScrollTop(target) {
        if (!target) {
            return;
        }

        if (target === window) {
            window.scrollTo(0, 0);
            return;
        }

        if (typeof target.scrollTo === 'function') {
            target.scrollTo({ top: 0, behavior: 'auto' });
        }
        target.scrollTop = 0;
    }

    function resolveActiveScroller() {
        const docTop = window.pageYOffset
            || (document.scrollingElement && document.scrollingElement.scrollTop)
            || document.documentElement.scrollTop
            || document.body.scrollTop
            || 0;

        let best = { el: null, top: docTop };

        MOBILE_SCROLL_CONTAINERS.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(function (el) {
                if (el.scrollTop > best.top) {
                    best = { el: el, top: el.scrollTop };
                }
            });
        });

        return best;
    }

    // Create a temporary container to hold the message
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = message;
    tempDiv.classList.add('woocommerce-message', 'cbs-added-to-cart-message');

    // Dismiss "x", styled by the theme's .dizmiz rules (whose margin-left:auto also
    // left-aligns the rest of the banner). Appended here so the AJAX quick-add toast
    // gets it too — the theme's decorator only runs at DOM-ready, and its dedupe
    // guard skips this span when it does run.
    const closeButton = document.createElement('span');
    closeButton.classList.add('dizmiz');
    closeButton.title = 'Dismiss';
    closeButton.textContent = 'x';
    tempDiv.appendChild(closeButton);

    // New design (OE-26616 reopen): render the banner inline under the category
    // title, but only on the default (single-category) menu layout. The
    // infinity-scroll layout keeps the fixed top toast (its wrapper sits above all
    // sections, so an inline banner lands wherever the customer happens to be
    // scrolled), as do non-menu pages — WooCommerce core prints its own
    // .woocommerce-notices-wrapper on shop/product/cart pages, and inserting there
    // would swallow the toast into a possibly hidden div.
    let createdWrapper = null;
    let wrapper = null;
    let wrapperInlineStyleBackup = null;
    const menuSection = document.querySelector('.menu_with_menuitems:not(.infinite-scroll-menu-container)');
    if (menuSection) {
        wrapper = menuSection.querySelector('.woocommerce-notices-wrapper');
        if (!wrapper) {
            createdWrapper = document.createElement('div');
            createdWrapper.classList.add('woocommerce-notices-wrapper');
            const categoryHeader = menuSection.querySelector('#menu_title_desc');
            if (categoryHeader) {
                categoryHeader.insertAdjacentElement('afterend', createdWrapper);
            } else {
                menuSection.insertAdjacentElement('afterbegin', createdWrapper);
            }
            wrapper = createdWrapper;
        }
    }
    if (wrapper) {
        if (isMobileMenuToast) {
            // Theme mobile CSS keeps notice wrappers fixed. Force this toast host
            // into normal flow so it doesn't look like it slides behind product cards.
            wrapperInlineStyleBackup = {
                position: wrapper.style.position,
                top: wrapper.style.top,
                left: wrapper.style.left,
                right: wrapper.style.right,
            };
            wrapper.style.position = 'static';
            wrapper.style.top = 'auto';
            wrapper.style.left = 'auto';
            wrapper.style.right = 'auto';
        }
        wrapper.appendChild(tempDiv);
    } else {
        tempDiv.classList.add('cbs-toast-fixed');
        document.body.appendChild(tempDiv);

        if (isMobileMenuToast) {
            // Mobile requirement: do not keep the toast fixed while scrolling.
            tempDiv.classList.remove('cbs-toast-fixed');
        }
    }

    const removeToast = () => {
        if (tempDiv.parentNode) {
            tempDiv.parentNode.removeChild(tempDiv);
        }
        if (createdWrapper && createdWrapper.parentNode && !createdWrapper.childElementCount) {
            createdWrapper.parentNode.removeChild(createdWrapper);
        }
        if (wrapper && wrapperInlineStyleBackup) {
            wrapper.style.position = wrapperInlineStyleBackup.position;
            wrapper.style.top = wrapperInlineStyleBackup.top;
            wrapper.style.left = wrapperInlineStyleBackup.left;
            wrapper.style.right = wrapperInlineStyleBackup.right;
        }
    };
    // The theme's delegated .dizmiz handler only hides the banner; remove it so the
    // auto-dismiss cleanup below has nothing left to race against.
    closeButton.addEventListener('click', removeToast);

    // The inline banner sits near the top of the menu (under the category title),
    // so when the customer quick-adds while scrolled down the grid it is above the
    // viewport. Scroll the page to the very top so the header, category title and
    // banner are all visible together. scrollIntoView({block:'nearest'}) can't be
    // used here: it aligns the banner to the viewport edge and tucks it under the
    // ~139px sticky header, making it look "too high". The fixed toast (wrapper is
    // null) is viewport-pinned already and needs no scroll.
    if (isMobileMenuToast) {
        const activeScroller = resolveActiveScroller().el;
        const targetScroller = activeScroller
            || document.querySelector('.section-main-content-wrap')
            || document.querySelector('#cbp-so-scroller')
            || document.scrollingElement
            || window;

        // Retry briefly because some layouts finish repainting after the toast append.
        [0, 16, 60].forEach(function (delay) {
            setTimeout(function () {
                forceInstantScrollTop(targetScroller);
            }, delay);
        });
    } else if (wrapper) {
            window.scrollTo({ top: 0, behavior: 'auto' });
    }

    setTimeout(() => {
        tempDiv.classList.add('cbs-toast-fade-out');
        setTimeout(removeToast, 1000);
    }, 3000);
}

// OE-25549: the form-POST / customize add path queues its failure message in the
// `oloAddError` cookie (set server-side in cbsValidateFormAddOnRedirect) and redirects
// to the menu. Show it here as the top toast instead of a notice inside the modal.
document.addEventListener('DOMContentLoaded', function () {
    const match = document.cookie.match(/(?:^|;\s*)oloAddError=([^;]+)/);
    if (match) {
        // decodeURIComponent throws URIError on malformed percent-encoding; guard it
        // so a corrupted cookie cannot break this handler.
        let message;
        try {
            message = decodeURIComponent(match[1]);
        } catch (e) {
            message = match[1];
        }
        showErrorMessage(message);
        document.cookie = 'oloAddError=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }
});

// OE-26616: the single-product Customize page's native WooCommerce form-POST
// add-to-cart hands its success message to the client via the `oloAddSuccess` cookie
// (set server-side in cbsCaptureFormAddedMessage()/cbsValidateFormAddOnRedirect(),
// woocommerce_hooks.php) instead of a WooCommerce session notice, then redirects to the
// menu page. Show it here as the same top toast quick-add already uses — mirrors the
// oloAddError handling above.
document.addEventListener('DOMContentLoaded', function () {
    const match = document.cookie.match(/(?:^|;\s*)oloAddSuccess=([^;]+)/);
    if (match) {
        let message;
        try {
            message = decodeURIComponent(match[1]);
        } catch (e) {
            message = match[1];
        }
        showSuccessMessage(message);
        document.cookie = 'oloAddSuccess=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    }
});

// Error toast for a failed add (e.g. WOAPI /validate rejected the item). Fade is
// driven by the .cbs-toast-fade-out class in style.css (no inline animation).
// Uses textContent (not innerHTML): the message is plain text, so this avoids any
// HTML-injection sink even if a future caller passes untrusted text.
function showErrorMessage(message) {
    const tempDiv = document.createElement('div');
    tempDiv.textContent = message;
    tempDiv.classList.add('woocommerce-error', 'cbs-add-error-message');
    document.body.appendChild(tempDiv);
    setTimeout(() => {
        tempDiv.classList.add('cbs-toast-fade-out');
        setTimeout(() => {
            if (tempDiv.parentNode) {
                document.body.removeChild(tempDiv);
            }
        }, 1000);
    }, 3000);
}
// Expose so the React product-detail block can show the menu top toast on a
// failed add instead of a message inside the modal (OE-25549).
window.showErrorMessage = showErrorMessage;

jQuery(document.body).on('wc_fragments_refreshed', function () {
    updateCartBlock();
});

function updateCartBlock() {
    const blockContainer = document.getElementById('block-container-cart');
    const optionKey = blockContainer?.getAttribute('data-option-key');
    const cacheKey = blockContainer?.getAttribute('data-cache-key');

    if(!optionKey || !cacheKey) return;

    jQuery.ajax({
        url: olo_vars_object.ajax_url,
        type: 'POST',
        data: {
            action: 'update_cart_block',
            option_key: optionKey,
            cache_key: cacheKey,
        },
        success: function (response) {
            if (response.success) {
                jQuery('#block-container-cart').html(response.data.html);
                reinitializeCartBlock();
            } else {
                console.error('Error updating cart block:', response.data.message);
            }
        },
        error: function () {
            console.error('AJAX error while updating cart block');
        },
    });
}

// --- Quick-add request coalescing -----------------------------------------
// At most one add_to_cart_action_cbs request is in flight per tab: a click
// while nothing is in flight fires immediately (no added latency for the
// common single-click case); a click that arrives while a request is in
// flight buffers into the next batch instead of racing it. Serializing this
// way is what actually stops the WooCommerce session's read-modify-write
// cycle from racing itself (concurrent requests each load a stale cart
// snapshot and the last one to save silently overwrites the others' items).
var CBS_QUICK_ADD_MAX_BATCH = 20;
var CBS_QUICK_ADD_INFLIGHT_TIMEOUT_MS = 15000;

var cbsQuickAddInFlight = false;
var cbsQuickAddPending = [];
var cbsQuickAddToken = 0;

function cbsQueueQuickAdd(item) {
    if (cbsQuickAddInFlight) {
        cbsQuickAddPending.push(item);
        return;
    }
    cbsSendQuickAddBatch([item]);
}

function cbsSendQuickAddBatch(items) {
    var token = ++cbsQuickAddToken;
    cbsQuickAddInFlight = true;

    // Guards reconcile+settle to run exactly once per batch. Without it, a
    // request that outlives the in-flight timeout below would get "given up
    // on" (reconciled as failed, queue unblocked, pending buffer possibly
    // flushed as a new in-flight batch) and THEN, whenever its real response
    // eventually arrived, reconcile a second time — showing stale button/toast
    // state on top of whatever happened in the meantime, and racing a second
    // request the timeout already started.
    var settled = false;
    function finish(response) {
        if (settled) {
            return;
        }
        settled = true;
        clearTimeout(timeoutId);
        cbsReconcileQuickAddBatch(items, response);
        cbsQuickAddSettle(token);
    }

    var payload = items.map(function (item) {
        return {
            product_id: item.productId,
            quantity: item.quantity,
            selComponents: item.selComponents,
            selComponentsQty: item.selComponentsQty,
            selComponentsPrice: item.selComponentsPrice,
        };
    });

    var jqXHR = jQuery.ajax({
        type: 'POST',
        url: olo_vars_object.ajax_url,
        data: {
            action: 'add_to_cart_action_cbs',
            items: JSON.stringify(payload),
            returnAddedMessage: true,
        },
        success: function (response) {
            finish(response);
        },
        error: function () {
            finish(null);
        },
    });

    // A hung/slow request past this bound is treated as failed so the queue
    // can unblock and flush the pending buffer. abort() is a best-effort
    // cancel (the server may already be mid-flight regardless — the session
    // lock backstop is what actually protects the cart in that case) but it
    // frees the browser's connection slot and stops a late real response from
    // reconciling a second time — `finish` above is idempotent either way.
    var timeoutId = setTimeout(function () {
        finish(null);
        jqXHR.abort();
    }, CBS_QUICK_ADD_INFLIGHT_TIMEOUT_MS);
}

// Shared by the ajax `complete` callback and the in-flight timeout — whichever
// fires first for a given batch wins; the token guard makes the other one a
// no-op instead of double-flushing or clobbering a newer batch's state (e.g. a
// hung request's real response finally landing after its timeout already
// unblocked the queue and a new batch started).
function cbsQuickAddSettle(token) {
    if (token !== cbsQuickAddToken) {
        return;
    }
    cbsQuickAddInFlight = false;

    if (cbsQuickAddPending.length > 0) {
        var nextBatch = cbsQuickAddPending.splice(0, CBS_QUICK_ADD_MAX_BATCH);
        cbsSendQuickAddBatch(nextBatch);
    }
}

function cbsResetQuickAddButton($button) {
    $button.text("Add").prop("disabled", false);
}

function cbsMarkQuickAddButtonAdded($button) {
    $button.text("Added").addClass("success");
    setTimeout(function () {
        $button.removeClass("success");
        $button.text("Add");
        $button.prop("disabled", false);
    }, 1000);
}

function cbsReconcileQuickAddBatch(items, response) {
    var isSuccess = !!(response && response.success);
    var data = response ? response.data : null;
    var failedIds = {};
    (data && data.failedProductIds || []).forEach(function (id) {
        failedIds[Number(id)] = true;
    });
    // Name lookup for the same failedProductIds — a separate list of {product_id,
    // name} pairs (not an ID-keyed object) since the failure ids alone are enough
    // to drive button state; names are only needed for the message text.
    var failedNameById = {};
    (data && data.failedProductNames || []).forEach(function (entry) {
        failedNameById[Number(entry.product_id)] = entry.name;
    });
    function namesForFailedIds(ids) {
        return (ids || [])
            .map(function (id) { return failedNameById[Number(id)]; })
            .filter(Boolean);
    }
    // No breakdown at all (lock timeout, network error) — nothing in this
    // batch is known to have made it into the cart, so every item is unresolved.
    var noBreakdown = !data || (!data.addedProductIds && !data.failedProductIds);

    if (!isSuccess) {
        var message = (data && data.message) || "An error occurred. Please try again.";
        var failedNames = namesForFailedIds(data && data.failedProductIds);
        if (failedNames.length) {
            message = failedNames.join(", ") + " could not be added: " + message;
        }
        items.forEach(function (item) {
            cbsResetQuickAddButton(item.$button);
        });
        showErrorMessage(message);
        jQuery(document.body).trigger('wc_fragments_refreshed');
        document.dispatchEvent(new CustomEvent('cbsCartUpdated', {
            detail: { count: Number(data && data.count || 0) }
        }));
        updateThirdPartyCartCounter();
        jQuery(".overlay").addClass("hidden");
        if (typeof spinnerOFF === 'function') {
            spinnerOFF();
        }
        return;
    }

    // A linked-category quick-add button (data-slug) redirects the whole page
    // on success instead of updating the cart UI — first one wins, matching
    // the pre-batch single-item behavior (the navigation makes any further
    // per-button reconciliation moot).
    var redirectItem = items.find(function (item) {
        return item.isOnlineOrdering && item.url && !failedIds[Number(item.productId)];
    });
    if (redirectItem) {
        window.location.href = redirectItem.url;
        return;
    }

    items.forEach(function (item) {
        if (!noBreakdown && failedIds[Number(item.productId)]) {
            cbsResetQuickAddButton(item.$button);
        } else {
            cbsMarkQuickAddButtonAdded(item.$button);
        }
    });

    // Some items in an otherwise-successful batch failed to add (e.g. one went
    // out of stock mid-burst) — those buttons were just reset above with no
    // explanation; name them here instead of leaving the customer to guess.
    var partialFailureNames = namesForFailedIds(data.failedProductIds);
    if (partialFailureNames.length) {
        showErrorMessage(partialFailureNames.join(", ") + " could not be added.");
    }

    jQuery(document.body).trigger('wc_fragments_refreshed');
    jQuery(".cart-total")?.html("Total: " + data.total);
    if (data.addedMessage) {
        showSuccessMessage(data.addedMessage);
    }
    document.dispatchEvent(new CustomEvent('cbsCartUpdated', {
        detail: { count: Number(data?.count ?? 0) }
    }));
    updateThirdPartyCartCounter();
    jQuery(".overlay").addClass("hidden");
}

document.addEventListener("DOMContentLoaded", function() {
    document.addEventListener('click', function(event) {
        if (event.target?.matches('button.quantity-button')) {
            event.preventDefault();
            const action = event.target.dataset.action;
            const quantityInput = event.target.parentElement.querySelector('.quantity-input');
            let quantity = parseInt(quantityInput.value);
            if (action === 'increase') {
                quantity += 1;
            } else if (action === 'decrease' && quantity > 1) {
                quantity -= 1;
            }
            quantityInput.value = quantity;
            return;
        }
        if( event.target?.matches('a.linkto')) {
            const isKioskMode = event.target.getAttribute('data-is-kiosk-mode') === 'true';
            if(isKioskMode){
                event.preventDefault();
            }
            return;
        }
        if (event.target?.matches('button.quick-add')) {
            event.preventDefault();

            const quantityInput = event.target.parentElement.parentElement.querySelector('.quantity-input');
            const quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
            var $button = jQuery(event.target);
            let isOnlineOrdering = false;

            // Show "Adding..." immediately regardless of whether this click fires its
            // own request right away or ends up coalesced into a follow-up batch —
            // the customer sees the same instant feedback either way.
            $button.prop("disabled", true);
            $button.text("Adding...");

            if ($button.closest('.menu_with_menuitems').length > 0) {
                isOnlineOrdering = true;
            }
            const data = $button.data("components");
            const slug = $button.data("slug");
            const productId = Number($button.prop("id"));
            let selComponents = [];
            let selComponentsQty = [];
            let selComponentsPrice = 0;
            let url = null;

            if(slug){
                url = window.location.origin + "/" + slug;
            }

            data.forEach(product => {
                selComponents = product.selComponents;
                selComponentsQty = product.selComponentsQty;
                selComponentsPrice = product.selComponentsPrice;
            });

            cbsQueueQuickAdd({
                productId,
                quantity,
                selComponents: JSON.stringify(selComponents),
                selComponentsQty: JSON.stringify(selComponentsQty),
                selComponentsPrice,
                isOnlineOrdering,
                url,
                $button,
            });
        }
    });
});