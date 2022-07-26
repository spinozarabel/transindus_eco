<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 */

/**
 * The core plugin class.
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

require_once(__DIR__."/studer_api.php");              // contains studer api class
require_once(__DIR__."/shelly_cloud_api.php");        // contains Shelly Cloud API class
require_once(__DIR__."/class_solar_calculation.php"); // contains studer api class
require_once(__DIR__."/openweather_api.php");         // contains openweather class

class class_transindus_eco
{
	// The loader that's responsible for maintaining and registering all hooks that power
	protected $loader;

	// The unique identifier of this plugin.
	protected $plugin_name;

	// The current version of the plugin.
	protected $version;

  //
  public $config;

  public $bv_avg_arr;
  public $psolar_avg_arr;
  public $pload_avg;
  public $count_for_averaging;
  public $counter;
  public $datetime;


    /**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 */
	public function __construct()
  {
      if ( defined( 'TRANSINDUS_ECO_VERSION' ) )
      {
          $this->version = TRANSINDUS_ECO_VERSION;
      }
      else
      {
          $this->version = '1.0';
      }

      $this->plugin_name = 'transindus_eco';

          // load actions only if admin
      if (is_admin()) $this->define_admin_hooks();

          // load public facing actions
      $this->define_public_hooks();

          // read the config file and build the secrets array
      $this->get_config();

          // set the logging
      $this->verbose = false;

      // Initialize the aarrays to hold quantities for running averages
      $this->bv_avg_arr       = [0, 0, 0, 0, 0, 0]; // 6 minutes of averaging
      $this->psolar_avg_arr   = [];
      $this->pload_avg        = [];

      // lat and lon at Trans Indus from Google Maps
      $this->lat        = 12.83463;
      $this->lon        = 77.49814;
      $this->utc_offset = 5.5;

      $this->timezone = new DateTimeZone("Asia/Kolkata");

      

      $datetime = new DateTime();

      date_default_timezone_set("Asia/Kolkata");

      $this->cloudiness_forecast = $this->check_if_forecast_is_cloudy();

	}

    /**
     *  reads in a config php file and gets the API secrets. The file has to be in gitignore and protected
     *  The information is read into an associative arrray automatically by the nature of the process
     *  1. Key and Secret of Payment Gateway involved needed to ccheck/create VA and read payments
     *  2. Moodle token to access Moodle Webservices
     *  3. Woocommerce Key and Secret for Woocommerce API on payment server
     *  4. Webhook secret for order completed, from payment server
     */
    private function get_config()
    {
      $this->config = include( __DIR__."/" . $this->plugin_name . "_config.php");
    }

    /**
     * Define all of the admin facing hooks and filters required for this plugin
     * @return null
     */
    private function define_admin_hooks()
    {   // create a sub menu called Admissions in the Tools menu
        add_action('admin_menu', [$this, 'add_my_menu']);
    }

    /**
     * 
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private function define_public_hooks()
    {
        // register shortcode for pages. This is for showing the page with studer readings
        add_shortcode( 'transindus-studer-readings',  [$this, 'studer_readings_page_render'] );

        add_shortcode( 'transindus-studer-settings',  [$this, 'studer_settings_page_render'] );

        add_shortcode( 'my-studer-readings',          [$this, 'my_studer_readings_page_render'] );
    }


    /**
     * 
     */
    public function get_user_index_of_logged_in_user()
    {  // get my user index knowing my login name

        $current_user = wp_get_current_user();
        $wp_user_name = $current_user->user_login;

        $config       = $this->config;

        // Now to find the index in the config array using the above
        $user_index = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        return $user_index;
    }

    /**
     * 
     */
    public function get_index_from_wp_user_ID($wp_user_ID)
    {
        $wp_user_object = get_user_by( 'id', $wp_user_ID);

        $wp_user_name   = $wp_user_object->user_login;

        $config         = $this->config;

        // Now to find the index in the config array using the above
        $user_index     = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        return $user_index;
    }


    /**
     * @param int:user_index
     * @return object:wp_user_obj
     */
    public function get_wp_user_from_user_index($user_index)
    {
        $wp_user_name = $this->config['accounts'][$user_index]['wp_user_name'];

        // Get the wp user object given the above username
        $wp_user_obj  = get_user_by('login', $wp_user_name);

        return $wp_user_obj;
    }

    /**
     * 
     */
    public function add_my_menu()
    {
        // add submenu page for testing various application API needed
        add_submenu_page(
            'tools.php',	                    // parent slug
            'My API Tools',                     // page title
            'My API Tools',	                    // menu title
            'manage_options',	                // capability
            'my-api-tools',	                    // menu slug
            [$this, 'my_api_tools_render']
        );
    }


    /**
     *  This function is called by the scheduler probably every minute or so.
     *  Its job is to get the minimal set of studer readings and the state of the ACIN shelly switch
     *  For every user in the config array who has the do_shelly variable set to TRUE.
     *  The ACIN switch is turned ON or OFF based on a complex algorithm. and user meta settings
     */
    public function shellystuder_cron_exec()
    {                        // Loop over all of the eligible users
        foreach ($this->config['accounts'] as $user_index => $account)
        {
            $wp_user_name = $this->config['accounts'][$user_index]['wp_user_name'];

                            // Get the wp user object given the above username
            $wp_user_obj          = get_user_by('login', $wp_user_name);
            $wp_user_ID           = $wp_user_obj->ID;

                            // extract the control flag as set in user meta
            $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true) ?? false;

            // Check if this's control flag is even set to do this control. set control flag to flase
            if( $do_shelly_user_meta ) {

                            // get all the readings for this user. This will write the data to the user meta
                $this->get_readings_and_servo_grid_switch($user_index, $wp_user_ID, $wp_user_name, $do_shelly_user_meta);
            }
            // loop for all users
        }

        return true;
    }



    /**
     * Gets all readings from Shelly and Studer and servo's AC IN shelly switch based on conditions
     * @param int:user_index
     * @param int:wp_user_ID
     * @param string:wp_user_name
     * @param bool:do_shelly_user_meta
     * @return object:studer_readings_obj
     */
    public function get_readings_and_servo_grid_switch($user_index, $wp_user_ID, $wp_user_name, $do_shelly_user_meta)
    {
        // get operation flags from user meta. Set it to false if not set
        $keep_shelly_switch_closed_always = get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always",  true) ?? false;

        // Check if this's control flag is even set to do this control. set control flag to flase
        if( empty( $do_shelly_user_meta ) || ! $do_shelly_user_meta ) {

            $control_shelly = false;
        }
        else 
        {
          $control_shelly = true;
        }

        // get the current ACIN Shelly Switch Status. This returns null if not a valid response or device offline
        $shelly_api_device_response = $this->get_shelly_device_status( $user_index );

        if ( is_null($shelly_api_device_response) ) {

            // switch status is unknown
            error_log("Shelly cloud not responding and or device is offline");

            $shelly_api_device_status_ON = null;

            $shelly_switch_status             = "OFFLINE";
            $shelly_api_device_status_voltage = "NA";
        }
        else {
            // Ascertain switch status: True if Switch is closed, false if Switch is open
            $shelly_api_device_status_ON      = $shelly_api_device_response->data->device_status->{"switch:0"}->output;
            $shelly_api_device_status_voltage = $shelly_api_device_response->data->device_status->{"switch:0"}->voltage;

            if ($shelly_api_device_status_ON)
                {
                    $shelly_switch_status = "ON";
                }
            else
                {
                    $shelly_switch_status = "OFF";
                }
        }

        // get the smaller set of Studer readings
        $studer_readings_obj  = $this->get_studer_min_readings($user_index);

        // check for valid studer values. Return if not valid
        if( empty(  $studer_readings_obj )                          ||
            empty(  $studer_readings_obj->battery_voltage_vdc )     ||
                    $studer_readings_obj->battery_voltage_vdc < 40  ||
            empty(  $studer_readings_obj->pout_inverter_ac_kw ) ) 
        {
          // cannot trust this Studer reading, do not update
          error_log($wp_user_name . ": " . "Could not get Studer Reading");

          return null;
        }

        // if we get this far it means that all readings are reliable. Drop the earliest battery voltage
        array_shift($this->bv_avg_arr);

        // add the latest reaiding at the top
        array_push($this->bv_avg_arr, $studer_readings_obj->battery_voltage_vdc);

        // average the battery voltage over last 6 readings of about 6 minutes.
        $battery_voltage_avg  = $this->get_battery_voltage_avg();

        // get the estimated solar power from calculations for a clear day
        $est_solar_kw         = $this->estimated_solar_power($user_index);

        $psolar               = $studer_readings_obj->psolar_kw;
        $solar_pv_adc         = $studer_readings_obj->solar_pv_adc;

        $pout_inverter        = $studer_readings_obj->pout_inverter_ac_kw;
        $grid_input_vac       = $studer_readings_obj->grid_input_vac;
        $inverter_current_adc = $studer_readings_obj->inverter_current_adc;

        $surplus              = $psolar - $pout_inverter;

        $aux1_relay_state     = $studer_readings_obj->aux1_relay_state;

        $now_is_daytime       = $this->nowIsWithinTimeLimits("07:00", "17:30");
        $now_is_sunset        = $this->nowIsWithinTimeLimits("17:31", "17:41");

        $it_is_a_cloudy_day   = $this->cloudiness_forecast->it_is_a_cloudy_day;

        if (true)
        {
            error_log("user: "                 . $wp_user_name                             . ": Shelly and Studer Values");
            error_log("Shelly Switch State: "  . $shelly_switch_status                     . "");
            error_log("Shelly Switch Servo: "  . $control_shelly                     . "");
            error_log("Battery Avg Voltage: "  . $battery_voltage_avg                         . "Vdc ");
            error_log("Battery Current: "      . $studer_readings_obj->battery_charge_adc     . "Adc ");

            error_log("Solar PowerGen: "       . $psolar                                   . "KW ");
            error_log("AC at Studer Input: "   . $shelly_api_device_status_voltage      	 . "Vac ");
            error_log("Surplus PowerOut: "     . $surplus                                  . "KW ");
            error_log("Calc Solar Pwr: "       . array_sum($est_solar_kw)                  . "KW ");
            error_log("Cloudy Day?: "          . $it_is_a_cloudy_day                       . "");
            error_log("Within 0700 - 1730?: "  . $now_is_daytime                           . "");
            error_log("AUX1 Relay State: "     . $aux1_relay_state                         . "");
        }

        // define all the conditions for the SWITCH - CASE tree

        $switch_override =  ($shelly_switch_status == "OFF")               &&
                            ($studer_readings_obj->grid_input_vac >= 190);

        // This is the only condition independent of user meta control settings                    
        $LVDS =             ( $battery_voltage_avg    <  48.7 )  						&&  // SOC is low but still with some margin if no grid
                            ( $shelly_api_device_status_voltage > 205.0	)		&&	// ensure AC is not too low
                            ( $shelly_api_device_status_voltage < 241.0	)		&&	// ensure AC is not too high
                            ( $shelly_switch_status == "OFF" );									// The switch is OFF

        $keep_switch_closed_always =  ( $shelly_switch_status == "OFF" )             &&
                                      ( $keep_shelly_switch_closed_always == true )  &&
                                      ( $control_shelly == true );


        $reduce_daytime_battery_cycling = ( $shelly_switch_status == "OFF" )              &&  // Switch is OFF
                                          ( $battery_voltage_avg	<	51.3 )								&&	// not in float
                                          ( $shelly_api_device_status_voltage > 205.0	)		&&	// ensure AC is not too low
                                          ( $shelly_api_device_status_voltage < 241.0	)		&&	// ensure AC is not too high
                                          ( $now_is_daytime )                             &&  // Daytime
                                          ( $psolar > 0.5 )                               &&  // at least some solar generation
                                          ( $surplus < -0.4 ) 														&&  // Load is greater than Solar Gen
                                          ( $control_shelly == true );

        $switch_release =  (	( $battery_voltage_avg > 49.0 && ! $it_is_a_cloudy_day )						// SOC enpough for not a cloudy day
                                                      ||
                               ( $battery_voltage_avg > 49.5 &&   $it_is_a_cloudy_day )						// SOC is adequate for a cloudy day
                           )																															&&
                           ( $shelly_switch_status == "ON" )  														&&  		// Switch is ON now
                           ( $surplus > 0.3 )                															&&  		// Solar is greater than Load
                           ( $keep_shelly_switch_closed_always == false )                 &&			// Emergency flag is False
                           ( $control_shelly == true );

        $sunset_switch_release			=	( $keep_shelly_switch_closed_always == false )  &&  // Emergency flag is False
                                      ( $shelly_switch_status == "ON" )               &&  // Switch is ON now
                                      ( $now_is_sunset )                              &&  // around sunset
                                      ( $control_shelly == true );

        $switch_release_float_state	= ( $shelly_switch_status == "ON" )  							&&  // Switch is ON now
                                      ( $battery_voltage_avg    >  51.8 )				  		&& 
                                      ( $keep_shelly_switch_closed_always == false )  &&
                                      ( $control_shelly == true );

        // write back new values to the readings object
        $studer_readings_obj->battery_voltage_avg               = $battery_voltage_avg;
        $studer_readings_obj->now_is_daytime                    = $now_is_daytime;
        $studer_readings_obj->now_is_sunset                     = $now_is_sunset;
        $studer_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
        $studer_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;

        $studer_readings_obj->LVDS                              = $LVDS;
        $studer_readings_obj->reduce_daytime_battery_cycling    = $reduce_daytime_battery_cycling;
        $studer_readings_obj->switch_release                    = $switch_release;
        $studer_readings_obj->sunset_switch_release             = $sunset_switch_release;
        $studer_readings_obj->switch_release_float_state        = $switch_release_float_state;
        $studer_readings_obj->control_shelly                    = $control_shelly;

        switch(true)
        {
            // if Shelly switch is OPEN but Studer transfer relay is closed and Studer AC voltage is present
            // it means that the ACIN is manually overridden at control panel
            // so ignore attempting any control and skip this user
            case (  $switch_override ):
                  // ignore this user
                  error_log("condition MCB Switch Override - NO ACTION)");
                  $cron_exit_condition = "Manual Switch Override";
            break;


            // <1> If switch is OPEN and running average Battery voltage from 5 readings is lower than limit, go ON-GRID
            case ( $LVDS ):

                $this->turn_on_off_shelly_switch($user_index, "on");

                error_log("Exited via Case 1: LVDS - Grid Switched ON");
                $cron_exit_condition = "Low SOC - Grid ON";
            break;


            // <3> If switch is OPEN and the keep shelly closed always is TRUE then close the switch
            case ( $keep_switch_closed_always ):

                $this->turn_on_off_shelly_switch($user_index, "on");

                error_log("Exited via Case 3 - keep switch closed always - Grid Switched ON");
                $cron_exit_condition = "Grid ON always";
            break;


            // <4> Daytime, reduce battery cycling, turn SWITCH ON
            case ( $reduce_daytime_battery_cycling ):

                $this->turn_on_off_shelly_switch($user_index, "on");

                error_log("Exited via Case 4 - reduce daytime battery cycling - Grid Switched ON");
                $cron_exit_condition = "RDBC-Grid ON";
            break;


            // <5> Release - Switch OFF for normal Studer operation
            case ( $switch_release ):

                $this->turn_on_off_shelly_switch($user_index, "off");

                error_log("Exited via Case 5 - adequate Battery SOC, Grid Switched OFF");
                $cron_exit_condition = "SOC ok-Grid Off";
            break;


            // <6> Turn switch OFF at 5:30 PM if emergency flag is False so that battery can supply load for the night
            case ( $sunset_switch_release ):

                $this->turn_on_off_shelly_switch($user_index, "off");

                error_log("Exited via Case 6 - sunset, Grid switched OFF");
                $cron_exit_condition = "Sunset Grid Off";
            break;


            case ( $switch_release_float_state ):

                $this->turn_on_off_shelly_switch($user_index, "off");

                error_log("Exited via Case 8 - Battery Float State, Grid switched OFF");
                $cron_exit_condition = "Float Grid Off";
            break;


            default:
                
                error_log("Exited via Case Default, NO ACTION TAKEN");
                $cron_exit_condition = "No Action";
            break;
        }
        $now = new DateTime();
        $studer_readings_obj->datetime            = $now;
        $studer_readings_obj->cron_exit_condition = $cron_exit_condition;

        $array_for_json = [ 'unixdatetime'        => $now->getTimestamp() ,
                            'cron_exit_condition' => $cron_exit_condition ,
                          ];

        // save the data in a transient indexed by the user name. Expiration is 2 minutes
        set_transient( $wp_user_name . '_studer_readings_object', $studer_readings_obj, 1*60 );

        // Update the user meta with the CRON exit condition only fir definite ACtion not for no action
        if ($cron_exit_condition !== "No Action") {
            update_user_meta( $wp_user_ID, 'studer_readings_object',  json_encode( $array_for_json ));
       }
        
        
        // New readings Object was updated but not yet read by Ajax
        // update_user_meta( $wp_user_ID, 'new_readings_read_by_ajax', true );

        return $studer_readings_obj;
    }



    /**
     *
     */
    public function get_current_cloud_cover_percentage()
    {
        $config = $this->config;
        $lat    = $this->lat;
        $lon    = $this->lon;
        $appid  = $config['appid'];

        $current_wether_api   = new openweathermap_api($lat, $lon, $appid);
        $current_weather_obj  = $current_wether_api->get_current_weather();

        if ($current_weather_obj)
        {
          $cloud_cover_percentage = $current_weather_obj->clouds->all;
          return $current_cloud_cover_percentage;
        }
        else
        {
          return null;
        }
    }

    /**
     *
     */
    public function check_if_forecast_is_cloudy()
    {
        $config = $this->config;
        $lat    = $this->lat;
        $lon    = $this->lon;
        $appid  = $config['openweather_appid'];
        $cnt    = 3;

        $current_wether_api   = new openweathermap_api($lat, $lon, $appid, $cnt);
        $cloudiness_forecast   = $current_wether_api->forecast_is_cloudy();

        return $cloudiness_forecast;
    }



    /**
     *  Takes the average of the battery values stored from last 5 readings
     */
    public function get_battery_voltage_avg()
    {
        $count  = 0;
        $sum    = 0;
        foreach ($this->bv_avg_arr as $key => $bv_reading)
        {
           if ($bv_reading > 46.0)
           {
              // average all values that are real
              $sum    +=  $bv_reading;
              $count  +=  1;
           }
        }
        unset($bv_reading);

        return ($sum / $count);
    }

    /**
     *  @param string:$start
     *  @param string:$stop
     *  @return bool true if current time is within the time limits specified otherwise false
     */
    public function nowIsWithinTimeLimits(string $start_time, string $stop_time): bool
    {
        date_default_timezone_set("Asia/Kolkata");

        $now =  new DateTime();
        $begin = new DateTime($start_time);
        $end   = new DateTime($stop_time);

        if ($now >= $begin && $now <= $end)
        {
          return true;
        }
        else
        {
          return false;
        }
    }

    /**
     *
     */
    public function studer_sttings_page_render()
    {
        $output = '';

        $output .= '
        <style>
            table {
                border-collapse: collapse;
                }
                th, td {
                border: 1px solid orange;
                padding: 10px;
                text-align: left;
                }
                .rediconcolor {color:red;}
                .greeniconcolor {color:green;}
                .img-pow-genset { max-width: 59px; }
        </style>';
        $output .= '
        <table>
        <tr>
            <th>
              Parameter
            </th>';


        foreach ($$config['accounts'] as $user_index => $account)
        {
          $home = $account['home'];
          $output .=
            '<th>' . $home .
            '</th>';
        }
        unset($account);
        $output .=
        '</tr>';
        // Now we need to get all of the parameters of interest for each of the users and display them
        foreach ($$config['accounts'] as $user_index => $account)
        {
          $wp_user_name = $account['wp_user_name'];

        }

    }

    /**
     *
     */
    public function my_studer_readings_page_render()
    {
        // initialize page HTML to be returned to be rendered by WordPress
        $output = '';

        $output .= '
        <style>
            @media (min-width: 768px) {
              .synoptic-table {
                  margin: auto;
                  width: 95% !important;
                  height: 100%;
                  border-collapse: collapse;
                  overflow-x: auto;
                  border-spacing: 0;
                  font-size: 1.5em;
              }
              .rediconcolor {color:red;}
              .greeniconcolor {color:green;}
              .clickableIcon {
                cursor: pointer
              .arrowSliding_nw_se {
                position: relative;
                -webkit-animation: slide_nw_se 2s linear infinite;
                        animation: slide_nw_se 2s linear infinite;
              }
        
              .arrowSliding_ne_sw {
                position: relative;
                -webkit-animation: slide_ne_sw 2s linear infinite;
                        animation: slide_ne_sw 2s linear infinite;
              }
        
              .arrowSliding_sw_ne {
                position: relative;
                -webkit-animation: slide_ne_sw 2s linear infinite reverse;
                        animation: slide_ne_sw 2s linear infinite reverse;
              }
        
              @-webkit-keyframes slide_ne_sw {
                  0% { opacity:0; transform: translate(20%, -20%); }
                  20% { opacity:1; transform: translate(10%, -10%); }
                  80% { opacity:1; transform: translate(-10%, 10%); }
                100% { opacity:0; transform: translate(-20%, 20%); }
              }
              @keyframes slide_ne_sw {
                  0% { opacity:0; transform: translate(20%, -20%); }
                  20% { opacity:1; transform: translate(10%, -10%); }
                  80% { opacity:1; transform: translate(-10%, 10%); }
                100% { opacity:0; transform: translate(-20%, 20%); }
              }
        
              @-webkit-keyframes slide_nw_se {
                  0% { opacity:0; transform: translate(-20%, -20%); }
                  20% { opacity:1; transform: translate(-10%, -10%); }
                  80% { opacity:1; transform: translate(10%, 10%);   }
                100% { opacity:0; transform: translate(20%, 20%);   }
              }
              @keyframes slide_nw_se {
                  0% { opacity:0; transform: translate(-20%, -20%); }
                  20% { opacity:1; transform: translate(-10%, -10%); }
                  80% { opacity:1; transform: translate(10%, 10%);   }
                100% { opacity:0; transform: translate(20%, 20%);   }
              }
           }
        </style>';

        // get my user index knowing my login name
        $current_user = wp_get_current_user();
        $wp_user_name = $current_user->user_login;
        $wp_user_ID   = $current_user->ID;

        $config       = $this->config;

        // Now to find the index in the config array using the above
        $user_index = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        if ($user_index === false) return "User Index invalid, You DO NOT have a Studer Install";

        // extract the control flag as set in user meta
        $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true) ?? false;

        // get the Studer status using the minimal set of readings
        $studer_readings_obj  = $this->get_readings_and_servo_grid_switch($user_index, $wp_user_ID, $wp_user_name, $do_shelly_user_meta);

        // check for valid studer values. Return if not valid
        if( empty(  $studer_readings_obj ) ) {
                $output .= "Could not get a valid Studer Reading using API";
                return $output;
        }

        // get the format of all the information for the table in the page
        $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

        // define all the icon styles and colors based on STuder and Switch values
        $output .= '
        <table id="my-studer-readings-table">
            <tr>
                <td id="grid_status_icon">'   . $format_object->grid_status_icon   . '</td>
                <td></td>
                <td id="shelly_servo_icon">'  . $format_object->shelly_servo_icon  . '</td>
                <td></td>
                <td id="pv_panel_icon">'      . $format_object->pv_panel_icon      . '</td>
            </tr>
                <td id="grid_info">'          . $format_object->grid_info          . '</td>
                <td id="grid_arrow_icon">'    . $format_object->grid_arrow_icon    . '</td>
                <td></td>
                <td id="pv_arrow_icon">'      . $format_object->pv_arrow_icon      . '</td>
                <td id="psolar_info">'        . $format_object->psolar_info        . '</td>

            <tr>
                <td></td>
                <td></td>
                <td id="studer_icon">'        . $format_object->studer_icon        . '</td>
                <td></td>
                <td></td>
            </tr>
            <tr>
                <td id="battery_info">'       . $format_object->battery_info       . '</td>
                <td id="battery_arrow_icon">' . $format_object->battery_arrow_icon . '</td>
                <td></td>
                <td id="load_arrow_icon">'    . $format_object->load_arrow_icon    . '</td>
                <td id="load_info">'          . $format_object->load_info          . '</td>
            </tr>
            <tr>
                <td id="battery_status_icon">'. $format_object->battery_status_icon . '</td>
                <td></td>
                <td id="cron_exit_condition">'. $format_object->cron_exit_condition . '</td>
                <td></td>
                <td id="load_icon">'          . $format_object->load_icon           . '</td>
            </tr>
        </table>';

        return $output;
    }


    /**
     *
     */
    public function studer_readings_page_render()
    {
        // $script = '"' . $config['fontawesome_cdn'] . '"';
        //$output = '<script src="' . $config['fontawesome_cdn'] . '"></script>';
        $output = '';

        $output .= '
        <style>
            table {
                border-collapse: collapse;
                }
                th, td {
                border: 1px solid orange;
                padding: 10px;
                text-align: left;
                }
                .rediconcolor {color:red;}
                .greeniconcolor {color:green;}
                .img-pow-genset { max-width: 59px; }
        </style>';

        $output .= '
        <table>
        <tr>
            <th>
              Install
            </th>
            <th>
                <i class="fa-regular fa-2xl fa-calendar-minus"></i>
                <i class="fa-solid fa-2xl fa-solar-panel greeniconcolor"></i>
            </th>
            <th>
                <i class="fa-regular fa-2xl fa-calendar-minus"></i>
                <i class="fa-solid fa-2xl fa-plug-circle-check rediconcolor"></i>
            </th>
            <th><i class="fa-solid fa-2xl fa-charging-station"></i></th>
            <th><i class="fa-solid fa-2xl fa-solar-panel greeniconcolor"></i></th>
            <th><i class="fa-solid fa-2xl fa-house"></i></th>
        </tr>';

        // loop through all of the users in the config
        foreach ($this->config['accounts'] as $user_index => $account)
        {
            $home = $account['home'];

            $studer_readings_obj = $this->get_studer_readings($user_index);

            if ($studer_readings_obj->grid_pin_ac_kw < 0.1)
            {
                $grid_staus_icon = '<i class="fa-solid fa-2xl fa-plug-circle-xmark greeniconcolor"></i>';
            }
            else
            {
                $grid_staus_icon = '<i class="fa-solid fa-2xl fa-plug-circle-check rediconcolor"></i>';
            }
            $solar_capacity         =   $account['solar_pk_install'];
            $battery_capacity       =   $account['battery_capacity'];
            $solar_yesterday        =   $studer_readings_obj->psolar_kw_yesterday;
            $grid_yesterday         =   $studer_readings_obj->energy_grid_yesterday;
            $consumed_yesterday     =   $studer_readings_obj->energy_consumed_yesterday;
            $battery_icon_class     =   $studer_readings_obj->battery_icon_class;
            $solar                  =   $studer_readings_obj->psolar_kw;
            $pout_inverter_ac_kw    =   $studer_readings_obj->pout_inverter_ac_kw;
            $battery_span_fontawesome = $studer_readings_obj->battery_span_fontawesome;
            $battery_voltage_vdc    =   round( $studer_readings_obj->battery_voltage_vdc, 1);

            $output .= $this->print_row_table(  $home, $solar_capacity, $battery_capacity, $battery_voltage_vdc,
                                                $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                                $battery_span_fontawesome, $solar, $grid_staus_icon, $pout_inverter_ac_kw   );
        }
        $output .= '</table>';

        return $output;
    }

    public function print_row_table(    $home, $solar_capacity, $battery_capacity, $battery_voltage_vdc,
                                        $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                        $battery_span_fontawesome, $solar, $grid_staus_icon, $pout_inverter_ac_kw   )
    {
        $returnstring =
        '<tr>' .
            '<td>' . $home .                                            '</td>' .
            '<td>' . '<font color="green">' . $solar_yesterday .        '</td>' .
            '<td>' . '<font color="red">' .   $grid_yesterday .         '</td>' .
            '<td>' . $battery_span_fontawesome . $battery_voltage_vdc . '</td>' .
            '<td>' . '<font color="green">' . $solar .                  '</td>' .
            '<td>' . $grid_staus_icon .       $pout_inverter_ac_kw  .   '</td>' .
        '</tr>';
        return $returnstring;
    }

/**
 *  Function to test code conveniently.
 */
    public function my_api_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Input index of config and Click on desired button to test</h1>
            <form action="" method="post" id="mytoolsform">
                <input type="text"   id ="config_index" name="config_index"/>
                <input type="submit" name="button" 	value="Get_Studer_Readings"/>
                <input type="submit" name="button" 	value="Get_Shelly_Device_Status"/>
                <input type="submit" name="button" 	value="turn_Shelly_Switch_ON"/>
                <input type="submit" name="button" 	value="turn_Shelly_Switch_OFF"/>
                <input type="submit" name="button" 	value="run_cron_exec_once"/>
                <input type="submit" name="button" 	value="estimated_solar_power"/>
                <input type="submit" name="button" 	value="check_if_cloudy_day"/>
            </form>


        <?php

        $config_index = sanitize_text_field( $_POST['config_index'] );
        $button_text  = sanitize_text_field( $_POST['button'] );

        echo "<pre>" . "config_index: " .    $config_index . "</pre>";
        echo "<pre>" . "button: " .    $button_text . "</pre>";


        switch ($button_text)
        {
            case 'Get_Studer_Readings':

                // echo "<pre>" . print_r($config, true) . "</pre>";
                $studer_readings_obj = $this->get_studer_readings($config_index);

                echo "<pre>" . "Studer Inverter Output (KW): " .    $studer_readings_obj->pout_inverter_ac_kw . "</pre>";
                echo "<pre>" . "Studer Solar Output(KW): " .        $studer_readings_obj->psolar_kw .           "</pre>";
                echo "<pre>" . "Battery Voltage (V): " .            $studer_readings_obj->battery_voltage_vdc . "</pre>";
                echo "<pre>" . "Solar Generated Yesterday (KWH): ". $studer_readings_obj->psolar_kw_yesterday . "</pre>";
                echo "<pre>" . "Battery Discharged Yesterday (KWH): ". $studer_readings_obj->energyout_battery_yesterday . "</pre>";
                echo "<pre>" . "Grid Energy In Yesterday (KWH): ".  $studer_readings_obj->energy_grid_yesterday . "</pre>";
                echo "<pre>" . "Energy Consumed Yesterday (KWH): ".  $studer_readings_obj->energy_consumed_yesterday . "</pre>";
                echo nl2br("/n");
            break;

            case "Get_Shelly_Device_Status":
                // Get the Shelly device status whose id is listed in the config.
                $shelly_api_device_response = $this->get_shelly_device_status($config_index);
                $shelly_api_device_status_ON = $shelly_api_device_response->data->device_status;
            break;

            case "turn_Shelly_Switch_ON":
                // command the Shelly ACIN switch to ON
                $shelly_api_device_response = $this->turn_on_off_shelly_switch($config_index, "on");
                sleep(1);

                // get a fresh status
                $shelly_api_device_response = $this->get_shelly_device_status($config_index);
                $shelly_api_device_status_ON   = $shelly_api_device_response->data->device_status;
            break;

            case "turn_Shelly_Switch_OFF":
                // command the Shelly ACIN switch to ON
                $shelly_api_device_response = $this->turn_on_off_shelly_switch($config_index, "off");
                sleep(1);

                // get a fresh status
                $shelly_api_device_response = $this->get_shelly_device_status($config_index);
                $shelly_api_device_status_ON   = $shelly_api_device_response->data->device_status;
            break;

            case "run_cron_exec_once":
                $this->verbose = true;
                $this->shellystuder_cron_exec();
                $this->verbose = false;
            break;

            case "estimated_solar_power":
              $est_solar_kw = $this->estimated_solar_power($config_index);
              foreach ($est_solar_kw as $key => $value)
              {
                echo "<pre>" . "Est Solar Power, Clear Day (KW): " .    $value . "</pre>";
              }
              echo "<pre>" . "Total Est Solar Power Clear Day (KW): " .    array_sum($est_solar_kw) . "</pre>";
            break;

            case "check_if_cloudy_day":
              $cloudiness_forecast= $this->check_if_forecast_is_cloudy();

              $it_is_a_cloudy_day = $cloudiness_forecast->it_is_a_cloudy_day;

              $cloud_cover_percentage = $cloudiness_forecast->cloudiness_average_percentage;

              echo "<pre>" . "Is it a cloudy day?: " .    $it_is_a_cloudy_day . "</pre>";
              echo "<pre>" . "Average CLoudiness percentage?: " .    $cloud_cover_percentage . "%</pre>";
            break;

        }
        if($shelly_api_device_status_ON->{"switch:0"}->output)
        {
            $switch_state = "Closed";
        }
        else
        {
          $switch_state = "Open";
        }
        if($shelly_api_device_status_ON->{"switch:0"}->output !== true)
        {
            $switch_state = "Open  and False";
        }
        else
        {
          $switch_state = "Closed  and True";
        }
        echo "<pre>" . "ACIN Shelly Switch State: " .    $switch_state . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Voltage: " .  $shelly_api_device_status_ON->{"switch:0"}->voltage . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Power: " .    $shelly_api_device_status_ON->{"switch:0"}->apower . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Current: " .  $shelly_api_device_status_ON->{"switch:0"}->current . "</pre>";
    }

    /**
     *
     */
    public function estimated_solar_power($user_index)
    {
        $config = $this->config;
        $panel_sets = $config['accounts'][$user_index]['panels'];

        foreach ($panel_sets as $key => $panel_set)
        {
          // 5.5 is the UTC offset of 5h 30 mins in decimal.
          $transindus_lat_long_array = [$this->lat, $this->lon];
          $solar_calc = new solar_calculation($panel_set, $transindus_lat_long_array, $this->utc_offset);
          $est_solar_kw[$key] =  round($solar_calc->est_power(), 1);
        }

        return $est_solar_kw;
    }


    /**
     *
     */
    public function turn_on_off_shelly_switch($user_index, $desired_state)
    {
        $config = $this->config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response
        $shelly_device_data = $shelly_api->turn_on_off_shelly_switch($desired_state);

        return $shelly_device_data;
    }

    public function get_shelly_device_status(int $user_index): ?object
    {
        // get API and device ID from config based on user index
        $config = $this->config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_device_data = $shelly_api->get_shelly_device_status();

        return $shelly_device_data;
    }

    /**
    ** This function returns an object that comprises data read form user's installtion
    *  @param int:$user_index  is the numeric index to denote a particular installtion
    *  @return object:$studer_readings_obj
    */
    public function get_studer_readings(int $user_index): ?object
    {
        $config = $this->config;

        $Ra = 0.0;       // value of resistance from DC junction to Inverter
        $Rb = 0.025;       // value of resistance from DC junction to Battery terminals

        $base_url  = $config['studer_api_baseurl'];
        $uhash     = $config['accounts'][$user_index]['uhash'];
        $phash     = $config['accounts'][$user_index]['phash'];

        $studer_api = new studer_api($uhash, $phash, $base_url);

        $studer_readings_obj = new stdClass;

        $body = [];

        // get the input AC active power value
        $body = array(array(
                              "userRef"       =>  3136,   // AC active power delivered by inverter
                              "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3076,   // Energy from Battery Yesterday
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3082,   // Energy consumed yesterday
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  3080,   // Energy from Grid yesterda
                               "infoAssembly"  => "Master"
                           ),
                       array(
                               "userRef"       =>  11011,   // Solar Production from Panel set1 yesterday
                               "infoAssembly"  => "1"
                           ),
                       array(
                               "userRef"       =>  11011,   // Solar Production from Panel set 2 yesterday
                               "infoAssembly"  => "2"
                           ),
                      array(
                               "userRef"       =>  3137,   // Grid AC input Active power
                               "infoAssembly"  => "Master"
                           ),
                      array(
                               "userRef"       =>  3020,   // State of Transfer Relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                               "userRef"       =>  3031,   // State of AUX1 relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3000,   // Battery Voltage
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3011,   // Grid AC in Voltage Vac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3012,   // Grid AC in Current Aac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3005,   // DC input current to Inverter
                              "infoAssembly"  => "Master"
                            ),

                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT2
                              "infoAssembly"  => "2"
                            ),
                      array(
                              "userRef"       =>  11002,   // solar pv Voltage to variotrac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT2
                              "infoAssembly"  => "2"
                            ),

                      array(
                              "userRef"       =>  3010,   // Phase of battery charge
                              "infoAssembly"  => "Master"
                            ),
                      );
        $studer_api->body   = $body;

        // POST curl request to Studer
        $user_values  = $studer_api->get_user_values();

        if (empty($user_values))
            {
              return null;
            }

        $solar_pv_adc = 0;
        $psolar_kw    = 0;
        $psolar_kw_yesterday = 0;


        foreach ($user_values as $user_value)
        {
          switch (true)
          {
            case ( $user_value->reference == 3031 ) :
              $aux1_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3020 ) :
              $transfer_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3011 ) :
              $grid_input_vac = round($user_value->value, 0);
            break;

            case ( $user_value->reference == 3012 ) :
              $grid_input_aac = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3000 ) :
              $battery_voltage_vdc = round($user_value->value, 2);
            break;

            case ( $user_value->reference == 3005 ) :
              $inverter_current_adc = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3137 ) :
              $grid_pin_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 2);

             break;

            case ( $user_value->reference == 11001 ) :
              // we have to accumulate values form 2 cases:VT1 and VT2 so we have used accumulation below
              $solar_pv_adc += $user_value->value;

            break;

            case ( $user_value->reference == 11002 ) :
              $solar_pv_vdc = round($user_value->value, 1);

            break;

            case ( $user_value->reference == 11004 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $psolar_kw += round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3010 ) :
              $phase_battery_charge = $user_value->value;

            break;

            case ( $user_value->reference == 11011 ) :
               // we have to accumulate values form 2 cases so we have used accumulation below
               $psolar_kw_yesterday += round($user_value->value, 2);

             break;
          }
        }

        $solar_pv_adc = round($solar_pv_adc, 1);

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
        $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 2); //$psolar_kw - $pout_inverter_ac_kw;


        // inverter's output always goes to load never the other way around :-)
        $inverter_pout_arrow_class = "fa fa-long-arrow-right fa-rotate-45 rediconcolor";

        // conditional class names for battery charge down or up arrow
        if ($battery_charge_adc > 0.0)
        {
          // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
          $battery_charge_arrow_class = "fa fa-long-arrow-down fa-rotate-45 rediconcolor";
          // battery animation class is from ne-sw
          $battery_charge_animation_class = "arrowSliding_ne_sw";

          $battery_color_style = 'greeniconcolor';

          // also good time to compensate for IR drop.
          // Actual voltage is smaller than indicated, when charging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
          $battery_charge_animation_class = "arrowSliding_sw_ne";
          $battery_color_style = 'rediconcolor';

          // Actual battery voltage is larger than indicated when discharging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra + abs($battery_charge_adc) * $Rb, 2);
        }

        switch(true)
        {
          case (abs($battery_charge_adc) < 27 ) :
            $battery_charge_arrow_class .= " fa-1x";
          break;

          case (abs($battery_charge_adc) < 54 ) :
            $battery_charge_arrow_class .= " fa-2x";
          break;

          case (abs($battery_charge_adc) >=54 ) :
            $battery_charge_arrow_class .= " fa-3x";
          break;
        }

        // conditional for solar pv arrow
        if ($psolar_kw > 0.1)
        {
          // power is greater than 0.2kW so indicate down arrow
          $solar_arrow_class = "fa fa-long-arrow-down fa-rotate-45 greeniconcolor";
          $solar_arrow_animation_class = "arrowSliding_ne_sw";
        }
        else
        {
          // power is too small indicate a blank line vertically down from Solar panel to Inverter in diagram
          $solar_arrow_class = "fa fa-minus fa-rotate-90";
          $solar_arrow_animation_class = "";
        }

        switch(true)
        {
          case (abs($psolar_kw) < 0.5 ) :
            $solar_arrow_class .= " fa-1x";
          break;

          case (abs($psolar_kw) < 2.0 ) :
            $solar_arrow_class .= " fa-2x";
          break;

          case (abs($psolar_kw) >= 2.0 ) :
            $solar_arrow_class .= " fa-3x";
          break;
        }

        switch(true)
        {
          case (abs($pout_inverter_ac_kw) < 1.0 ) :
            $inverter_pout_arrow_class .= " fa-1x";
          break;

          case (abs($pout_inverter_ac_kw) < 2.0 ) :
            $inverter_pout_arrow_class .= " fa-2x";
          break;

          case (abs($pout_inverter_ac_kw) >=2.0 ) :
            $inverter_pout_arrow_class .= " fa-3x";
          break;
        }

        // conditional for Grid input arrow
        if ($transfer_relay_state)
        {
          // Transfer Relay is closed so grid input is possible
          $grid_input_arrow_class = "fa fa-long-arrow-right fa-rotate-45";
        }
        else
        {
          // Transfer relay is open and grid input is not possible
          $grid_input_arrow_class = "fa fa-times-circle fa-2x";
        }

        switch(true)
        {
          case (abs($grid_pin_ac_kw) < 1.0 ) :
            $grid_input_arrow_class .= " fa-1x";
          break;

          case (abs($grid_pin_ac_kw) < 2.0 ) :
            $grid_input_arrow_class .= " fa-2x";
          break;

          case (abs($grid_pin_ac_kw) < 3.5 ) :
            $grid_input_arrow_class .= " fa-3x";
          break;

          case (abs($grid_pin_ac_kw) < 4 ) :
            $grid_input_arrow_class .= " fa-4x";
          break;
        }

       $current_user           = wp_get_current_user();
       $current_user_ID        = $current_user->ID;
       // $battery_vdc_state_json = get_user_meta($current_user_ID, "json_battery_voltage_state", true);
       // $battery_vdc_state      = json_decode($battery_vdc_state_json, true);

       // select battery icon based on charge level
      switch(true)
      {
        case ($battery_voltage_vdc < $config['battery_vdc_state']["25p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["50p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["75p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["75p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
        break;
      }

      $battery_span_fontawesome = '
                                    <i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

      // select battery icon color: Green if charging, Red if discharging


      // update the object with battery data read
      $studer_readings_obj->battery_charge_adc          = $battery_charge_adc;
      $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
      $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
      $studer_readings_obj->battery_charge_arrow_class  = $battery_charge_arrow_class;
      $studer_readings_obj->battery_icon_class          = $battery_icon_class;
      $studer_readings_obj->battery_charge_animation_class = $battery_charge_animation_class;
      $studer_readings_obj->energyout_battery_yesterday    = $energyout_battery_yesterday;

      // update the object with SOlar data read
      $studer_readings_obj->psolar_kw                   = $psolar_kw;
      $studer_readings_obj->solar_pv_adc                = $solar_pv_adc;
      // $studer_readings_obj->solar_pv_vdc                = $solar_pv_vdc;
      $studer_readings_obj->solar_arrow_class           = $solar_arrow_class;
      $studer_readings_obj->solar_arrow_animation_class = $solar_arrow_animation_class;
      $studer_readings_obj->psolar_kw_yesterday         = $psolar_kw_yesterday;

      //update the object with Inverter Load details
      $studer_readings_obj->pout_inverter_ac_kw         = $pout_inverter_ac_kw;
      $studer_readings_obj->inverter_pout_arrow_class   = $inverter_pout_arrow_class;

      // update the Grid input values
      $studer_readings_obj->transfer_relay_state        = $transfer_relay_state;
      $studer_readings_obj->grid_pin_ac_kw              = $grid_pin_ac_kw;
      $studer_readings_obj->grid_input_vac              = $grid_input_vac;
      $studer_readings_obj->grid_input_arrow_class      = $grid_input_arrow_class;
      $studer_readings_obj->aux1_relay_state            = $aux1_relay_state;
      $studer_readings_obj->energy_grid_yesterday       = $energy_grid_yesterday;
      $studer_readings_obj->energy_consumed_yesterday   = $energy_consumed_yesterday;
      $studer_readings_obj->battery_span_fontawesome    = $battery_span_fontawesome;


      // update the object with the fontawesome cdn from Studer API object
      // $studer_readings_obj->fontawesome_cdn             = $studer_api->fontawesome_cdn;

      return $studer_readings_obj;
    }

    /**
    ** This function returns an object that comprises data read form user's installtion
    *  @param int:$user_index  is the numeric index to denote a particular installtion
    *  @return object:$studer_readings_obj
    */
    public function get_studer_min_readings(int $user_index): ?object
    {
        $config = $this->config;

        $Ra = 0.0;       // value of resistance from DC junction to Inverter
        $Rb = 0.025;       // value of resistance from DC junction to Battery terminals

        $base_url  = $config['studer_api_baseurl'];
        $uhash     = $config['accounts'][$user_index]['uhash'];
        $phash     = $config['accounts'][$user_index]['phash'];

        $studer_api = new studer_api($uhash, $phash, $base_url);

        $studer_readings_obj = new stdClass;

        $body = [];

        // get the input AC active power value
        $body = array(array(
                              "userRef"       =>  3136,   // AC active power delivered by inverter
                              "infoAssembly"  => "Master"
                           ),
                      array(
                               "userRef"       =>  3137,   // Grid AC input Active power
                               "infoAssembly"  => "Master"
                           ),
                      array(
                               "userRef"       =>  3020,   // State of Transfer Relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                               "userRef"       =>  3031,   // State of AUX1 relay
                               "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3000,   // Battery Voltage
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3011,   // Grid AC in Voltage Vac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3012,   // Grid AC in Current Aac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3005,   // DC input current to Inverter
                              "infoAssembly"  => "Master"
                            ),

                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11001,   // DC current into Battery junstion from VT2
                              "infoAssembly"  => "2"
                            ),
                      array(
                              "userRef"       =>  11002,   // solar pv Voltage to variotrac
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11004,   // Psolkw from VT2
                              "infoAssembly"  => "2"
                            ),

                      array(
                              "userRef"       =>  3010,   // Phase of battery charge
                              "infoAssembly"  => "Master"
                            ),
                      );
        $studer_api->body   = $body;

        // POST curl request to Studer
        $user_values  = $studer_api->get_user_values();

        if (empty($user_values))
            {
              return null;
            }

        $solar_pv_adc = 0;
        $psolar_kw    = 0;
        $psolar_kw_yesterday = 0;


        foreach ($user_values as $user_value)
        {
          switch (true)
          {
            case ( $user_value->reference == 3031 ) :
              $aux1_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3020 ) :
              $transfer_relay_state = $user_value->value;
            break;

            case ( $user_value->reference == 3011 ) :
              $grid_input_vac = round($user_value->value, 0);
            break;

            case ( $user_value->reference == 3012 ) :
              $grid_input_aac = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3000 ) :
              $battery_voltage_vdc = round($user_value->value, 2);
            break;

            case ( $user_value->reference == 3005 ) :
              $inverter_current_adc = round($user_value->value, 1);
            break;

            case ( $user_value->reference == 3137 ) :
              $grid_pin_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 2);

             break;

            case ( $user_value->reference == 11001 ) :
              // we have to accumulate values form 2 cases:VT1 and VT2 so we have used accumulation below
              $solar_pv_adc += $user_value->value;

            break;

            case ( $user_value->reference == 11002 ) :
              $solar_pv_vdc = round($user_value->value, 1);

            break;

            case ( $user_value->reference == 11004 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $psolar_kw += round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3010 ) :
              $phase_battery_charge = $user_value->value;

            break;

            case ( $user_value->reference == 11011 ) :
               // we have to accumulate values form 2 cases so we have used accumulation below
               $psolar_kw_yesterday += round($user_value->value, 2);

             break;
          }
        }

        $solar_pv_adc = round($solar_pv_adc, 1);

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
        $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 2); //$psolar_kw - $pout_inverter_ac_kw;


        // conditional class names for battery charge down or up arrow
        if ($battery_charge_adc > 0.0)
        {
          // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
          $battery_charge_arrow_class = "fa fa-long-arrow-down fa-rotate-45 rediconcolor";
          // battery animation class is from ne-sw
          $battery_charge_animation_class = "arrowSliding_ne_sw";

          $battery_color_style = 'greeniconcolor';

          // also good time to compensate for IR drop.
          // Actual voltage is smaller than indicated, when charging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
          $battery_charge_animation_class = "arrowSliding_sw_ne";
          $battery_color_style = 'rediconcolor';

          // Actual battery voltage is larger than indicated when discharging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra + abs($battery_charge_adc) * $Rb, 2);
        }

        switch(true)
        {
          case (abs($battery_charge_adc) < 27 ) :
            $battery_charge_arrow_class .= " fa-1x";
          break;

          case (abs($battery_charge_adc) < 54 ) :
            $battery_charge_arrow_class .= " fa-2x";
          break;

          case (abs($battery_charge_adc) >=54 ) :
            $battery_charge_arrow_class .= " fa-3x";
          break;
        }

        // conditional for solar pv arrow
        if ($psolar_kw > 0.1)
        {
          // power is greater than 0.2kW so indicate down arrow
          $solar_arrow_class = "fa fa-long-arrow-down fa-rotate-45 greeniconcolor";
          $solar_arrow_animation_class = "arrowSliding_ne_sw";
        }
        else
        {
          // power is too small indicate a blank line vertically down from Solar panel to Inverter in diagram
          $solar_arrow_class = "fa fa-minus fa-rotate-90";
          $solar_arrow_animation_class = "";
        }

        switch(true)
        {
          case (abs($psolar_kw) < 0.5 ) :
            $solar_arrow_class .= " fa-1x";
          break;

          case (abs($psolar_kw) < 2.0 ) :
            $solar_arrow_class .= " fa-2x";
          break;

          case (abs($psolar_kw) >= 2.0 ) :
            $solar_arrow_class .= " fa-3x";
          break;
        }
/*
        switch(true)
        {
          case (abs($pout_inverter_ac_kw) < 1.0 ) :
            $inverter_pout_arrow_class .= " fa-1x";
          break;

          case (abs($pout_inverter_ac_kw) < 2.0 ) :
            $inverter_pout_arrow_class .= " fa-2x";
          break;

          case (abs($pout_inverter_ac_kw) >=2.0 ) :
            $inverter_pout_arrow_class .= " fa-3x";
          break;
        }
*/
        // conditional for Grid input arrow
        if ($transfer_relay_state)
        {
          // Transfer Relay is closed so grid input is possible
          $grid_input_arrow_class = "fa fa-long-arrow-right fa-rotate-45";
        }
        else
        {
          // Transfer relay is open and grid input is not possible
          $grid_input_arrow_class = "fa fa-times-circle fa-2x";
        }

        switch(true)
        {
          case (abs($grid_pin_ac_kw) < 1.0 ) :
            $grid_input_arrow_class .= " fa-1x";
          break;

          case (abs($grid_pin_ac_kw) < 2.0 ) :
            $grid_input_arrow_class .= " fa-2x";
          break;

          case (abs($grid_pin_ac_kw) < 3.5 ) :
            $grid_input_arrow_class .= " fa-3x";
          break;

          case (abs($grid_pin_ac_kw) < 4 ) :
            $grid_input_arrow_class .= " fa-4x";
          break;
        }

       $current_user           = wp_get_current_user();
       $current_user_ID        = $current_user->ID;
       // $battery_vdc_state_json = get_user_meta($current_user_ID, "json_battery_voltage_state", true);
       // $battery_vdc_state      = json_decode($battery_vdc_state_json, true);

       // select battery icon based on charge level
      switch(true)
      {
        case ($battery_voltage_vdc < $config['battery_vdc_state']["25p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["50p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["75p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["75p"] &&
              $battery_voltage_vdc <  $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
        break;

        case ($battery_voltage_vdc >= $config['battery_vdc_state']["100p"] ):
          $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
        break;
      }

      $battery_span_fontawesome = '
                                    <i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

      // select battery icon color: Green if charging, Red if discharging


      // update the object with battery data read
      $studer_readings_obj->battery_charge_adc          = $battery_charge_adc;
      $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
      $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
      $studer_readings_obj->battery_charge_arrow_class  = $battery_charge_arrow_class;
      $studer_readings_obj->battery_icon_class          = $battery_icon_class;
      $studer_readings_obj->battery_charge_animation_class = $battery_charge_animation_class;
      // $studer_readings_obj->energyout_battery_yesterday    = $energyout_battery_yesterday;

      // update the object with SOlar data read
      $studer_readings_obj->psolar_kw                   = $psolar_kw;
      $studer_readings_obj->solar_pv_adc                = $solar_pv_adc;
      // $studer_readings_obj->solar_pv_vdc                = $solar_pv_vdc;
      $studer_readings_obj->solar_arrow_class           = $solar_arrow_class;
      $studer_readings_obj->solar_arrow_animation_class = $solar_arrow_animation_class;
      $studer_readings_obj->psolar_kw_yesterday         = $psolar_kw_yesterday;

      //update the object with Inverter Load details
      $studer_readings_obj->pout_inverter_ac_kw         = $pout_inverter_ac_kw;
      // $studer_readings_obj->inverter_pout_arrow_class   = $inverter_pout_arrow_class;
      $studer_readings_obj->inverter_current_adc        = $inverter_current_adc;

      // update the Grid input values
      $studer_readings_obj->transfer_relay_state        = $transfer_relay_state;
      $studer_readings_obj->grid_pin_ac_kw              = $grid_pin_ac_kw;
      $studer_readings_obj->grid_input_vac              = $grid_input_vac;
      $studer_readings_obj->grid_input_arrow_class      = $grid_input_arrow_class;
      $studer_readings_obj->aux1_relay_state            = $aux1_relay_state;
      // $studer_readings_obj->energy_grid_yesterday       = $energy_grid_yesterday;
      // $studer_readings_obj->energy_consumed_yesterday   = $energy_consumed_yesterday;
      $studer_readings_obj->battery_span_fontawesome    = $battery_span_fontawesome;

      return $studer_readings_obj;
    }
    

    /**
     * 
     */
    public function ajax_my_solar_update_handler()     
    {   // service AJax Call
        // The error log time stamp was showing as UTC so I added the below statement
      date_default_timezone_set("Asia/Kolkata");

        // Ensures nonce is correct for security
        check_ajax_referer('my_solar_app_script');

        if ($_POST['data']) {   // extract data from POST sent by the Ajax Call and Sanitize
            
            $data = $_POST['data'];

            $toggleGridSwitch = $data['toggleGridSwitch'];

            // sanitize the POST data
            $toggleGridSwitch = sanitize_text_field($toggleGridSwitch);

            // get my user index knowing my login name
            $wp_user_ID   = $data['wp_user_ID'];

            // sanitize the POST data
            $wp_user_ID   = sanitize_text_field($wp_user_ID);

            $doShellyToggle = $data['doShellyToggle'];

            // sanitize the POST data
            $doShellyToggle = sanitize_text_field($doShellyToggle);
        }

        if ( $doShellyToggle ) {  // User request to toggle do_shelly user meta

            // get the current setting from User Meta
            $current_status_doShelly = get_user_meta($wp_user_ID, "do_shelly", true);

            switch(true)
            {
                case( is_null( $current_status_doShelly ) ):  // do nothing, since the user has not formally set this flag.  
                    break;

                case($current_status_doShelly):               // If TRUE, update user meta to FALSE
                    
                    update_user_meta( $wp_user_ID, "do_shelly", false);
                    break;

                case( ! $current_status_doShelly):            // If FALSE, update user meta to TRUE
                    update_user_meta( $wp_user_ID, "do_shelly", true);
                    break;
            }
        }
        
        {    // get user_index based on user_name
            $current_user = get_user_by('id', $wp_user_ID);
            $wp_user_name = $current_user->user_login;
            $user_index   = array_search( $wp_user_name, array_column($this->config['accounts'], 'wp_user_name')) ;

            error_log("from Ajax Call: toggleGridSwitch Value: " . $toggleGridSwitch . 
                                                  ' wp_user_ID:' . $wp_user_ID       . 
                                            ' doShellyToggle:'   . $doShellyToggle   . 
                                                ' user_index:'   . $user_index);
        }

        // extract the do_shelly control flag as set in user meta
        $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true);

        if ($toggleGridSwitch)  {   // User has requested to toggle the GRID ON/OFF Shelly Switch

            // Get current status of switch
            $shelly_api_device_response   = $this->get_shelly_device_status($user_index);

            if ( empty($shelly_api_device_response) ) {   // what do we do we do if device is OFFLINE?
                // do nothing
            }
            else {  // valid switch response so we can determine status
                    
                    $shelly_api_device_status_ON  = $shelly_api_device_response->data->device_status->{"switch:0"}->output;

                    if ($shelly_api_device_status_ON) {   // Switch is ON, toggle switch to OFF
                        $shelly_switch_status = "ON";

                        // we need to turn it off because user has toggled switch
                        $response = $this->turn_on_off_shelly_switch($user_index, "off");

                        error_log('Changed Switch from ON->OFF due to Ajax Request');

                    }
                    else {    // Switch is OFF, toggle switch to ON
                        $shelly_switch_status = "OFF";

                        // we need to turn switch ON since user has toggled switch
                        $response = $this->turn_on_off_shelly_switch($user_index, "on");

                        error_log('Changed Switch from OFF->ON due to Ajax Request');
                    }
            }
        }

        // get a new set of readings
        $studer_readings_obj = $this->get_readings_and_servo_grid_switch  ($user_index, 
                                                                          $wp_user_ID, 
                                                                          $wp_user_name, 
                                                                          $do_shelly_user_meta);

        $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

        // error_log( print_r($format_object, true) );

        wp_send_json($format_object);
 
	      // finished now die
        wp_die(); // all ajax handlers should die when finished
    }    
    
    /**
     * @param object:studer_readings_obj contains details of all the readings
     * @return object:format_object contains html for all the icons to be updatd by JS on Ajax call return
     * determine the icons based on updated data
     */
    public function prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj )
    {
        $config         = $this->config;

        // Initialize object to be returned
        $format_object  = new stdClass();

        $psolar_kw              =   $studer_readings_obj->psolar_kw;
        $solar_pv_adc           =   $studer_readings_obj->solar_pv_adc;

        $pout_inverter_ac_kw    =   $studer_readings_obj->pout_inverter_ac_kw;

    
        $battery_voltage_vdc    =   round( $studer_readings_obj->battery_voltage_vdc, 1);

        // Positive is charging and negative is discharging
        $battery_charge_adc     =   $studer_readings_obj->battery_charge_adc;

        $pbattery_kw            = $studer_readings_obj->pbattery_kw;

        $grid_pin_ac_kw         =   $studer_readings_obj->grid_pin_ac_kw;
        $grid_input_vac         =   $studer_readings_obj->grid_input_vac;

        $shelly_api_device_status_ON      = $studer_readings_obj->shelly_api_device_status_ON;
        $shelly_api_device_status_voltage = $studer_readings_obj->shelly_api_device_status_voltage;

        // If power is flowing OR switch has ON status then show CHeck and Green
        $grid_arrow_size = $this->get_arrow_size_based_on_power($grid_pin_ac_kw);

        switch (true)
        {   // choose grid icon info based on switch status
            case ( is_null($shelly_api_device_status_ON) ): // No Grid OR switch is OFFLINE
                $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Yellow;"></i>';

                $grid_arrow_icon = ''; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

                $grid_info = 'No<br>Grid';

                break;


            case ( $shelly_api_device_status_ON): // Switch is ON
                $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Blue;"></i>';

                $grid_arrow_icon  = '<i class="fa-solid' . $grid_arrow_size .  'fa-arrow-right-long fa-rotate-by"
                                                                                  style="--fa-rotate-angle: 45deg;">
                                    </i>';
                $grid_info = '<span style="font-size: 18px;color: Red;"><strong>' . $grid_pin_ac_kw . 
                              ' KW</strong><br>' . $shelly_api_device_status_voltage . ' V</span>';
                break;


            case ( ! $shelly_api_device_status_ON):   // Switch is online and OFF
                $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Red;"></i>';

                $grid_arrow_icon = ''; //'<i class="fa-solid fa-1x fa-circle-xmark"></i>';
    
                $grid_info = '<span style="font-size: 18px;color: Red;">' . $grid_pin_ac_kw . 
                        ' KW<br>' . $shelly_api_device_status_voltage . ' V</span>';
                break;

            default:  
              $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Brown;"></i>';

              $grid_arrow_icon = 'XX'; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

              $grid_info = '???';
        }

        $format_object->grid_status_icon  = $grid_status_icon;
        $format_object->grid_arrow_icon   = $grid_arrow_icon;

        // grid power and voltage info
        $format_object->grid_info       = $grid_info;

        // PV arrow icon psolar_info
        $pv_arrow_size = $this->get_arrow_size_based_on_power($psolar_kw);

        if ($psolar_kw > 0.1) {
            $pv_arrow_icon = '<i class="fa-solid' . $pv_arrow_size . 'fa-arrow-down-long fa-rotate-by"
                                                                           style="--fa-rotate-angle: 45deg;
                                                                                              color: Green;"></i>';
            $psolar_info =  '<span style="font-size: 18px;color: Green;"><strong>' . $psolar_kw . 
                            ' KW</strong><br>' . $solar_pv_adc . ' A</span>';
        }
        else {
            $pv_arrow_icon = ''; //'<i class="fa-solid fa-1x fa-circle-xmark"></i>';
            $psolar_info =  '<span style="font-size: 18px;">' . $psolar_kw . 
                            ' KW<br>' . $solar_pv_adc . ' A</span>';
        }

        $pv_panel_icon =  '<span style="color: Green;">
                              <i class="fa-solid fa-3x fa-solar-panel"></i>
                          </span>';

        $format_object->pv_panel_icon = $pv_panel_icon;
        $format_object->pv_arrow_icon = $pv_arrow_icon;
        $format_object->psolar_info   = $psolar_info;

        // Studer Inverter icon
        $studer_icon = '<i style="display:block; text-align: center;" class="clickableIcon fa-solid fa-3x fa-cog"></i>';
        $format_object->studer_icon = $studer_icon;

        if ($studer_readings_obj->control_shelly)
        {
            $shelly_servo_icon = '<span style="color: Green; display:block; text-align: center;">
                                      <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                  </span>';
        }
        else
        {
            $shelly_servo_icon = '<span style="color: Red; display:block; text-align: center;">
                                      < i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                  </span>';
        }
        $format_object->shelly_servo_icon = $shelly_servo_icon;

        // battery status icon: select battery icon based on charge level
        switch(true)
        {
            case ($battery_voltage_vdc < $config['battery_vdc_state']["25p"] ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
            break;

            case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] &&
                  $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
            break;

            case ($battery_voltage_vdc >= $config['battery_vdc_state']["50p"] &&
                  $battery_voltage_vdc <  $config['battery_vdc_state']["75p"] ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
            break;

            case ($battery_voltage_vdc >= $config['battery_vdc_state']["75p"] &&
                  $battery_voltage_vdc <  $config['battery_vdc_state']["100p"] ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
            break;

            case ($battery_voltage_vdc >= $config['battery_vdc_state']["100p"] ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
            break;
        }

        // now determione battery arrow direction and battery color based on charge or discharge
        // conditional class names for battery charge down or up arrow
        $battery_arrow_size = $this->get_arrow_size_based_on_power($pbattery_kw);

        if ($battery_charge_adc > 0.0)
        {
            // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
            $battery_arrow_icon = '<i class="fa-solid' .  $battery_arrow_size . 'fa-arrow-down-long fa-rotate-by"
                                                                                style="--fa-rotate-angle: 45deg;
                                                                                                   color:green;">
                                   </i>';

            // battery animation class is from ne-sw
            $battery_charge_animation_class = "arrowSliding_ne_sw";

            // battery icon shall be green in color
            $battery_color_style = 'greeniconcolor';

            // battery info shall be green in color
            $battery_info =  '<span style="font-size: 18px;color: Green;"><strong>' . $pbattery_kw  . ' KW</strong><br>' 
                                                                            . abs($battery_charge_adc)  . 'A<br>'
                                                                            . $battery_voltage_vdc      . ' V<br></span>';
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_arrow_icon = '<i class="fa-solid' . $battery_arrow_size . 'fa-arrow-up fa-rotate-by"
                                                                              style="--fa-rotate-angle: 45deg;
                                                                                                 color:red;">
                                  </i>';

          $battery_charge_animation_class = "arrowSliding_sw_ne";

          // battery status in discharge is red in color
          $battery_color_style = 'rediconcolor';

          // battery info shall be red in color
          $battery_info =  '<span style="font-size: 18px;color: Red;"><strong>' . $pbattery_kw . ' KW</strong><br>' 
                                                                        . abs($battery_charge_adc)  . 'A<br>'
                                                                        . $battery_voltage_vdc      . ' V<br></span>';
        }

        if  ($pbattery_kw < 0.01 ) $battery_arrow_icon = ''; // '<i class="fa-solid fa-1x fa-circle-xmark"></i>';

        $format_object->battery_arrow_icon  = $battery_arrow_icon;

        $battery_status_icon = '<i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

        $format_object->battery_status_icon = $battery_status_icon;
        $format_object->battery_arrow_icon  = $battery_arrow_icon;
        $format_object->battery_info        = $battery_info;

        $load_arrow_size = $this->get_arrow_size_based_on_power($pout_inverter_ac_kw);

        $load_info = '<span style="font-size: 18px;color: Black;"><strong>' . $pout_inverter_ac_kw . ' KW</strong></span>';
        $load_arrow_icon = '<i class="fa-solid' . $load_arrow_size . 'fa-arrow-right-long fa-rotate-by"
                                                                          style="--fa-rotate-angle: 45deg;">
                            </i>';

        $load_icon = '<span style="color: Black;">
                          <i class="fa-solid fa-3x fa-house"></i>
                      </span>';

        $format_object->load_info        = $load_info;
        $format_object->load_arrow_icon  = $load_arrow_icon;
        $format_object->load_icon        = $load_icon;

        // Get Cron Exit COndition from User Meta and its time stamo
        $json_cron_exit_condition_user_meta = get_user_meta( $wp_user_ID, 'studer_readings_object', true );

        // decode the JSON encoded string into an Object
        $cron_exit_condition_user_meta_arr = json_decode($json_cron_exit_condition_user_meta, true);

        // extract the last condition saved that was NOT a No Action.
        $saved_cron_exit_condition = $cron_exit_condition_user_meta_arr['cron_exit_condition'];

        // Extract the exit conditioned saved on every update regardless of servo action
        $latest_cron_exit_condition = $studer_readings_obj->cron_exit_condition;

        if ( $latest_cron_exit_condition === "No Action" ) {

          // keep the old condition since no further action has taken place
          $cron_exit_condition    = $saved_cron_exit_condition;

          $now = new DateTime();
          $past_unixdatetime = $cron_exit_condition_user_meta_arr['unixdatetime'];
          $past = (new DateTime('@' . $past_unixdatetime))->setTimezone(new DateTimeZone("Asia/Kolkata"));
          $interval_since_last_change = $now->diff($past);

        }
        else {
            // We have a new Servo Action so display that
            $cron_exit_condition = $latest_cron_exit_condition;
            $past = $studer_readings_obj->datetime;
            $interval_since_last_change = $now->diff($past);
        }

        $formatted_interval = $this->format_interval($interval_since_last_change);

        $format_object->cron_exit_condition = '<span style="color: Blue; display:block; text-align: center;">' . 
                                                  $formatted_interval   . '<br>' .
                                                  $cron_exit_condition  .
                                              '</span>';

        return $format_object;
    }

    /**
     * 
     */
    public function get_arrow_size_based_on_power($power)
    {
        switch (true)
        {
            case ($power > 0.0 && $power < 1.0):
                return " fa-1x ";

            case ($power >= 1.0 && $power < 2.0):
                return " fa-2x ";

            case ($power >= 2.0 && $power < 3.0):
                return " fa-3x ";

            case ($power >= 3.0 ):
              return " fa-4x ";
        }
    }

    /**
     * Format an interval to show all existing components.
     * If the interval doesn't have a time component (years, months, etc)
     * That component won't be displayed.
     *
     * @param DateInterval $interval The interval
     *
     * @return string Formatted interval string.
     */
    public function format_interval(DateInterval $interval) {
      $result = "";
      if ($interval->y) { $result .= $interval->format("%y years "); }
      if ($interval->m) { $result .= $interval->format("%m months "); }
      if ($interval->d) { $result .= $interval->format("%d d "); }
      if ($interval->h) { $result .= $interval->format("%h h "); }
      if ($interval->i) { $result .= $interval->format("%i m "); }
      if ($interval->s) { $result .= $interval->format("%s s "); }

      return $result;
    }

}