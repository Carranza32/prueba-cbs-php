jQuery(document).ready(function($) {
    $('#clear-register').on('click', function() {
        var url = jQuery(this).attr('url');
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'clear_register_action',
            },
            success: function(response) {
                updateRegister(url);
                window.location.reload();
            },
            error: function(response) {
                console.log('error clearing register');
            }
        });
    });

    function updateRegister(url){
        jQuery.ajax({
            url: url,
            type: "GET",
            cache: false,
            success: function(data) {
                console.log("webhooks registration called");
            }
          });
    }
});


function addAreasOptions($select) {

    var selectedValue = $select.val();
    var rowid = $select.attr('rowid');
    var selectContainer = jQuery(".area"+ rowid);

    var $loadingIcon = jQuery('<div id="loading-icon-cbs-settings"><img src="' + saveProductData.loadingIconUrl + '" alt="Loading..."></div>');
    selectContainer.append($loadingIcon);
    
            if (selectedValue === 'Default') {
                var rowid = $select.attr('rowid');
                var tokenInput = jQuery('input[name="token"]');
                var instance = jQuery('#instance');
                let areaContainer = jQuery(".area"+ rowid);
                var areaActive = areaContainer.attr('data-defaultarea');
    
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'menu_day_parts',
                        siteId: rowid,
                        token: tokenInput.val(),
                        instance: instance.val()
                    },
                    success: function(response) {
                        console.log('response');
                        console.log(response)
                        let messages = response.data;
                        var $select = jQuery('<select></select>');
                        $select.attr('name', "area" + rowid);
    
                        jQuery.each(response.data, function(index, option) {
                            var $option = jQuery('<option></option>')
                            .val(index)
                            .text(option)
                            .attr('name', "area" + rowid )
                    

                        if (index == areaActive) {
                            $option.prop('selected', true);
                        }
                        $select.append($option);
                        });
    
    
                        selectContainer.html($select);
    
                        
    
                        if (Array.isArray(messages) && messages.length > 0) {
                            let message = messages.join('\n');
                            alert(message)
    
                          } else if (typeof messages === 'string' && messages.length > 0) {
                            let message = messages;
                            alert(message)
    
                          }
                    },
                    error: function(response) {
                        let $message = 'There was an unknown error deleting the product';
                        alert($message)
                    }
                });
                
            }else{
                var rowid = $select.attr('rowid');
                selectContainer.html("Area no selected");
            }
}



jQuery(document).ready(function($) {
    jQuery('select').each(function() {
        var selectedOption = jQuery(this).find('option:selected').text();
        if (selectedOption === 'Default') {

            addAreasOptions(jQuery(this));
        }
    });

    $('select').on('change', function() {
        addAreasOptions(jQuery(this)); 
    });
});

const siteDisplay = document.getElementById('display_mode');
siteDisplay?.addEventListener('change', (e) => {
    const siteDisplayValue = siteDisplay.value;
    fetch(ajaxurl, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
    },
    body:new URLSearchParams({
        action: 'update_site_display_mode',
        siteMode: siteDisplayValue,
    })
    })
    .then(response => response.json())
    .then(data => {
        console.log(data);
    });
});

const imageCompression = document.getElementById('image_compression');
imageCompression?.addEventListener('change', (e) => {
    console.log('image compression changed');
    const imageCompressionValue = imageCompression.value;
    fetch(ajaxurl, {
    method: 'POST',
    headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
    },
    body:new URLSearchParams({
        action: 'update_image_compression',
        image_compression: imageCompressionValue,
    })
    })
    .then(response => response.json())
    .then(data => {
        console.log(data);
    });
});