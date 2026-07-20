function removeItemsfromCart() {
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'remove_items_from_cart',
        },
        success: function (data) {
            if (data) {
                console.log('items removed');
            };
        }
    });
}

function restartCart(e, url) {
    window.location.href = url;
    removeItemsfromCart();
}


function addItemToCart(preventAutoSubmit= false) {

    var selCompo = generatePayload(false);
    var selCompoQty = generatePayload(true);
    var priceTotal = "";
    var priceTotalcontainer = jQuery(".total-price-container").data("price");
    var componentsprice =  jQuery(".total-price-container").data("componentstotalamount");
    if(componentsprice !== undefined ){
        priceTotal = parseFloat(priceTotalcontainer) - parseFloat(componentsprice);
    }else{
        priceTotal = priceTotalcontainer;
    }

    var prodid = jQuery(".total-price-container").data("prodid");
    var variationid = jQuery(".total-price-container").data("variation");
    var qtystr = ".quantity-number-" + prodid;
    var qty = jQuery(qtystr).text();
    var customerName=jQuery("#customerName").val();
    var validateServingOptions = checkSelectionServingOptions();

    const validateComponents = HandleUnfulfilledRules()

    if(validateComponents === false || preventAutoSubmit){
        return;
    }
    if(validateServingOptions === false){
        return;
    }
    spinnerON();
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'woocommerce_ajax_add_to_cart',
            product_id: prodid,
            quantity: qty,
            variation_id: variationid,
            item_selected_for: customerName,
            product_price_input: parseFloat(priceTotal),
            selComponents: JSON.stringify(selCompo),
            selComponentsPrice: componentsprice,
            selComponentsQty: JSON.stringify(selCompoQty),
            selectedComponents: JSON.stringify(globalSelectedComponents)
        },
        success: function (data) {
            getCart();
            compoPrice = 0;
        },
        error: function (request, status, error) {
            console.log('error: ' +request.responseText);
        }

    }).done(function () {
        spinnerOFF();
        getCart();
        globalSelectedComponents = [];
    });
}

function HandleUnfulfilledRules() {
    const prodid = jQuery(".total-price-container").data("prodid");
    let {controlflag:flag, unfulfilledForScrolling} = validateComponentSelection(componentdata,prodid);

    if (unfulfilledForScrolling.length > 0) {
        const divId = unfulfilledForScrolling.shift();
        callToSubmit = true;
        ScrollToRequiredComponent(divId);
    }
    else{
        callToSubmit = false;
    }
    return flag;
}

function ScrollToRequiredComponent(componentDivId) {
    const HEADER_HEIGHT = 325;
    const UPPER_SCROLL_LIMIT = 1;
    const LOWER_SCROLL_LIMIT = -1;
    const targetElement = jQuery(componentDivId).parent().parent();
    const position = targetElement.offset().top;
    const scrollPosition = position - HEADER_HEIGHT;
    if (scrollPosition > UPPER_SCROLL_LIMIT || scrollPosition < LOWER_SCROLL_LIMIT) {
        jQuery('.component-main-container').animate({scrollTop:scrollPosition},800);
    }
}

function editItemToCart(e, preventAutoSubmit= false) {

    itemid = jQuery(e).data('itemid');

    var selCompo = generatePayload(false);
    var selCompoQty = generatePayload(true);
    var priceTotal = "";
    var priceTotalcontainer = jQuery(".total-price-container").data("price");
    var componentsprice =  jQuery(".total-price-container").data("componentstotalamount");
    if(componentsprice){
        priceTotal = parseFloat(priceTotalcontainer)-parseFloat(componentsprice);
    }else{
        priceTotal = priceTotalcontainer;
    }
    var prodid = jQuery(".total-price-container").data("prodid");
    var variationid = jQuery(".total-price-container").data("variation");
    var qtystr = ".quantity-number-" + prodid;
    var qty = jQuery(qtystr).text();
    var price = parseFloat(priceTotal) + parseFloat(compoPrice);
    var customerName=jQuery("#customerName").val();

    const validateServingOptions = checkSelectionServingOptions();
    const validateComponents = HandleUnfulfilledRules()

    if(validateComponents === false || preventAutoSubmit){
        return;
    }
    if(validateServingOptions === false){
        return;
    }

    spinnerON();
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'woocommerce_ajax_add_to_cart',
            product_id: prodid,
            quantity: qty,
            variation_id: variationid,
            item_selected_for: customerName,
            product_price_input: priceTotal,
            selComponents: JSON.stringify(selCompo),
            selComponentsPrice: componentsprice,
            selComponentsQty: JSON.stringify(selCompoQty),
            selectedComponents: JSON.stringify(globalSelectedComponents)
        },
        success: function (data) {
            removeItemToCart(itemid);
            compoPrice = 0;
        },
        error: function (request, status, error) {
            console.log(request.responseText);
        }

    }).done(function () {
        spinnerOFF();
    });




}

function removeItemToCart(prodid) {
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'woocommerce_remove_product_from_cart',
            product_id: prodid,
        },
        success: function (data) {
            getCart();
            console.log('Successfully removed');

        }
    });
}

/*
* Get Cart
 */
function getCart() {
    spinnerON();
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'getCartItems_kiosk'
        },
        success: function (data) {
            let output = '';
            jQuery.each(data.items, function (key, value) {
                const arr = value.variation;
                let variations = '';
                let variationsData = '';
                let componentsdata = '';
                let componentsdata_test = '';
                jQuery.each(arr, function (key, value) {
                    variations += '<li>' + value + '</li>';
                    variationsData += value + " " ;
                })
                variations = variations ? `<ul>${variations}</ul>` : '';
                if(value.product_component_id){
                    Object.keys(value.product_component_id)?.forEach(element => componentsdata += element + "#" + Object.values(value.product_component_id)+ ",");
                }
                jQuery.each(value.product_component_id , function(key, component_qty){
                    componentsdata_test  += key + '#' + component_qty + ',' ;
                });
                output += `
          <div class="row cart-item">
          <div class="col-2 cart-icons">
            <i class="fa-solid fa-pen-to-square" 
                onclick="editmodal(this)"
                data-itemid="${value.key}"
                data-productid=${value.product_id}
                data-variationId=${value.variation_id}
                data-quantity=${value.quantity}
                data-variationsdata="${variationsData}"
                data-componetsCategories='${JSON.stringify(value.product_component_selected_by_category)}'
                data-components="${componentsdata_test}"></i>
            <i onclick="removeItemToCart('${value.key}')" class="fa-solid fa-circle-xmark"></i>
          </div>
          <div class="col-3 picture"><img src="`+ value.image + `" alt=${value.description}></div>
          <div class="col description"><p class="item-title">`+ value.name + `</p><p>` + variations + (value.product_component ? value.product_component : '') + `</p></div>
          <div class="col-1 total" >$`+ parseFloat(value.line_total).toFixed(2) + `</div>
          </div> 
          `
            })
            jQuery('.cart-box').html(output);
            jQuery('.cart-number').html(data.count);
            jQuery('#cart-subtotal').html("$" + parseFloat(data.subtotal).toFixed(2));
            jQuery('#cart-tax').html("$" + (data.tax).toFixed(2));
            jQuery('#cart-total').html("$" + data.total);
            jQuery('#item-price-global').html("$" + data.total);
        }
    }).done(function () {
        spinnerOFF();
    });
}
