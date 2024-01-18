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
 require __DIR__ . '/shelly_cloud_api.php';
 
 

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
     *  Assumes pump is channel 0 of the Shelly Pro 4PM supplying the entire Home Load
     */
    public function turn_pump_on_off( int $user_index, string $desired_state ) : bool
    {
        // get the config array from the object properties
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_homepwr'];
        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_4pm'];

        // set the channel of the switch that the pump is on
        $channel_pump  = 0;

        $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

        // Get the pump switch status now
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        if ( $shelly_api_device_response )
        {
          $pump_initial_switch_state = $shelly_api_device_response->{'switch:0'}->output;

          // if the existing pump switch is same as desired, we simply exit with message
          if (  ( $pump_initial_switch_state === true  &&  ( strtolower( $desired_state) === "on"  || $desired_state === true  || $desired_state == 1) ) || 
                ( $pump_initial_switch_state === false &&  ( strtolower( $desired_state) === "off" || $desired_state === false || $desired_state == 0) ) )
          {
            // esisting state is same as desired final state so return
            error_log( "No Action in Pump Switch done since no change is desired " );
            echo( "No Action in Pump Switch done since no change is desired " . "\n" );
            return true;
          }
        }
        else
        {
          // we didn't get a valid response but we can continue and try switching
          error_log( "we didn't get a valid response for pump switch initial status check but we can continue and try switching" );
        }

        // Now lets change the pump state
        $shelly_api_device_response = $shelly_api->turn_on_off_shelly_switch_over_lan( $desired_state );
        sleep (1);

        // lets get the new state of the pump shelly switch
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        $pump_final_switch_state = $shelly_api_device_response->{'switch:0'}->output;

        if (  ( $pump_final_switch_state === true  &&  ( strtolower( $desired_state) === "on"  || $desired_state === true  || $desired_state == 1) ) || 
              ( $pump_final_switch_state === false &&  ( strtolower( $desired_state) === "off" || $desired_state === false || $desired_state == 0) ) )
        {
          // Final state is same as desired final state so return
          echo( "Pump Switch to desired state was successful " . "\n" );
          return true;
        }
        else
        {
          error_log( "Pump Switch to desired state was not successful" );
          echo( "Pump Switch to desired state was NOT successful " . "\n" );
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
    public function get_shelly_switch_acin_details_over_lan( int $user_index) : array
    {
      $return_array = [];

      // set default timezone to Asia Kolkata
      date_default_timezone_set("Asia/Kolkata");

      $config     = $this->config;

      // ensure that the data below is current before coming here
      $all_usermeta['do_shelly'] = true;

      $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['ip_shelly_acin_1pm'] )  && $all_usermeta['do_shelly'];
    
      if ( $valid_shelly_config ) 
      {  // Cotrol Shelly TRUE if usermeta AND valid config
        $control_shelly = true;
      }
      else 
      {    // Cotrol Shelly FALSE if usermeta AND valid config FALSE
        $control_shelly = false;
      }

      // get the current ACIN Shelly Switch Status. This returns null if not a valid response or device offline
      if ( $valid_shelly_config ) 
      {   //  get shelly device status ONLY if valid config for switch

          $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
          $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
          $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];
          $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

          $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

          // this is curl_response.
          $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

          if ( is_null($shelly_api_device_response) ) 
          { // switch status is unknown

              error_log("Shelly Grid Switch API call failed - Grid power failure Assumed");

              $shelly_api_device_status_ON = null;

              $shelly_switch_status             = "OFFLINE";
              $shelly_api_device_status_voltage = "NA";
          }
          else 
          {  // Switch is ONLINE - Get its status and Voltage
              
              $shelly_api_device_status_ON        = $shelly_api_device_response->{'switch:0'}->output;
              $shelly_api_device_status_voltage   = $shelly_api_device_response->{'switch:0'}->voltage;

              $shelly_api_device_status_current   = $shelly_api_device_response->{'switch:0'}->current;
              $shelly_api_device_status_minute_ts = $shelly_api_device_response->{'switch:0'}->aenergy->minute_ts;

              $shelly_api_device_status_power_kw  = round( $shelly_api_device_response->{'switch:0'}->apower * 0.001, 3);

              if ($shelly_api_device_status_ON)
              {
                  $shelly_switch_status = "ON";
              }
              else
              {
                  $shelly_switch_status = "OFF";
              }
              
          }
      }
      else 
      {  // no valid configuration for shelly switch set variables for logging info

          $shelly_api_device_status_ON = null;

          $shelly_switch_status               = "Not Configured";
          $shelly_api_device_status_voltage   = "NA";
          $shelly_api_device_status_current   = 'NA';
          $shelly_api_device_status_power_kw  = 'NA';
          $shelly_api_device_status_minute_ts = 'NA';   
      }  

      $return_array['shelly1pm_acin_switch_config']   = $valid_shelly_config;
      $return_array['control_shelly']                 = $control_shelly;
      $return_array['shelly1pm_acin_switch_status']   = $shelly_switch_status;
      $return_array['shelly1pm_acin_voltage']         = $shelly_api_device_status_voltage;
      $return_array['shelly1pm_acin_current']         = $shelly_api_device_status_current;
      $return_array['shelly1pm_acin_power_kw']        = $shelly_api_device_status_power_kw;
      $return_array['shelly1pm_acin_minute_ts']       = $shelly_api_device_status_minute_ts;

      $this->shelly_switch_acin_details = $return_array;

      return $return_array;
    }
}

$test = new my_shelly_over_lan_test();

$user_index = 0;

// Make an API call on the Shelly plus Add on device
$config = $test->config;

$shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
$shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
$shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_plus_addon'];
$ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_addon'];


$shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

// this is $curl_response.
$shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

$adc_voltage = $shelly_api_device_response->{'input:100'}->percent;

$voltage =  ( $adc_voltage * 0.1 - 2.5 );

$volts_per_amp = 0.625 / 100 * 4.7;

$battery_amps = -1.0 * $voltage / $volts_per_amp;

echo("Battery Amps = " . $battery_amps . "\n" );
 
// test AC IN Shelly 1PM switch
{ // --------------------- Shelly1PM ACIN SWITCH data after making a Shelly API call -------------------

  $shelly_switch_acin_details_arr = $test->get_shelly_switch_acin_details_over_lan( $user_index );

  $shelly1pm_acin_switch_config     = $shelly_switch_acin_details_arr['shelly1pm_acin_switch_config'];  // Is configuration valid?
  $control_shelly                   = $shelly_switch_acin_details_arr['control_shelly'];                // is switch set to be controllable?
  $shelly1pm_acin_switch_status     = $shelly_switch_acin_details_arr['shelly1pm_acin_switch_status'];  // ON/OFF/OFFLINE/Not COnfigured
  $shelly1pm_acin_voltage           = $shelly_switch_acin_details_arr['shelly1pm_acin_voltage'];
  $shelly1pm_acin_current           = $shelly_switch_acin_details_arr['shelly1pm_acin_current'];
  $shelly1pm_acin_power_kw          = $shelly_switch_acin_details_arr['shelly1pm_acin_power_kw'];

  print_r($shelly_switch_acin_details_arr);
}


$test->turn_pump_on_off( 0, "on");


$shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
$shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
$shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_em_load'];
$ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_em'];

$shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, 'gen1' );

// this is $curl_response.
$shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();
$energy = $shelly_api_device_response->emeters[0]->total;

echo("Home Shelly EM WH counter = " . $energy . "\n" );

// test Shelly Pro 4PM to see how it works
$shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
$shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
$shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_homepwr'];
$ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_4pm'];

$shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, 'gen2' );

// this is $curl_response.
$shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();
print_r($shelly_api_device_response);
