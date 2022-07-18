var count = 0; // <== make the variable global
jQuery(document).ready(function($) {

  // set an intervel. The callback gets executed every interval
  var setInterval1_ID = setInterval(triggerAjax, 60000); // 60 sec updates

  var toggleGridSwitch = 0;

  // var timeout1_ID = setTimeout(stopSetInterval1, 100000); // this is 100 seconds for 10 updates

  $(document).on("click","#grid_status_icon",function() {
                                                          toggleGridSwitch = 1;
                                                    }
                );

  function triggerAjax() {


                            $.post(my_ajax_obj.ajax_url,
                            {                                 //POST request
                              _ajax_nonce: my_ajax_obj.nonce, //nonce extracted and sent
                              action: "my_solar_update",  // hook added for action wp_ajax_my_solar_update in php file
                              toggleGridSwitch: toggleGridSwitch
                            },
                              function(data) {	// data is JSON data sent back by server in response, wp_send_json($somevariable)
                                                // update the page with new readings. Lets just log the value sto see if we are getting good data
                                                console.log('data: ', data);
                                                // console.log('battery html', $('#power-battery').html());
                                            });

                            // reset the toggle function to 0 to prevent further actions
                            toggleGridSwitch = 0;
                        };
    function round(value, exp) {
    if (typeof exp === 'undefined' || +exp === 0)
      return Math.round(value);

    value = +value;
    exp = +exp;

    if (isNaN(value) || !(typeof exp === 'number' && exp % 1 === 0))
      return NaN;

    // Shift
    value = value.toString().split('e');
    value = Math.round(+(value[0] + 'e' + (value[1] ? (+value[1] + exp) : exp)));

    // Shift back
    value = value.toString().split('e');
    return +(value[0] + 'e' + (value[1] ? (+value[1] - exp) : -exp));
  }

});
