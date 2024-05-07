<?php

declare(strict_types=1);

defined( 'MyConst' ) or die( 'No script kiddies please!' );


/**
 *
 */

/**
 * The test  class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

 

 // require __DIR__ . '/vendor/autoload.php';
 require __DIR__ . '/class_shelly_device_lan.php';
 require __DIR__ . '/class_solar_calculation.php';
 require __DIR__ . '/openweather_api.php';
 
 

class my_shelly_over_lan_test 
{

    // configuration variable
    public  $config;

    public $message;

    public function __construct()
    {
        $this->config = $this->get_config();
    }



    public  function get_config()
    {
        $config = include( __DIR__."/transindus_eco_config.php");

        // return the array of the account holder directly as there is only one account in the file
        return $config;
    }

    public  function init()
    {
        $this->config = $this->get_config();
    }


    /**
     *  @return bool returns true if actual state is same as desired state, returns false otherwise or if api call failed
     *  @param int:$user_index
     *  @param string:$desired_state can be either 'on' or 'off' to turn on the switch to ON or OFF respectively
     *  @param string:$shelly_switch_name is the name of the switch used to index its shelly_device_id in the config array
     *  
     */
    public function turn_on_off_shelly1pm_acin_switch_over_lan( int $user_index, string $desired_state ) :  bool
    {
        // get the config array from the object properties
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];
        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

        // set the channel of the switch
        $channel  = 0;

        $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly  );

        // Get the switch initial status now before any switching happens
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        if ( $shelly_api_device_response )
        {
          $initial_switch_state = $shelly_api_device_response->{'switch:0'}->output;

          // if the existing switch state is same as desired, we simply exit with message
          if (  ( $initial_switch_state === true  &&  ( strtolower( $desired_state) === "on"  || $desired_state === true  || $desired_state == 1) ) || 
                ( $initial_switch_state === false &&  ( strtolower( $desired_state) === "off" || $desired_state === false || $desired_state == 0) ) )
          {
            // esisting state is same as desired final state so return
            error_log( "LogSw: No Action in ACIN Switch - Initial Switch State: $initial_switch_state, Desired State: $desired_state" );
            return true;
          }
        }
        else
        {
          // we didn't get a valid response but we can continue and try switching
          error_log( "LogSw: we didn't get a valid response for ACIN switch initial status check but we can continue and try switching" );
        }

        // Now lets change the switch state by command over LAN
        $shelly_api_device_response = $shelly_api->turn_on_off_shelly_switch_over_lan( $desired_state );
        sleep (1);

        // lets get the new state of the ACIN shelly switch
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        If ( empty( $shelly_api_device_response ) )
        {
          error_log( "LogSw: Danger-we didn't get a valid response for switch turn on/off" );

          return false;
        }
        // we do have a valid response from the switch
        $final_switch_state = $shelly_api_device_response->{'switch:0'}->output;

        if (  ( $final_switch_state === true  &&  ( strtolower( $desired_state) === "on"  || $desired_state === true  || $desired_state == 1) ) || 
              ( $final_switch_state === false &&  ( strtolower( $desired_state) === "off" || $desired_state === false || $desired_state == 0) ) )
        {
          // Final state is same as desired final state so return success
          return true;
        }
        else
        {
          error_log( "LogSw: Danger-ACIN Switch to desired state Failed - Desired State: $desired_state, Final State: $final_switch_state" );
          return false;
        }
    }




    /**
     *  @param int:$user_index is the user of ineterst in the config array
     *  @return array:$return_array containing values from API call on Shelly ACIN Transfer switch
     * 
     *  Checks the validity of Shelly switch configuration required for program
     *  Makes an API call on the Shelly ACIN switch and return the ststus such as State, Voltage, etc.
     */
    public function get_shelly_grid_switch_details_over_lan( int $user_index) : ? object
    {
      $return_array = [];

      $config     = $this->config;

      // ensure that the data below is current before coming here
      $all_usermeta['do_shelly'] = true;

      $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['ip_shelly_acin_1pm'] );

      $control_shelly = $valid_shelly_config && $all_usermeta["do_shelly"];
    
      

      // get the current ACIN Shelly Switch Status. This returns null if not a valid response or device offline
      if ( $valid_shelly_config ) 
      {   //  get shelly device status ONLY if valid config for switch

          $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
          $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
          $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];
          $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

          $shelly_device    =  new shelly_device( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, 'shellyplus1pm' );

          $shelly_device_data = $shelly_device->get_shelly_device_data();

          return $shelly_device_data;
      }
    }


    public function get_shelly_batterty_details_over_lan( int $user_index) : ? object
    {
      $return_array = [];

      $config     = $this->config;

      // ensure that the data below is current before coming here
      $all_usermeta['do_shelly'] = true;

      $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['ip_shelly_addon'] );
    
      

      // get the current ACIN Shelly Switch Status. This returns null if not a valid response or device offline
      if ( $valid_shelly_config ) 
      {   //  get shelly device status ONLY if valid config for switch

          $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
          $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
          $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_plus_addon'];
          $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_addon'];

          $shelly_device    =  new shelly_device( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, 'shellyplus1-v' );

          $shelly_device_data = $shelly_device->get_shelly_device_data();

          return $shelly_device_data;
      }
    }




    public function get_shelly_em_details_over_lan ( int $user_index) : ? object
    {
      $return_array = [];

      $config     = $this->config;

      $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['ip_shelly_addon'] );
    
      

      // get the current ACIN Shelly Switch Status. This returns null if not a valid response or device offline
      if ( $valid_shelly_config ) 
      {   //  get shelly device status ONLY if valid config for switch

          $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
          $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
          $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_em_load'];
          $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_em'];

          $shelly_device    =  new shelly_device( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, 'shellyem' );

          $shelly_device_data = $shelly_device->get_shelly_device_data();

          return $shelly_device_data;
      }
    }



     /**
     *
     */
    public function estimated_solar_power( $user_index ): object
    {
      // initialize to prevent warning after dark
       $total_to_west_panel_ratio = 0;

        $est_solar_obj = new stdClass;

        $est_solar_kw_arr = [];

        $config = $this->config;

        $panel_sets = $config['accounts'][$user_index]['panels'];

        foreach ($panel_sets as $key => $panel_set)
        {
          // 5.5 is the UTC offset of 5h 30 mins in decimal.
          $transindus_lat_long_array = [12.83463, 77.49814];

          $solar_calc = new solar_calculation($panel_set, $transindus_lat_long_array, 5.5);

          $est_solar_kw_arr[$key] =  round($solar_calc->est_power(), 1);
        }

        $est_solar_obj->est_solar_total_kw = array_sum( $est_solar_kw_arr );

        $est_solar_obj->est_solar_kw_arr =  $est_solar_kw_arr;

        if ( $this->nowIsWithinTimeLimits( '06:00', '12:00' ) )
        {
          // west Panel Solar Amps is lower than East Panel
          // reduce by factor of 1.2 based on AM measurements
          $west_panel_est_kw = min( $est_solar_kw_arr ) * 1.0;
        }
        else 
        {
          // it is afternoon and West panel has maximum solar power
          // increase by factor of 1.2 in PM based on comparison with Studer measurements
          $west_panel_est_kw = max( $est_solar_kw_arr ) / 1.0;
        }

        if ( $west_panel_est_kw > 0 )
        {
          // in morning the ratio will be greater than 2
          // at noon it will be around 2
          // after noon it will be less than 2 and greater than 1
          $total_to_west_panel_ratio = $est_solar_obj->est_solar_total_kw / $west_panel_est_kw;
        }

        if ( $total_to_west_panel_ratio > 3 ) $total_to_west_panel_ratio = 3;

        $est_solar_obj->total_to_west_panel_ratio =  $total_to_west_panel_ratio;

        $est_solar_obj->sunrise =  $solar_calc->sunrise();
        $est_solar_obj->sunset  =  $solar_calc->sunset();


        return $est_solar_obj;
    }

    /**
     *  @param string:$start
     *  @param string:$stop
     *  @return bool true if current time is within the time limits specified otherwise false
     */
    public function nowIsWithinTimeLimits(string $start_time, string $stop_time): bool
    {
        //

        $now    = new DateTime('NOW',        new DateTimeZone('Asia/Kolkata'));
        $begin  = new DateTime($start_time,  new DateTimeZone('Asia/Kolkata'));
        $end    = new DateTime($stop_time,   new DateTimeZone('Asia/Kolkata'));

        if ($now >= $begin && $now <= $end)
        {
          return true;
        }
        else
        {
          return false;
        }
    }

    public function get_shelly_3p_grid_wh_since_midnight_over_lan(  int     $user_index ): ? object
    {

      // get API and device ID from config based on user index
      $config = $this->config;

      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin_3p'];
      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_3em'];

      // gen2 default pass parameter
      $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

      return $shelly_api_device_response;
      
    }



}

$test = new my_shelly_over_lan_test();

// $shelly_3p_grid_energy_measurement_obj = $test->get_shelly_3p_grid_wh_since_midnight_over_lan( 0 );
 // print_r($shelly_3p_grid_energy_measurement_obj);

 // $home_em = "em1:2";
 // $home_emdata = "em1data:2";

 // $home_ac_voltage = $shelly_3p_grid_energy_measurement_obj->$home_em->voltage;
 // $home_ac_power = $shelly_3p_grid_energy_measurement_obj->$home_emdata->total_act_energy;

 // print($home_ac_voltage);
 // print ($home_ac_power);

 $shelly_grid_switch_details = $test->get_shelly_grid_switch_details_over_lan(0);

 print_r($shelly_grid_switch_details);

 $shelly_battery_details = $test->get_shelly_batterty_details_over_lan(0);

 print_r($shelly_battery_details);

 $shelly_em_details = $test->get_shelly_em_details_over_lan(0);

 print_r($shelly_em_details);











