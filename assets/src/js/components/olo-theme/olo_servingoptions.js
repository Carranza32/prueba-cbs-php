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