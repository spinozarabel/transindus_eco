// declare global variables before the ready function
var count             = 0; 

var toggleGridSwitch  = 0;

var doShellyToggle    = 0;

jQuery(document).ready(function($) {

  // Trigger the Ajax that updates my solar screen based on minutely cron readings
  // There is no user interaction - this keeps running as long as mysolar page is being viewd
  triggerAjaxForCronNativeUpdates();

  // this is the on-demand readings update
  $(document).on("click","#studer_icon",function() {
      // initialize the counter
      count = 0;
      // set spinner in motion
      $("#studer_icon").addClass("fa-spin");
      // make an initial Ajax call. This calls itself recursively 5 times
      triggerAjax();
 });

  // var timeout1_ID = setTimeout(stopSetInterval1, 100000); // this is 100 seconds for 10 updates

  $(document).on("click","#grid_status_icon",function() {
                                                          toggleGridSwitch = 1;
                                                          count = 4; // prevent multiple Ajax triggers
                                                          triggerAjax();
                                                        }
                );
  $(document).on("click","#shelly_servo_icon",function() {
                                                          doShellyToggle = 1;
                                                          triggerAjax();
                                                        }
                );
  function triggerAjax() {

      var data =  {toggleGridSwitch : toggleGridSwitch,
                      doShellyToggle : doShellyToggle,
                    wp_user_ID       : my_ajax_obj.wp_user_ID};
      $.post(my_ajax_obj.ajax_url,
      {                                 //POST request
        _ajax_nonce: my_ajax_obj.nonce, //nonce extracted and sent
        action: "my_solar_update",  // hook added for action wp_ajax_my_solar_update in php file
        data: data
      },
        function(data) {	// data is JSON data sent back by server in response, wp_send_json($somevariable)

            if (toggleGridSwitch) toggleGridSwitch = 0;

            if (doShellyToggle) doShellyToggle = 0;

            // update the screen with new readings from Ajax Call
            updateScreenWithNewData(data);
            // update the counter
            count++;
            // Do this foa total of 5 times
            if(count >= 4) {   // remove all animations                                                   
                $("#studer_icon").removeClass("fa-spin");
                $('#grid_arrow_icon').removeClass("fa-beat-fade");
                $('#pv_arrow_icon').removeClass("fa-beat-fade");
                $('#battery_arrow_icon').removeClass("fa-beat-fade");
                $('#load_arrow_icon').removeClass("fa-beat-fade");
                // all execution should stop here till further prompt from user
            }
            else {
                var timeout_ID = setTimeout(triggerAjax, 5000); // this is 5s delay
            }
        }
        );  // end of ajax post

      
  };  // end of triggerAjax function
                
    function triggerAjaxForCronNativeUpdates() {

      var data =  {wp_user_ID       : my_ajax_obj.wp_user_ID};

      $.post(my_ajax_obj.ajax_url,
        {                                 //POST request
          _ajax_nonce: my_ajax_obj.nonce, //nonce extracted and sent
          action: "my_solar_cron_update",  // hook added for action wp_ajax_my_solar_cron_update in php file
          data: data
        },
          function(data) {	// data is JSON data sent back by server in response, wp_send_json($somevariable)
  
              // update the screen with new readings from Ajax Call
              updateScreenWithNewData(data);
              
              var timeout_ID1 = setTimeout(triggerAjaxForCronNativeUpdates, 30000); // this is 30s delay
          }
          );  // end of ajax post

    };

    function updateScreenWithNewData(data) {

        // update only if desired page elements exist. So check for some of them:
        if( $('#grid_status_icon').length ) {
            // update the Grid  Switch Icon
            $('#grid_status_icon').html( data.grid_status_icon);
        
            // Updatehe Grid Power Flow Arrow
            $('#grid_arrow_icon').html( data.grid_arrow_icon).addClass("fa-beat-fade");

            // Updatehe Grid Info
            $('#grid_info').html( data.grid_info);

            //Update the PV solar Panel Grid Arrow
            $('#pv_arrow_icon').html( data.pv_arrow_icon).addClass("fa-beat-fade");

            // update psolar_info
            $('#psolar_info').html( data.psolar_info);

            // update battery icon
            $('#battery_status_icon').html( data.battery_status_icon);

            // update battery info
            $('#battery_info').html( data.battery_info);

            // update battery arrow
            $('#battery_arrow_icon').html( data.battery_arrow_icon).addClass("fa-beat-fade");

            // update load information
            $('#load_info').html( data.load_info);

            // update load information
            $('#load_arrow_icon').html( data.load_arrow_icon).addClass("fa-beat-fade");

            // update Shelly Servo icon
            $('#shelly_servo_icon').html( data.shelly_servo_icon);

            // update Shelly Servo icon
            $('#soc_percentage_now').html( data.soc_percentage_now_html);

            // update CRON exit condition and time it happened
            $('#cron_exit_condition').html( data.cron_exit_condition);
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
