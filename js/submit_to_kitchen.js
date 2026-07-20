function sendTokitchen(){
    document.querySelector('i.fa-spin.submit').style.display = 'block';
    document.querySelector('#submit_items_kitchen').disabled = true;
      jQuery.ajax({
          type: "POST",
          url: session_obj.ajax_url,
          data: { 
          action: 'session_request',
          nonce: session_obj.nonce,
          send_items: "true" },
          success: function(result) {
            console.log(result);
            if(result){
              window.location.reload();
            }
          }
        });
        jQuery(document).ajaxStop(function(){
          
      });
  }

