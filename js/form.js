var token=jQuery("#token").val();
var instance = jQuery('#instance').find(":selected").val();

jQuery(document).ready(function() {
  jQuery('#instance').change(function(){
  var instance_name = jQuery('#instance').find(":selected").text();
  jQuery('#instance_name').val(instance_name);
});
        jQuery('#example1,#example2 , #cbsconfiguration').DataTable({
          "pageLength": 10,
           "scrollY": "100%",
        "scrollCollapse": true,
        "scrollX":true,
        "pagingType": "numbers"
        });
      });
jQuery(document).ready(function(){
  jQuery("#saveproduct").click(function(){

 jQuery("#pageloader").fadeIn();
 //alert('Products saving...');

   });

  jQuery("#save1").click(function(){

    var temp = window.alert;
    window.alert = function() { };
    
    var table=jQuery('#example1,#example2 , #cbsconfiguration').DataTable( {
    });

    table.destroy();  // to reinitialize pagelength we need to destroy table

    var table=jQuery('#example1,#example2, #cbsconfiguration').DataTable( {
        pageLength: -1
    });
   
     window.alert = temp;

    
    var arr = new Array();
    var row = new Array();
    jQuery( "select[name='area']" ).each(function( index, value ) {
        if(jQuery(this).children("option:selected").val() != 'Please Select an Area'){
            arr.push(jQuery(this).children("option:selected").val());
            row.push(jQuery(this).attr('row-id'));
        }
    });


    jQuery('#areaval').val(arr);
    jQuery('#rowval').val(row);
    jQuery('#areaform').submit();
    jQuery("#pageloader").fadeIn();

    setTimeout(function() { jQuery("#myElem").hide(); }, 9000000);
  });


  jQuery("#update1").click(function(){

    var temp = window.alert;
    window.alert = function() { };
    
    var table=jQuery('#example1,#example2, #cbsconfiguration').DataTable( {
    });

    table.destroy();  // to reinitialize pagelength we need to destroy table

    var table=jQuery('#example1,#example2, #cbsconfiguration').DataTable( {
        pageLength: -1
    });
   
     window.alert = temp;

    
    var arr = new Array();
    var row = new Array();
    jQuery( "select[name='area']" ).each(function( index, value ) {
        if(jQuery(this).children("option:selected").val() != 'Please Select an Area'){
            arr.push(jQuery(this).children("option:selected").val());
            row.push(jQuery(this).attr('row-id'));
        }
    });
    jQuery('#areaval').val(arr);
    jQuery('#rowval').val(row);
    jQuery('#areaform').submit();
    jQuery("#pageloader").fadeIn();

    setTimeout(function() { jQuery("#myElem").hide(); }, 9000000);
  });


});


function getareasval(count,ref){
  var token=jQuery("#token").val();
    if (ref.value != 'Day Part') {
     return;
    }
    jQuery("#pageloader").fadeIn();
    var siteId =jQuery(ref).attr('rowId');
    var s_id=jQuery(ref).attr('id');
    var area_id = jQuery(ref).attr('data-area-id');
    var form = new FormData();
    form.append("siteId", siteId);
     let data=[];
     jQuery.ajax({
        type: "GET",
       url: instance+"/sites/"+siteId+"/",
        timeout: 0,
        headers: {
          Authorization: "Token "+token
        },
        processData: false,
        mimeType: "multipart/form-data",
        contentType: false,
        data: form,
        error: function(xhr){
        alert(xhr.statusText +" "+xhr.status+": An error occured while fetching areas (API might taking too much time to response or there are no areas found)");
        jQuery("#"+s_id).val("Disabled");
        jQuery("#pageloader").fadeOut();
         },
       
        success: function(response){
            let res = JSON.parse(response);
            data = res.Data;
        },

        complete: function(response){
            jQuery.ajax({
               type: "GET",
               url: instance+"/sites/"+siteId+"/menu/",
               timeout: 0,
               headers: {
                  Authorization: "Token "+token
               },
               processData: false,
               mimeType: "multipart/form-data",
               contentType: false,
               data: form,
               success: function(response2){
                  let res2 = JSON.parse(response2);
                  let data2 = res2.Data;
                  let area_list=data2.DataDefinitions.Areas.Area;

                  jQuery(data.WebOrderingAreas).each(function(index,value){
                    
                     jQuery(area_list).each(function(index1,value1){
 
                      if(value===value1.AreaId){
                        var selectid = jQuery(ref).attr('id').replace('purpose','business');
                        if(!jQuery("#"+selectid+" option[value="+value1.AreaId+"]").length > 0){
                           jQuery('#'+selectid).append(new Option(value1.Name, value1.AreaId));
                        }

                       
                          if(value1.AreaId==area_id){
                            jQuery('#'+selectid+' option[value='+area_id+']').attr('selected', 'selected');
                          }
                      }
                    });
                  });
                  jQuery("#pageloader").fadeOut();
               },
           });
        }
    });


}

function getAreaName(count,e){

  // var area_name= jQuery('#business'+count+' option:selected').text();
   var area_name=jQuery('#business'+count+' option:selected').map(function(){return this.text}).get().join(',');
  jQuery('#area_name_'+count).val(area_name);


// var selObj = document.getElementById('#business'+count);
//   var txtTextObj = document.getElementById('#area_name_'+count);
//   var selected = [];
    
//     for(var i=0, l=selObj.options.length; i < l; i++){
//         if(selObj.options[i].selected){
//             selected.push(selObj.options[i].textContent);
//         }
//     } 
//     txtTextObj.value = selected.join(', ');




  var areas_id = jQuery('#business'+count).val();
  jQuery('#areas_id_'+count).val(areas_id);
}
function blockSpecialChar(e){
    var k;
    document.all ? k = e.keyCode : k = e.which;
    return ((k > 64 && k < 91) || (k > 96 && k < 123) || k == 8 || k == 32 || (k >= 48 && k <= 57));
}

/* Deprected Function not Using Daypart Areas */
function getareasval2(count){
  var token=jQuery("#token").val();
    if (jQuery('#purpose'+count).val() != 'Day Part') {
     return;
    }
    var siteId = jQuery('#purpose'+count).attr('rowId');
    var area_id = jQuery('#purpose'+count).attr('data-area-id');
    var form = new FormData();
    form.append("siteId", siteId);
    var settings = {
      "url": instance+"/sites/"+siteId+"/areas/",
      "method": "GET",
      "timeout": 0,
      "headers": {
        "Authorization": "Token "+token
      },
      "processData": false,
      "mimeType": "multipart/form-data",
      "contentType": false,
      "data": form
    };


     let data=[];
     jQuery.ajax({
        type: "GET",
        url: instance+"/sites/"+siteId+"/",
        timeout: 0,
        headers: {
          Authorization: "Token "+token
        },
        processData: false,
        mimeType: "multipart/form-data",
        contentType: false,
        data: form,
        success: function(response){
            let res = JSON.parse(response);

            data = res.Data;
          let WebOrderingAreas= data.WebOrderingAreas;

      jQuery.ajax(settings).done(function (response) {
        var res = JSON.parse(response);
        var allareas = res.Data;
        var selectid = jQuery('#purpose'+count).attr('id').replace('purpose','business');
     jQuery(WebOrderingAreas).each(function( index1, value1 ) {   
      jQuery(allareas).each(function( index, value ) {
        if(value1===value.AreaId){
            jQuery('#'+selectid).show();
          if(!jQuery("#"+selectid+" option[value="+value.AreaId+"]").length > 0)
               {
            jQuery('#'+selectid).append(new Option(value.Name, value.AreaId));
               }
            if(value.AreaId==area_id)
            {
              jQuery('#'+selectid+' option[value='+area_id+']').attr('selected', 'selected');
            }
          }
      });
     });

    });



        }

      });
}


const modalContainer = document.querySelector('.out-stock-modal-container');
const closeButton = document.querySelector('.fa-x');


function hideModal() {
    if (modalContainer) {
        modalContainer.style.display = 'none'; 
    }
}

if (closeButton) {
    closeButton.addEventListener('click', hideModal);
}


const selectReplacementButton = document.querySelector('.select-replacement');
if (selectReplacementButton) {
    selectReplacementButton.addEventListener('click', () => {
        if (modalContainer) {
            modalContainer.style.display = 'flex'; 
        }
    });
}