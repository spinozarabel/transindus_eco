var count = 0; // <== make the variable global
jQuery(document).ready(function($) {

  // set an intervel. The callback gets executed every interval
  // var setInterval1_ID = setInterval(window_reload(), 120000); // 60 sec updates

  var toggleGridSwitch = 0;

  $(document).on("click","#refresh-button",function() {
      // initialize the counter
      count = 0;
      // set spinner in motion
      $("#studer_icon").addClass("fa-spin");
      // make Ajax call every 10s for 60s
      triggerAjax();
      // after all calls, remove all animations
 });

  //triggerAjax();

  function window_reload() {
                              clearInterval(setInterval1_ID);
                              window.location.reload();
  }

  // var timeout1_ID = setTimeout(stopSetInterval1, 100000); // this is 100 seconds for 10 updates

  $(document).on("click","#grid_status_icon",function() {
                                                          toggleGridSwitch = 1;
                                                          triggerAjax();
                                                    }
                );

  function triggerAjax() {

                            var data =  {toggleGridSwitch : toggleGridSwitch,
                                         wp_user_ID       : my_ajax_obj.wp_user_ID};
                            $.post(my_ajax_obj.ajax_url,
                            {                                 //POST request
                              _ajax_nonce: my_ajax_obj.nonce, //nonce extracted and sent
                              action: "my_solar_update",  // hook added for action wp_ajax_my_solar_update in php file
                              data: data
                            },
                              function(data) {	// data is JSON data sent back by server in response, wp_send_json($somevariable)
                                                // update the page with new readings. Lets just log the value sto see if we are getting good data
                                                // console.log('data: ', data);
                                                // console.log('battery html', $('#power-battery').html());
                                                // reset the toggle function to 0 if it was at 1 to prevent switch action
                                                if (toggleGridSwitch) toggleGridSwitch = 0;

                                                // update the screen with new readings from Ajax Call
                                                updateScreenWithNewData(data);

                                                 //
                                                 count++; // <== update count
                                                 if(count >= 9)
                                                 {
                                                     // remove spinner on update button
                                                     $("#studer_icon").removeClass("fa-spin");
 
                                                     // all execution should stop here till further prompt from user
                                                 }
                                                 else {
                                                     var timeout_ID = setTimeout(triggerAjax, 10000); // this is 10s delay
                                                 }
                                            });

                            
                        };
                        
    function updateScreenWithNewData(data) {
      // update the Grid  Switch Icon
      $('#grid_status_icon').html( data.grid_staus_icon);

      // Updatehe Grid Power Flow Arrow
      $('#grid_arrow_icon').html( data.grid_arrow_icon);

      // Updatehe Grid Info
      $('#grid_info').html( data.grid_info);

      //Update the PV solar Panel Grid Arrow
      $('#pv_arrow_icon').html( data.pv_arrow_icon);

      // update psolar_info
      $('#psolar_info').html( data.psolar_info);

      // update battery icon
      $('#battery_status_icon').html( data.battery_status_icon);

      // update battery info
      $('#battery_info').html( data.battery_info);

      // update battery arrow
      $('#battery_arrow_icon').html( data.battery_arrow_icon);

      // update load information
      $('#load_info').html( data.load_info);

    }

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
