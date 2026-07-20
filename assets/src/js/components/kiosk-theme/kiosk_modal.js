function create_modal(value, data) {
    globalSelectedComponents = [];
    const arr = value.price.split(',');
    prices = "";
    prices2 = "";
    jQuery.each(arr , function(k, v ){
        if(k === 0 ){
            prices += '<span>  $' + parseFloat(v).toFixed(2) + '</span>';
        }else if(k === arr.length-1){
            prices += '<span>  $' + parseFloat(v).toFixed(2) + '</span>';
        }
    });


    let totalPrice = 0;

    jQuery.each(data.components, function(category, categoryData) {
        jQuery.each(categoryData.items, function(item, itemData) {
            const isDefault = itemData.isDefault === "1";
            const componentPrice = parseFloat(itemData.componentPrice);

            if (!isNaN(componentPrice) && isDefault) {
                totalPrice += componentPrice;
            }
        });
    });

    componentdata = data.components;
    arr.forEach(element => prices2 += '' + element + '');
    output = '';
    output += `
       <button class="close-modal-button" onClick="closeModal()" role="button" aria-label="Close"> <i class="fa-solid fa-xmark" tabindex="0"></i></button>
       <div class="modal-body-container">
         <div class="row modal-head">
           <div class="col-3">
             <img class="card-img-top" src="`+ value.image + `" alt="${value.description}">
           </div>
           <div class="col-9">
           <div class="header-title">
             <h3> `+ value.post_title + ` </h3>
           <div class="card-text"> 
          
           <h3 class='modal-prices'> `+ prices + ` </h3></div> </div>
             <p class="description">`+ data.description + `</p>
             <div style="display:none" class="alert alert-danger" role="alert">
             A simple danger alert—check it out!
           </div>
           <div class="serving-options-container">`+ add_serving_options(data.attributes_list, value, data) + `
           </div>
           <div class="row menu-info">
            <label class="costumer"> 
             Customer 
             <input  id="customerName" type="text" aria-label="Customer name, optional"> 
            </label>
            <div class="Quantity">
               Quantity
               <div class="holder">
               <i class="fa-solid fa-circle-minus" data-prodid="` + value.ID + `" onClick="minusQuantity(this)"></i> <div class="quantity-number-` + value.ID + `" data-prodid ="` + value.ID + `" data-number="1">1</div>  <i class="fa-solid fa-circle-plus" data-prodid="`+ value.ID + `" onClick="addQuantity(this)"></i>      </div>
               </div>
              </div>
         </div>
         </div>
 
         <div class="component-main-container">
            
             `; if (data.components) {
        editvalue = false ;
        componentsArr = [];
        positionArr = [];
        output += add_components(data.components, value , componentsArr , positionArr , editvalue  );
        /* jQuery(".components-items-container").unbind('click').bind("DOMSubtreeModified", function() {
          console.log("list changed");

        }); */
    } else {
        jQuery('.modal').removeClass('bigger');
    }
    output += `
 
         </div>
       </div>
       <div class="modal-footer-container">
       <div class="modal-footer">
	   <div data-price="`+ (arr.length === 1 ? prices2 : "0.00") + `" data-variation=0  data-prodid="` + value.ID + `" class="total-price-container total-` + value.ID + `">` + (arr.length === 1 ? prices : "$0.00") + `</div>
         <button id="add-to-cart-button" type="button" class="btn btn-secondary add-to-cart-button" onclick="addItemToCart()">Add to Cart</button>
       </div>
     </div>
    `;
    jQuery('#main-modal').html(output);

    if(arr.length === 1){
        jQuery(".total-price-container").attr("data-servingtotalamount", prices2 );
    }

    addComponentdefaultgetdata(data.components, value);
    detectServingOption(value.ID, data.attributes_list, data.variations);
    add_default_component_price(totalPrice);
    globalSelectedComponents = getActiveDefaultComponents();
    calculateComponentsTotal();
}

function edit_modal_create(value, data , itemId , quantity , variations ,componentsItems , variationId, selectedComponentsByCategory) {
    globalSelectedComponents = [];
    var totalPrice = 0;
    var currentVariationPrice = '';
    jQuery.each(componentsItems , function(key_component_id , value_component){

        jQuery.each(data.components, function(category, categoryData) {
            jQuery.each(categoryData.items, function(item, itemData) {
                const isDefault = itemData.isDefault === "1";
                const componentPrice = parseFloat(itemData.componentPrice);

                if (!isNaN(componentPrice) && itemData.componentId === value_component.split('#')[0]) {
                    totalPrice += parseFloat(componentPrice)*parseFloat(value_component.split('#')[1]);
                }
            });
        });
    });

    variationprices = data.variations[variationId];

    if(variationprices?.dispaly_price){
        currentVariationPrice = variationprices.dispaly_price;

    }
    const arr = value.price.split(',');
    prices = "";
    prices2 = "";
    varia = [];
    componentsArr = [];
    positionArr = [];

    if(componentsItems){
        componentsItems.forEach(element => element != '' ? componentsArr[element.split('#')[0].split('_')[0]] = element.split('#')[1]  : "" );
        componentsItems.forEach(element => element != '' ? positionArr[element.split('#')[0].split('_')[0]] = element.split('#')[0].split('_')[1]  : "" );
    }

    variations.forEach(element => element != '' ? varia[element] = element : "" );

    jQuery.each(arr , function(k, v ){
        if(k === 0 ){
            prices += '<span>  $' + parseFloat(v).toFixed(2) + '</span>';
        }else if(k === arr.length-1){
            prices += '<span>  $' + parseFloat(v).toFixed(2) + '</span>';
        }
    });
    arr.forEach(element => prices2 += '' + element + '');
    output = '';
    output += `
       <button class="close-modal-button" onClick="closeModal()"> <i class="fa-solid fa-xmark"></i></button>
       <div class="modal-body-container">
         <div class="row modal-head">
           <div class="col-3">
             <img class="card-img-top" src="`+ value.image + `" alt="Card image cap">
           </div>
           <div class="col-9">
           <div class="header-title">
             <h3> `+ value.post_title + ` </h3>
           <div class="card-text"> 
          
           <h3 class='modal-prices'> `+ prices + ` </h3></div> </div>
             <p class="description">`+ data.description + `</p>
             <div style="display:none" class="alert alert-danger" role="alert">
             A simple danger alert—check it out!
           </div>
           <div class="serving-options-container">`+ edit_serving_options(data.attributes_list, value, data , varia) + `
           </div>
           <div class="row menu-info">
            <div class="costumer"> 
             Customer 
             <input  id="customerName" type="text"> 
            </div>
            <div class="Quantity">
               Quantity
               <div class="holder">
               <i class="fa-solid fa-circle-minus" data-prodid="` + value.ID + `" onClick="minusQuantity(this)"></i> <div class="quantity-number-` + value.ID + `" data-prodid ="` + value.ID + `" data-number="`+quantity+`">`+quantity+`</div> <i class="fa-solid fa-circle-plus" data-prodid="`+ value.ID + `" onClick="addQuantity(this)"></i> </div>
            </div>
         </div>
         </div>
         </div>
 
         <div class="component-main-container">
            
             `;
    if (data.components) {
        editvalue = true;
        output += add_components(data.components, value ,componentsArr , positionArr , editvalue  );
    } else {
        jQuery('.modal').removeClass('bigger');
    }
    output += `
 
         </div>
 

       </div>
       <div class="modal-footer-container">
       <div class="modal-footer">
       <div data-price="`+ (arr.length === 1 ? prices2 : currentVariationPrice ? currentVariationPrice :  "0.00") + `" data-variation="`+(variationId ? variationId : 0)+`"  data-prodid="` + value.ID + `" class="total-price-container total-` + value.ID + `">` + (arr.length === 1 ? prices : currentVariationPrice ? " $" + currentVariationPrice :  "$0.00") + `</div>
         <button type="button" class="btn btn-secondary add-to-cart-button" onclick="editItemToCart(this)" data-itemid="`+itemId+`">Save</button>
       </div>
     </div>
    `;
    jQuery('#main-modal').html(output);

    addeditedComponentdefaultgetdata(data.components, value , componentsArr , positionArr);
    detectServingOption(value.ID, data.attributes_list, data.variations);
    if(currentVariationPrice){
        jQuery(".total-price-container").attr("data-servingtotalamount", currentVariationPrice);
    }
    if(arr.length === 1){
        jQuery(".total-price-container").attr("data-servingtotalamount", prices2 );
    }
    if(totalPrice){
        add_default_component_price(totalPrice);
    }

    globalSelectedComponents = {...selectedComponentsByCategory};
    calculateComponentsTotal();
}

function openModal(e) {
    id = jQuery(e).data('prodid');
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'single_product_components_kiosk',
            product_id: id

        },
        success: function (data) {

            dataGlobal[id] = data;
        }
    }).done(function () {
        create_modal(valueGlobal[id], dataGlobal[id]);
        jQuery('#modal-container').show();
    });
}
function editmodal(e){
    let id = jQuery(e).data('productid');
    let item = jQuery(e).data('itemid');
    let quantity = jQuery(e).data('quantity');
    let variationsData = jQuery(e).data('variationsdata');
    let componentsitems = jQuery(e).data('components');
    let variationId = jQuery(e).data('variationid');
    let selectedComponentsByCategory = jQuery(e).data('componetscategories');
    console.log('selectedComponentsByCategory on edit modal',selectedComponentsByCategory)
    let componentsItemData = componentsitems.split(',') !="" ? componentsitems.split(',') : null ;
    let variations = variationsData.split(' ') ? variationsData.split(' ') : null;
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'single_product_components_kiosk',
            product_id: id

        },
        success: function (data) {
            dataGlobal[id] = data;
        }
    }).done(function () {
        edit_modal_create(valueGlobal[id], dataGlobal[id], item , quantity , variations , componentsItemData , variationId,selectedComponentsByCategory);
        jQuery('#modal-container').show();
    });
    console.log('edit');
}

function closeModal(e) {
    jQuery('#modal-container').hide();
}
