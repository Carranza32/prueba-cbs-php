 function setCookies(name, value, exp_days) {
       var d = new Date();
       d.setTime(d.getTime() + (exp_days * 24 * 60 * 60 * 1000));
       var expires = "expires=" + d.toGMTString();
       document.cookie = name + "=" + value + ";" + expires + ";path=/";
     }

     function deleteCookies(cname, cvalue, exMins) {
       var d = new Date();
       d.setTime(d.getTime() + (exMins * 60 * 1000));
       var expires = "expires=" + d.toUTCString();
       document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
     }

          const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
          setCookies('cbs_local_timezone', timezone, 1);

          const date = new Date();
          const offset = date.getTimezoneOffset();
          setCookies('cbs_local_offset', offset, 1); 