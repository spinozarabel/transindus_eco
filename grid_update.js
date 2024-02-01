// declare global variables before the ready function
var count             = 0; 

var toggleGridSwitch  = 0;

var doShellyToggle    = 0;

jQuery(document).ready(function($) {

  // Trigger the Ajax that updates my solar screen based on minutely cron readings
  // There is no user interaction - this keeps running as long as mysolar page is being viewd
  triggerAjaxForCronNativeUpdates();

  
  function triggerAjax() {

      var data =  { toggleGridSwitch    : toggleGridSwitch,
                    doShellyToggle      : doShellyToggle,
                  };
      $.post(my_ajax_obj.ajax_url,
      {                                 //POST request
        _ajax_nonce: my_ajax_obj.nonce, //nonce extracted and sent
        action: "my_grid_cron_update",  // hook added for action wp_ajax_my_solar_update in php file
        data: data
      },
        function(data) {	// data is JSON data sent back by server in response, wp_send_json($somevariable)

            // update the screen with new readings from Ajax Call
            updateScreenWithNewData(data);
            // update the counter
            count++;
            // Do this foa total of 5 times
            if(count >= 4) {   // remove all animations                                                   
                
            }
            else {
                var timeout_ID = setTimeout(triggerAjax, 5000); // this is 5s delay
            }
        }
        );  // end of ajax post

      
  };  // end of triggerAjax function
                
    function triggerAjaxForCronNativeUpdates() {

      var data =  "no data";

      $.post(my_ajax_obj_grid_view.ajax_url,
        {                                 //POST request
          _ajax_nonce: my_ajax_obj_grid_view.nonce, //nonce extracted and sent
          action: "my_grid_cron_update",  // hook added for action wp_ajax_my_solar_cron_update in php file
          data: data
        },
          function(data) {	// data is JSON data sent back by server in response, wp_send_json($somevariable)
  
              // update the screen with new readings from Ajax Call
              updateScreenWithNewData(data);
              
              var timeout_ID1 = setTimeout(triggerAjaxForCronNativeUpdates, 2500); // this is 5s delay
          }
          );  // end of ajax post

    };

    function updateScreenWithNewData(data) {

        // update only if desired page elements exist. So check for some of them:
        if( data.a_phase_grid_voltage_html ) {
            // update the Grid  Switch Icon
            $('#a_phase_grid_voltage').html( data.a_phase_grid_voltage_html);
        }
        if( data.b_phase_grid_voltage_html ) {
            // update the Grid  Switch Icon
            $('#b_phase_grid_voltage').html( data.b_phase_grid_voltage_html);
        }
        if( data.c_phase_grid_voltage_html ) {
            // update the Grid  Switch Icon
            $('#c_phase_grid_voltage').html( data.c_phase_grid_voltage_html);
        }
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
