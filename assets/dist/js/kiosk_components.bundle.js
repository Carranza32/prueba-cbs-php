/**
 * This variable is used to control the components
 * behavior when its add a component to a product
 * and when is removed from it
 * @type {*[]}
 */
let globalSelectedComponents = [];

/* global callToSubmit */


var touching=false;
var timeout=kiosk_vars_object.timeout;
var actualCat=0;
var noCat=0;
let callToSubmit = false;

jQuery(document).on('click',function(e){
    touching=true;
    timeout=kiosk_vars_object.timeout;
});

window.setInterval(escribir, 1000);
//restartCart
function escribir(){
    if(touching){
        touching=false;
    }else{
        if(timeout>0){
            if(timeout==10){
                timeOutWarning();
            }
            if(timeout==-1)
            {
                timeout=kiosk_vars_object.timeout+1;
            }
            timeout=timeout-1;
            jQuery("#timeout-div").html(`<h1>`+timeout+`</h1>`);

        }else{
            restartCart(this,kiosk_vars_object.home_url)
        }
    }
}

var compoPrice =0;
jQuery.fn.riseUp = function()   { jQuery(this).show("slide", { direction: "down" }, 1000); }
function generatePayload(type=false)
{
    var payload= [];
    var siteid = kiosk_vars_object.data_var_1;
    items = jQuery(".components-items-container").length;
    var i = 1;
    jQuery(".components-items-container").each(function (event) {

        $(this).find('a.active').each(function (e) {


            var price = jQuery(this).data("price");
            var loc = jQuery(this).data("position");
            var locStr = (loc != "" ? "_" + loc : "");
            let header;
            if (type) {
                header  = {
                    key: 10,
                    componentName: jQuery(this).data('compname'),
                    price: price > 0 ? price : "",
                    siteId: siteid,
                    componentId: jQuery(this).data('componentid') + locStr,
                    quantity: jQuery(this).data('quantity')
                }
            }
            else {
                header  = {
                    key: 10,
                    componentName: jQuery(this).data('compname'),
                    price: price > 0 ? price : "",
                    siteId: siteid,
                    componentId: jQuery(this).data('componentid') + locStr,
                }
                compoPrice = parseFloat(compoPrice) + parseFloat(price);
            }
            payload.push(header)

            i++;
        });
    });
    return payload;
}

var rulesGlobal = [];
var dataGlobal = [];
var valueGlobal = [];
var selectedComp = [];
var componentdata = [];

jQuery("#list-cat li").click(function (event) {

    event.preventDefault();
});
jQuery('#main').click(function () {
    jQuery('#main').hide();
    if(getCookie("kioskcbs_deviceId"))
    {
        jQuery('#guestIdentifier').show();
    }
    else{
        jQuery('#registerModal').show();

    }

    getCategories();


});

function dinein(){

    jQuery('#preorder').hide();
    jQuery('#mainDiv').show();
    localStorage.setItem("ordertype", "DineIn");
}
function takeout(){

    jQuery('#preorder').hide();
    jQuery('#mainDiv').show();
    localStorage.setItem("ordertype", "TakeOut");
}
function preorder(){


    jQuery('#preorder').hide();
    jQuery('#mainDiv').show();
    localStorage.setItem("ordertype", "PreOrder");

}
let count = 0;

var categories_kiosk;

function getCategories() {
    spinnerON();
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'get_categories_kiosk',
            siteid: kiosk_vars_object.data_var_1
        },
        success: function (data) {
            categories_kiosk = data;
            let i = 0;
            let output;
            output = "<div class='list-group'>";
            jQuery.each(data, function (key, value) {
                output += ' <div onclick="getProductByCategory(' + value.term_id + ',' + i + ',false)" data-termid="' + value.term_id + '" class="list-group-item  category-item" role="button">' + value.name + '</div>';
                i++;
            });
            output += '</div>';
            jQuery("#categoriesdiv").html(output);
        },
        error: function (request, status, error) {
            console.log(request.responseText);
        }
    }).done(function () {
        getCart();
        spinnerOFF();
        getProductByCategory(categories_kiosk[actualCat].term_id, actualCat, false);
    });
}

function getCategoryDefault() {
    var id = 0;
    jQuery(".category-item").each(function (event) {
        id = jQuery(this).data("termid");
    });
    getProductByCategory(id);
}

function getProductByCategory(id,i,a) {
    // spinnerON();
    if(a){

    }
    else{
        actualCat=i;
    }
    if(noCat==0)
    {
        jQuery(".loading").show();
        jQuery.ajax({
            type: "POST",
            url: wc_add_to_cart_params.ajax_url,
            data: {
                action: 'getProductsByCategoryId_kiosk',
                catid: id
            },
            success: function (data) {
                jQuery('#wc-products').html(data);
                jQuery( ".category-item" ).removeClass( "active" );
                let output = `<h1>`+categories_kiosk[actualCat].name+`</h1>
      <hr>
<div class="card-deck">`;
                jQuery.each(data, function (key, value) {
                    const arr = value.price.split(',');
                    let prices = "";
                    jQuery.each(arr , function(k, v ){
                        if(k === 0 ){
                            prices += '<span>  $' + parseFloat(v).toFixed(2) + '</span>';
                        }else if(k === arr.length-1){
                            prices += '<span>  $' + parseFloat(v).toFixed(2) + '</span>';
                        }
                    });

                    output += ` 
                        <div class="card"> 
                        <a href="#`+ value.ID + `" data-prodid="` + value.ID + `" class="modal-opener" onClick="openModal(this)">
                        <img class="card-img-top" src="`+ value.image + `" alt="${value.description}">
                        <div class="card-body">
                        <p class="card-title">`+ value.post_title + `</p>
                        <div class="card-text">`+ prices + `</div>
                        </div>
                        </a>
                        </div>
              `;
                    valueGlobal[value.ID] = value;
                    //  getSingleProductComponents(value.ID);
                });  var siteid = kiosk_vars_object.data_var_1;
                output += '</div>';
                if(a)
                {
                    jQuery("#productsdiv").append(output);
                }
                else{
                    actualCat=i;
                    jQuery("#productsdiv").html(output);
                }
            },
            complete: function (data) {
            }
        }).done(function (data) {
            spinnerOFF();
            jQuery("[data-termid="+categories_kiosk[actualCat].term_id+"]").addClass("active");
            jQuery(".loading").hide();
            spinnerOFF();
            if(actualCat<(categories_kiosk.length-1)){
                actualCat++;
            }
            else{
                actualCat=0;
            }
        });
    }
}


function add_default_component_price(currentprice) {
    var componentstotalcurrent = '';
    var totalwithcomponent = '';
    var totalcontainerinsert = jQuery(".total-price-container");
    var servingoptionstotal = jQuery(".total-price-container").attr("data-servingtotalamount");
    var componentstotal = jQuery(".total-price-container").data("componentstotalamount");
    var containerTotal = jQuery(".total-price-container").data("price");

    componentstotalcurrent = currentprice;
    if(servingoptionstotal  !== undefined){
        if(componentstotalcurrent){
            totalwithcomponent = parseFloat(servingoptionstotal) + parseFloat(componentstotalcurrent);
        }else{
            totalwithcomponent = parseFloat(servingoptionstotal);
        }
    }else{
        totalwithcomponent = parseFloat(componentstotalcurrent) + parseFloat(containerTotal);
    }

    const totalWithComponentRounded = parseFloat(totalwithcomponent).toFixed(2);
    totalcontainerinsert.data('price', totalWithComponentRounded);
    totalcontainerinsert.attr('data-price', totalWithComponentRounded);
    totalcontainerinsert.text('$' + totalWithComponentRounded);
    totalcontainerinsert.data('componentstotalamount', parseFloat(componentstotalcurrent).toFixed(2));
}


function add_components(data, value ,componentsArr , positionArr , editvalue ) {
    let output = '';
    let componentCount = 0;

    jQuery.each(data, function (keyCategorydata, valueCategorydata) {

        let rules = valueCategorydata.info.rules;
        let firstRule = Object.keys(rules)[0];
        let rule = rules[firstRule];
        let rulesLabel = getRulesLabel(rule);

        componentCount++;
        output += `
        <div class="row components">
        <div class="col-5">
          <div id="${valueCategorydata.info.componentCatId}-${value.ID}" class="component-dropdown">
            <div>
                <p>${keyCategorydata}</p>
                <p class="component-warning"></p>
            </div>
            ${rulesLabel}
          </div>
        </div>
        <div class="`+ "compo-" + valueCategorydata.info.componentCatId + "-" + value.ID + ` warning-container">
          <div class="components-items-container" id="`+ "compo-" + valueCategorydata.info.componentCatId + "-" + value.ID + `">
          `;
        jQuery.each(valueCategorydata.items, function (keyComponent, valueComponent) {
            rulesGlobal[valueComponent.ruleId] = valueCategorydata.info.rules[valueComponent.ruleId];
            const rulesId = valueComponent.ruleId;
            const rules = valueCategorydata.info.rules[valueComponent.ruleId];
            let maxUnique = rules.MaxUnique;
            let dataUnique = rules.MaxUnique === 1 ? 'data-unique = "true"' : "";
            let maxUniqueLabel = (maxUnique > 1 ? `<small><b>(Max: ${maxUnique})</b></small>` : "");
            let nameComponent = `
            <p> ${valueComponent.componentName} ${(valueComponent.componentPrice != 0 ? "$" + valueComponent.componentPrice : "")} ${maxUniqueLabel}</p>
            `
            let quantity = 0;
            if(componentsArr[valueComponent.componentId]){
                quantity = componentsArr[valueComponent.componentId];
            }else {
                quantity = valueComponent.isDefault ? valueComponent.isDefault : "0";
            }
            if(editvalue){
                if(componentsArr[valueComponent.componentId]){

                    if (valueComponent.NumberOfPlacements === "1") {

                        output += `
                            
                            <a  data-compname="${valueComponent.componentName}"
                                data-maxUnique="${rules.MaxUnique}"
                                id="${valueComponent.componentId}-${value.ID}"
                                data-wcid="${value.ID}"
                                ${dataUnique}
                                data-position=''
                                data-action=""
                                class="active  component-item-${valueCategorydata.info.componentCatId}-${value.ID}"
                                onclick="addItemComponent(this${dataUnique ? ", callToSubmit": ""})"
                                data-quantity="${quantity}"
                                data-price="${(valueComponent.componentPrice ? valueComponent.componentPrice : 0)}"
                                data-category="${keyCategorydata}"
                                data-componentid="${valueComponent.componentId}"
                                data-catid="${valueCategorydata.info.componentCatId}-${value.ID}"
                                data-rule= "${rulesId}"
                                data-component= "${valueComponent.componentName}"
                                data-siteId="${valueComponent.siteId}"
                                data-placements="${valueComponent.NumberOfPlacements}"
                                data-isDefault="${valueComponent.isDefault}"
                            > 
                                <div class="col-12 item-main-container">
                                 ${nameComponent}
                                 <div class="qty-controls-container">
                                 ` +(componentsArr[valueComponent.componentId] > 0? rules.MaxUnique === 1 ? '<div class="quantity-controls"><i class="fa-solid fa-circle-check"></i> </div>' :  '<div class="quantity-controls"> <i class="fa-solid fa-circle-minus" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onclick="removeitems(this)"></i> <div class="component-qty " > '+componentsArr[valueComponent.componentId]+'</div> <i class="fa-solid fa-circle-plus""></i> </div> ' : "" ) +  ` </div> </div></a>
                        `;
                    }
                    else {


                        output += `
                            <a  data-compname="${valueComponent.componentName}"
                                data-maxUnique="${rules.MaxUnique}"
                                id="${valueComponent.componentId}-${value.ID}"
                                data-wcid="${value.ID}"
                                ${dataUnique}
                                data-position="${(!positionArr[valueComponent.componentId] ? "" : positionArr[valueComponent.componentId] )}"
                                data-action=""
                                class="active component-item-${valueCategorydata.info.componentCatId}-${value.ID}"
                                onclick="addItemComponent(this${dataUnique ? ", callToSubmit": ""})"
                                data-quantity=${quantity}
                                data-price="${(valueComponent.componentPrice ? valueComponent.componentPrice : 0)}"
                                data-category="${keyCategorydata}"
                                data-componentid="${valueComponent.componentId}"
                                data-catid="${valueCategorydata.info.componentCatId}-${value.ID}"
                                data-rule= "${rulesId}"
                                data-component= "${valueComponent.componentName}"
                                data-siteId="${valueComponent.siteId}"
                                data-placements="${valueComponent.NumberOfPlacements}"
                                data-isDefault="${valueComponent.isDefault}"
                            >
                                <div class="col-12 item-main-container">
                                    ${nameComponent}
                                    <div class="qty-controls-container">
                                    ` +(componentsArr[valueComponent.componentId] > 0? rules.MaxUnique === 1 ? '<div class="quantity-controls"><i class="fa-solid fa-circle-check"></i> </div>' :  '<div class="quantity-controls"> <i class="fa-solid fa-circle-minus" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onclick="removeitems(this)"></i> <div class="component-qty " > '+componentsArr[valueComponent.componentId]+' </div> <i class="fa-solid fa-circle-plus""></i> </div> ' : "" ) +  ' </div> </div> <div class="position-container col-12"> '+(componentsArr[valueComponent.componentId] > 0  ?  '<button class="position-selector '+(positionArr[valueComponent.componentId] === "left"? "active" : "")+'" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="left"> Left </button> <button class="position-selector '+( !positionArr[valueComponent.componentId] ? "active" : "")+'" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="whole"> Whole </button> <button class="position-selector '+(positionArr[valueComponent.componentId] === "right"? "active" : "")+'"  data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="right"> Right </button>': "" )+' </div>  </a>';

                    }
                }
                else{
                    if (valueComponent.NumberOfPlacements === "1") {

                        output += `
                            <a    data-compname="`+ valueComponent.componentName + `"
                                data-maxUnique="${rules.MaxUnique}"
                                id="` + valueComponent.componentId + "-" +value.ID+ `"
                                data-wcid="` + value.ID + `" 
                                ${dataUnique}
                                data-position=''
                                data-action=""
                                class="  component-item-`+valueCategorydata.info.componentCatId + `-` + value.ID +`"
                                onclick="addItemComponent(this${dataUnique ? ", callToSubmit": ""})"
                                data-quantity="${quantity}"
                                data-price="` + (valueComponent.componentPrice ? valueComponent.componentPrice : 0) + `"
                                data-category="` + keyCategorydata + `"
                                data-componentid="`+ valueComponent.componentId +`"
                                data-catid="` + valueCategorydata.info.componentCatId + `-` + value.ID + `"
                                data-rule= "` + rulesId + `"
                                data-component= "` + valueComponent.componentName + `"   
                                data-siteId="${valueComponent.siteId}"
                                data-placements="${valueComponent.NumberOfPlacements}"
                                data-isDefault="${valueComponent.isDefault}"
                            > 
                                <div class="col-12 item-main-container">
                                    ${nameComponent}
                                    <div class="qty-controls-container">` +(componentsArr[valueComponent.componentId] > 0? rules.MaxUnique === 1 ? '<div class="quantity-controls"><i class="fa-solid fa-circle-check"></i> </div>' :  '<div class="quantity-controls"> <i class="fa-solid fa-circle-minus" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onclick="removeitems(this)"></i> <div class="component-qty " > '+componentsArr[valueComponent.componentId]+'</div> <i class="fa-solid fa-circle-plus""></i> </div> ' : "" ) +  ` </div> </div></a>
                        `;
                    }
                    else {
                        output += `
                            <a  data-compname="`+ valueComponent.componentName + `"
                                data-maxUnique="${rules.MaxUnique}"
                                id="` + valueComponent.componentId + "-" +value.ID+ `"
                                data-wcid="` + value.ID + `"
                                ${dataUnique}
                                data-position='`+positionArr[valueComponent.componentId]+`'
                                data-action=""
                                class="component-item-`+valueCategorydata.info.componentCatId + `-` + value.ID +`"
                                onclick="addItemComponent(this${dataUnique ? ", callToSubmit": ""})"
                                data-quantity="${quantity}"
                                data-price="` + (valueComponent.componentPrice ? valueComponent.componentPrice : 0) + `"
                                data-category="` + keyCategorydata + `"
                                data-componentid="`+ valueComponent.componentId +`"
                                data-catid="` + valueCategorydata.info.componentCatId + `-` + value.ID + `"
                                data-rule= "` + rulesId + `"
                                data-component= "` + valueComponent.componentName + `"
                                data-siteId="${valueComponent.siteId}"
                                data-placements="${valueComponent.NumberOfPlacements}"
                                data-isDefault="${valueComponent.isDefault}"  
                            >
                                <div class="col-12 item-main-container">
                                    ${nameComponent}
                                    <div class="qty-controls-container">` +(componentsArr[valueComponent.componentId] > 0? rules.MaxUnique === 1 ? '<div class="quantity-controls"><i class="fa-solid fa-circle-check"></i> </div>' :  '<div class="quantity-controls"> <i class="fa-solid fa-circle-minus" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onclick="removeitems(this)"></i> <div class="component-qty " > '+componentsArr[valueComponent.componentId]+' </div> <i class="fa-solid fa-circle-plus""></i> </div> ' : "" ) +  ' </div> </div> <div class="position-container col-12"> '+(componentsArr[valueComponent.componentId] > 0  ?  '<button class="position-selector" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="left"> Left </button> <button class="position-selector active" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="whole"> Whole </button> <button class="position-selector" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="right"> Right </button>': "" )+' </div>  </a>';
                    }
                }
            }
            else{
                if (valueComponent.NumberOfPlacements === "1") {

                    output += `
                        <a  data-compname="`+ valueComponent.componentName + `"
                            data-maxUnique="${rules.MaxUnique}"
                            id="` + valueComponent.componentId + "-" +value.ID+ `"
                            data-wcid="` + value.ID + `"
                            ${dataUnique}
                            data-position=''
                            data-action=""
                            class="`+ (valueComponent.isDefault > 0? "active" : "" )+` component-item-`+valueCategorydata.info.componentCatId + `-` + value.ID +`"
                            onclick="addItemComponent(this${dataUnique ? ", callToSubmit": ""})"
                            data-quantity="`+(quantity)+`"
                            data-price="` + (valueComponent.componentPrice ? valueComponent.componentPrice : 0) + `"
                            data-category="` + keyCategorydata + `"
                            data-componentid="`+ valueComponent.componentId +`"
                            data-catid="` + valueCategorydata.info.componentCatId + `-` + value.ID + `"
                            data-rule= "` + rulesId + `"
                            data-component= "` + valueComponent.componentName + `"
                            data-siteId="${valueComponent.siteId}"
                            data-placements="${valueComponent.NumberOfPlacements}"
                            data-isDefault="${valueComponent.isDefault}"
                            data-siteId="${valueComponent.siteId}"
                            data-placements="${valueComponent.NumberOfPlacements}"
                            data-isDefault="${valueComponent.isDefault}"
                        > 
                            <div class="col-12 item-main-container">
                                ${nameComponent}
                                <div class="qty-controls-container">` +(valueComponent.isDefault > 0? rules.MaxUnique === 1 ? '<div class="quantity-controls"><i class="fa-solid fa-circle-check"></i> </div>' :  '<div class="quantity-controls"> <i class="fa-solid fa-circle-minus" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onclick="removeitems(this)"></i> <div class="component-qty " > 1 </div> <i class="fa-solid fa-circle-plus""></i> </div> ' : "" ) +  ` </div> </div></a>
                        `;
                } else {
                    output += `
                        <a  data-compname="`+ valueComponent.componentName + `"
                            data-maxUnique="${rules.MaxUnique}"
                            id="` + valueComponent.componentId + "-" +value.ID+ `"
                            data-wcid="` + value.ID + `"
                            ${dataUnique}
                            data-position='`+(valueComponent.isDefault > 0 ? "": "")+`'
                            data-action=""
                            class="`+ (valueComponent.isDefault > 0? "active" : "" )+` component-item-`+valueCategorydata.info.componentCatId + `-` + value.ID +`"
                            onclick="addItemComponent(this${dataUnique ? ", callToSubmit": ""})"
                            data-quantity="`+(quantity)+`"
                            data-price="` + (valueComponent.componentPrice ? valueComponent.componentPrice : 0) + `"
                            data-category="` + keyCategorydata + `"
                            data-componentid="`+ valueComponent.componentId +`"
                            data-catid="` + valueCategorydata.info.componentCatId + `-` + value.ID + `"
                            data-rule= "` + rulesId + `"
                            data-component= "` + valueComponent.componentName + `"
                            data-siteId="${valueComponent.siteId}"
                            data-placements="${valueComponent.NumberOfPlacements}"
                            data-isDefault="${valueComponent.isDefault}"
                        >
                                <div class="col-12 item-main-container">
                                 ${nameComponent}
                                 <div class="qty-controls-container">` +(valueComponent.isDefault > 0? rules.MaxUnique === 1 ? '<div class="quantity-controls"><i class="fa-solid fa-circle-check"></i> </div>' :  '<div class="quantity-controls"> <i class="fa-solid fa-circle-minus" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onclick="removeitems(this)"></i> <div class="component-qty " > 1 </div> <i class="fa-solid fa-circle-plus""></i> </div> ' : "" ) +  ' </div> </div> <div class="position-container col-12"> '+(valueComponent.isDefault > 0  ?  '<button class="position-selector" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="left"> Left </button> <button class="position-selector active" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="whole"> Whole </button> <button class="position-selector" data-componentid="' + valueComponent.componentId + "-" +value.ID+ '" onClick="selectposition(this)" data-position="right"> Right </button>': "" )+' </div>  </a>';

                }
            }
        });
        output +=`
          </div>
        </div>    
        </div> 
        `;
    });
    if (componentCount > 3) {
        jQuery('.modal').addClass('bigger');
    } else {
        jQuery('.modal').removeClass('bigger');
    }

    return output;



}


function addComponentdefaultgetdata(data, value) {
    jQuery.each(data, function (keyCategorydata, valueCategorydata) {
        jQuery.each(valueCategorydata.items, function (keyComponent, valueComponent) {
            if (valueComponent.isDefault) {
                addComponentdefault(valueComponent.NumberOfPlacements, valueCategorydata.info.componentCatId + "-" + value.ID, valueComponent, valueComponent.ruleId, valueCategorydata.info.rules, keyCategorydata, valueComponent.componentPrice, value.ID);
                checkMinimunRequired(valueCategorydata.info.componentCatId + "-" + value.ID, valueComponent, valueComponent.ruleId, valueCategorydata.info.rules, keyCategorydata);
            }
        });
    });
}
function addeditedComponentdefaultgetdata(data, value , componentsArr , positionArr) {

    jQuery.each(data, function (keyCategorydata, valueCategorydata) {
        jQuery.each(valueCategorydata.items, function (keyComponent, valueComponent) {
            if( componentsArr[valueComponent.componentId] ){
                addComponentdefault(valueComponent.NumberOfPlacements, valueCategorydata.info.componentCatId + "-" + value.ID, valueComponent, valueComponent.ruleId, valueCategorydata.info.rules, keyCategorydata, valueComponent.componentPrice, value.ID , componentsArr[valueComponent.componentId] , positionArr[valueComponent.componentId] );
                checkMinimunRequired(valueCategorydata.info.componentCatId + "-" + value.ID, valueComponent, valueComponent.ruleId, valueCategorydata.info.rules, keyCategorydata);
            }
        });
    });
}

function openDropdown() {
    jQuery('.dropdown-content').toggleClass('show');
}


function addComponenttodisplay(e) {
    componentRule = jQuery(e).data('rule');
    rules = rulesGlobal[componentRule];
    text = jQuery(e).data('component');
    position = jQuery(e).data('position');
    category = jQuery(e).data('category');
    price = jQuery(e).data('price');
    id = jQuery(e).attr('id');
    catid = jQuery(e).data('catid');
    items = jQuery(".component-item-" + catid).length;
    wcId = jQuery(e).data('wcid');
    sameItem = jQuery("#" + id + "-" + wcId).length

    if (items < rules.MaxAllowed) {
        if (sameItem == 0) {
            jQuery("#compo-" + catid).append('<a data-compname="' + text + '" id="' + id + '-' + wcId + '" class="component-item-' + catid + '" onClick="removefromdisplay(this)" ' + (position ? 'data-position="' + position + '"' : "data-position=''") + ' data-price="' + (price ? price : 0) + '"data-category="' + category + '" data-componentid = "' + id + '" data-catid="' + catid + '" data-quantity=1 data-rule="' + componentRule + '">' + text + " (1) " + (position ? position : " ") + (price != 0 ? " $" + price : "") + '<i class="fa-solid fa-xmark"></i></a>');
            selectedComp.push(id);
        }
        else {
            let alink = jQuery("#" + id + "-" + wcId);

            tmpQty = alink.data("quantity");
            tmpQty = tmpQty + 1
            alink.data("quantity", tmpQty);

            alink.html(text + "(" + tmpQty + ") " + (position ? position : " ") + (price != 0 ? " $" + price * tmpQty : "") + "<i class=\"fa-solid fa-xmark\"></i>");
            selectedComp.push(id);
        }

    } else {
        jQuery(".alert-danger").html("just " + rules.MaxAllowed + " items allowed");
        jQuery(".alert-danger").show();
    }
    num = jQuery("#compo-" + catid + " a").length;
    if (rules.MinRequired != 0) {
        if (num < rules.MinRequired) {
            categoryerror = jQuery(".error-compo-" + catid).length;
            if (categoryerror === 0) {
                jQuery('<div class="error-compo-' + catid + '"> Please select at least ' + rules.MinRequired + ' ' + category + ' </div>').insertBefore("#compo-" + catid);
            }
        } else {
            jQuery('.error-compo-' + catid).remove();
        }
    }

}
function addComponentdefault(placement, datacat, datacompo, rule, rulesdata, category, price, productWcId, editquantity , currentPosition) {
    rule = rule;
    rules = rulesdata[rule];
    text = datacompo.componentName;
    id = datacompo.componentId;
    catid = datacat;
    wcId = productWcId;
    items2 = jQuery(".active.component-item-" + catid);
    totalItems = 0
    jQuery.each(items2 , function (key , value ){
        totalItems += parseFloat(value.attributes['data-quantity'].value);
    })
    items = jQuery(".active.component-item-" + catid).length;
    if (totalItems < rules.MaxAllowed) {
        /*     jQuery("#compo-" + catid).append('<a data-compname="' + text + '" id="' + id + '-' + productWcId + '" data-wcid="' + productWcId + '" class="component-item-' + catid + '" data-quantity="'+(editquantity  ? editquantity : 1)+'" onClick="removefromdisplay(this)" ' + (placement === "2" ? currentPosition ? 'data-position=' + currentPosition :  'data-position=' + '"whole"' : "data-position='' ") + ' data-price="' + (price ? price : 0) + '" data-category="' + category + '" data-componentid = "' + id + '" data-catid="' + datacat + '" data-rule="' + rule + '">' + text + " ("+(editquantity  ? editquantity : 1)+") " + (placement === "2" ? currentPosition ? currentPosition : "Whole" : " ") + (price != 0 ? " $" + price : "") + '<i class="fa-solid fa-xmark"></i></a>');
            selectedComp.push(id); */
    } else {
        jQuery("#"+catid + " .component-warning").html("(just " + rules.MaxAllowed + " items allowed)");
    }

}
function removefromdisplay(e) {
    componentRule = jQuery(e).data('rule');
    rules = rulesGlobal[componentRule];
    id = jQuery(e).attr('id');
    catid = jQuery(e).data('catid');
    qty = jQuery(e).data('quantity');
    category = jQuery(e).data('category');
    cpname = jQuery(e).data('compname');
    if (qty > 1) {
        qty = qty - 1
        jQuery(e).data('quantity', qty);
        jQuery(e).text(cpname + "(" + qty + ")");
    }

    else {
        text = jQuery(e).text();
    }


    num = jQuery("#compo-" + catid + " a").length;
    if (rules.MinRequired != 0) {
        if (num < rules.MinRequired) {
            categoryerror = jQuery(".error-compo-" + catid).length;
            if (categoryerror === 0) {
                jQuery('<div class="error-compo-' + catid + '"> Please select at least ' + rules.MinRequired + ' ' + category + ' </div>').insertBefore("#compo-" + catid);
            }

        } else {

        }
    }
}


// Checks the minimum required for components
function checkMinimunRequired(datacat, datacompo, rule, rulesdata, category) {
    num = jQuery("#compo-" + datacat + " a").length;
    if (rulesdata[rule].MinRequired != 0) {
        if (num < rulesdata[rule].MinRequired) {
            jQuery('<div class="error-compo-' + datacat + '"> Please select at least ' + rulesdata[rule].MinRequired + ' ' + category + ' </div>').insertBefore("#compo-" + datacat);
        }
    }
}

function addQuantity(e) {
    id = jQuery(e).data('prodid');

    number = jQuery('.quantity-number-' + id).text();
    number++;
    jQuery('.quantity-number-' + id).html(number);
}

function minusQuantity(e) {
    id = jQuery(e).data('prodid');
    number = jQuery('.quantity-number-' + id).text();
    if (number != 0) {
        number--;
    }
    jQuery('.quantity-number-' + id).html(number);
}

function spinnerON() {
    jQuery('#main-modal').html(`<div style="display:none" class="d-flex justify-content-center">
  <div class="spinner-border" style="width: 40rem; height: 40rem;color:white;" role="status">
    <span class="sr-only">Loading...</span>
  </div>
</div>`);
    jQuery('#modal-container').show();
    jQuery('#main-modal').css("background-color", "transparent");
    jQuery('#main-modal').css("box-shadow", "0 0");
}

function spinnerOFF() {
    jQuery("#modal-container").hide();
    jQuery('#main-modal').css("background-color", "white");
}




window.onclick = function (event) {
    if (!event.target.matches('.dropbtn')) {
        var dropdowns = document.getElementsByClassName("dropdown-content");
        var i;
        for (i = 0; i < dropdowns.length; i++) {
            var openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('show')) {
                openDropdown.classList.remove('show');
            }
        }
    }
    if (jQuery('#modal-container').is(":visible")) {
        if (!event.target.matches('#modal-container')) {
            jQuery('#modal-container').hide();
        }
    }
}
window.onclick = function (event) {
    var tar = jQuery(event.target);
    if (jQuery('#modal-container').is(":visible")) {
        if (event.target.matches('.blocker')) {
            jQuery('#modal-container').hide();
        }
    }
    if (event.target.matches('#myDropdown') || event.target.matches('.dropbtn') || event.target.matches('#item-price-global') || jQuery(tar).parents().is('#myDropdown') || jQuery(tar).parents().is('#cart-icon' || jQuery(tar).parents().is('.price'))) {
    } else {
        var dropdowns = document.getElementsByClassName("dropdown-content");
        var i;
        for (i = 0; i < dropdowns.length; i++) {
            var openDropdown = dropdowns[i];
            if (openDropdown.classList.contains('show')) {
                openDropdown.classList.remove('show');
            }
        }
    }
}

function timeOutWarning()
{
    jQuery('#main-modal').html(`<div style="display:none;color:white" class="d-flex justify-content-center">
   <div><h1>`+kiosk_vars_object.timeout_message+` &nbsp<h1></div>
  <div id="timeout-div"></div><div> <h1>&nbspseconds</h1></div>
 </div>
 <div class="d-grid gap-2">
  <button style="
  height: 100px;font-size:xx-large" onclick='moreTime()' class="btn btn-primary" type="button">I need more time</button>  
</div>
 `);
    jQuery('#modal-container').show();
    jQuery('#main-modal').css("background-color", "transparent");
    jQuery('#main-modal').css("box-shadow", "0 0");
}

function moreTime()
{
    jQuery("#modal-container").hide();
    jQuery('#main-modal').css("background-color","white");
    timeout=kiosk_vars_object.timeout;
}
let $container = $('.card-deck');
var nextP=0;
var counter=1;
function getAllProducts()
{
    if(nextP<counter)
    {
        jQuery(".loading").show();
        jQuery.ajax({
            type: "POST",
            url: wc_add_to_cart_params.ajax_url,
            data: {
                action: 'getAllProducts_kiosk',
                offset: nextP
            },
            success: function (data) {
                counter=data.count;

                jQuery( ".category-item" ).removeClass( "active" );
                nextP=nextP+12;
                //  jQuery('#wc-products').html(data);
                let output = "";
                jQuery.each(data.produtcs, function (key, value) {
                    const arr = value.price.split(',');
                    let prices = "";

                    arr.forEach(element => prices += '$' + parseFloat(element).toFixed(2) + '');
                    output += ` 
                          <div class="card item" data-cat=`+value.cat_id+`> 
                          <a href="#`+ value.ID + `" data-prodid="` + value.ID + `" class="modal-opener" onClick="openModal(this)">
                          <img class="card-img-top" src="`+ value.image + `" alt="${value.desciption}">
                          <div class="card-body">
                          <h5 class="card-title">`+ value.post_title + `</h5>
                          <div class="card-text">`+ prices + `</div>
                          </div>
                          </a>
                          </div>
                `;
                    valueGlobal[value.ID] = value;
                    //  getSingleProductComponents(value.ID);
                });
                output += '';

                jQuery(".card-deck").append(output);
                /*  jQuery('#back_to_cat').click(function(){
                   jQuery('#imgloading').show();
                    getCategories();
                  });*/


            },
            complete: function (data) {
            }
        }).done(function (data) {
            jQuery("[data-termid="+data.lastCat+"]").addClass("active");
            jQuery(".loading").hide();
            spinnerOFF();
        });
    }

}
function getCookie(name) {
    var dc = document.cookie;
    var prefix = name + "=";
    var begin = dc.indexOf("; " + prefix);
    if (begin == -1) {
        begin = dc.indexOf(prefix);
        if (begin != 0) return null;
    }
    else
    {
        begin += 2;
        var end = document.cookie.indexOf(";", begin);
        if (end == -1) {
            end = dc.length;
        }
    }
    // because unescape has been deprecated, replaced with decodeURI
    //return unescape(dc.substring(begin + prefix.length, end));
    return decodeURI(dc.substring(begin + prefix.length, end));
}

jQuery(document).ready(function(){




    const cardDeck=document.getElementById("productsdiv");
    cardDeck.addEventListener("scroll", function(event){
        const e = event.target;
        if (e.scrollHeight - e.scrollTop === e.clientHeight) {

            if(noCat==0)
            {
                getProductByCategory(categories_kiosk[actualCat].term_id,actualCat,true);
            }
            noCat=0;
        }
    });
});
jQuery('#guestBtn').click(function () {
    var guestPhone1= jQuery("#guestPhone").val();
    var orderType = jQuery(this).attr('data-order');

    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'setGuestIdintifier',
            guestPhone: guestPhone1

        }
    }).done(function(){
        jQuery(".phone-number").text("xxx-xxx-" + guestPhone1.substr(-4));
        jQuery('#guestIdentifier').hide();
        switch(orderType){
            case "dineIn" :
                dinein();
                break;
            case "takeOut":
                takeout();
                break;
            case "preOrder":
                preorder();
                break;
            case "none":
                jQuery('#preorder').show();
                getCategories();
                break;
            default:
                console.log("not found");
                break;
        }
    });

});

jQuery('#registerBtn').click(function () {
    const daysToExpire = new Date(2147483647 * 1000).toUTCString();
    spinnerON();
    var device= jQuery("#devicepin").val();
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'registerDevice',
            pin:device
        },
        success: function (data) {

            if (data !== undefined && data !== null && data.Error === undefined) {
                console.log('device ID Found')
                document.cookie = "kioskcbs_deviceId="+data.DeviceId+"; expires="+daysToExpire+"; path=/";
                document.cookie = "kioskcbs_registrationId="+data.RegistrationId+"; expires="+daysToExpire+"; path=/";
                location.reload();
            }else{
                jQuery('#error-invalid-pin').show();
            }
        }
    }).done(function () {
        spinnerOFF();
    });
});

function validateInputNumber(input) {
    let phoneNumber = formatPhoneNumber(input.value);
    input.value =  phoneNumber;

}
function formatPhoneNumber(input) {

    let phoneNumber = input.replace(/\D/g, '');

    if (phoneNumber.length > 10) {
        // Format as (XXX) XXX-XXXX
        return phoneNumber.replace(/(\d{3})(\d{3})(\d{4})/, '($1) $2-$3');
    } else {
        // Format as XXX-XXX-XXXX
        return phoneNumber.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
    }
}
function addDigit(digit) {
    var numberField = document.getElementById("guestPhone");
    if (numberField.value.length < 10) {
        let formattedNumber = numberField.value + digit;
        console.log(formattedNumber);
        numberField.value = formatPhoneNumber(formattedNumber);
    }
}
function removeDigit() {
    var numberField = document.getElementById("guestPhone");
    var number = numberField.value;
    numberField.value = number.substring(0, number.length - 1);
}

function getCookieValue(cookieName) {

    var cookies = document.cookie.split(';');


    for (var i = 0; i < cookies.length; i++) {
        var cookie = cookies[i].trim();

        if (cookie.indexOf(cookieName + '=') === 0) {
            return cookie.substring(cookieName.length + 1);
        }
    }

    // If the cookie was not found, return null
    return null;
}

function showGuestIdentifier(){
    var guestIdentifier = document.getElementById("guestIdentifier");
    var products = document.getElementById("mainDiv");
    products.style.display = "none";
    guestIdentifier.style.display = "flex";
}
function sendToCheckout()
{
    noCat=1;
    var ordertype = localStorage.getItem("ordertype");
    openDropdown();
    spinnerON();
    jQuery("#productsdiv").html(' ');
    jQuery.ajax({
        type: "POST",
        url: wc_add_to_cart_params.ajax_url,
        data: {
            action: 'checkout_kiosk',
            ordertype : ordertype
        },
        success: function (data) {
            jQuery("#productsdiv").html('');
            jQuery("#productsdiv").html(data);

            var dropdowns = document.getElementsByClassName("dropdown-content");
            var i;
            for (i = 0; i < dropdowns.length; i++) {
                var openDropdown = dropdowns[i];
                if (openDropdown.classList.contains('show')) {
                    openDropdown.classList.remove('show');
                }
            }
        }
    }).done(function(){
        spinnerOFF();
        jQuery(".woocommerce-billing-fields").html("");
        localStorage.removeItem("ordertype");
    });

}

jQuery(document).ready(function($) {
    jQuery(document).on('click', '#place_order', function(e) {
        spinnerON();
    });
});

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

//Checks the selection of serving options on the click event.
function checkSelectionServingOptions() {
    let controlFlag = true;
    jQuery('.serving-options-container fieldset').each(function() {
        const fieldsetId = $(this).attr('id');
        const radioButtons = $(this).find('input[type="radio"]');
        const isAnySelected = radioButtons.is(':checked');

        if (isAnySelected) {
            //if its selected
        } else {
            jQuery("#" + fieldsetId+ " .title").css('color', 'red');
            controlFlag = false;
        }
    });
    return controlFlag;
}

// Detects the change event for serving options
function detectServingOption(Id, attributes, variations) {
    jQuery('fieldset').change(function () {
        
        //adding total from components
        var currenttotal = jQuery(".total-price-container").data("componentstotalamount");
        var resulttotal = 0;
        conuntitems = 0;
        attributes_st = "";
        countTimes = 0;
        jQuery.each(attributes, function (key, value) {
            jQuery.each(value, function (key, attribs) {
                console.log("#" + Id + "-" + attribs);
                if (jQuery("#" + Id + "-" + attribs).is(':checked')) {
                    conuntitems++;
                    attributes_st += attribs;
                    jQuery.each(variations, function (keyvariations, vari) {
                        if (Object.keys(vari.attributes).length === 2) {
                            attributes_st_list = "";
                            if (conuntitems === 2) {
                                jQuery.each(vari.attributes, function (attkey, valueattr) {
                                    attributes_st_list += valueattr;
                                });
                            }
                            if (attributes_st_list) {
                                if (attributes_st_list === attributes_st) {
                                    console.log(attributes_st + "Id is: " + keyvariations);
                                    if (currenttotal) {
                                        resulttotal = parseFloat(vari.dispaly_price) + parseFloat(currenttotal);
                                    } else {
                                        resulttotal = vari.dispaly_price;
                                    }
                                    const resultTotalRounded = parseFloat(resulttotal).toFixed(2);
                                    jQuery('.total-' + Id).html("$" + resultTotalRounded);
                                    jQuery('.total-' + Id).attr("data-price", resulttotal);
                                    jQuery('.total-' + Id).data("price", resulttotal);
                                    jQuery('.total-' + Id).attr("data-variation", keyvariations);
                                    jQuery('.total-' + Id).attr("data-servingtotalamount", vari.dispaly_price);
                                }
                            }
                        } else {
                            datainf = jQuery("#" + Id + "-" + attribs).data('variation');
                            jQuery("#" + datainf + " .title").css('color', 'black');
                            if (vari.attributes[datainf] === attribs) {
                                if (currenttotal) {
                                    resulttotal = parseFloat(vari.dispaly_price) + parseFloat(currenttotal);
                                } else {
                                    resulttotal = vari.dispaly_price;
                                }
                                const resultTotalRounded = parseFloat(resulttotal).toFixed(2);
                                jQuery('.total-' + Id).html("$" + resultTotalRounded);
                                jQuery('.total-' + Id).attr("data-variation", keyvariations);
                                jQuery('.total-' + Id).data("price", resulttotal);
                                jQuery('.total-' + Id).attr("data-price", resulttotal);
                                jQuery('.total-' + Id).attr("data-servingtotalamount", vari.dispaly_price);
                                jQuery('.total-' + Id).data("servingtotalamount", vari.dispaly_price);
                            }
                        }
                    });
                }
            });
        });

        let customEvent = jQuery.Event('detectServingOption');
        jQuery(document).trigger(customEvent);
    });
}

function add_serving_options(data, productValue, infoId , varia) {
    variationArr = null ;
    variationArr = varia ;
    
    count++;

    if (data) {
        output = '';
        output += ` 
      <div class="serving-options-container">
      <form>
      `;


        const attributes = create_ordered_attributes(infoId.variations);

      jQuery.each(attributes,function (key, attribute) {
          const attributeName = key.replace("attribute_pa_", "");
          const title_variation = attributeName.replace(/-/g, " ");
          output += `
      <fieldset id="`+ key + `">
      <div class="serving-options-separator">
      <legend class="title" >`+ title_variation + `</legend> </div> <div  class="serving-options-separator" >`;
          jQuery.each(attribute, function (key_attr, attr) {
              if(attr != ""){
                  output += ` <input type="radio" id="` + infoId.id + "-" + attr + `" name="` + key + `" data-variation="` + key + `" value="` + attr + `">
        <label for="`+ infoId.id + "-" + attr + `" name="` + key + `">` + attr + `</label><br>`;
              }
          });
          output += `
    </div>
      </fieldset>
`;

      })

        output += `
      </form> 
      </div> `;

        return output;
    }
}

function create_ordered_attributes (variations) {


      const variationsWithOrderArray = Object.keys(variations).map(function(key) {
        return variations[key];
      })

      variationsWithOrderArray.sort(function(a, b) {
            return a.display_order - b.display_order;
        });

        //create a new object with the key (attribute_pa_) and attribute
        const attributes = {};
        jQuery.each(variationsWithOrderArray, function(index, item) {
            jQuery.each(item.attributes, function(key, value) {
                if (!attributes[key]) {
                    attributes[key] = [];
                }
        
                attributes[key].push(value);
            });
        });

        return attributes
}

function edit_serving_options(data, productValue, infoId , varia) {
    variationArr = null ;
    variationArr = varia ;

    count++;
    if(varia ){
        console.log(varia.length);
        console.log(varia);

    }
    if (data) {
        output = '';
        output += ` 
      <div class="serving-options-container">
      <form>
      `;

      const attributes = create_ordered_attributes(infoId.variations);

        jQuery.each(attributes, function (key, value) {
            replace_res = key.replace("attribute_pa_", "");
            title_variation = replace_res.replace(/-/g, " ");
            output += `
        <fieldset id="`+ key + `">
        <div class="serving-options-separator">
        <legend class="title" >`+ title_variation + `</legend>  </div> <div class="serving-options-separator">`;
            jQuery.each(value, function (key_attr, attr) {
                if(varia[attr]){
                    output += ` <input type="radio" id="` + infoId.id + "-" + attr + `" name="` + key + `" data-variation="` + key + `" value="` + attr + `" checked>
          <label for="`+ infoId.id + "-" + attr + `" name="` + key + `">` + attr + `</label><br>
`;
                }else{
                    output += ` <input type="radio" id="` + infoId.id + "-" + attr + `" name="` + key + `" data-variation="` + key + `" value="` + attr + `">
          <label for="`+ infoId.id + "-" + attr + `" name="` + key + `">` + attr + `</label><br>
`;
                }

            });
            output += `
        </div>
        </fieldset>
  `;

        });
        output += `
      </form> 
      </div> `;

        return output;
    }
}
