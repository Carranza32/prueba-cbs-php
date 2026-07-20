jQuery(document).on('addItemComponent', (event) => {
    let newComponentElement = jQuery(event.elementAdded);
    let newComponentData = newComponentElement.data();

    if(globalSelectedComponents[newComponentData.category]){
        globalSelectedComponents[newComponentData.category].push(prepareDataOfComponent(newComponentData))
    }else {
        globalSelectedComponents[newComponentData.category] = [];
        globalSelectedComponents[newComponentData.category].push(prepareDataOfComponent(newComponentData))
    }
    calculateComponentsTotal();
});

jQuery(document).on('removeitems', (event) => {
    let componentElement = jQuery(event.elementRemoved);
    let componentRemoved = componentElement.data();
    let categoryComponents = globalSelectedComponents[componentRemoved.category];

    let componentIdToRemove = componentRemoved.componentid;

    // get the index of the last item with the same component id
    let indexToRemove = categoryComponents.map(component => component.componentid).lastIndexOf(componentIdToRemove);

    // remove the item from the array
    categoryComponents.splice(indexToRemove, 1);
    globalSelectedComponents[componentRemoved.category] = categoryComponents;
    componentElement.find('.fa-circle-plus').removeClass('disabled');
    calculateComponentsTotal();
});

jQuery(document).on('detectServingOption', () => {
    calculateComponentsTotal();
});

function calculateComponentsTotal() {
    let total = 0;
    let total_components = calculateTotal(globalSelectedComponents);
    let servingOptionsElement = jQuery('.total-price-container');
    let servingOptions = servingOptionsElement.data('servingtotalamount');
    if(!isNaN(servingOptions)){
        total = servingOptions;
    }
    total = total + total_components;
    const totalRounded = parseFloat(total).toFixed(2);
    servingOptionsElement.html('$' + totalRounded);
    servingOptionsElement.data('price', totalRounded);
}
