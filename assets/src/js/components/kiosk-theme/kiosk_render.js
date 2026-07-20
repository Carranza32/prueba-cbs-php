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
