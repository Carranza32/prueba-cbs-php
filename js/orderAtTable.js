(function ($) {
    const dlg = document.getElementById('successDialog');
    const errorDlg = document.getElementById('errorDialog');

  function initAreaContext() {
    const queryParams = getQueryParams();


    if (!queryParams.site_id || !queryParams.location || !queryParams.areaExternalCode) {
      console.warn("Missing required URL params");
      return;
    }


    const stored = $.cookie('ns_area_ctx') ? JSON.parse($.cookie('ns_area_ctx')) : null;


    if (stored && contextsEqual(stored, queryParams)) {
      console.log("Context unchanged. Skipping API call.");
      return;
    }


    $.ajax({
      url: session_obj.ajax_url,
      type: "POST",
      data: {
        action: 'set_area_context_action',
        nonce: session_obj.nonce,
        site_id: queryParams.site_id,
        location: queryParams.location,
        area: queryParams.areaExternalCode
      },
      success: function (response) {
        const dlg = document.getElementById('successDialog');
        const errorDlg = document.getElementById('errorDialog');

        console.log("API response:", response);
        console.log(response.data);
        if(response.success && response.data === "invalid_check"){

            $('#closeX').on('click', function() {
                location.reload();
            });
            $('#okBtn').on('click', function() {
                location.reload();
            });
            $('#successDesc').text("Location Available");
            dlg.showModal();
            

        }else if(response.success && response.data === "invalid_location"){
            $('#errorDesc').text("Please select a valid location");
            errorDlg.showModal();
        }else if (response.success && response.data === "current_check"){
            $('#successDesc').text("Current Check Loaded");
            $('#okBtn').on('click', function() {
                location.reload();
            });
            $('#closeX').on('click', function() {
                location.reload();
            });

            dlg.showModal();
        }

       
        $.cookie('ns_area_ctx', JSON.stringify(queryParams), { 
          path: '/', 
          expires: 30, 
          secure: location.protocol === 'https:'
        });

        $(document.body).trigger('wc_fragment_refresh');
      },
      error: function (xhr) {
        console.error("API request failed:", xhr.responseText);
      }
    });
  }




  function getQueryParams() {
    const params = new URLSearchParams(window.location.search);
    return {
      site_id: params.get('site_id'),
      location: params.get('location'),
      areaExternalCode: params.get('area')
    };
  }


  function contextsEqual(a, b) {
    return a.site_id === b.site_id &&
           a.location === b.location &&
           a.areaExternalCode === b.areaExternalCode;
  }


  $(document).ready(function () {
    initAreaContext();
  });

})(jQuery);
document.addEventListener('DOMContentLoaded', function() {
    const closeX = document.getElementById('closeX');
    const okBtn = document.getElementById('okBtn');
    const closeXerror = document.getElementById('closeXError');
    const okBtnerror = document.getElementById('okBtnError');
    const dlg = document.getElementById('successDialog');
    const errorDlg = document.getElementById('errorDialog');

    function closeDialogsuccess() { dlg.close(); }
    function closeDialogerror() { errorDlg.close(); }
    closeX.addEventListener('click', closeDialogsuccess);
    okBtn.addEventListener('click', closeDialogsuccess);
    closeXerror.addEventListener('click', closeDialogerror);
    okBtnerror.addEventListener('click', closeDialogerror);
});
