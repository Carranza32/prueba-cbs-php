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
