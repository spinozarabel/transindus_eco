<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 * Ver internet-master-ver1
 *  1. Nominally, cloud site gets state from local linux pc using pub/sub via mqtt on both servers.
 *  2. Nominally, control is by local linux PC only. The cloud site is only for display and UI settings relay back.
 *  3. Nominally, The settings from cloud PC override those of the local. These are: All flags and settings values.
 *  4. When internet fails, the local linux PC deals exclusively. There is no UI other than locally.
 *  5. When local linux PC fails but the LAN and the internet is functional the remote site must sense this
 *  6. When remote site takes over control it controls all devices using the internet. The local linux PC is unreacheable.
 *  7. When the local PC functionality is restored it needs to take over the control
 *  8. It needs to get the environment variables from the remote PC not just the flags and settings for reset.
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
require_once(__DIR__."/class_my_mqtt.php");

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
  public $load_kw_avg_arr;
  public $psolar_avg_arr;
  public $pload_avg;
  public $count_for_averaging;
  public $counter;
  public $datetime;
  public $valid_shelly_config;
  public $do_soc_cal_now_arr;

  public $all_usermeta;

  public $twelve_cron_5s_cycles_completed;

  // This flag is only true when SOC update in cron loop is done using Shelly readings and not studer readings
  // This can only happen when it is dark and when SOC after dark capture are both true
  public $soc_updated_using_shelly_energy_readings;

  // the following flag is only true only when SOC is updated using Studer readings else false
  public $soc_updated_using_studer_readings;


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
          $this->version = '3.0';
      }

      $this->plugin_name = 'transindus_eco';

          // load actions only if admin such as adding menus etc.
      if (is_admin()) $this->define_admin_hooks();

          // load public facing actions
      $this->define_public_hooks();

          // read the config file and build the secrets array for all logged in users. Currently config available only for FB16
      $this->get_config();

      // Initialize the defaults array to blank. These will hold defaults for the user supplied settings for this App
      $this->user_meta_defaults_arr = [];

      // Cannot call wp_get_current_user in a constructor

      $this->timezone = new DateTimeZone("Asia/Kolkata");

      $this->init();
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
      $config = include( __DIR__."/" . $this->plugin_name . "_config.php");

      $this->config = $config;

      return $config;
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
        // register shortcode for pages. This is for showing the page with studer readings. Currently not used
        add_shortcode( 'transindus-studer-readings',  [$this, 'studer_readings_page_render']      );

        // Action to process submitted data from a Ninja Form.
        add_action( 'ninja_forms_after_submission',   [$this, 'my_ninja_forms_after_submission']  );

        // This is the page that displays the Individual Studer with All powers, voltages, currents, and SOC% and Shelly Status
        add_shortcode( 'my-studer-readings',          [$this, 'my_studer_readings_page_render']   );

        // Define shortcode to prepare for my-studer-settings page
        add_shortcode( 'my-studer-settings',          [$this, 'my_studer_settings']               );

        // Define shortcode to prepare for view-power-values page. Page code for displaying values todo.
        add_shortcode( 'view-grid-values',            [$this, 'view_grid_values_page_render']     );
    }

    /**
     *  Separated this init function so that it can execute frequently rather than just at class construct
     */
    public function init()
    {
      // set the logging
      $this->verbose = true;

      // lat and lon at Trans Indus from Google Maps
      $this->lat        = 12.83463;
      $this->lon        = 77.49814;

      // UTC offset for local timezone
      $this->utc_offset = 5.5;

      // ................................ CLoudiness management ---------------------------------------------->

      $window_open  = $this->nowIsWithinTimeLimits("05:00", "05:15");

      if ( false !== get_transient( 'timestamp_of_last_weather_forecast_acquisition' ) )
      { // transient exists, get it and check its validity

        $ts           = get_transient( 'timestamp_of_last_weather_forecast_acquisition' );
        $invalid_ts   = $this->check_validity_of_timestamp( $ts, 86400 )->elapsed_time_exceeds_duration_given;
      }
      else
      { // timestamp transient does not exist. 
        $invalid_ts = true;
      }
      
      switch(true)  
      {
        case ( $window_open === true &&  $invalid_ts === true ):
          //  window is open and timestamp is invalid. This happens everyday at least once after 0500
          // so get a new forecast from API
          $cloudiness_forecast = $this->check_if_forecast_is_cloudy();

          // a null can result from a bad API call check for this
          if ( $cloudiness_forecast )
          { // we have a valid new forecast. Write the values to transients. This is the most probable case
            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $ts = $now->getTimestamp();

            set_transient( 'timestamp_of_last_weather_forecast_acquisition',  $ts,                  20 * 60 );
            set_transient( 'cloudiness_forecast',                             $cloudiness_forecast, 27*60*60 );

            error_log("Log-Captured cloudiness forecast");
            error_log( print_r( $cloudiness_forecast, true ) );
          }
          else
          { // bad API call. try again as long as window is open on next cron iteration
            // till then get yesterdays value from transient which must exist
            $cloudiness_forecast = get_transient( 'cloudiness_forecast' );
          }
        break;

        case ( $window_open === true &&  $invalid_ts === false ):
          //  window is open and timestamp is valid. We usually get here on the 2nd iteration after capturing in 1st
          //  we readin the existing forecast as a transient at the end
          $cloudiness_forecast = get_transient( 'cloudiness_forecast' );
        break;


        case ( $window_open === false ):
          //  window is closed. Try reading in the forecast from transient. If unavilable make it up
          //  lets just make up the forecast for today

          // lets see if we have the old yesterday's forecast still lying around
          if ( false !== ( $cloudiness_forecast = get_transient( 'cloudiness_forecast' ) ) )
          { // Yes, we do. renew the old transient since all our API's failed
            // transient is read in and so all is OK
          }
          else
          {
            // so make up a weather forecast
            $cloudiness_forecast = new stdClass;

            // now stuff made up properties
            $cloudiness_forecast->sunrise_hms_format                = "06:00:00";

            $cloudiness_forecast->sunset_hms_format                 = "18:00:00";
            $cloudiness_forecast->sunset_plus_10_minutes_hms_format = "18:10:00";
            $cloudiness_forecast->sunset_plus_15_minutes_hms_format = "18:15:00";

            $cloudiness_forecast->cloudiness_average_percentage           = 10;
            $cloudiness_forecast->cloudiness_average_percentage_weighted  = 10;

            $cloudiness_forecast->it_is_a_cloudy_day                      = false;
            $cloudiness_forecast->it_is_a_cloudy_day_weighted_average     = false;

            // now save the madeup one as a transient
            set_transient( 'cloudiness_forecast', $cloudiness_forecast, 27*60*60 );

            error_log("Log-made up cloudiness forecast due to API failure over time window and lack of yesterdays forecast");
            error_log( print_r( $cloudiness_forecast, true ) );
          }
        break;  
      }

      $this->cloudiness_forecast = $cloudiness_forecast;
    }



    /**
     *  @param int:$ts is the timestamp referenced to whatever TZ, but shall be in the past to now
     *  @param int:$duration_in_seconds is the given duration
     * 
     *  @param int:obj
     * 
     *  The function checks that the time elapsed in seconds from now in Kolkata to the given timestamp in the past
     *  It returns the elapsed time and also whether the elapsed time has exceeded the given duration.
     *  If it exceeds then true is returned if not a false is returned.
     */
    public function check_validity_of_timestamp( int $ts, int $duration_in_seconds) : object
    {
      $obj = new stdClass;

      $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

      $now_ts = $now->getTimestamp();

      // The number of seconds is positive if timestamp given is in the past
      $seconds_elapsed = ( $now_ts - $ts );

      if ( $seconds_elapsed > $duration_in_seconds )
        {
          $obj->elapsed_time_exceeds_duration_given = true;
        }
      else
        { 
          $obj->elapsed_time_exceeds_duration_given = false;
        }

      $obj->seconds_elapsed = $seconds_elapsed;

      return $obj;
    }


    /**
     *  This shoercode checks the user meta for studer settings to see if they are set.
     *  If not set the user meta are set using defaults.
     *  When the Ninja Forms opens it uses the user meta. If a user meta was not set, now it will be, with programmed defaults
     */
    public function my_studer_settings()
    {
      $defaults     = [];   // Initialize the defaults array
      $current_user = wp_get_current_user();
      $wp_user_ID   = $current_user->ID;

      if ( empty($this->user_meta_defaults_arr) || in_array(null, $this->user_meta_defaults_arr, true) )
      {

        $defaults['soc_percentage_lvds_setting']                      = ['default' => 30,   'lower_limit' =>10,   'upper_limit' =>90];  // lower Limit of SOC for LVDS
        $defaults['battery_voltage_avg_lvds_setting']                 = ['default' => 48.3, 'lower_limit' =>47,   'upper_limit' =>54];  // lower limit of BV for LVDS
        $defaults['soc_percentage_rdbc_setting']                      = ['default' => 85,   'lower_limit' =>30,   'upper_limit' =>90];  // upper limit of SOC for RDBC activation
        $defaults['soh_percentage_setting']                           = ['default' => 100,  'lower_limit' =>0,    'upper_limit' =>100]; // Current SOH of battery
        $defaults['soc_percentage_switch_release_setting']            = ['default' => 95,   'lower_limit' =>90,   'upper_limit' =>100]; // Upper limit of SOC for switch release
        $defaults['min_soc_percentage_for_switch_release_after_rdbc'] = ['default' => 32,   'lower_limit' =>20,   'upper_limit' =>90];  // Lower limit of SOC for switch release after RDBC
        $defaults['min_solar_surplus_for_switch_release_after_rdbc']  = ['default' => 0.2,  'lower_limit' =>0,    'upper_limit' =>4];   // Lower limit of Psurplus for switch release after RDBC
        $defaults['battery_voltage_avg_float_setting']                = ['default' => 51.9, 'lower_limit' =>50.5, 'upper_limit' =>54];  // Upper limit of BV for SOC clamp/recal takes place
        $defaults['acin_min_voltage_for_rdbc']                        = ['default' => 199,  'lower_limit' =>190,  'upper_limit' =>210]; // Lower limit of ACIN for RDBC
        $defaults['acin_max_voltage_for_rdbc']                        = ['default' => 241,  'lower_limit' =>230,  'upper_limit' =>250]; // Upper limit of ACIN for RDBC
        $defaults['psolar_surplus_for_rdbc_setting']                  = ['default' => -0.5, 'lower_limit' =>-4,   'upper_limit' =>0];   // Lower limit of Psurplus for surplus for RDBC
        $defaults['psolar_min_for_rdbc_setting']                      = ['default' => 0.3,  'lower_limit' =>0.1,  'upper_limit' =>4];   // lower limit of Psolar for RDBC activation
        $defaults['do_minutely_updates']                              = ['default' => true,  'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['do_shelly']                                        = ['default' => false,  'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['keep_shelly_switch_closed_always']                 = ['default' => false,  'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['pump_duration_control']                            = ['default' => true,   'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['pump_duration_secs_max']                           = ['default' => 2700,   'lower_limit' => 0,    'upper_limit' =>7200];
        $defaults['pump_power_restart_interval_secs']                 = ['default' => 120,    'lower_limit' => 0,    'upper_limit' =>86400];
        
        // save the data in a transient indexed by the user ID. Expiration is 30 minutes
        set_transient( $wp_user_ID . 'user_meta_defaults_arr', $defaults, 30*60 );

        foreach ($defaults as $user_meta_key => $default_row) {
          $user_meta_value  = get_user_meta($wp_user_ID, $user_meta_key,  true);
  
          if ( empty( $user_meta_value ) ) {
            update_user_meta( $wp_user_ID, $user_meta_key, $default_row['default']);
          }
        }
      }
      add_action( 'nf_get_form_id', function( $form_id )
      {

        // Check for a specific Form ID.
        if( 2 !== $form_id ) return;
      
        /**
         * Change a field's settings when localized to the page.
         *   ninja_forms_localize_field_{$field_type}
         *
         * @param array $field [ id, settings => [ type, key, label, etc. ] ]
         * @return array $field
         */
        add_filter( 'ninja_forms_localize_field_checkbox', function( $field )
        {
          $wp_user_ID = get_current_user_id();

          switch ( true )
            {
              case ( stripos( $field[ 'settings' ][ 'key' ], 'keep_shelly_switch_closed_always' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'keep_shelly_switch_closed_always',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'do_minutely_updates' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'do_minutely_updates',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'do_shelly' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'do_shelly',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'do_soc_cal_now' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'do_soc_cal_now',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;

              case ( stripos( $field[ 'settings' ][ 'key' ], 'pump_duration_control' ) !== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'pump_duration_control',  true);

                // Change the `default_value` setting of the checkbox field based on the retrieved user meta
                if ($user_meta_value == true)
                {
                  $field[ 'settings' ][ 'default_value' ] = 'checked';
                }
                else
                {
                  $field[ 'settings' ][ 'default_value' ] = 'unchecked';
                }
              break;
            }
          return $field;
        } );  // Add filter to check for checkbox field and set the default using user meta
      } );    // Add Action to check form ID
      
    }


    /**
     * 
     */
    public function get_user_index_of_logged_in_user() : int
    {  // get my user index knowing my login name

        $current_user = wp_get_current_user();
        $wp_user_name = $current_user->user_login;

        $config       = $this->config;

        // Now to find the index in the config array using the above
        $user_index = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

        $this->index_of_logged_in_user = $user_index;
        $this->wp_user_name_logged_in_user = $wp_user_name;
        $this->wp_user_obj = $current_user;

        return $user_index;
    }

    /**
     * 
     */
    public function get_index_from_wp_user_ID( int $wp_user_ID ) : int
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
    public function get_wp_user_from_user_index( int $user_index): ? object
    {
        $config = $this->get_config();

        $wp_user_name = $config['accounts'][$user_index]['wp_user_name'];

        // Get the wp user object given the above username
        $wp_user_obj  = get_user_by('login', $wp_user_name);

        return $wp_user_obj;
    }

    /**
     *  add submenu page for testing various application API needed
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
     * 
     */
    public function get_all_usermeta( int $wp_user_ID ) : array
    {
      $all_usermeta = [];

      // set default timezone to Asia Kolkata
      //

      $all_usermeta = array_map( function( $a ){ return $a[0]; }, get_user_meta( $wp_user_ID ) );

      // Set this as class property valid for the user index under consideration.
      $this->all_usermeta = $all_usermeta;

      return $all_usermeta;
    }


    /**
     *  @param int:$user_index is the user of ineterst in the config array
     *  @return array:$return_array containing values from API call on Shelly ACIN Transfer switch
     *  Checks the validity of Shelly switch configuration required for program
     *  Makes an API call on the Shelly ACIN switch and return the ststus such as State, Voltage, etc.
     */
    public function get_shelly_switch_acin_details( int $user_index) : array
    {
      $return_array = [];

      $config     = $this->config;

      // get WP user object and so get its ID
      $wp_user_ID = $this->get_wp_user_from_user_index( $user_index )->ID;

      // ensure that the data below is current before coming here
      $all_usermeta = $this->all_usermeta ?? $this->get_all_usermeta( $wp_user_ID );

      $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['shelly_device_id_acin']   )  &&
                              ! empty( $config['accounts'][$user_index]['shelly_device_id_homepwr'] ) &&
                              ! empty( $config['accounts'][$user_index]['shelly_server_uri']  )       &&
                              ! empty( $config['accounts'][$user_index]['shelly_auth_key']    );
    
      if ( $valid_shelly_config && $all_usermeta['do_shelly'] ) 
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

          $shelly_api_device_response = $this->get_shelly_device_status_acin( $user_index );

          if ( is_null($shelly_api_device_response) ) 
          { // switch status is unknown

              error_log("Shelly Grid Switch API call failed - Grid power failure Assumed");

              $shelly_api_device_status_ON = null;

              $shelly_switch_status             = "OFFLINE";
              $shelly_api_device_status_voltage = "NA";
          }
          else 
          {  // Switch is ONLINE - Get its status and Voltage
              
              $shelly_api_device_status_ON      = $shelly_api_device_response->data->device_status->{'switch:0'}->output;
              $shelly_api_device_status_voltage = $shelly_api_device_response->data->device_status->{'switch:0'}->voltage;

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

          $shelly_switch_status             = "Not Configured";
          $shelly_api_device_status_voltage = "NA";    
      }  

      $return_array['valid_shelly_config']              = $valid_shelly_config;
      $return_array['control_shelly']                   = $control_shelly;
      $return_array['shelly_switch_status']             = $shelly_switch_status;
      $return_array['shelly_api_device_status_voltage'] = $shelly_api_device_status_voltage;
      $return_array['shelly_api_device_status_ON']      = $shelly_api_device_status_ON;

      $this->shelly_switch_acin_details = $return_array;

      return $return_array;
    }

    /**
     *  Measure current energy counter of Shelly EM measuring load consumption in WH
     *  Obtain the energy counter reading stored just after midnight
     *  The difference between them will give the consumed energy in WH since midnight
     *  The counter is a perpetual counter and is never erased or reset
     */
    public function get_shellyem_accumulated_load_wh_since_midnight( int $user_index, string $wp_user_name, int $wp_user_ID ): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;

      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_em_acin'];

      // get value accumulated till midnight upto previous API call
      $previous_grid_wh_since_midnight = (int) round( (float) get_user_meta( $wp_user_ID, 'grid_wh_since_midnight', true), 0);

      $returned_obj = new stdClass;

      $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status();

      // check to make sure that it exists. If null API call was fruitless
      if (  empty( $shelly_api_device_response ) || 
            empty( $shelly_api_device_response->data->device_status->emeters[0]->total ) ||
            $shelly_api_device_response->data->device_status->emeters[0]->is_valid !== true || 
            (int) round($shelly_api_device_response->data->device_status->emeters[0]->total, 0) <= 0
          )
      {

        // since no grid get value from user meta. Also readings will not change since grid is absent :-)
        error_log( "LogApi: Shelly EM Load Energy API call failed - See below for response" );
        $this->verbose ? error_log( print_r($shelly_api_device_response , true) ): false;

        return null;
      }

      // If you get here, the Shelly API call was successfull and we have useful data
      $shelly_em_home_wh = (int) round($shelly_api_device_response->data->device_status->emeters[0]->total, 0);

      // get the energy counter value set at midnight. Assumes that this is an integer
      $shelly_em_home_energy_counter_at_midnight = (int) round(get_user_meta( $wp_user_ID, 'shelly_em_home_energy_counter_at_midnight', true), 0);

      $returned_obj->shelly_em_home_voltage = (int)   round($shelly_api_device_response->data->device_status->emeters[0]->voltage,        0);
      $returned_obj->shelly_em_home_kw      = (float) round($shelly_api_device_response->data->device_status->emeters[0]->power * 0.001,  3);

      // subtract the 2 integer counter readings to get the accumulated WH since midnight
      $shelly_em_home_wh_since_midnight = $shelly_em_home_wh - $shelly_em_home_energy_counter_at_midnight;

      $returned_obj->shelly_em_home_wh  = $shelly_em_home_wh;   // update object property for present wh energy counter reading
        
      $returned_obj->shelly_em_home_wh_since_midnight           = $shelly_em_home_wh_since_midnight;          // object property for energy since midnight
      $returned_obj->shelly_em_home_energy_counter_at_midnight  = $shelly_em_home_energy_counter_at_midnight; // object property for energy counter at midnight

      if ( $shelly_em_home_wh_since_midnight >=  0 )
      { // the value is positive so counter did not reset due to software update etc.
        update_user_meta( $wp_user_ID, 'shelly_em_home_wh_since_midnight', $shelly_em_home_wh_since_midnight);
      }
      else 
      {
        // value must be negative so cannot be possible set it to 0
        $returned_obj->shelly_em_home_wh_since_midnight   = 0;
      }

      return $returned_obj;
    }



    /**
     * 
     */
    public function get_load_average( string $wp_user_name, float $new_load_kw_reading ): ? float
    {
      // Load the voltage array that might have been pushed into transient space
      $load_kw_arr_transient = get_transient( $wp_user_name . '_' . 'load_kw_avg_arr' ); 

      // If transient doesnt exist rebuild
      if ( ! is_array($load_kw_arr_transient))
      {
        $load_kw_avg_arr = [];
      }
      else
      {
        // it exists so populate
        $load_kw_avg_arr = $load_kw_arr_transient;
      }
      
      // push the new voltage reading to the holding array
      array_push( $load_kw_avg_arr, $new_load_kw_reading );

      // If the array has more than 30 elements then drop the earliest one
      // We are averaging for only 30 minutes
      if ( sizeof($load_kw_avg_arr) > 10 )  
      {   // drop the earliest reading
          array_shift($load_kw_avg_arr);
      }
      // Write it to this object for access elsewhere easily
      $this->load_kw_avg_arr = $load_kw_avg_arr;

      // Setup transiet to keep previous state for averaging
      set_transient( $wp_user_name . '_' . 'load_kw_avg_arr', $load_kw_avg_arr, 5*60 );

      $count  = 0.00001;    // prevent division by 0 error
      $sum    = 0;
      foreach ($load_kw_avg_arr as $key => $value)
      {
         if ( $value > 0.010 )  // greater than 10W
         {
            // average all values that are meaningful
            $sum    +=  $value;   // accumulate
            $count  +=  1;        // increase count by 1
         }
      }
      unset($value);

      $load_kw_avg = round( $sum / $count, 2);

      return $load_kw_avg;
    }


    /**
     *  @param string:$future_time is in the typical format of hh:mm:ss
     *  @return int:$minutes_now_to_future is the number of minutes from now to future time passed in
     */
    public function minutes_now_to_future( $future_time ) : float
    {
      //

      $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

      if ( $this->nowIsWithinTimeLimits( '00:00', $future_time ) )
      {
        $future_datetime_object = new DateTime($future_time, new DateTimeZone('Asia/Kolkata'));

        // we are past midnight so we just calulcate mins from now to future time
        // form interval object between now and  time stamp under investigation
        $diff = $now->diff( $future_datetime_object );

        $minutes_now_to_future = $diff->s / 60  + $diff->i  + $diff->h *60;

        return $minutes_now_to_future;
      }
      else
      {
        // we are not past midnight of today so future time is past 23:59:59 into tomorrow
        $future_datetime_object = new DateTime( "tomorrow " . $future_time, new DateTimeZone('Asia/Kolkata'));

        $diff = $now->diff( $future_datetime_object );

        $minutes_now_to_future = $diff->s / 60  + $diff->i  + $diff->h * 60 + $diff->d * 24 * 60;

        return $minutes_now_to_future;
      }

    }


    /**
     *  @param int:$timestamp is the timestamp of event past
     *  @return int:$elapsed_time_mins is the elapsed time from the reference timestamp to NOW
     */
    public function minutes_from_reference_to_now( int $timestamp ) : float
    {
      //

      $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

      $reference_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

      // This should be in the past
      $reference_datetime_obj->setTimeStamp( $timestamp );

      // form interval object between now and  time stamp under investigation
      $diff = $now->diff( $reference_datetime_obj );

      // Get the elapsed time in minutes
      $elapsed_time_mins = $diff->s / 60  + $diff->i  + $diff->h *60;

      return $elapsed_time_mins;
    }


    /**
     *  @param int:$user_index index of user in config array
     *  @param int:$wp_user_ID is the WP user ID
     *  @param string:$shelly_switch_status is the string indicating the ON OFF or NULL state of the ACIN shelly switch
     *  @param bool:$it_is_still_dark indicates if it is daylight or dark at present
     *  @return object:$battery_measurements_object contains the measurements of the battery using the Shelly UNI device
     *  
     *  The current is measured using a hall effect sensor. The sensor output voltage is read by the ADC in the shelly Plus Addon
     *  The transducer function is used to translate the ADC voltage to Battery current estimated.
     *  No integrated AH is done, This is done elsewhere. Just the battery current in Amps along with unixts is returned
     */
    public function get_shelly_battery_measurement( int $user_index ) : ? object
    {
         // initialize the object to be returned
         $shelly_bm_measurement_obj = new stdClass;

         // Make an API call on the Shelly UNI device
         $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_plus_addon'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_api_device_response = $shelly_api->get_shelly_device_status();

        // check to make sure that it exists. If null call was fruitless
        if ( empty( $shelly_api_device_response ) )
        {
          error_log("LogApi: Danger-Shelly Battery Measurement API call over LAN failed");

          return null;
        }

        // The measure ADC voltage is in percent of 10V. So a 25% reading indicates 2.5V measured. Also get timestamp
        $adc_voltage_shelly = $shelly_api_device_response->data->device_status->{"input:100"}->percent;
        $timestamp_shellybm = $shelly_api_device_response->data->device_status->sys->unixtime;

        // calculate the current using the 65mV/A formula around 2.5V. Positive current is battery discharge
        $delta_voltage = $adc_voltage_shelly * 0.1 - 2.54;

        // 100 Amps gives a voltage of 0.625V amplified by opamp by 4.7. So voltas/amp measured by Shelly Addon is
        $volts_per_amp = 0.625 * 4.7 / 100;

        // Convert Volts to Amps using the value above. SUbtract an offest of 8A noticed, probably due to DC offset
        $battery_amps_raw_measurement = ($delta_voltage / $volts_per_amp);

        // +ve value indicates battery is charging. Due to our inverting opamp we have to reverse sign and educe the current by 5%
        // This is because the battery SOC numbers tend about 4 points more from about a value of 40% which indicates about 10% over measurement
        // so to be conservative we are using a 10% reduction to see if this corrects the tendency.
        $batt_amps_shellybm = -1.0 * round( $battery_amps_raw_measurement, 1) * 0.90;

        $shelly_bm_measurement_obj->batt_amps_shellybm  = $batt_amps_shellybm;
        $shelly_bm_measurement_obj->timestamp_shellybm  = $timestamp_shellybm;
        
        return $shelly_bm_measurement_obj;
    }

    /**
     *  @param int:$user_index of user in config array
     *  @return object:$shelly_device_data contains energy counter and its timestamp along with switch status object
     *  Gets the power readings supplied to Home using Shelly Pro 4PM
     */
    public function get_shelly_device_status_homepwr(int $user_index): ?object
    {
        // get API and device ID from config based on user index
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_homepwr'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_api_device_response = $shelly_api->get_shelly_device_status();

        // check to make sure that it exists. If null API call was fruitless
        if ( empty( $shelly_api_device_response ) || empty( $shelly_api_device_response->data->device_status->{"switch:3"}->aenergy->total ) )
        {
          $this->verbose ? error_log("Shelly Homepwr switch API call failed"): false;

          return null;
        }

        // Since this is the switch that also measures the power and energy to home, let;s extract those details
        $power_channel_0 = $shelly_api_device_response->data->device_status->{"switch:0"}->apower;
        $power_channel_1 = $shelly_api_device_response->data->device_status->{"switch:1"}->apower;
        $power_channel_2 = $shelly_api_device_response->data->device_status->{"switch:2"}->apower;
        $power_channel_3 = $shelly_api_device_response->data->device_status->{"switch:3"}->apower;

        $power_to_home_kw = round( ( $power_channel_2 + $power_channel_3 ) * 0.001, 3 );
        $power_to_ac_kw   = round( ( $power_channel_1 * 0.001 ), 3 );
        $power_to_pump_kw = round( ( $power_channel_0 * 0.001 ), 3 );

        $power_total_to_home = $power_channel_0 + $power_channel_1 + $power_channel_2 + $power_channel_3;
        $power_total_to_home_kw = round( ( $power_total_to_home * 0.001 ), 3 );

        $energy_channel_0_ts = $shelly_api_device_response->data->device_status->{"switch:0"}->aenergy->total;
        $energy_channel_1_ts = $shelly_api_device_response->data->device_status->{"switch:1"}->aenergy->total;
        $energy_channel_2_ts = $shelly_api_device_response->data->device_status->{"switch:2"}->aenergy->total;
        $energy_channel_3_ts = $shelly_api_device_response->data->device_status->{"switch:3"}->aenergy->total;

        $energy_total_to_home_ts = (float) ($energy_channel_0_ts + 
                                            $energy_channel_1_ts + 
                                            $energy_channel_2_ts + 
                                            $energy_channel_3_ts);

        $current_total_home =  $shelly_api_device_response->data->device_status->{"switch:0"}->current;
        $current_total_home += $shelly_api_device_response->data->device_status->{"switch:1"}->current;
        $current_total_home += $shelly_api_device_response->data->device_status->{"switch:2"}->current;
        $current_total_home += $shelly_api_device_response->data->device_status->{"switch:3"}->current;


        // Unix minute time stamp for the power and energy readings
        $minute_ts = $shelly_api_device_response->data->device_status->{"switch:0"}->aenergy->minute_ts;

        $energy_obj = new stdClass;

        // add these to returned object for later use in calling program
        $energy_obj->power_total_to_home_kw   = $power_total_to_home_kw;
        $energy_obj->power_total_to_home      = $power_total_to_home;
        $energy_obj->power_to_home_kw         = $power_to_home_kw;
        $energy_obj->power_to_ac_kw           = $power_to_ac_kw;
        $energy_obj->power_to_pump_kw         = $power_to_pump_kw;

        $energy_obj->energy_total_to_home_ts  = $energy_total_to_home_ts;
        $energy_obj->minute_ts                = $minute_ts;
        $energy_obj->current_total_home       = $current_total_home;
        $energy_obj->voltage_home             = $shelly_api_device_response->data->device_status->{"switch:3"}->voltage;

        // set the state of the channel if OFF or ON. ON switch will be true and OFF will be false
        $energy_obj->pump_switch_status_bool  = $shelly_api_device_response->data->device_status->{"switch:0"}->output;
        $energy_obj->ac_switch_status_bool    = $shelly_api_device_response->data->device_status->{"switch:1"}->output;
        $energy_obj->home_switch_status_bool  = $shelly_api_device_response->data->device_status->{"switch:2"}->output || 
                                                $shelly_api_device_response->data->device_status->{"switch:3"}->output;

        return $energy_obj;
    }

    /**
     * 'grid_wh_counter_midnight' user meta is set at midnight elsewhere using the current reading then
     *  At any time after, this midnight reading is subtracted from current reading to get consumption since midnight
     *  @return object:$shelly_3p_grid_wh_measurement_obj contsining all the measurements
     */
    public function get_shelly_3p_grid_wh_since_midnight( int     $user_index, 
                                                          string  $wp_user_name, 
                                                          int     $wp_user_ID,  ): ? object
    {
      // Blue phase of RYB is assigned to home so this corresponds to c phase of abc
      $home_em      = "em1:2";
      $home_emdata  = "em1data:2";

      // Red phase of RYB is assigned to car charger sinside garage o this corresponds to a phase of abc sequence
      $car_em      = "em1:0";
      $car_emdata  = "em1data:0";

      $car_outside_em      = "em1:1";
      $car_outside_emdata  = "em1data:1";



      // get value of Shelly Pro 3EM Red phase watt hour counter as set at midnight
      $grid_wh_counter_midnight = (int) round( (float) get_user_meta( $wp_user_ID, 'grid_wh_counter_midnight', true), 0);

      // get API and device ID from config based on user index
      $config = $this->config;

      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin_3p'];

      $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status();

      $shelly_3p_grid_wh_measurement_obj = new stdClass;

      // check to make sure that it exists. If null API call was fruitless
      if ( empty( $shelly_api_device_response ) )
      {
        $this->verbose ? error_log("Shelly 3EM Grid Energy API call failed"): false;

        // since no valid reading so lets use the reading from transient
        $home_grid_wh_counter_now_from_transient        = (float) get_transient('home_grid_wh_counter');
        $car_charger_grid_wh_counter_now_from_transient = (float) get_transient('car_charger_grid_wh_counter');

        $shelly_3p_grid_wh_measurement_obj->home_grid_wh_counter_now        = $home_grid_wh_counter_now_from_transient;
        $shelly_3p_grid_wh_measurement_obj->car_charger_grid_wh_counter_now = $car_charger_grid_wh_counter_now_from_transient;

        $home_grid_wh_accumulated_since_midnight = $home_grid_wh_counter_now_from_transient - $grid_wh_counter_midnight;

        $shelly_3p_grid_wh_measurement_obj->home_grid_wh_accumulated_since_midnight = $home_grid_wh_accumulated_since_midnight;

        $shelly_3p_grid_wh_measurement_obj->home_grid_kw_power         = 0;

        $shelly_3p_grid_wh_measurement_obj->car_charger_grid_kw_power  = 0;

        return $shelly_3p_grid_wh_measurement_obj;
      }
      else
      {
        // get energy counter and power values of phase supplying home using passed in phase variable
        $home_grid_wh_counter_now  = $shelly_api_device_response->data->device_status->$home_emdata->total_act_energy;
        $home_grid_w_pwr           = $shelly_api_device_response->data->device_status->$home_em->act_power;
        $home_grid_voltage         = $shelly_api_device_response->data->device_status->$home_em->voltage;

        // get energy counter value and power values of phase supplying car charger, assumed b or Y phase
        $car_charger_grid_wh_counter_now  = $shelly_api_device_response->data->device_status->$car_emdata->total_act_energy;
        $car_charger_grid_w_pwr           = $shelly_api_device_response->data->device_status->$car_em->act_power;
        $car_charger_grid_voltage         = $shelly_api_device_response->data->device_status->$car_em->voltage;

        $car_charger_outside_grid_wh_counter_now  = $shelly_api_device_response->data->device_status->$car_outside_emdata->total_act_energy;
        $car_charger_outside_grid_w_pwr           = $shelly_api_device_response->data->device_status->$car_outside_em->act_power;
        $car_charger_outside_grid_voltage         = $shelly_api_device_response->data->device_status->$car_outside_em->voltage;


        
        $home_grid_kw_power             = round( 0.001 * $home_grid_w_pwr,        3);
        $car_charger_grid_kw_power      = round( 0.001 * $car_charger_grid_w_pwr, 3);
        $car_charger_outside_grid_kw_pwr  = round( 0.001 * $car_charger_outside_grid_w_pwr, 3);

        // update the transient with most recent measurement
        set_transient( 'home_grid_wh_counter',        $home_grid_wh_counter_now,        24 * 60 * 60 );
        set_transient( 'car_charger_grid_wh_counter', $car_charger_grid_wh_counter_now, 24 * 60 * 60 );

        $home_grid_wh_accumulated_since_midnight = $home_grid_wh_counter_now - $grid_wh_counter_midnight;

        $shelly_3p_grid_wh_measurement_obj->home_grid_wh_counter_now              = $home_grid_wh_counter_now;
        $shelly_3p_grid_wh_measurement_obj->car_charger_grid_wh_counter_now       = $car_charger_grid_wh_counter_now;
        $shelly_3p_grid_wh_measurement_obj->car_charger_outside_grid_wh_counter_now = $car_charger_outside_grid_wh_counter_now;

        $shelly_3p_grid_wh_measurement_obj->home_grid_kw_power            = $home_grid_kw_power;
        $shelly_3p_grid_wh_measurement_obj->car_charger_grid_kw_power     = $car_charger_grid_kw_power;
        $shelly_3p_grid_wh_measurement_obj->car_charger_outside_grid_kw_pwr = $car_charger_outside_grid_kw_pwr;

        $shelly_3p_grid_wh_measurement_obj->home_grid_voltage               = $home_grid_voltage;
        $shelly_3p_grid_wh_measurement_obj->car_charger_grid_voltage        = $car_charger_grid_voltage;
        $shelly_3p_grid_wh_measurement_obj->car_charger_outside_grid_voltage = $car_charger_outside_grid_voltage;

        $shelly_3p_grid_wh_measurement_obj->a_phase_grid_voltage  = $home_grid_voltage;
        $shelly_3p_grid_wh_measurement_obj->b_phase_grid_voltage  = $car_charger_grid_voltage;
        $shelly_3p_grid_wh_measurement_obj->c_phase_grid_voltage  = $car_charger_outside_grid_voltage;

        set_transient( 'a_phase_grid_voltage', $car_charger_grid_voltage,             20 );
        set_transient( 'b_phase_grid_voltage', $car_charger_outside_grid_voltage,     20 );
        set_transient( 'c_phase_grid_voltage', $home_grid_voltage,                    20 );

        $shelly_3p_grid_wh_measurement_obj->home_grid_wh_accumulated_since_midnight = $home_grid_wh_accumulated_since_midnight;

        return $shelly_3p_grid_wh_measurement_obj;
      }
      
    }


    /**
     * This is the home power and energy consumed from output of Studer by the Shelly EM device.
     *  'shelly_em_home_energy_counter_midnight' user meta has the midnight value of counter set elsewhere
     *  The consumption since midnight is got by subtracting the midnight value from the present reading
     *  @return object:$shelly_em_readings_object has all the measurements of nterest
     */
    public function get_shelly_em_home_load_measurements( int $user_index, string $wp_user_name, int $wp_user_ID ): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;

      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_em_load'];

      $shelly_em_readings_object = new stdClass;

      $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status();

      // check to make sure that it exists. If null API call was fruitless
      if (  empty( $shelly_api_device_response ) || 
            empty( $shelly_api_device_response->data->device_status->emeters[0]->total ) ||
            $shelly_api_device_response->data->device_status->emeters[0]->is_valid !== true || 
            (int) round($shelly_api_device_response->data->device_status->emeters[0]->total, 0) <= 0
          )
      {
        $this->verbose ? error_log("Shelly EM Grid Energy API call failed"): false;

        return null;
      }

      // Shelly API call was successfull and we have a valid reading
      $present_home_wh_reading = (int) round($shelly_api_device_response->data->device_status->emeters[0]->total, 0);

      // get the energy counter value set at midnight. Assumes that this is an integer
      $shelly_em_home_energy_counter_midnight = (int) get_user_meta( $wp_user_ID, 
                                                            'shelly_em_home_energy_counter_midnight', true);

      $shelly_em_readings_object->home_voltage_em = round($shelly_api_device_response->data->device_status->emeters[0]->voltage, 0);

      // subtract the 2 integer counter readings to get the home consumed energy in WH since midnight
      $home_consumption_wh_since_midnight = $present_home_wh_reading - $shelly_em_home_energy_counter_midnight;

      $present_home_kw_shelly_em = round( 0.001 * $shelly_api_device_response->data->device_status->emeters[0]->power, 3 );

      $shelly_em_readings_object->present_home_kw_shelly_em = $present_home_kw_shelly_em;
      $shelly_em_readings_object->present_home_wh_reading   = $present_home_wh_reading;

      $shelly_em_readings_object->home_consumption_wh_since_midnight = $home_consumption_wh_since_midnight;

      return $shelly_em_readings_object;
    }





    /**
     *  @todo implement lagging and leading studer offset, at present only lagging is pmplemented
     *  @param int:$user_index
     *  @param string:$wp_user_name is the user name for current loop's user
     *  We check to see if Studer clock is just past midnight. This will be true only once in 24h.
     *  Typically it happens close to Servers's midnight due to any offset in Studers clock.
     *  So we check in a window of 30mr on either side of server midnight.
     *  Transient for Studer CLock offset expires every hour and gets recalculated by API call if needed.
     *  So if Studer clock was adjusted during day it will be correctly acquired by API call
     */
    public function is_studer_time_just_pass_midnight( int $user_index, string $wp_user_name ): bool
    {
      // if not within an hour of server clocks midnight return false. Studer offset will never be allowed to be more than 1h
      if ($this->nowIsWithinTimeLimits("00:30:00", "23:30:00") )
      {
        return false;
      }
      // if the transient is expired it means we need to check
      if ( false === get_transient( $wp_user_name . '_' . 'is_studer_time_just_pass_midnight' ) )
      {
        // this could also be leading in which case the sign will be automatically negative
        $studer_time_offset_in_mins_lagging = $this->get_studer_clock_offset( $user_index );

        // get current time compensated for our timezone
        $test = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $h=$test->format('H');
        $m=$test->format('i');
        $s=$test->format('s');

        // if hours are 0 and offset adjusted minutes are 0 then we are just pass midnight per Studer clock
        // we added an additional offset just to be sure to account for any seconds offset
        if( $h == 0 && ($m - $studer_time_offset_in_mins_lagging ) > 0 ) 
        {
          // We are just past midnight on Studer clock, so return true after setiimg the transient
          set_transient( $wp_user_name . '_' . 'is_studer_time_just_pass_midnight',  'yes', 2*60*60 );
          return true;
        }
      }

      //  If we het here it means that the transient exists and so we are way past midnight, check was triggered already
      return false;
    }

    /**
     *  @param int:$user_index
     *  @param string:$wp_user_name
     *  @param int:$wp_user_ID
     *  @return bool:true if timestamp is witin last 12h of present server time
     *  Check if SOC capture after dark took place based on timestamp
     *  SOC capture can happen anytime in a window of 15m after sunset. It is checked for till Sunrise.
     *  SO it needs to be valid from potentially 6PM to 7Am or almost 13h.
     *  
     *  For this reason it is important to delete the transient after sunrise to force a SOC reference again the following sunset
     *  This is done in the main cron driven service loop itself.
     *  No other check is made in the function
     */
    public function check_if_soc_after_dark_happened( int $user_index, string $wp_user_name, int $wp_user_ID ) : bool
    {
      //

      // Get the transient if it exists
      if (false === ($timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' ) ) )
      {
        // if transient DOES NOT exist then read in value from user meta
        $timestamp_soc_capture_after_dark = get_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', true);
      }
      else
      {
        // transient exists so get it. This is redundant, the top statement already takes care of this
        $timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' );
      }

      if ( empty( $timestamp_soc_capture_after_dark ) )
      {
        // timestamp does not exist. Log and return false
        $this->verbose ? error_log( "Time stamp for SOC capture after dark is empty or not valid") : false;

        return false;
      }

      // we have a non-emtpy timestamp. We have to check its validity.
      // It is valid if the timestamp is after sunset and is within 12h of it
      $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

      // create a datetime object correesponding to the timestamp of SOC dark capture
      $datetimeobj_from_timestamp = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
      $datetimeobj_from_timestamp->setTimestamp($timestamp_soc_capture_after_dark);

      // form the intervel object from now to the timestamp last on record for SOC capture after dark
      $diff = $now->diff( $datetimeobj_from_timestamp );

      // convert the time into hours using days, hours and minutes.
      $time_in_hours_from_soc_dark_capture_to_now = $diff->days * 24 + $diff->h + $diff->i / 60;

      if ( $time_in_hours_from_soc_dark_capture_to_now <= 13 )
      {
        // we have a valid capture of SOC after dark, so return TRUE
        return true;
      }
      // SOC capture took place more than 13h ago so SOC Capture DID NOT take place yet
      return false;
    }


    /**
     *  @param float:SOC_percentage_now is the passed in value of SOC percentage to be captured
     *  @param int:present_home_wh_reading is the present counter reading of wh of home energy by Shelly EM
     *  @param object:reading_timestamp is the timestamp of home energy reading counter by Shelly EM
     *  @return bool:true if successful, false if not.
     * 
     *  If now is after sunset and 15m after, and if timestamp is not yet set then capture soc
     *  The transients are set to last 13h so if capture happens at 6PM transients expire at 7AM
     *  However the captured values are saved to user meta for retrieval.
     *  'shelly_energy_counter_after_dark' user meta stores the captured SOC value at dark.
     *  This keeps getting updated later on every cycle with then measurements
     *  'shelly_energy_counter_after_dark'  user meta holds the captured Shelly EM energy counter at dark.
     *  This also keeps gettinhg updated to the counter's instantaneous value thereafter.
     *  
     */
    public function capture_evening_soc_after_dark( int     $user_index, 
                                                    string  $wp_user_name, 
                                                    int     $wp_user_ID , 
                                                    float   $SOC_percentage_now ,
                                                    int     $present_home_wh_reading,
                                                    bool    $time_window_for_soc_dark_capture_open )  : bool
    {
      // set default timezone to Asia Kolkata
      //


      // check if it is after dark and before midnightdawn annd that the transient has not been set yet
      // The time window for this to happen is over 15m after sunset for Studer and 5m therafter for Shelly if Studer fails
      if (  $time_window_for_soc_dark_capture_open === true  ) 
      {
        // lets get the transient. The 1st time this is tried in the evening it should be false, 2nd time onwards true
        if ( false === ( $timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' ) ) 
                    ||
                       empty(get_user_meta($wp_user_ID, 'timestamp_soc_capture_after_dark', true))
            )
        {
          // transient has expired or doesn't exist, OR meta data also is empty
          // Capture the after dark values
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $timestamp_soc_capture_after_dark = $now->getTimestamp();

          update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $present_home_wh_reading);
          update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $timestamp_soc_capture_after_dark);
          update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $SOC_percentage_now);

          // set transient to last for 13h only
          set_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark,  13 * 3600 );
          set_transient( $wp_user_name . '_' . 'shelly_energy_counter_after_dark',  $present_home_wh_reading,           13 * 3600 );
          set_transient( $wp_user_name . '_' . 'soc_update_from_studer_after_dark', $SOC_percentage_now,                13 * 3600 );

          error_log("SOC Capture after dark took place - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $present_home_wh_reading);

          return true;
        }
        else
        {
          // event transient exists, but lets double check the validity of the timestamp
          $check_if_soc_after_dark_happened = $this->check_if_soc_after_dark_happened( $user_index, $wp_user_name, $wp_user_ID );

          if ( $check_if_soc_after_dark_happened === true )
          {
            // Yes it all looks good, the timestamp is less than 13h old compared with soc after dark capture timestamp
            return true;
          }
          else
          {
            // looks like the transient was bad so lets do the capture after dark for SOC and Shelly EM energy counter
            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $timestamp_soc_capture_after_dark = $now->getTimestamp();

            set_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark,  13 * 3600 );
            set_transient( $wp_user_name . '_' . 'shelly_energy_counter_after_dark',  $present_home_wh_reading,           13 * 3600);
            set_transient( $wp_user_name . '_' . 'soc_update_from_studer_after_dark', $SOC_percentage_now,                13 * 3600 );


            update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark',  $present_home_wh_reading);
            update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark);
            update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $SOC_percentage_now);

            error_log("SOC Capture after dark took place - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $present_home_wh_reading);

            return true;
          }
        }
      }
      //  Window is closed so event cannot happen return false
      return false;
    }

    /**
     *  Each time the function is called increment the cron 5s counter modulo 12. 
     */
    public function count_5s_cron_cycles_modulo_12():bool
    {
        $this->twelve_cron_5s_cycles_completed = false;

        // We need to keep track of the count each time we land here. The CRON interval is nominally 5s
        // We need a counter to count to 1 minute
        $count_cron_5sec = get_transient( 'count_cron_5sec' );
        
        if ( false === $count_cron_5sec )
        {
            // this is 1st time or transient somehow got deleted or expired
            $count_cron_5sec = 1;

            // set transient with the above value for 60s
            set_transient( 'count_cron_5sec', $count_cron_5sec, 60 );
        }
        else
        {
            // increment the counter by 1
            $count_cron_5sec += get_transient( 'count_cron_5sec' );

            if ( $count_cron_5sec >= 12 ) 
            {
                // the counter overflows past 1 minute so roll sback to 1 or 5sec
                $count_cron_5sec = 1;

                $this->twelve_cron_5s_cycles_completed = true;
            }

            // set a new transient value either incremented or rolled over value
            set_transient( 'count_cron_5sec', $count_cron_5sec, 60 );
        }

        return $this->twelve_cron_5s_cycles_completed;
    }


    /**
     *  This function is called by the scheduler  every minute or so.
     *  Its job is to get the needed set of studer readings and the state of the ACIN shelly switch
     *  For every user in the config array who has the do_shelly variable set to TRUE.
     *  The ACIN switch is turned ON or OFF based on a complex algorithm. and user meta settings
     *  A data object is created and stored as a transient to be accessed by an AJAX request running asynchronously to the CRON
     */
    public function shellystuder_cron_exec()
    {   
        $config = $this->get_config();

        $user_index = 0;

        $account = $config['accounts'][$user_index];
        
        $wp_user_name = $account['wp_user_name'];

        // Get the wp user object given the above username
        $wp_user_obj  = get_user_by('login', $wp_user_name);


        $wp_user_ID   = $wp_user_obj->ID;

        if ( $wp_user_ID )
        {   // we have a valid user
          
          // Trigger an all usermeta get such that all routines called from this loop will have a valid updated usermeta
          // The call also updates the all usermeta as a property of this object for access from anywahere in the class
          $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

          // extract the control flag for the servo loop to pass to the servo routine
          $do_shelly  = $all_usermeta['do_shelly'];

          // extract the control flag to perform minutely updates
          $do_minutely_updates  = $all_usermeta['do_minutely_updates'];

          // Check if the control flag for minutely updates is TRUE. If so get the readings
          if( $do_minutely_updates ) 
          {

            // get all the readings for this user. Enable Studer measurements. User Index is 0 since only one user
            $this->get_readings_and_servo_grid_switch( $user_index, $wp_user_ID, $wp_user_name, $do_shelly, true );

            
            for ( $i = 0; $i < 10; $i++ )
            {
              sleep(5);
              // enable Studer measurements. These will complete and end the script. User index is 0 since only 1 user
            $this->get_readings_and_servo_grid_switch( $user_index, $wp_user_ID, $wp_user_name, $do_shelly, false );
            }
          }
        }
        else
        {
          error_log("WP user ID: $wp_user_ID is not valid");
        }
      return true;
    }


    /**
     * 
     */
    public function turn_pump_on_off( int $user_index, string $desired_state, $shelly_switch_name = 'shelly_device_id_homepwr' ) : ? object
    {
      // Shelly API has a max request rate of 1 per second. So we wait 1s just in case we made a Shelly API call before coming here
        sleep (2);

        // get the config array from the object properties
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];

        // this is the device ID using index that is passed in defaults to 'shelly_device_id_acin'
        // other shelly 1PM names are: 'shelly_device_id_water_heater'
        $shelly_device_id   = $config['accounts'][$user_index][$shelly_switch_name];

        // set the channel of the switch that the pump is on
        $channel_pump       = 0;

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id, $channel_pump);

        // this is $curl_response. Pump is on channel 0 which is default argument assumed in the called function
        $shelly_device_data = $shelly_api->turn_on_off_shelly_switch( $desired_state );

        // True if API call was successful, False if not.
        return $shelly_device_data;
    }
    

    /**
     * 
     */
    public function control_pump_on_duration( int $wp_user_ID, int $user_index, object $shelly_4pm_readings_object )
    {
      if (empty($shelly_4pm_readings_object))
      {
        // pad data passed in do nothing
        return null;
      }

      // get the user meta, all of it as an array for fast retrieval rtahr than 1 by 1 as done before
      $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

      // get webpshr subscriber id for this user
      $webpushr_subscriber_id = $all_usermeta['webpushr_subscriber_id'];

      // Webpushr NPush otifications API Key
      $webpushrKey            = $this->config['accounts'][$user_index]['webpushrKey'];

      // Webpushr Token
      $webpushrAuthToken      = $this->config['accounts'][$user_index]['webpushrAuthToken'];

      // pump_duration_secs_max
      $pump_duration_secs_max           = $all_usermeta['pump_duration_secs_max'];

      // pump_duration_control
      $pump_duration_control            = $all_usermeta['pump_duration_control'];

      // pump_power_restart_interval_secs
      $pump_power_restart_interval_secs = $all_usermeta['pump_power_restart_interval_secs'];

      // set property in case pump was off so that this doesnt give a php notice otherwise
      $shelly_4pm_readings_object->pump_ON_duration_secs = 0;

      // set default timezone
      //

      $power_to_pump_is_enabled = $shelly_4pm_readings_object->pump_switch_status_bool;

      $pump_power_watts = (int) round(  $shelly_4pm_readings_object->power_to_pump_kw * 1000, 0 );

      $pump_is_drawing_power = ( $pump_power_watts > 50 );

      // if we are here it means pump is ON or power is disabled
      // check if required transients exist
      if ( false === ( $pump_alreay_ON = get_transient( 'pump_alreay_ON' ) ) )
      {
        // the transient does NOT exist so lets initialize the transients valid for 12 hours
        // This happens rarely, when transients get wiped out or 1st time code is run
        set_transient( 'pump_alreay_ON', 0,  3600 );

        // lets also initialize the pump start time to now since this is the 1st time ever
        // $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        // $timestamp = $now->getTimestamp();

        // set pump start time as curreny time stamp
        // set_transient( 'timestamp_pump_ON_start',  $timestamp,  12 * 60 * 60 );
        // set_transient( 'timestamp_pump_OFF',  $timestamp,  12 * 60 * 60 );
      }
      else
      {
        // the pump_already_ON transient is loaded into the variable so start using the variable
      }

      switch (true)
      {
        // pump power is Enabled but pump is OFF.
        case ( ! $pump_is_drawing_power && $power_to_pump_is_enabled ):

          // Check to see if pump just got auto turned OFF by pump controller  as it normally should when tank is full
          if ( ! empty( $pump_alreay_ON ) )
          {
            // reset the transient so next time it wont come here
            set_transient( 'pump_alreay_ON', 0,  3600 );

            // calculate pump ON duration time. This will be used for notifications
            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $timestamp = $now->getTimestamp();

            // we are pretty sure the transient exists because pump is already ON
            $previous_timestamp = get_transient(  'timestamp_pump_ON_start' );

            $prev_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $prev_datetime_obj->setTimeStamp($previous_timestamp);

            // find out the time interval between the start timestamp and the present one in seconds
            $diff = $now->diff( $prev_datetime_obj );

            $pump_ON_duration_secs = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 );

            // Write the duration time as property of the object
            $shelly_4pm_readings_object->pump_ON_duration_secs = $pump_ON_duration_secs;

            $this->verbose ? error_log("Pump ON for: $pump_ON_duration_secs Seconds") : false;

            // set pump start time as curreny time stamp. So the duration will be small from now on
            set_transient( 'timestamp_pump_ON_start',  $timestamp,  1 * 60 * 60 );

            $notification_title = "Pump Auto OFF";
            $notification_message = "Pump Auto OFF after " . $pump_ON_duration_secs;
            /*
            $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                $webpushrKey, $webpushrAuthToken  );
            */
          }
          else
          {
            // Pump is OFF long back so we just need to reset the transients
            set_transient( 'pump_alreay_ON', 0,  3600 );

            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $timestamp = $now->getTimestamp();

            // set pump start time as curreny time stamp
            set_transient( 'timestamp_pump_ON_start',  $timestamp,  1 * 60 * 60 );

            // disable notifications
            set_transient( 'pump_notification_count', 1, 2 * 3600 );
          }

          return null;

        break;


        // pump was just ON. So we set the flag and start timer
        case ( $pump_is_drawing_power &&  empty( $pump_alreay_ON ) ) :

          $this->verbose ? error_log("Pump Just turned ON") : false;

          // set the flag to indicate that pump is already on for next cycle check
          $pump_alreay_ON = 1;
          
          // update the transient so next check will work
          set_transient( 'pump_alreay_ON', 1, 2 * 3600 );

          // capture pump ON start time as now
          // get the unix time stamp when measurement was made
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $timestamp = $now->getTimestamp();

          // set pump start time as curreny time stamp
          set_transient( 'timestamp_pump_ON_start',  $timestamp,   2 * 3600);

          // reset notification transient so that notifications are enabled afresh for this cycle
          set_transient( 'pump_notification_count', 0, 2 * 3600 );

          return null;

        break;


        // pump is already ON. Measure duration and if over limit disable power to pump
        case ( $pump_is_drawing_power &&  ( ! empty( $pump_alreay_ON ) ) ):

          // calculate pump ON duration time. If greater than 60 minutes switch power to pump OFF
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $timestamp = $now->getTimestamp();

          // we are pretty sure the transient exists because pump is already ON
          $previous_timestamp = get_transient(  'timestamp_pump_ON_start' );

          $prev_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $prev_datetime_obj->setTimeStamp($previous_timestamp);

          // find out the time interval between the start timestamp and the present one in seconds
          $diff = $now->diff( $prev_datetime_obj );

          $pump_ON_duration_secs = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 );

          // Write the duration time as property of the object
          $shelly_4pm_readings_object->pump_ON_duration_secs = $pump_ON_duration_secs;

          $this->verbose ? error_log("Pump ON for: $pump_ON_duration_secs Seconds") : false;

          // if pump ON duration is more than 1h then switch the pump power OFF in Shelly 4PM channel 0
          if ( $pump_ON_duration_secs > 3600 )
          {
            // turn shelly power for pump OFF and update transients
            $status_turn_pump_off = $this->turn_pump_on_off( $user_index, 'off' );

            $pump_notification_count = get_transient( 'pump_notification_count' );

            if ( $status_turn_pump_off->isok )
            {
              // the pump was tuneed off per status
              $this->verbose ? error_log("Pump turned OFF after duration of: $pump_ON_duration_secs Seconds") : false;

              // pump is not ON anymore so set the flag to false
              set_transient( 'pump_alreay_ON', 0, 12 * 3600 );

              $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
              $timestamp = $now->getTimestamp();

              // set pump STOP time as curreny time stamp
              set_transient( 'timestamp_pump_OFF',  $timestamp,   12 * 60 * 60 );

              set_transient( 'timestamp_pump_ON_start',  $timestamp,   2 * 3600);

              // issue notification only once
              if ( empty( $pump_notification_count ) )
              {
                $notification_title = "Pump Overflow OFF";
                $notification_message = "After " . $pump_ON_duration_secs;
                /*
                $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                    $webpushrKey, $webpushrAuthToken  );
                */

                // disable notifications
                set_transient( 'pump_notification_count', 1, 1 * 3600 );
              }
            }
            else 
            {
              if ( empty( $pump_notification_count ) )
              {
                // the pump was tordered to turn off but it did not
                $this->verbose ? error_log("Problem - Pump could NOT be turned OFF after duration of: $pump_ON_duration_secs Seconds") : false;
                
                $notification_title = "Pump OFF problem";
                $notification_message = "Tank maybe overflowing!";
                /*
                $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                    $webpushrKey, $webpushrAuthToken  );
                */
                set_transient( 'pump_notification_count', 1, 1 * 3600 );
              }
            }
          }

        break;

        //  pump was switched off after timer and needs to be ON again
        case ( ! $power_to_pump_is_enabled ):
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $timestamp = $now->getTimestamp();

          $previous_timestamp = get_transient(  'timestamp_pump_OFF' );
          $prev_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $prev_datetime_obj->setTimeStamp($previous_timestamp);

          // find out the time interval between the last timestamp and the present one in seconds
          $diff = $now->diff( $prev_datetime_obj );

          $pump_OFF_duration_secs = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 );

          if ( $pump_OFF_duration_secs >= 120 && $pump_OFF_duration_secs <= 360)
          {
            // turn the shelly 4PM pump control back ON after 2m
            $status_tun_pump_on = $this->turn_pump_on_off( $user_index, 'on' );

            $this->verbose ? error_log("Pump turned back ON after duration of: $pump_OFF_duration_secs Seconds after Pump OFF"): false;

            $notification_title = "Pump Pwr Back ON";
            $notification_message = "Pump Power back ON";
            /*
            $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                $webpushrKey, $webpushrAuthToken  );
            */
          }
        break;
      }
    }



    /**
     * Gets all readings from Shelly and Studer and servo's AC IN shelly switch based on conditions
     * @param int:user_index
     * @param int:wp_user_ID
     * @param string:wp_user_name
     * @param bool:do_shelly
     * @param bool:$make_studer_api_call default true
     * @return object:studer_readings_obj
     * 
     */
    public function get_readings_and_servo_grid_switch( int     $user_index, 
                                                        int     $wp_user_ID, 
                                                        string  $wp_user_name, 
                                                        bool    $do_shelly,
                                                        bool    $make_studer_api_call = true ) : ? object
    {
        $config = $this->get_config();

        $webpushr_subscriber_id = get_user_meta( $wp_user_ID, 'webpushr_subscriber_id', true );
        $webpushrKey            = $config['accounts'][$user_index]['webpushrKey'];
        $webpushrAuthToken      = $config['accounts'][$user_index]['webpushrAuthToken'];

        { // readin the data from the home computer
          $object_from_linux_home_desktop = $this->get_mqtt_data_from_from_linux_home_desktop( $user_index );

          // how do we determine that a notification needs to be sent?
          // we send notifications for any LDS, switch relese, float release, pump overflow type events
          // we need to detect these events since now they are controlled locally by LAN


          // how do we determine that the data from home computer is valid?
          // data is valid if the timestamp from object received using mqtt, is not stale.

          // get the ts that was sent by xcomlan and shellybm
          $xcomlan_ts   = $object_from_linux_home_desktop->xcomlan_ts;
          $shellybm_ts  = $object_from_linux_home_desktop->timestamp_shellybm;

          $obj_check_ts_validity_xcomlan  = $this->check_validity_of_timestamp( $xcomlan_ts,  120 );
          $obj_check_ts_validity_shellybm = $this->check_validity_of_timestamp( $shellybm_ts, 120 );

          // check its validity - if it exceeds duration given, it is not valid. Use that
          
          if (  $obj_check_ts_validity_xcomlan->elapsed_time_exceeds_duration_given   === false || 
                $obj_check_ts_validity_shellybm->elapsed_time_exceeds_duration_given  === false     )
          { // at least one timestamp is fresher than 3m so acceptable
            // get the value of the duration since timestamp
            $seconds_elapsed_xcomlan_ts   =  $obj_check_ts_validity_xcomlan->seconds_elapsed;
            $seconds_elapsed_shellybm_ts  =  $obj_check_ts_validity_shellybm->seconds_elapsed;

            // write these back to the object for access outside of this routine
            $object_from_linux_home_desktop->seconds_elapsed_xcomlan_ts   = $seconds_elapsed_xcomlan_ts;
            $object_from_linux_home_desktop->seconds_elapsed_shellybm_ts  = $seconds_elapsed_shellybm_ts;

            if ( false === ( $last_notification_ts = get_transient('last_notification_ts') ) )
            {
              $notifications_enabled = (bool) true;
            }
            else
            {
              if ( $this->check_validity_of_timestamp( $last_notification_ts, 1800 )->elapsed_time_exceeds_duration_given )
              {
                $notifications_enabled = (bool) true;
              }
              else
              {
                $notifications_enabled = (bool) false;
              }
            }

            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $now_ts = $now->getTimestamp();

            // get the switch tree object
            $switch_tree_obj = $object_from_linux_home_desktop->switch_tree_obj;

            switch ( $object_from_linux_home_desktop->present_switch_tree_exit_condition )
            {
              case "no_action":

                // no notifications
                $notifications_enabled = (bool) false;  // independent of everything else

              break;

              case "LVDS":

                $notification_title   = "LVDS";
                $notification_message = "LVDS - SOC " . $object_from_linux_home_desktop->soc_percentage_now . "%";
                
              break;

              case "lvds_release":

                $notification_title   = "lvds_release";
                $notification_message = "lvds_release - SOC " . $object_from_linux_home_desktop->soc_percentage_now . "%";
                
              break;

              case "float_release":

                $notification_title   = "float_release";
                $notification_message = "float_release - SOC " . $object_from_linux_home_desktop->soc_percentage_now . "%";
                
                break;

                case "always_on":

                  $notification_title   = "always_on";
                  $notification_message = "always_on - SOC " . $object_from_linux_home_desktop->soc_percentage_now . "%";
                  
                  break;

                default:

                  $notifications_enabled = (bool) false;

                  break;
            }

            
            if ( $notifications_enabled )
                {
                  error_log( "This is the notofication that would have been sent: $notification_message");
                  
                  $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                      $webpushrKey, $webpushrAuthToken  );
                                                      
                  set_transient('last_notification_ts', $now_ts, 3600 );                        
                }
              
            

            set_transient( 'shelly_readings_obj', $object_from_linux_home_desktop, 3 * 60 );

            return $object_from_linux_home_desktop;
          }
          else
          { // timestamp is stale so xcomlan data is not valid.
            /*
            // update the SOC captured at midnight
            $soc_percentage_at_midnight = $object_from_linux_home_desktop->soc_percentage_at_midnight;
            update_user_meta( $wp_user_ID, 'soc_percentage', $soc_percentage_at_midnight );

            // update the Shelly EM home energy WH counter at midnight
            $shelly_em_home_energy_counter_at_midnight = $object_from_linux_home_desktop->shelly_em_home_energy_counter_at_midnight;
            update_user_meta( $wp_user_ID, 'shelly_em_home_energy_counter_midnight', $shelly_em_home_energy_counter_at_midnight );

            // update the battery AH accumulation since midnight. We are using the xcomlan value since it is reliable
            $battery_soc_percentage_accumulated_since_midnight = $object_from_linux_home_desktop->battery_xcomlan_soc_percentage_accumulated_since_midnight;
            update_user_meta( $wp_user_ID, 'battery_accumulated_percent_since_midnight', $battery_soc_percentage_accumulated_since_midnight );

            // we don;t have separate xcomlan midnight values. We use existing ones as above
            // $battery_xcomlan_soc_percentage_accumulated_since_midnight = $object_from_linux_home_desktop->battery_xcomlan_soc_percentage_accumulated_since_midnight;
            // update_user_meta( $wp_user_ID, 'studer_current_based_soc_percentage_accumulated_since_midnight', $battery_xcomlan_soc_percentage_accumulated_since_midnight );

            // update the midnight Grid Counter value
            $grid_wh_counter_at_midnight = $object_from_linux_home_desktop->grid_wh_counter_at_midnight;
            update_user_meta( $wp_user_ID, 'grid_wh_counter_midnight', $grid_wh_counter_at_midnight );

            // After dark SOC capture value
            $soc_percentage_update_after_dark = $object_from_linux_home_desktop->soc_percentage_update_after_dark;
            update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $soc_percentage_update_after_dark );

            // after dark energy counter value for Shelly 1EM
            $shelly_energy_counter_after_dark = $object_from_linux_home_desktop->shelly_energy_counter_after_dark;
            update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $shelly_energy_counter_after_dark );

            // update the SOC dark capture timestamp
            $timestamp_soc_capture_after_dark = $object_from_linux_home_desktop->timestamp_soc_capture_after_dark;
            update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $timestamp_soc_capture_after_dark );

            // update the grid energy accumulated since midnight.
            $home_grid_kwh_accumulated_since_midnight = $object_from_linux_home_desktop->home_grid_kwh_accumulated_since_midnight;
            update_user_meta( $wp_user_ID, 'grid_wh_since_midnight', $home_grid_kwh_accumulated_since_midnight );
            */
            return null;
          }
          
        }

        { // Define boolean control variables for various time intervals
          
          $sunrise_hms_format = $this->cloudiness_forecast->sunrise_hms_format  ?? "06:00:00";

          $sunset_hms_format  = $this->cloudiness_forecast->sunset_hms_format   ?? "18:00:00";
          $sunset_plus_10_minutes_hms_format  = $this->cloudiness_forecast->sunset_plus_10_minutes_hms_format ?? "18:10:00";
          $sunset_plus_15_minutes_hms_format  = $this->cloudiness_forecast->sunset_plus_15_minutes_hms_format ?? "18:15:00";

          // error_log ("Sunset: $sunset_hms_format, Sunset plus 10m: $sunset_plus_10_minutes_hms_format, Sunset plus 15m: $sunset_plus_15_minutes_hms_format");
          // error_log("Sunrise: $sunrise_hms_format");

          // From sunset to 15m after, the total time window for SOC after Dark Capture
          $time_window_for_soc_dark_capture_open = $this->nowIsWithinTimeLimits( $sunset_hms_format, $sunset_plus_15_minutes_hms_format );

          // From sunset to 10m after is the time window alloted for SOC capture after dark, using Studder 1st.
          $time_window_open_for_soc_capture_after_dark_using_studer = $this->nowIsWithinTimeLimits( $sunset_hms_format, $sunset_plus_10_minutes_hms_format );

          // From 10m after sunset to 5m after is the time window for SOC capture after dark using Shelly, if Studer fails in 1st window
          $time_window_open_for_soc_capture_after_dark_using_shelly = $this->nowIsWithinTimeLimits( $sunset_plus_10_minutes_hms_format, $sunset_plus_15_minutes_hms_format );

          $it_is_still_dark = $this->nowIsWithinTimeLimits( $sunset_hms_format, "23:59:59" ) || 
                              $this->nowIsWithinTimeLimits( "00:00", $sunrise_hms_format );
        }

        { // Get user meta for limits and controls as an array rather than 1 by 1
          $all_usermeta                           = $this->get_all_usermeta( $wp_user_ID );
          // SOC percentage needed to trigger LVDS
          $soc_percentage_lvds_setting            = $all_usermeta['soc_percentage_lvds_setting']  ?? 30;

          // SOH of battery currently. 
          $soh_percentage_setting                 = $all_usermeta['soh_percentage_setting']       ?? 100;

          // Avg Battery Voltage lower threshold for LVDS triggers
          $battery_voltage_avg_lvds_setting       = $all_usermeta['battery_voltage_avg_lvds_setting']  ?? 48.3;

          // RDBC active only if SOC is below this percentage level.
          $soc_percentage_rdbc_setting            = $all_usermeta['soc_percentage_rdbc_setting']  ?? 80.0;

          // Switch releases if SOC is above this level 
          $soc_percentage_switch_release_setting  = $all_usermeta['soc_percentage_switch_release_setting']  ?? 95.0; 

          // SOC needs to be higher than this to allow switch release after RDBC
          $min_soc_percentage_for_switch_release_after_rdbc 
                                                  = $all_usermeta['min_soc_percentage_for_switch_release_after_rdbc'] ?? 32;

          // min KW of Surplus Solar to release switch after RDBC
          $min_solar_surplus_for_switch_release_after_rdbc 
                                                  = $all_usermeta['min_solar_surplus_for_switch_release_after_rdbc'] ?? 0.2;

          // battery float voltage setting. Only used for SOC clamp for 100%
          $battery_voltage_avg_float_setting      = $all_usermeta['battery_voltage_avg_float_setting'] ?? 51.9; 

          // Min VOltage at ACIN for RDBC to switch to GRID
          $acin_min_voltage_for_rdbc              = $all_usermeta['acin_min_voltage_for_rdbc'] ?? 199;  

          // Max voltage at ACIN for RDBC to switch to GRID
          $acin_max_voltage_for_rdbc              = $all_usermeta['acin_max_voltage_for_rdbc'] ?? 241; 

          // KW of deficit after which RDBC activates to GRID. Usually a -ve number
          $psolar_surplus_for_rdbc_setting        = $all_usermeta['psolar_surplus_for_rdbc_setting'] ?? -0.5;  

          // Minimum Psolar before RDBC can be actiated
          $psolar_min_for_rdbc_setting            = $all_usermeta['psolar_min_for_rdbc_setting'] ?? 0.3;  

          // get operation flags from user meta. Set it to false if not set
          $keep_shelly_switch_closed_always       = $all_usermeta['keep_shelly_switch_closed_always'] ?? false;

          // get the installed battery capacity in KWH from config
          $SOC_capacity_KWH = $this->config['accounts'][$user_index]['battery_capacity'];

          // get webpshr subscriber id for this user
          $webpushr_subscriber_id = $all_usermeta['webpushr_subscriber_id'];

          // Webpushr NPush otifications API Key
          $webpushrKey            = $this->config['accounts'][$user_index]['webpushrKey'];

          // Webpushr Token
          $webpushrAuthToken      = $this->config['accounts'][$user_index]['webpushrAuthToken'];
        }

        { // --------------------- ACIN SWITCH Details after making a Shelly API call -------------------

          $shelly_switch_acin_details_arr = $this->get_shelly_switch_acin_details( $user_index );

          $valid_shelly_config              = $shelly_switch_acin_details_arr['valid_shelly_config'];
          $control_shelly                   = $shelly_switch_acin_details_arr['control_shelly'];
          $shelly_switch_status             = $shelly_switch_acin_details_arr['shelly_switch_status'];
          $shelly_api_device_status_voltage = $shelly_switch_acin_details_arr['shelly_api_device_status_voltage'];
          $shelly_api_device_status_ON      = $shelly_switch_acin_details_arr['shelly_api_device_status_ON'];
        }

        { // get the SOCs from the user meta. These will be used to calculate new updates

          // This is the value of the SOC from previous cycle as calculated by STUDER readings
          $SOC_percentage_previous            = (float) get_user_meta($wp_user_ID, "soc_percentage_now",  true);

          // This is the value of the SOC from previous cycle using SHelly BM
          $SOC_percentage_previous_shelly_bm  = (float) get_user_meta($wp_user_ID, "soc_percentage_now_calculated_using_shelly_bm",  true) ?? $SOC_percentage_previous;

          // Get the SOC percentage at beginning of Dayfrom the user meta. This gets updated only just past midnight once
          $SOC_percentage_beg_of_day          = (float) get_user_meta($wp_user_ID, "soc_percentage",  true) ?? 60;

          // SOC percentage just after midnight as measured by Shelly BM.
          $shelly_soc_percentage_at_midnight = (float) get_user_meta($wp_user_ID, "shelly_soc_percentage_at_midnight",  true) 
                                                              ?? $SOC_percentage_beg_of_day;
          // SOC percentage after dark as computed by Shelly EM after dark. This gets captured at dark and gets updated every cycle
          // using only shelly devices no STUDER involvement.
          $soc_update_from_studer_after_dark  = (float) get_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark',  true);
        }
        
        {  // make all measurements, update SOC, set switch tree control flags, reset midnight values

          // check to see if soc_capture_after_dark_happened.
          // we do this check now to determine if Studer Calls are to be permitted at all if SOC after dark capture happened
          // This signifies that it is dark
          $soc_capture_after_dark_happened = $this->check_if_soc_after_dark_happened($user_index, $wp_user_name, $wp_user_ID);

          // define conditions for Studer API call to be made
          $conditions_satisfied_to_make_studer_api_call = 
                  
                $make_studer_api_call &&  

                ( ! $it_is_still_dark ||  // It is daytime and studer call flag is true. This is the main trusted mode
                  // it is dark and Studer flag is enabled but soc capture after dark did not happen yet.
                (   $it_is_still_dark &&  ! $soc_capture_after_dark_happened )  ||  
                  // It is dark, Studer flag is enabled, soc dark capture happened but its values dont exist
                (   $it_is_still_dark &&    $soc_capture_after_dark_happened && empty( $soc_update_from_studer_after_dark ) ) ||
                  // it is dark, Studer flag is enabled, soc after dark capture happened but soc after dark values are bad
                (   $it_is_still_dark &&    $soc_capture_after_dark_happened && 
                    $soc_update_from_studer_after_dark < 30 && $soc_update_from_studer_after_dark > 102 ) );
 
          // make Studer API call when flag is let in main cron loop to do so
          if ( $conditions_satisfied_to_make_studer_api_call === true )
          {   // conditions are satisfied to make Studer API 
            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $studer_measured_battery_amps_now_timestamp = $now->getTimestamp();

            $studer_readings_obj  = $this->get_studer_min_readings($user_index);

            // define the condition for failure of the Studer API call
            $studer_api_call_failed =   ( empty(  $studer_readings_obj )                          ||  // object is empty
                                          empty(  $studer_readings_obj->battery_voltage_vdc )     ||  // voltage is empty
                                          $studer_readings_obj->battery_voltage_vdc < 40          ||  // voltage < 40V
                                          empty(  $studer_readings_obj->pout_inverter_ac_kw ) );      // Load KW is empty
          }
          else
          {   // as Studer measurements were not made lets recall the previous STUDER readings object to start with
              // TODO not clear if the studer object is needed if studer API was NOT called
            // This flag is set when it is a non-studer cycle or when Studer API call fails or when its dark and SOC after dark valid
            $studer_api_call_failed = true;

            $studer_readings_obj = get_transient( $wp_user_name . '_' . 'studer_readings_object');

            if ( empty($studer_readings_obj) )
            {
              $studer_readings_obj = new stdClass;
            }
          }

          // initialize a new object for holding Shelly measurements similar to the Studer Readings Object
          $shelly_readings_obj = new stdClass;

          { // make WATER HEATER measurements using Shelly plus 1PM
            $shelly_water_heater_data = $this->get_shelly_device_status_water_heater( $user_index );

            if ( $shelly_water_heater_data )
            {   // update the Shelly readings object with the water heater data object
              $shelly_readings_obj->shelly_water_heater_data  = $shelly_water_heater_data;

              // Update Studer Readings Object also with the water heater object
              if ( ! empty ( $studer_readings_obj ) )
              {
                $studer_readings_obj->shelly_water_heater_data = $shelly_water_heater_data;
              }
            }
          }
          
          { // Measure Battery Charging current as positive using Shelly UNI
            { // get the estimated solar power object from calculations for a clear day
              
              $est_solar_obj = $this->estimated_solar_power($user_index);

              $est_solar_total_kw = $est_solar_obj->est_solar_total_kw;

              $total_to_west_panel_ratio = $est_solar_obj->total_to_west_panel_ratio;

              $est_solar_kw_arr = $est_solar_obj->est_solar_kw_arr;

              // Boolean Variable to designate it is a cloudy day. This is derived from a free external API service
              $it_is_a_cloudy_day   = $this->cloudiness_forecast->it_is_a_cloudy_day_weighted_average;
            }

            // get a measurement of the charging current into battery
            $shelly_battery_measurement_object = $this->get_shelly_battery_measurement( $user_index, $wp_user_name, $wp_user_ID, 
                                                                                        $shelly_switch_status, $it_is_still_dark );
            if ( $shelly_battery_measurement_object )
            {
              $battery_capacity_ah                    = $shelly_battery_measurement_object->battery_capacity_ah;

              $battery_accumulated_percent_since_midnight = $shelly_battery_measurement_object->battery_accumulated_percent_since_midnight;

              $battery_accumulated_ah_since_midnight  = $battery_accumulated_percent_since_midnight / 100 * $battery_capacity_ah;

              $battery_kwh_since_midnight = round( 49.8 * 0.001 * $battery_accumulated_ah_since_midnight, 3 );

              $shelly_readings_obj->est_solar_total_kw        = $est_solar_total_kw;
              $shelly_readings_obj->est_solar_kw_arr          = $est_solar_kw_arr;
              $shelly_readings_obj->total_to_west_panel_ratio = $total_to_west_panel_ratio;
              $shelly_readings_obj->battery_kwh_since_midnight  = $battery_kwh_since_midnight;
              $shelly_readings_obj->battery_amps                = $shelly_battery_measurement_object->battery_amps;
              $shelly_readings_obj->battery_accumulated_ah_since_midnight       = $battery_accumulated_ah_since_midnight;
              $shelly_readings_obj->battery_accumulated_percent_since_midnight  = $battery_accumulated_percent_since_midnight;
              $shelly_readings_obj->battery_ah_this_measurement = $shelly_battery_measurement_object->battery_ah_this_measurement;
              $shelly_readings_obj->battery_capacity_ah         = $battery_capacity_ah;
            }
            // Also update the Studer object with battery amps
            if ( ! empty ( $studer_readings_obj ) )
            {
              $studer_readings_obj->battery_amps              = $shelly_battery_measurement_object->battery_amps;
              $studer_readings_obj->est_solar_total_kw        = $est_solar_total_kw;
            }
          }

          { // Now make a Shelly 4PM measurement to get individual powers for all channels
            $shelly_4pm_readings_object = $this->get_shelly_device_status_homepwr( $user_index );

            if ( ! empty( $shelly_4pm_readings_object ) ) 
            {   // there is a valid response from the Shelly 4PM switch device
                
                // Also check and control pump ON duration
                $this->control_pump_on_duration( $wp_user_ID, $user_index, $shelly_4pm_readings_object);

                // Load or update the STUDER Object with properties from the Shelly 4PM object
                if ( ! empty ( $studer_readings_obj ) )
                {
                  $studer_readings_obj->power_to_home_kw    = $shelly_4pm_readings_object->power_to_home_kw;
                  $studer_readings_obj->power_to_ac_kw      = $shelly_4pm_readings_object->power_to_ac_kw;
                  $studer_readings_obj->power_to_pump_kw    = $shelly_4pm_readings_object->power_to_pump_kw;
                  $studer_readings_obj->power_total_to_home = $shelly_4pm_readings_object->power_total_to_home;
                  $studer_readings_obj->power_total_to_home_kw  = $shelly_4pm_readings_object->power_total_to_home_kw;
                  $studer_readings_obj->current_total_home      = $shelly_4pm_readings_object->current_total_home;
                  $studer_readings_obj->energy_total_to_home_ts = $shelly_4pm_readings_object->energy_total_to_home_ts;

                  $studer_readings_obj->pump_switch_status_bool = $shelly_4pm_readings_object->pump_switch_status_bool;
                  $studer_readings_obj->ac_switch_status_bool   = $shelly_4pm_readings_object->ac_switch_status_bool;
                  $studer_readings_obj->home_switch_status_bool = $shelly_4pm_readings_object->home_switch_status_bool;
                  $studer_readings_obj->voltage_home            = $shelly_4pm_readings_object->voltage_home;

                  $studer_readings_obj->pump_ON_duration_secs   = $shelly_4pm_readings_object->pump_ON_duration_secs;
                }
            }
            else 
            {
              error_log(" Shelly Pro 4 PM Home Load measurement API call failed ");
            }
          }

          // make an API call on the Shelly EM device and calculate the accumulated WH Home load consumption since midnight
          $shelly_em_readings_object = $this->get_shelly_em_home_load_measurements( $user_index, $wp_user_name, $wp_user_ID );

          if ( $shelly_em_readings_object )
          {
            $present_home_wh_reading  = $shelly_em_readings_object->present_home_wh_reading;

            // Current Power in KW consumed by Home on Red Phase
            $shelly_readings_obj->present_home_kw_shelly_em = $shelly_em_readings_object->present_home_kw_shelly_em;

            /// Current energy counter reading of Home Energy WJH meter
            $shelly_readings_obj->present_home_wh_reading = $present_home_wh_reading;

            // present AC RMS phase voltage at panel, after Studer output
            $shelly_readings_obj->home_voltage_em = $shelly_em_readings_object->home_voltage_em;

            // Energy consumed in WH by home since midnight on the red phase
            $shelly_readings_obj->home_consumption_wh_since_midnight = $shelly_em_readings_object->home_consumption_wh_since_midnight;

            // energy consumed in KWH by home since midnight as measured by Shelly EM 
            $home_consumption_kwh_since_midnight_shelly_em = round( $shelly_readings_obj->home_consumption_wh_since_midnight * 0.001, 3 );
          
            $shelly_readings_obj->home_consumption_kwh_since_midnight_shelly_em = $home_consumption_kwh_since_midnight_shelly_em;

            // another variable created for better readability of code
            $present_shelly_em_home_wh_counter = $present_home_wh_reading;
          }
          

          { // Make Shelly pro 3EM energy measuremnts of 3phase Grid inout
            $shelly_3p_grid_wh_measurement_obj = $this->get_shelly_3p_grid_wh_since_midnight( $user_index, 
                                                                                              $wp_user_name, 
                                                                                              $wp_user_ID);
            if ( ! empty( $shelly_3p_grid_wh_measurement_obj ) )
            {
              $home_grid_wh_counter_now                 = $shelly_3p_grid_wh_measurement_obj->home_grid_wh_counter_now;
              $home_grid_wh_accumulated_since_midnight  = $shelly_3p_grid_wh_measurement_obj->home_grid_wh_accumulated_since_midnight;
              $home_grid_kwh_accumulated_since_midnight = round( $home_grid_wh_accumulated_since_midnight * 0.001, 3 );
              $home_grid_kw_power                       = $shelly_3p_grid_wh_measurement_obj->home_grid_kw_power;

              $car_charger_grid_wh_counter_now          = $shelly_3p_grid_wh_measurement_obj->car_charger_grid_wh_counter_now;
              $car_charger_grid_kw_power                = $shelly_3p_grid_wh_measurement_obj->car_charger_grid_kw_power;
              
              $this->verbose ? error_log("home_grid_wh_counter_now: $home_grid_wh_counter_now, wh since midnight: $home_grid_wh_accumulated_since_midnight, Home Grid PowerKW: $home_grid_kw_power"): false;
            }
            
          }

          { // calculate non-studer based SOC using Shelly Battery Measurements
            $soc_charge_net_percent_today_shelly = $battery_accumulated_percent_since_midnight;

            $soc_percentage_now_shelly = $shelly_soc_percentage_at_midnight + $soc_charge_net_percent_today_shelly;
            
            // lets update the user meta for updated SOC
            update_user_meta( $wp_user_ID, 'soc_percentage_now_calculated_using_shelly_bm', $soc_percentage_now_shelly);

            // $surplus  power is any surplus from solar after load consumption, available for battery, etc.
            $surplus = round( $shelly_readings_obj->battery_amps * 49.8 * 0.001, 1 ); // in KW

            $shelly_readings_obj->surplus  = $surplus;
            $shelly_readings_obj->SOC_percentage_now  = round( $soc_percentage_now_shelly, 5);


            // Independent of Servo Control Flag  - Switch Grid ON due to Low SOC - Don't care about Grid Voltage     
            $shelly_readings_obj->LVDS_BM = ( $soc_percentage_now_shelly  <= $soc_percentage_lvds_setting ) // SOC threshold
                                            &&
                                            ( $shelly_switch_status == "OFF" );					                    // The Grid switch is OFF
          }
          
          if ( ! $studer_api_call_failed )
          {   // Studer API call was successful, so we update SOC using STUDER values
              // average the battery voltage over last 3 readings
            $battery_voltage_avg  = $this->get_battery_voltage_avg( $wp_user_name, $studer_readings_obj->battery_voltage_vdc );

            set_transient( $wp_user_name . '_' . 'battery_voltage_avg', $battery_voltage_avg, 60 * 60 );
  
            // Solar power Now
            $psolar               = $studer_readings_obj->psolar_kw;
  
            // Check if it is cloudy AT THE MOMENT. Yes if solar is less than half of estimate
            $it_is_cloudy_at_the_moment = $psolar <= 0.5 * array_sum($est_solar_kw_arr);
            // Weighted percentage cloudiness
            $cloudiness_average_percentage_weighted = round($this->cloudiness_forecast->cloudiness_average_percentage_weighted, 0);
  
            // Inverter readings at present Instant
            $pout_inverter        = $studer_readings_obj->pout_inverter_ac_kw;    // Inverter Output Power in KW
            $grid_input_vac       = $studer_readings_obj->grid_input_vac;         // Grid Input AC Voltage measured by Studer
  
            // Surplus power from Solar after supplying the Load as calculated by STUDER
            $surplus              = $psolar - $pout_inverter;
  
            // get the current Measurement values from the STUDER Readings Object
            $KWH_solar_today      = $studer_readings_obj->KWH_solar_today;  // Net Solar Units generated Today
            $KWH_grid_today       = $studer_readings_obj->KWH_grid_today;   // Net Grid Units consumed Today
            $KWH_load_today       = $studer_readings_obj->KWH_load_today;   // Net Load units consumed Today

            if ($home_consumption_kwh_since_midnight_shelly_em > 0.2 )
            {   // for the case where the Studer Load KWH consumed is bad sometimes
              $KWH_load_today_percent_delta = ( $KWH_load_today - $home_consumption_kwh_since_midnight_shelly_em ) / 
                                                $home_consumption_kwh_since_midnight_shelly_em * 100.0;

              // compare the Load consumption in KWH with Shelly EM method. If too different use the Shelly EM value
              if ( abs( $KWH_load_today_percent_delta ) > 10 )
              {
                $KWH_load_today = $home_consumption_kwh_since_midnight_shelly_em;
                error_log("Used Shelly EM load calculation for STUDER SOC update - KWH_load_today_percent_delta: $KWH_load_today_percent_delta");
              }
            }

            {   // calculate SOC update based on Studer measurement of battery current only, amps positive if charging
                $studer_measured_battery_amps_now = $studer_readings_obj->battery_charge_adc;

                // get Studer measured current from previous cycle from Transient if it exists. If not set value to present value
                $studer_measured_battery_amps_previous = (float) get_transient( 'studer_measured_battery_amps_previous' ) ?? 
                                                                  $studer_measured_battery_amps_now;
                // get timestamp of previous studer measurement - set to present value if it doesnt exist
                $studer_measured_battery_amps_previous_timestamp = (float) get_transient( 'studer_measured_battery_amps_previous_timestamp' ) ?? 
                                                                  $studer_measured_battery_amps_now_timestamp;

                // calculate the time difference in seconds between previous and current Studer measurements
                $prev_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
                $prev_datetime_obj->setTimeStamp($studer_measured_battery_amps_previous_timestamp);

                // $now was set just around the time STuder readings were taken.
                $diff = $now->diff( $prev_datetime_obj );
                $time_between_measurements_secs   = (int) ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 );
                $time_between_measurements_hours  = $time_between_measurements_secs / 3600;

                // calculate the AM of battery current charge in this time interval
                $studer_measured_battery_ah_this_interval = 
                0.5 * ( $studer_measured_battery_amps_now + $studer_measured_battery_amps_previous ) * $time_between_measurements_hours;
                
                $studer_current_based_battery_delta_soc_percentage = 
                                                            $studer_measured_battery_ah_this_interval / $battery_capacity_ah * 100;
                // get the accumulated studer measured battery SOC5 using the current measurement only from user meta
                $studer_current_based_soc_percentage_accumulated_since_midnight = (float) get_user_meta(  $wp_user_ID, 
                                                            'studer_current_based_soc_percentage_accumulated_since_midnight', true) ?? $battery_accumulated_percent_since_midnight;
                // accumulate current measurement
                $studer_current_based_soc_percentage_accumulated_since_midnight += $studer_current_based_battery_delta_soc_percentage;

                if ( $time_between_measurements_secs >= 15 * 60 )
                {   // If time difference is greater than 10m reset the accumulated value to the one based on Shelly BM
                  $studer_current_based_soc_percentage_accumulated_since_midnight = $battery_accumulated_percent_since_midnight;
                }

                // Use the Studer SOC percentage at midnight to calculate the present SOC based on current based measurement
                $studer_current_based_soc_percentage_now =  round(  $SOC_percentage_beg_of_day + 
                                                                    $studer_current_based_soc_percentage_accumulated_since_midnight, 1  );
                // write current measurement to user meta accumulation 
                update_user_meta( $wp_user_ID, 'studer_current_based_soc_percentage_accumulated_since_midnight', 
                                               $studer_current_based_soc_percentage_accumulated_since_midnight);

                $studer_current_based_measurement_timestamp_now = $now->getTimestamp();

                // Reset transients to current measurements for use in next cycle for trapeziod rule integration
                set_transient( 'studer_measured_battery_amps_previous',           $studer_measured_battery_amps_now ,               5 * 60);
                set_transient( 'studer_measured_battery_amps_previous_timestamp', $studer_current_based_measurement_timestamp_now,  5 * 60);

                // display values for logging
                $this->verbose ? error_log("Studer Current Based SOC: $studer_current_based_soc_percentage_now %"): false;
            }

            // Net battery charge in KWH (discharge if minus) as measured by STUDER
            $KWH_batt_charge_net_today  = $KWH_solar_today * 0.96 + (0.988 * $KWH_grid_today - $KWH_load_today) * 1.07;
  
            // Calculate in percentage of  installed battery capacity
            $SOC_batt_charge_net_percent_today = $KWH_batt_charge_net_today / $SOC_capacity_KWH * 100;
  
            //  Update SOC  number  using STUDER Measurements
            $SOC_percentage_now = round( $SOC_percentage_beg_of_day + $SOC_batt_charge_net_percent_today, 5);
  
            // Check if STUDER computed SOC update is reasonable
            if ( $SOC_percentage_now < 30 || $SOC_percentage_now > 101 ) 
            { // STUDER computed SOC is out of bounds
                error_log("No SOC update: SOC_studer: $SOC_percentage_now");
            }
            else
            {   // STUDER SOC update seems reasonable Update user meta so this becomes the previous value for next cycle
                update_user_meta( $wp_user_ID, 'soc_percentage_now', $SOC_percentage_now);
            }

            if ( $this->verbose )
            {   // log all measurements inluding Studer and Shelly
                error_log("AC Voltage at Shelly Home Panel: $shelly_em_readings_object->home_voltage_em");

                error_log("Load_KWH_today_Studer = "  . $KWH_load_today . 
                          " KWH_load_shellyEM =  "    . $home_consumption_kwh_since_midnight_shelly_em);

                error_log("Grid KWH Studer Today: $KWH_grid_today, Grid KWH Shelly3EM Today: $home_grid_kwh_accumulated_since_midnight");

                error_log("Solar KWH Studer Today: $KWH_solar_today");

                error_log("SOC_shelly_BM: $soc_percentage_now_shelly, SOC_Studer: $SOC_percentage_now");
            }

            // Since STUDER API call was successful, lets equalize SOC now of shelly BM method to that of STUDER SOC now
            // We also want that SOC midnight of both are the same

            { // This is Studer based LVDS and only happens when SOC after dark is not happening
              // When SOC after dark happens the same variable is determined by SOC after dark values.
              // TODO what happens during day time when STUDER is offline and close to LVDS?
              // TODO how to then use $shelly_readings_obj->LVDS_BM as the main LVDS?
              $LVDS =             ( ( $battery_voltage_avg  <= $battery_voltage_avg_lvds_setting || 
                                      $SOC_percentage_now   <= $soc_percentage_lvds_setting           )  &&
                                      $shelly_switch_status == "OFF"                                     &&
                                      $control_shelly === true );

              if ($LVDS)
              {
                error_log("LVDS using SOC from STUDER: $LVDS");
              }
              

              $switch_override =  ( $shelly_switch_status                == "OFF" )  &&
                                  ( $studer_readings_obj->grid_input_vac >= 190   );

              $soc_update_method = "studer";
            }

            // update the object
            $studer_readings_obj->SOC_percentage_now  = $SOC_percentage_now;
            $studer_readings_obj->LVDS                = $LVDS;
            $studer_readings_obj->switch_override     = $switch_override;
            $studer_readings_obj->soc_update_method   = "studer";
            $studer_readings_obj->soc_percentage_now_using_dark_shelly = 1000;
            $studer_readings_obj->studer_current_based_soc_percentage_accumulated_since_midnight = $studer_current_based_soc_percentage_accumulated_since_midnight;
            $studer_readings_obj->studer_current_based_soc_percentage_now = $studer_current_based_soc_percentage_now;

          }   // endif of STUDER measurement successful
          else
          {   // Studer API call failed. So we set the flag appropriately
            $soc_update_method = "shelly";
            $shelly_readings_obj->soc_update_method   = "shelly";
            $shelly_readings_obj->soc_percentage_now_using_dark_shelly = 1000;
          }
        }

        if ($it_is_still_dark)
        { // Do all the SOC after Dark operations here - Capture and also SOC updation

          // check if capture happened. now-event time < 12h since event can happen at 7PM and last till 6:30AM
          $soc_capture_after_dark_happened = $this->check_if_soc_after_dark_happened($user_index, $wp_user_name, $wp_user_ID);

          // Ideally if SOC after dark is to happen, then 1st preference should be given to SOC STUDER value
          // If Studer API calls keep failing then as a fallback the SOC shelly BM value should be used
          // before the time window closes.

          if (  $soc_capture_after_dark_happened === false  && $present_home_wh_reading )
          { // event not happened yet so make it happen with valid value for the home energy EM counter reading

            // Give 1st preference to Studer readings. Use the 1st window of 10m after sunset
            if (  $soc_update_method === "studer" && $SOC_percentage_now && $SOC_percentage_now > 30 && 
                  $SOC_percentage_now <= 101  && $time_window_open_for_soc_capture_after_dark_using_studer )
            {   // Capture SOC after dark using Studer SOC value and set the energy counter after dark to present reading
              $this->capture_evening_soc_after_dark(  $user_index, 
                                                      $wp_user_name, 
                                                      $wp_user_ID, 
                                                      $SOC_percentage_now, 
                                                      $present_home_wh_reading,
                                                      $time_window_for_soc_dark_capture_open );
            }
            elseif (  $soc_update_method === "shelly" && $soc_percentage_now_shelly && $soc_percentage_now_shelly > 30 && 
                      $soc_percentage_now_shelly <= 101  && $time_window_open_for_soc_capture_after_dark_using_shelly )
            { // Studer SOC after dark failed in 10m window so use Shelly for dark capture in 5m window after
              $this->capture_evening_soc_after_dark(  $user_index, 
                                                      $wp_user_name, 
                                                      $wp_user_ID, 
                                                      $soc_percentage_now_shelly, 
                                                      $present_home_wh_reading,
                                                      $time_window_for_soc_dark_capture_open );
            }
          } 

          // iimediately after capture thie following will not trigger but the next loop will.
          if ( $soc_capture_after_dark_happened === true )
          { // SOC capture after dark is DONE and it is still dark, so use it to compute SOC after dark using only Shelly readings

            $soc_update_method = "shelly-after-dark";

            if ( $shelly_switch_status == "ON" && $home_grid_kw_power > 0.1 )
            { // Grid is supplying Load and since Solar is 0, battery current is 0 so no change in battery SOC
              
              // update the after dark energy counter to latest value
              update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $present_home_wh_reading);

              // SOC is unchanging due to Grid ON however set the variables using the user meta since they are undefined.
              $soc_percentage_now_using_dark_shelly = (float) get_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark',  true);

              
              $shelly_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;

              if ($studer_readings_obj)
              {
                $studer_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;
              }
            }
            else
            {   // Get the captured after dark SOC and home wh counters from user meta
              
              $soc_percentage_after_dark        = (float) get_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark',  true);
              $shelly_energy_counter_after_dark = (float) get_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark',   true);

              // get the difference in energy consumed since last reading
              $home_consumption_wh_after_dark_using_shellyem = $present_home_wh_reading - $shelly_energy_counter_after_dark;
              // convert to KW and round to 3 decimal places
              $home_consumption_kwh_after_dark_using_shellyem = round( $home_consumption_wh_after_dark_using_shellyem * 0.001, 3);
              // calculate SOC percentage discharge
              $soc_percentage_discharge = $home_consumption_kwh_after_dark_using_shellyem / $SOC_capacity_KWH * 100;
              // round it to 3 decimal places for accuracy of arithmatic for accumulation
              $soc_percentage_now_using_dark_shelly = round( $soc_percentage_after_dark - $soc_percentage_discharge, 5);

              // check the validity of the SOC using this after dark shelly method
              $soc_after_dark_update_valid =  $soc_percentage_now_using_dark_shelly < 100 &&
                                              $soc_percentage_now_using_dark_shelly > 30;

              // update SOC only if values are reasonable
              if ( $soc_after_dark_update_valid === true )
              {
                // uValid values, pdate values to get differentials for next cycl. Word STUDER is legacy, nothing to do with Studer
                update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $soc_percentage_now_using_dark_shelly);
                update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $present_home_wh_reading);

                // update the readings object for transient and display
                $shelly_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;

                if ($studer_readings_obj)
                { // not sure if this is needed TODO check
                  $studer_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;
                }

                $this->verbose ? error_log("SOC % using after dark Shelly: $soc_percentage_now_using_dark_shelly"): false;
              }
              else
              {
                error_log("SOC using after dark Shelly not updated due to bad value: $soc_percentage_now_using_dark_shelly");
                error_log("shelly_energy_counter value NOW: $present_home_wh_reading");
              }
            }

            // check the validity of the SOC using this after dark shelly method. We need to repeat since could have come from top branch
            $soc_after_dark_update_valid =  $soc_percentage_now_using_dark_shelly < 100 &&
                                            $soc_percentage_now_using_dark_shelly > 30;

            if ( $soc_after_dark_update_valid === true )
            {
              // set the switch tree conditions for this mode of update
              $LVDS = $soc_percentage_now_using_dark_shelly <= $soc_percentage_lvds_setting &&  // less than LVDS setting
                      $shelly_switch_status == "OFF"  &&
                      $control_shelly === true;                                         // Grid switch is OFF

              if ($LVDS)
              {
                error_log("LVDS using SOC after Dark using Shelly EM: $LVDS");
              }
            }
            else
            { // invalid Sdark OC value, use soc using shelly BM as fallback since soc dark seems invalid
              $LVDS = $soc_percentage_now_shelly <= $soc_percentage_lvds_setting &&  // less than LVDS setting
                      $shelly_switch_status == "OFF" ; 
                      
              if ($LVDS)
              {
                error_log("LVDS using SOC Shelly BM: $LVDS");
              }
            }

            $shelly_readings_obj->LVDS = $LVDS;

            $switch_override = false;
          }
        }       // end of flow for dark
        else
        {
          // not dark so reset the soc update after dark value so we can check for this
          update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', 0);
        }

        // common flow for dark and day

        $this->verbose ? error_log("SOC update method: " .  $soc_update_method): false;

        // we can now check to see if Studer midnight has happened for midnight rollover capture
        // Each time the following executes it looks at a transient. Only when it expires does an API call made on Studer for 5002
        $studer_time_just_passed_midnight = $this->is_studer_time_just_pass_midnight( $user_index, $wp_user_name );

        if ( $studer_time_just_passed_midnight )
        { // reset the shelly load energy counter to 0. Capture SOC value for beginning of day
          // If Grid is OFF, then Shelly Pro 3EM  will not respond to read the energy counter at midnight.
          // We need to keep this value as a transient and use it so that the last value is used as it is still valid

          error_log("Studer Clock just passed midnight-STUDER-SOC: $SOC_percentage_now ShellyBM SOC: $soc_percentage_now_shelly");
          error_log("SOC after Dark at midnight - $soc_percentage_now_using_dark_shelly");
          
          // 1st preference is given to SOC after dark for midnight update if that value at midnight is reasonable
          if (  $soc_update_method            === "shelly-after-dark"    && 
                $soc_after_dark_update_valid  === true  )
          {
            $soc_used_for_midnight_update = $soc_percentage_now_using_dark_shelly;
            error_log("1st preference SOC after Dark used for midnight update: $soc_percentage_now_using_dark_shelly");
          }
          elseif ( $SOC_percentage_now  > 30 && $SOC_percentage_now  < 100 && ( ! $studer_api_call_failed ) )
          { // 2nd preference is given to STUDER SOC if its SOC value at midnight is reasonable and available
            $soc_used_for_midnight_update = $SOC_percentage_now;
            error_log("2nd preference SOC STUDER used for midnight update: $SOC_percentage_now");
          }
          else
          {
            $soc_used_for_midnight_update = $soc_percentage_now_shelly;
            error_log("3rd preference SOC shelly BM used for midnight update: $soc_percentage_now_shelly");
          }

          // reset the SOC percentage at midnight for Studer to present value, This is SOC dark or SOC STUDER or Shelly
          update_user_meta( $wp_user_ID, 'soc_percentage', $soc_used_for_midnight_update );

          // reset the user meta SOC as calculated using Shelly BM to the present value
          update_user_meta( $wp_user_ID, 'shelly_soc_percentage_at_midnight', $soc_used_for_midnight_update );

          // reset the battery accumulated charge in AH to 0 at just past midnight.
          update_user_meta( $wp_user_ID, 'battery_accumulated_percent_since_midnight', 0.0001 );

          // reset midnighyt energy counter value for Red phase to present measured value, or from transient if Grid OFF
          update_user_meta( $wp_user_ID, 'grid_wh_counter_midnight', $home_grid_wh_counter_now );

          // Load energy consumed since midnight as measured by Shelly4PM reset to 0 at midnight
          update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', 0 );

          // reset midnight energy counter value for home load consumed to current measured value as measured by Shelly EM
          update_user_meta( $wp_user_ID, 'shelly_em_home_energy_counter_midnight', $present_home_wh_reading );

          // Reset the Studer Current based method of calculating SOC% to 0 at midnight.
          update_user_meta( $wp_user_ID, 'studer_current_based_soc_percentage_accumulated_since_midnight', 0.0001 );
        }

        $LVDS_soc_6am_grid_on = false;
        $LVDS_soc_6am_grid_off = false;

        
        {   // define all the conditions for the SWITCH - CASE tree except for LVDS that is done individually
            // note that $SOC_percentage_now needs to be defined properly depending on path taken
          // AC input voltage is being sensed by Studer even though switch status is OFF meaning manual MCB before Studer is ON
          // In this case, since grid is manually switched ON there is nothing we can do
          // Keep Grid Switch CLosed Untless Solar charges Battery to $soc_percentage_switch_release_setting - 5 or say 90%
          // So between this and switch_release_float_state battery may cycle up and down by 5 points
          // Ofcourse if the Psurplus is too much it will charge battery to 100% inspite of this.
          // Obviously after sunset the battery will remain at 90% till sunrise the next day

          $keep_switch_closed_always =  ( $shelly_switch_status == "OFF" )             &&
                                        ( $soc_update_method === "studer")             &&
                                        ( $keep_shelly_switch_closed_always == true )  &&
                                        ( $SOC_percentage_now <= ($soc_percentage_switch_release_setting - 5) )	&&  // OR SOC reached 90%
                                        ( $control_shelly == true );


          

          // switch release typically after RDBC when Psurplus is positive.
          $switch_release =  ( $soc_update_method === "studer" )                              &&
                             ( $SOC_percentage_now >= ( $soc_percentage_lvds_setting + 0.3 ) ) &&  // SOC ?= LBDS + offset
                             ( $shelly_switch_status == "ON" )  														  &&  // Switch is ON now
                             ( $surplus >= $min_solar_surplus_for_switch_release_after_rdbc ) &&  // Solar surplus is >= 0.2KW
                             ( $keep_shelly_switch_closed_always == false )                   &&	// Emergency flag is False
                             ( $control_shelly == true );                                         // only for studer updated                           



          // This is needed when RDBC or always ON was triggered and Psolar is charging battery beyond 95%
          // independent of keep_shelly_switch_closed_always flag status
          $switch_release_float_state	= ( $shelly_switch_status == "ON" )  							  &&  // Switch is ON now
                                        ( $soc_update_method === "studer")                &&
                                        ( $SOC_percentage_now >= $soc_percentage_switch_release_setting )	&&  // OR SOC reached 95%
                                        // ( $keep_shelly_switch_closed_always == false )  &&  // Always ON flag is OFF
                                        ( $control_shelly == true );
        }

        if ( $soc_update_method === "studer" )
        {   // write back new values to the studer_readings_obj
          $studer_readings_obj->battery_voltage_avg               = $battery_voltage_avg;

          $studer_readings_obj->control_shelly                    = $control_shelly;
          $studer_readings_obj->shelly_switch_status              = $shelly_switch_status;
          $studer_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;
          $studer_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
          $studer_readings_obj->shelly_switch_acin_details_arr    = $shelly_switch_acin_details_arr;


          $studer_readings_obj->switch_release                    = $switch_release;

          $studer_readings_obj->switch_release_float_state        = $switch_release_float_state;
          
          $studer_readings_obj->cloudiness_average_percentage_weighted  = $cloudiness_average_percentage_weighted;
          $studer_readings_obj->est_solar_kw_arr  = round( array_sum($est_solar_kw_arr), 1);

          $studer_readings_obj->shelly_water_heater_data          = $shelly_water_heater_data;

          $note_exit = "Studer";
        }
        elseif ( $soc_update_method === "shelly" )
        {   // write back to shelly_readings_obj object
          $note_exit = "Shelly";

          // define conditions to false, these are unused by shelly update but checked downstream
          $LVDS_soc_6am_grid_on   = false;
          $LVDS_soc_6am_grid_off  = false;
          $switch_override  = false;
          $LVDS             = false;


          $shelly_readings_obj->battery_voltage_avg  = get_transient( $wp_user_name . '_' . 'battery_voltage_avg' ) ?? 49.8;

          $shelly_readings_obj->control_shelly                    = $control_shelly;
          $shelly_readings_obj->shelly_switch_status              = $shelly_switch_status;
          $shelly_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;
          $shelly_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
          $shelly_readings_obj->shelly_switch_acin_details_arr    = $shelly_switch_acin_details_arr;

          $pbattery_kw =  round( 49.8 * 0.001 * $shelly_battery_measurement_object->battery_amps, 3 );

          $shelly_readings_obj->battery_charge_adc = $shelly_battery_measurement_object->battery_amps;
          $shelly_readings_obj->pbattery_kw = $pbattery_kw;
          $shelly_readings_obj->grid_pin_ac_kw = $home_grid_kw_power;
          $shelly_readings_obj->grid_input_vac = $shelly_api_device_status_voltage;

          // Since we calculate Psolar indirectly, that depends on conditions as below
          if ($it_is_still_dark)
          {
            $shelly_readings_obj->psolar_kw = 0;
          }
          elseif ( $shelly_switch_status == "OFF" && ! $it_is_still_dark )
          {
            $shelly_readings_obj->psolar_kw = ($pbattery_kw + 1.07 * $shelly_readings_obj->power_total_to_home_kw) / 0.96;
          }
          elseif ( $shelly_switch_status == "ON" && ! $it_is_still_dark )
          {
            $shelly_readings_obj->psolar_kw = ($pbattery_kw ) / 0.96;
          }
          
        }
        elseif ( $soc_update_method === "shelly-after-dark" )
        {   // write back to shelly_readings_obj object
          $note_exit = "Shelly-after-dark";

          // define conditions to false, these are unused by shelly update but checked downstream
          $LVDS_soc_6am_grid_on   = false;
          $LVDS_soc_6am_grid_off  = false;
          // $switch_override already defined as False ahead
          // $LVDS already defined in terms of conditionals above


          $shelly_readings_obj->battery_voltage_avg  = get_transient( $wp_user_name . '_' . 'battery_voltage_avg' ) ?? 49.8;

          $shelly_readings_obj->control_shelly                    = $control_shelly;
          $shelly_readings_obj->shelly_switch_status              = $shelly_switch_status;
          $shelly_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;
          $shelly_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
          $shelly_readings_obj->shelly_switch_acin_details_arr    = $shelly_switch_acin_details_arr;

          $pbattery_kw =  round( 49.8 * 0.001 * $shelly_battery_measurement_object->battery_amps, 3 );

          // This is looked for to get battery urrent for display later on and the name is same for Studer or Shelly BM
          $shelly_readings_obj->battery_charge_adc = $shelly_battery_measurement_object->battery_amps;

          $shelly_readings_obj->pbattery_kw = $pbattery_kw;
          $shelly_readings_obj->grid_pin_ac_kw = $home_grid_kw_power;
          $shelly_readings_obj->grid_input_vac = $shelly_api_device_status_voltage;

          // Since we calculate Psolar indirectly, that depends on conditions as below
          if ($it_is_still_dark)
          {
            $shelly_readings_obj->psolar_kw = 0;
          }
          elseif ( $shelly_switch_status == "OFF" && ! $it_is_still_dark )
          {
            $shelly_readings_obj->psolar_kw = ($pbattery_kw + 1.07 * $shelly_readings_obj->power_total_to_home_kw) / 0.96;
          }
          elseif ( $shelly_switch_status == "ON" && ! $it_is_still_dark )
          {
            $shelly_readings_obj->psolar_kw = ($pbattery_kw ) / 0.96;
          }
          
        }
        
        switch(true)
        {   // switch conditional tree
            // if Shelly switch is OPEN but Studer transfer relay is closed and Studer AC voltage is present
            // it means that the ACIN is manually overridden at control panel
            // so ignore attempting any control and skip this user
            case (  $switch_override ):
                  // ignore this state
                  $this->verbose ? error_log("MCB Switch Override - NO ACTION)") : false;
                  $cron_exit_condition = "Manual Switch Override";
            break;


            // <1> If switch is OPEN AND running average Battery voltage from 5 readings is lower than limit
            //      AND control_shelly = TRUE. Note that a valid config and do_shelly user meta need to be TRUE.
            case ( $LVDS ):

                $response = $this->turn_on_off_shelly_switch($user_index, "on", 'shelly_device_id_acin');

                error_log("LVDS - Grid ON.  SOC: " . $SOC_percentage_now . " % and Vbatt(V): " . $battery_voltage_avg);
                $cron_exit_condition = "Low SOC - Grid ON ";

                if ( $response->isok )
                {
                  $notification_title = "LVDS";
                  $notification_message = "LVDS Grid ON SOC " . $SOC_percentage_now . "%";
                  $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                      $webpushrKey, $webpushrAuthToken  );
                }
                else 
                {
                  error_log("Grid Switch ON/OFF problem: " . print_r($response, true) );
                }
                
            break;


            // <3> If switch is OFF, Grid is present and the keep shelly closed always is TRUE then close the switch
            case ( $keep_switch_closed_always ):

                $response = $this->turn_on_off_shelly_switch($user_index, "on", 'shelly_device_id_acin');

                error_log("Exited via Case 3 - keep switch closed always - Grid Switched ON");
                $cron_exit_condition = "Grid ON always ";

                if ( $response->isok )
                {
                  $notification_title = "KSCA - Switch ON";
                  $notification_message = "SOC " . $SOC_percentage_now . "%";
                  $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                      $webpushrKey, $webpushrAuthToken  );
                }
                else 
                {
                  error_log("Grid Switch ON/OFF problem: " . print_r($response, true) );
                }

                
            break;



            // <5> Release - Switch OFF for normal Studer operation
            case ( $switch_release ):

                $response = $this->turn_on_off_shelly_switch($user_index, "off", 'shelly_device_id_acin');

                error_log("Exited via Case 5 - adequate Battery SOC, Grid Switched OFF");
                $cron_exit_condition = "SOC ok - Grid Off ";

                if ( $response->isok )
                {
                  $notification_title = "SOC OK Grid Off";
                  $notification_message = "SOC " . $SOC_percentage_now . "%";
                  $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                      $webpushrKey, $webpushrAuthToken  );
                }
                else 
                {
                  error_log("Grid Switch ON/OFF problem: " . print_r($response, true) );
                }
                
            break;


            case ( $switch_release_float_state ):

                $response = $this->turn_on_off_shelly_switch($user_index, "off", 'shelly_device_id_acin');

                if ( $keep_shelly_switch_closed_always ) 
                {
                  update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always' , false);
                }

                error_log("Exited via Case 8 - Battery Float State, Grid switched OFF");
                $cron_exit_condition = "SOC Float-Grid Off ";

                if ( $response->isok )
                {
                  $notification_title = "KSCA Float Switch OFF";
                  $notification_message = "SOC " . $SOC_percentage_now . "%";
                  $this->send_webpushr_notification(  $notification_title, $notification_message, $webpushr_subscriber_id, 
                                                      $webpushrKey, $webpushrAuthToken  );
                }
                else 
                {
                  error_log("Grid Switch ON/OFF problem: " . print_r($response, true) );
                }
                
            break;


            default:
                
                $this->verbose ? error_log('Exited via Case Default, NO ACTION TAKEN') : false;
                $cron_exit_condition = "No Action ";
                
            break;

        }   // end witch statement

        // set transient. This will be read in by prepare data to load appropriate transient object
        set_transient( $wp_user_name . '_' . 'soc_update_method', $soc_update_method, 30 * 60 );

        $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

        $array_for_json = [ 'unixdatetime'        => $now->getTimestamp() ,
                            'cron_exit_condition' => $cron_exit_condition ,
                          ];

        // save the data in a transient indexed by the user name. Expiration is 5 minutes. Object depends on whether shelly or Studer
        // Update the user meta with the CRON exit condition only for definite ACtion not for No Action
        if ($cron_exit_condition !== "No Action ") 
          {
              update_user_meta( $wp_user_ID, 'studer_readings_object',  json_encode( $array_for_json ) );
          }
        
        // return object based on mode of update whetehr Studer or Shelly. For Studer case only, also apply 100% clamp
        if ( $soc_update_method === "studer" )
        {  // SOC is updated by Studer
          set_transient( $wp_user_name . '_' . 'studer_readings_object', $studer_readings_obj, 5*60 );

          if (  $SOC_percentage_now > 100.0 || $battery_voltage_avg  >=  $battery_voltage_avg_float_setting )
          {
            // Since we know that the battery SOC is 100%, reset the SOC at midnight since we cannot change the Studer day accumulation values
            $SOC_percentage_beg_of_day_recal = 100 - $SOC_batt_charge_net_percent_today;

            // reset the STUDER SOC at midnight to clamp calculated value above
            update_user_meta( $wp_user_ID, 'soc_percentage', $SOC_percentage_beg_of_day_recal);
            // we equalize the Shelly based midnight SOC value to the STuder's midnight SOC value
            update_user_meta( $wp_user_ID, 'shelly_soc_percentage_at_midnight',           $SOC_percentage_beg_of_day_recal);

            // Since the Shelly BM uses the shelly_soc_percentage_at_midnight and battery_accumulated_percent_since_midnight
            // we need to also reset the battery_accumulated_percent_since_midnight based on rest value above and 100% present SOC
            update_user_meta( $wp_user_ID, 'battery_accumulated_percent_since_midnight',  $SOC_batt_charge_net_percent_today );

            // reset the studer current based accumulated SOC percent since midnight
            $studer_current_based_soc_percentage_accumulated_since_midnight_recal = 100 - $studer_current_based_soc_percentage_accumulated_since_midnight;

            update_user_meta( $wp_user_ID, 'studer_current_based_soc_percentage_accumulated_since_midnight', 
                                           $studer_current_based_soc_percentage_accumulated_since_midnight_recal );
            
            $this->verbose ? error_log("SOC 100% clamp for STuder and Shelly BM applied") : false;
          }
          return $studer_readings_obj;
        }
        elseif ( $soc_update_method === "shelly" )
        { // SOC updates from Shelly Uni, Shelly EM and Shelly Pro day time measurements

          if (  $soc_percentage_now_shelly > 100.2 )
          {
            // Since we know that the battery SOC is 100%, calculate the battery accumulated anew since we want to keep midnioght SOC fixed
            $battery_accumulated_percent_since_midnight_recal = 100 - $shelly_soc_percentage_at_midnight;
            
            update_user_meta( $wp_user_ID, 'battery_accumulated_percent_since_midnight', $battery_accumulated_percent_since_midnight_recal );
            
            $this->verbose ? error_log("SOC 100% clamp for Shelly BM only, applied") : false;
          }

          set_transient( $wp_user_name . '_' . 'shelly_readings_obj', $shelly_readings_obj, 5*60 );

          // We only apply 100% clamp based on Studer values or the battery voltage of Studer reading
          return $shelly_readings_obj;
        }
        elseif ( $soc_update_method === "shelly-after-dark" )
        { // SOC updates from Shelly Uni, Shelly EM and Shelly Pro day time measurements

          set_transient( $wp_user_name . '_' . 'shelly_readings_obj', $shelly_readings_obj, 5*60 );

          // We only apply 100% clamp based on Studer values or the battery voltage of Studer reading
          return $shelly_readings_obj;
        }
    }



    /**
     *  This routine will publish the updated flag values via mqtt.
     *  It is upto the local server to subscribe to this topic and get the values
     */
    public function push_flag_changes_to_local_server( int $user_index, int $wp_user_ID, object $flag_object )
    {
      $config = $this->config;

      $message = json_encode( $flag_object );

      $topic = $config['accounts'][$user_index]['topic_flag_from_remote'];

      $mqtt_ch = new my_mqtt();

      // publish message to remote mqtt broker using TLS even if on same server. set retain messages to be true
      // we retain messages such that asynchronous subscription will still get the message even if it is sent only once.
      // if the message is ressent with new data, that new data will take the place of the latest retained data.
      $mqtt_ch->mqtt_pub_remote_qos_0( $topic, $message, true );
    }


    /**
     * 
     */
    public function get_mqtt_data_from_from_linux_home_desktop( int $user_index )
    {
      $config = $this->config ?? $this->get_config();

      // This is the pre-defined topic
      $topic = $config['accounts'][$user_index]['topic'];

      $mqtt_ch = new my_mqtt();

      $mqtt_ch->mqtt_sub_remote_qos_0( $topic );

      // The above is blocking till it gets a message or timeout.
      $json_string = $mqtt_ch->message;

      // Check that the message is not empty
      if (! empty( $json_string ))
      {
        $object_from_linux_home_desktop = json_decode($json_string);

        if ($object_from_linux_home_desktop === null) 
        {
          error_log( 'Error parsing JSON from MQTT studerxcomlan: '. json_last_error_msg() );
        }
        elseif( json_last_error() === JSON_ERROR_NONE )
        {
          return $object_from_linux_home_desktop;
        }
        else
        {
          error_log( 'Error parsing JSON from MQTT studerxcomlan: '. json_last_error_msg() );
        }
      }
      else
      {
        error_log( "JSON string from mqtt subscription of scomlan via cron shell exec is empty");
      }
    }



    /**
     * 
     */
    public function send_webpushr_notification( $notification_title, $notificaion_message, $webpushr_subscriber_id, 
                                            $webpushrKey, $webpushrAuthToken )
    {
      $end_point = 'https://api.webpushr.com/v1/notification/send/sid';

      $http_header = array( 
          "Content-Type: Application/Json", 
          "webpushrKey: $webpushrKey", 
          "webpushrAuthToken: $webpushrAuthToken"
      );

      $req_data = array(
          'title' 		  => $notification_title,             //required
          'message' 		=> $notificaion_message,            //required
          'target_url'	=> 'https://avasarala.in/mysolar',  //required
          'sid'         => $webpushr_subscriber_id,         //required
          'auto_hide'	  => 0,                               //optional message displayed till user reads it
          'expire_push'	=> '5m',                            //optional if user not online message expires after this time
        //following parameters are optional
        //'name'		=> 'Test campaign',
        //'icon'		=> 'https://cdn.webpushr.com/siteassets/wSxoND3TTb.png',
        //'image'		=> 'https://cdn.webpushr.com/siteassets/aRB18p3VAZ.jpeg',
        //'auto_hide'	=> 1,
        //'expire_push'	=> '5m',
        //'send_at'		=> '2022-01-04 11:01 +5:30',
        //'action_buttons'=> array(	
            //array('title'=> 'Demo', 'url' => 'https://www.webpushr.com/demo'),
            //array('title'=> 'Rates', 'url' => 'https://www.webpushr.com/pricing')
        //)
      );

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_HTTPHEADER, $http_header);
      curl_setopt($ch, CURLOPT_URL, $end_point );
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($req_data) );
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      $response = curl_exec($ch);
      $response_json = json_decode( $response );

      if ( $response_json->status != "success" )
      {
        error_log( 'webpushr notification failed, see returned contents below');
        error_log( $response );
      }
    }


    /**
     *  Ninja form data is checked for proper limits.
     *  If data is changed the corresponding user meta is updated to trhe new form data.
     *  In the case here of remote WP being a shadow of the local WP any changes to some fields will be sent to the Local WP
     */
    public function my_ninja_forms_after_submission( $form_data )
    {
      if ( 2 != $form_data['form_id'] ) 
      {
        error_log("returning from post submission due to form id not matching");
        return; // we don;t casre about any form except form with id=2
      }

      $wp_user_ID = get_current_user_id();

      $do_soc_cal_now = false;    // initialize variable

      if (false !== get_transient( $wp_user_ID . 'user_meta_defaults_arr'))
      {
        // Valid transient aretrieved so proceed to use it
        $defaults_arr = get_transient( $wp_user_ID . 'user_meta_defaults_arr');
      }
      else
      {
        // transient does not exist so exit so abort
        error_log("Could not retrieve transient data for defaults array for settings, aborting without user meta updates");
        return;
      }

      // initialize object to be sent to the local WP to reflect updates here
      $settings_obj_to_local_wp = new stdClass;

      $defaults_arr_keys    = array_keys($defaults_arr);       // get all the keys in numerically indexed array
      
      $defaults_arr_values  = array_values($defaults_arr);    // get all the rows in a numerically indexed array
      

      foreach( $form_data[ 'fields' ] as $field ): 

        switch ( true ):
        

          case ( stripos( $field[ 'key' ], 'keep_shelly_switch_closed_always' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', $submitted_field_value);

              $settings_obj_to_local_wp->keep_shelly_switch_closed_always =  $submitted_field_value;

              error_log( "Updated User Meta - keep_shelly_switch_closed_always - from Settings Form: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'pump_duration_control' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "pump_duration_control",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'pump_duration_control', $submitted_field_value);

              error_log( "Updated User Meta - pump_duration_control - from Settings Form: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'do_minutely_updates' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "do_minutely_updates",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'do_minutely_updates', $submitted_field_value);

              error_log( "Updated User Meta - do_minutely_updates - from Settings Form: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'do_shelly' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "do_shelly",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'do_shelly', $submitted_field_value);

              $settings_obj_to_local_wp->do_shelly =  $submitted_field_value;

              error_log( "Updated User Meta - do_shelly - from Settings Form: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'do_soc_cal_now' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $do_soc_cal_now = true;
            }
            else 
            {
              $do_soc_cal_now = false;
            }
            // Set the $this object for this flag
            $this->do_soc_cal_now_arr[$wp_user_ID] = $do_soc_cal_now;
          break;



          case ( stripos( $field[ 'key' ], 'soc_percentage_now' ) !== false ):
            $defaults_key = array_search('soc_percentage_now', $defaults_arr_keys); // get the index of desired row in array
            $defaults_row = $defaults_arr_values[$defaults_key];

            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              $soc_percentage_now_for_cal = $field[ 'value' ];
            }
          break;



          case ( stripos( $field[ 'key' ], 'battery_voltage_avg_lvds_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'battery_voltage_avg_lvds_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          
          case ( stripos( $field[ 'key' ], 'soc_percentage_lvds_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soc_percentage_lvds_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );

                $settings_obj_to_local_wp->soc_percentage_lvds_setting =  $field[ 'value' ];
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'soh_percentage_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soh_percentage_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;




          case ( stripos( $field[ 'key' ], 'soc_percentage_rdbc_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soc_percentage_rdbc_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'soc_percentage_switch_release_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'soc_percentage_switch_release_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );

                $settings_obj_to_local_wp->soc_percentage_switch_release_setting =  $field[ 'value' ];
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'min_soc_percentage_for_switch_release_after_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'min_soc_percentage_for_switch_release_after_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'min_solar_surplus_for_switch_release_after_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'min_solar_surplus_for_switch_release_after_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'battery_voltage_avg_float_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'battery_voltage_avg_float_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );

                $settings_obj_to_local_wp->average_battery_float_voltage =  $field[ 'value' ];
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'acin_min_voltage_for_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'acin_min_voltage_for_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'acin_max_voltage_for_rdbc' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'acin_max_voltage_for_rdbc';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'psolar_surplus_for_rdbc_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'psolar_surplus_for_rdbc_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;



          case ( stripos( $field[ 'key' ], 'psolar_min_for_rdbc_setting' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'psolar_min_for_rdbc_setting';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'pump_duration_secs_max' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'pump_duration_secs_max';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'pump_power_restart_interval_secs' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'pump_power_restart_interval_secs';

            // look for the defaults using the user meta key
            $defaults_key = array_search($user_meta_key, $defaults_arr_keys); // get the index of desired row in defaults array
            $defaults_row = $defaults_arr_values[$defaults_key];
            // validate user input
            if ( $field[ 'value' ] >= $defaults_row['lower_limit'] && $field[ 'value' ] <= $defaults_row['upper_limit'] )
            {
              // get the existing user meta value
              $existing_user_meta_value = get_user_meta($wp_user_ID, $user_meta_key,  true);

              // update the user meta with this value if different from existing value only
              if ($existing_user_meta_value != $field[ 'value' ])
              {
                update_user_meta( $wp_user_ID, $user_meta_key, $field[ 'value' ] );
                error_log( "Updated User Meta - " . $user_meta_key . " - from Settings Form: " . $field[ 'value' ] );
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;

        endswitch;       // end of switch

      endforeach;        // end of foreach

      // before we leave as we would normally for a standalone control site since this is a shadow remote site we mqtt pub the updates
      if ( ! empty($settings_obj_to_local_wp ) ) $this->push_flag_changes_to_local_server( 0, $wp_user_ID, $settings_obj_to_local_wp );

    } // end function

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
          return $cloud_cover_percentage;
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
     *  Takes the average of the battery values stored in the array, independent of its size
     *  @preturn float:$battery_avg_voltage
     */
    public function get_battery_voltage_avg( string $wp_user_name, float $new_battery_voltage_reading ): ? float
    {
        // Load the voltage array that might have been pushed into transient space
        $bv_arr_transient = get_transient( $wp_user_name . '_' . 'bv_avg_arr' ); 

        // If transient doesnt exist rebuild
        if ( ! is_array($bv_arr_transient))
        {
          $bv_avg_arr = [];
        }
        else
        {
          // it exists so populate
          $bv_avg_arr = $bv_arr_transient;
        }
        
        // push the new voltage reading to the holding array
        array_push( $bv_avg_arr, $new_battery_voltage_reading );

        // If the array has more than 3 elements then drop the earliest one
        // We are averaging for only 3 minutes
        if ( sizeof($bv_avg_arr) > 3 )  {   // drop the earliest reading
            array_shift($bv_avg_arr);
        }
        // Write it to this object for access elsewhere easily
        $this->bv_avg_arr = $bv_avg_arr;

        // Setup transiet to keep previous state for averaging
        set_transient( $wp_user_name . '_' . 'bv_avg_arr', $bv_avg_arr, 5*60 );


        $count  = 0.00001;    // prevent division by 0 error
        $sum    = 0;
        foreach ($bv_avg_arr as $key => $bv_reading)
        {
           if ($bv_reading > 46.0)
           {
              // average all values that are meaningful
              $sum    +=  $bv_reading;
              $count  +=  1;
           }
        }
        unset($bv_reading);

        $battery_avg_voltage = round( $sum / $count, 2);

        return $battery_avg_voltage;
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
    }


    /**
     * 
     */
    public function view_grid_values_page_render()
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

      // read in the object from transient if it exists process the grid values for display
      // get grid voltage processed object
      $grid_obj = $this->get_grid_voltage_data_from_obj_in_transient();

      $time_formatted_string      = $grid_obj->time_formatted_string;

      $a_phase_grid_voltage_html  = $grid_obj->a_phase_grid_voltage_html;
      $b_phase_grid_voltage_html  = $grid_obj->b_phase_grid_voltage_html;
      $c_phase_grid_voltage_html  = $grid_obj->c_phase_grid_voltage_html;

      $phase_voltage_peak_percentage_array  = $grid_obj->phase_voltage_peak_percentage_array;
      

      // define all the icon styles and colors based on STuder and Switch values
      $output .= '<div id="my-desscription"><h3>'. '3P AC voltages at FB feeder'     . '</h3></div>';
      $output .= '
      <table id="my-grid-voltage-readings-table">
          <tr>
              <th>'   . 'Status'  . '</th>
              <th>'   . 'Red'     . '</th>  
              <th>'   . 'Yellow'  . '</th>
              <th>'   . 'Blue'    . '</th>
          </tr>
          <tr>
              <td id="time_formatted_string">'   . 'Time in this Grid Status: ' . $time_formatted_string       . '</td>
              <td id="a_phase_grid_voltage">'    . $a_phase_grid_voltage_html   . '</td>
              <td id="b_phase_grid_voltage">'    . $b_phase_grid_voltage_html   . '</td>
              <td id="c_phase_grid_voltage">'    . $c_phase_grid_voltage_html   . '</td>
          </tr>
          <tr>
              <td id="voltage_peak_percent">'       . 'Voltage Variation Pk %'                  . '</td>
              <td id="a_phase_voltage_pk_percent">' . $phase_voltage_peak_percentage_array[0]   . '</td>
              <td id="b_phase_voltage_pk_percent">' . $phase_voltage_peak_percentage_array[1]   . '</td>
              <td id="c_phase_voltage_pk_percent">' . $phase_voltage_peak_percentage_array[2]   . '</td>
          </tr>

      </table>';
      $output .= '<div id="averaging-text">' . 'The time is since last grid status change. The calculation is over 20 readings over 5 mins' . '</div>';
      return $output;
    }



    /**
     *  responds to an AJAX call from JS timer for updating grid voltages on the grid page
     */
    public function ajax_my_grid_cron_update_handler()
    {
      // check if nonce is OK this is an AJAX call
      check_ajax_referer( 'my_grid_app_script' );

      // get grid voltages from transients if they exist
      $grid_obj = $this->get_grid_voltage_data_from_obj_in_transient();

      // send the object as a json string as server response to ajax call and die after
      wp_send_json($grid_obj);

      // die is implicit in wp_send_json
    }


    /**
     * 
     */
    public function grid_voltage_processing( $a, $b, $c )  : array
    {
      // Load the voltage array for each phase
      if ( false === ( $a_array = get_transient(  'a_array' ) ) )
      {
        $a_array = [];
      }
      if ( false === ( $b_array = get_transient(  'b_array' ) ) )
      {
        $b_array = [];
      }
      if ( false === ( $c_array = get_transient(  'c_array' ) ) )
      {
        $c_array = [];
      } 
      
      
      // push the new voltage reading to the holding array
      array_push( $a_array, $a );
      array_push( $b_array, $b );
      array_push( $c_array, $c );

      // If the array has more than 20 elements then drop the earliest one
      // We are averaging over 20 readings roughly 400s or about 5m
      if ( sizeof($a_array) > 20 )   array_shift($a_array);
      if ( sizeof($b_array) > 20 )   array_shift($b_array);
      if ( sizeof($c_array) > 20 )   array_shift($c_array);

      // Setup transiet to keep previous state for averaging
      set_transient( 'a_array', $a_array, 200 );
      set_transient( 'b_array', $b_array, 200 );
      set_transient( 'c_array', $c_array, 200 );

      // get average value of Red phase array
      $a_array = array_filter($a_array,  fn($n) => $n > 10 );
      if(count($a_array)) 
      {
         $a_average = array_sum($a_array) / count($a_array);
      } 

      // get average value of Yellow Phase array
      $b_array = array_filter($b_array, fn($n) => $n > 10);
      if(count($b_array)) 
      {
         $b_average = array_sum($b_array) / count($b_array);
      }
      
      // get average of Blue phase array values
      // get average value of Yellow Phase array
      $c_array = array_filter($c_array, fn($n) => $n > 10);
      if(count($c_array)) 
      {
         $c_average = array_sum($c_array) / count($c_array);
      }

      if ( ! empty( $a_average ) )
      {
        $a_peak_percentage = round( ( max( $a_array ) - min( $a_array ) ) / $a_average * 100, 2);
      }
      else
      {
        $a_peak_percentage = 0;
      }

      if ( ! empty( $b_average ) )
      {
        $b_peak_percentage = round( ( max( $b_array ) - min( $b_array ) ) / $b_average * 100, 2);
      }
      else
      {
        $b_peak_percentage = 0;
      }


      if ( ! empty( $c_average ) )
      {
        $c_peak_percentage = round( ( max( $c_array ) - min( $c_array ) ) / $c_average * 100, 2);
      }
      else
      {
        $c_peak_percentage = 0;
      }

      // error_log("Average Phase Voltages: $a_average, $b_average, $c_average");
      
      return [ $a_peak_percentage, $b_peak_percentage, $c_peak_percentage ];
    }





    /**
     *  This function defined the shortcode to a page called mysolar that renders a user's solar system readings
     *  The HTML is created in a string variable and returned as is typical of a shortcode function
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
        $do_shelly  = get_user_meta($wp_user_ID, "do_shelly", true) ?? false;

        // get the data by executing the cron loop by force once
        $readings_obj  = $this->get_readings_and_servo_grid_switch($user_index, $wp_user_ID, $wp_user_name, $do_shelly);

        $it_is_still_dark = $this->nowIsWithinTimeLimits( "18:55", "23:59:59" ) || $this->nowIsWithinTimeLimits( "00:00", "06:30" );

        // check for valid studer values. Return if empty.
        if( empty(  $readings_obj ) )
        {
          $output .= "Could not get valid data from home server using mqtt";

          return $output;
        }

        // get the format of all the information for the table in the page
        $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $readings_obj );

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
                <td id="battery_status_icon">'. $format_object->battery_status_icon     . '</td>
                <td></td>
                <td id="soc_percentage_now">'. $format_object->soc_percentage_now_html  . '</td>
                <td></td>
                <td id="load_icon">'          . $format_object->load_icon               . '</td>
            </tr>
            
        </table>';

        $output .= '
        <table id="my-load-distribution-table">
            <tr>
                <td id="water_heater_icon">'  . $format_object->water_heater_icon  . '</td>
                <td id="ev_charge_icon">'     . $format_object->ev_charge_icon     .'</td>
                <td id="ac_icon">'            . $format_object->ac_icon            . '</td>
                <td id="wall_charge_icon">'   . $format_object->wall_charge_icon   . '</td>
                <td id="pump_icon">'          . $format_object->pump_icon          . '</td>
            </tr>
            <tr>
                <td id="shelly_water_heater_kw">' . $format_object->shelly_water_heater_kw    . '</td>
                <td id="car_charger_grid_kw_power">'     . $format_object->car_charger_grid_kw_power        .'</td>
                <td id="power_to_ac_kw">'   . $format_object->power_to_ac_kw      . '</td>
                <td id="wallcharger_grid_kw_power">'   . $format_object->wallcharger_grid_kw_power      .'</td>
                <td id="power_to_pump_kw">' . $format_object->power_to_pump_kw    . '</td>
            </tr>
            
        </table>';


        $output .= '<div id="cron_exit_condition">'. $format_object->status     . '</div>';

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
                <input type="submit" name="button" 	value="get_shelly_device_status_homepwr"/>
                <input type="submit" name="button" 	value="check_if_soc_after_dark_happened"/>
                <input type="submit" name="button" 	value="get_studer_clock_offset"/>
                <input type="submit" name="button" 	value="get_shelly_battery_measurement"/>
            </form>


        <?php

        $config_index = sanitize_text_field( $_POST['config_index'] );
        $button_text  = sanitize_text_field( $_POST['button'] );
        

        // force a config run since we may be starting from the middle.
        $this->get_config();

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

                print_r( $shelly_api_device_status_ON );
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

            

            case "check_if_cloudy_day":
              $cloudiness_forecast= $this->check_if_forecast_is_cloudy();

              $it_is_a_cloudy_day = $cloudiness_forecast->it_is_a_cloudy_day;

              $cloud_cover_percentage = $cloudiness_forecast->cloudiness_average_percentage;

              echo "<pre>" . "Is it a cloudy day?: " .    $it_is_a_cloudy_day . "</pre>";
              echo "<pre>" . "Average CLoudiness percentage?: " .    $cloud_cover_percentage . "%</pre>";
            break;

            case "get_all_usermeta":

              $wp_user_ID = $this->get_wp_user_from_user_index( $config_index )->ID;
              $all_usermeta = $this->get_all_usermeta( $config_index, $wp_user_ID )['do_shelly'];

              print_r( $all_usermeta );
            break;

            case "shelly_status_acin":

              $wp_user_ID = $this->get_wp_user_from_user_index( $config_index )->ID;

              print_r( $this->get_shelly_switch_acin_details($config_index) );
            break;

            case "get_shelly_device_status_homepwr":

              print_r( $this->get_shelly_device_status_homepwr($config_index) );
            break;

            

            case "get_studer_clock_offset":

              $studer_time_offset_in_mins_lagging = $this->get_studer_clock_offset( $config_index );

              print( "Studer time offset in mins lagging = " . $studer_time_offset_in_mins_lagging);
              
            break;

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
          $transindus_lat_long_array = [$this->lat, $this->lon];

          $solar_calc = new solar_calculation($panel_set, $transindus_lat_long_array, $this->utc_offset);

          $est_solar_kw_arr[$key] =  round($solar_calc->est_power(), 2);
        }

        $est_solar_obj->est_solar_total_kw = array_sum( $est_solar_kw_arr );

        $est_solar_obj->est_solar_kw_arr =  $est_solar_kw_arr;

        // ensure that division by 0 doesn not happen
        if ( $est_solar_kw_arr[1] > 0 )
        {

          $total_to_west_panel_ratio = $est_solar_obj->est_solar_total_kw / $est_solar_kw_arr[1];
        }

        if ( $total_to_west_panel_ratio > 10 ) $total_to_west_panel_ratio = 10;

        $est_solar_obj->total_to_west_panel_ratio =  $total_to_west_panel_ratio;

        $est_solar_obj->sunrise =  $solar_calc->sunrise();
        $est_solar_obj->sunset  =  $solar_calc->sunset();

        $est_solar_obj->time_correction_factor  = $solar_calc->time_correction_factor;
        $est_solar_obj->hra_degs                = $solar_calc->hra_degs;
        $est_solar_obj->sun_azimuth_deg         = $solar_calc->sun_azimuth_deg;
        $est_solar_obj->sun_elevation_deg       = $solar_calc->sun_elevation_deg;
        $est_solar_obj->declination_deg         = $solar_calc->declination_deg;
        $est_solar_obj->lat_deg                 = $solar_calc->lat_deg;
        $est_solar_obj->long_deg                = $solar_calc->long_deg;
        $est_solar_obj->zenith_theta_s_deg      = $solar_calc->zenith_theta_s_deg;

        return $est_solar_obj;
    }


    /**
     *  @param int:$user_index
     *  @param string:$desired_state can be either 'on' or 'off' to turn on the switch to ON or OFF respectively
     *  @param string:$shelly_switch_name is the name of the switch used to index its shelly_device_id in the config array
     *  @return object:$shelly_device_data is the curl response stdclass object from Shelly device
     */
    public function turn_on_off_shelly_switch( int $user_index, string $desired_state, $shelly_switch_name = 'shelly_device_id_acin' ) : ? object
    {
      // Shelly API has a max request rate of 1 per second. So we wait 1s just in case we made a Shelly API call before coming here
        sleep (2);

        // get the config array from the object properties
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];

        // this is the device ID using index that is passed in defaults to 'shelly_device_id_acin'
        // other shelly 1PM names are: 'shelly_device_id_water_heater'
        $shelly_device_id   = $config['accounts'][$user_index][$shelly_switch_name];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response
        $shelly_device_data = $shelly_api->turn_on_off_shelly_switch( $desired_state );

        // True if API call was successful, False if not.
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

    public function get_shelly_device_status_acin(int $user_index): ? object
    {
        // get API and device ID from config based on user index
        $config = $this->config;
        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_device_data = $shelly_api->get_shelly_device_status();

        return $shelly_device_data;
    }


    public function get_shelly_device_status_water_heater(int $user_index): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;
      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_water_heater'];

      $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status();

      if ( is_null($shelly_api_device_response) ) 
          { // No response for Shelly water heater switch API call

            error_log("Shelly Water Heater Switch API call failed - Reason unknown");
            return null;
          }
          else 
          {  // Switch is ONLINE - Get its status, VOltage, and Power
              // switch ON is true OFF is false boolean variable
              $shelly_water_heater_status         = $shelly_api_device_response->data->device_status->{'switch:0'}->output;
              

              if ($shelly_water_heater_status)
              {
                  $shelly_water_heater_status_ON      = "ON";
                  $shelly_water_heater_voltage        = $shelly_api_device_response->data->device_status->{'switch:0'}->voltage;
                  $shelly_water_heater_current        = $shelly_api_device_response->data->device_status->{'switch:0'}->current;
                  $shelly_water_heater_w              = $shelly_api_device_response->data->device_status->{'switch:0'}->apower;
                  $shelly_water_heater_kw             = round( $shelly_water_heater_w * 0.001, 3);
              }
              else
              {
                  $shelly_water_heater_status_ON      = "OFF";
                  $shelly_water_heater_current        = 0;
                  $shelly_water_heater_kw             = 0;
              }
          }

          $shelly_water_heater_data =  new stdclass;
          $shelly_water_heater_data->shelly_water_heater_status     = $shelly_water_heater_status;
          $shelly_water_heater_data->shelly_water_heater_status_ON  = $shelly_water_heater_status_ON;
          $shelly_water_heater_data->shelly_water_heater_kw         = $shelly_water_heater_kw;
          $shelly_water_heater_data->shelly_water_heater_current    = $shelly_water_heater_current;

      return $shelly_water_heater_data;
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
                               "userRef"       =>  3078,   // Energy from Battery Today till now
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
                              "userRef"       =>  5002,   // Studer RCC date in Unix timestamp with UTC offset built in
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
              $grid_pin_ac_kw = round($user_value->value, 3);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 3);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

            case ( $user_value->reference == 3078 ) :
              $KWH_battery_today = round($user_value->value, 3);

            break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 3);

             break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 3);

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
              $psolar_kw += round($user_value->value, 3);

            break;

            case ( $user_value->reference == 3010 ) :
              $phase_battery_charge = $user_value->value;

            break;

            case ( $user_value->reference == 11011 ) :
               // we have to accumulate values form 2 cases so we have used accumulation below
               $psolar_kw_yesterday += round($user_value->value, 3);

             break;

            case ( $user_value->reference == 5002 ) :

              $studer_clock_unix_timestamp_with_utc_offset = $user_value->value;

            break;
          }
        }

        $solar_pv_adc = round($solar_pv_adc, 1);

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
        $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 3); //$psolar_kw - $pout_inverter_ac_kw;


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

      $studer_readings_obj->studer_clock_unix_timestamp_with_utc_offset = $studer_clock_unix_timestamp_with_utc_offset;

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
                              "userRef"       =>  3078,   // KWH today Energy discharged from Battery
                              "infoAssembly"  => "Master"
                            ),      
                      array(
                              "userRef"       =>  3081,   // KWH today Energy In from GRID
                              "infoAssembly"  => "Master"
                            ),
                      array(
                              "userRef"       =>  3083,   // KWH today Energy consumed by Load
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
                              "userRef"       =>  11007,   // KWHsol generated today till now, from VT1
                              "infoAssembly"  => "1"
                            ),
                      array(
                              "userRef"       =>  11007,   // KWHsol generated today till now, from VT2
                              "infoAssembly"  => "2"
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
        $KWH_solar_today = 0;


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
              $grid_pin_ac_kw = round($user_value->value, 3);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 3);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 3);

             break;

             case ( $user_value->reference == 3078 ) :
                $KWH_batt_discharged_today = round($user_value->value, 3);

            break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 3);

             break;

             case ( $user_value->reference == 3081 ) :
                $KWH_grid_today = round($user_value->value, 3);

            break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 3);

             break;

             case ( $user_value->reference == 3083 ) :
              $KWH_load_today = round($user_value->value, 3);

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
              $psolar_kw += round($user_value->value, 3);

            break;

            case ( $user_value->reference == 3010 ) :
              $phase_battery_charge = $user_value->value;

            break;

            case ( $user_value->reference == 11011 ) :
               // we have to accumulate values form 2 cases so we have used accumulation below
               $psolar_kw_yesterday += round($user_value->value, 3);

             break;

            case ( $user_value->reference == 11007 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $KWH_solar_today += round($user_value->value, 3);

            break;

          }
        }

        $solar_pv_adc = round($solar_pv_adc, 1);

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
        $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 3); //$psolar_kw - $pout_inverter_ac_kw;


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

      // update the object with Solar data read
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

      // Energy in KWH generated since midnight to now by Solar Panels
      $studer_readings_obj->KWH_solar_today    = $KWH_solar_today;

      $studer_readings_obj->KWH_grid_today    = $KWH_grid_today;

      $studer_readings_obj->KWH_load_today    = $KWH_load_today;

      $studer_readings_obj->KWH_batt_discharged_today    = $KWH_batt_discharged_today;

      return $studer_readings_obj;
    }

    /**
     *  service AJax Call for minutely cron updates to my solar page of website
     */
    public function ajax_my_solar_cron_update_handler()     
    {   // service AJax Call for minutely cron updates to my solar screen
        // The error log time stamp was showing as UTC so I added the below statement
      //

      // Ensures nonce is correct for security. The below function looks for _ajax_nonce in the data to check
      // default option is to stop the script if check fails builtin to function below.
      check_ajax_referer('my_solar_app_script');

      if ($_POST['data']) {   // extract data from POST sent by the Ajax Call and Sanitize
          
          $data = $_POST['data'];

          // get my user index knowing my login name
          $wp_user_ID   = $data['wp_user_ID'];

          // sanitize the POST data
          $wp_user_ID   = sanitize_text_field($wp_user_ID);
      }

      {    // get user_index based on user_name
        $ajax_user    = get_user_by('id', $wp_user_ID);
        $wp_user_name = $ajax_user->user_login;
        $user_index   = array_search( $wp_user_name, array_column($this->config['accounts'], 'wp_user_name')) ;

        if ( $user_index !=  0 )
        {
          // illegal user so die
          wp_send_json('Illegal User'); // dies after this
        }
        // error_log('from CRON Ajax Call: wp_user_ID:' . $wp_user_ID . ' user_index:'   . $user_index);
      }


      // get the transient related to this user ID that stores the latest Readingss - check if from Studer or Shelly
      // $it_is_still_dark = $this->nowIsWithinTimeLimits( "18:55", "23:59:59" ) || $this->nowIsWithinTimeLimits( "00:00", "06:30" );

      $shelly_readings_obj = get_transient( 'shelly_readings_obj' );

      // error_log(print_r($studer_readings_obj, true));

      if ($shelly_readings_obj) {   // transient exists so we can send it
          
          $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $shelly_readings_obj );

          // send JSON encoded data to client browser AJAX call and then die
          wp_send_json($format_object);
      }
      else {    // transient does not exist so send null
        wp_send_json(null);
      }
    }



    /**
     *  This AJAX handler server side function when power icon
     *  The function toggles the doShelly user meta and the keepalways user meta depending on received inputs
     */
    public function ajax_my_solar_update_handler()     
    {   // service Ajax Call
        // The error log time stamp was showing as UTC so I added the below statement
        //

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
                    $do_shelly = (bool) false;
                    update_user_meta( $wp_user_ID, "do_shelly", false);
                    break;

                case( ! $current_status_doShelly):            // If FALSE, update user meta to TRUE
                  $do_shelly = (bool) true;
                    update_user_meta( $wp_user_ID, "do_shelly", true);
                    break;
            }
        }


        if ($toggleGridSwitch)
        { // User has requested to toggle the GRID ON/OFF Shelly Switch
          // the current interpretation is that this is the toggle for the keep_always_on flag
          // Once the change if any happens the cron loop will determine grid switch, not here.
          // Find the current status and just toggle the status
          $current_state_keep_always_on =  (bool) get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always",  true);

          if ($current_state_keep_always_on === true)
          {
            $keep_shelly_switch_closed_always = (bool) false;

            update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', false);

            error_log('Changed keep always ON flag from true-->false due to Ajax Request');
          }
          else {
            $keep_shelly_switch_closed_always = (bool) true;

            update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', true);

            error_log('Changed keep always ON flag from false-->true due to Ajax Request');
          }
        }

          $flag_object = new stdClass;

          if ($toggleGridSwitch) $flag_object->keep_shelly_switch_closed_always = $keep_shelly_switch_closed_always;
          
          if ( $doShellyToggle ) $flag_object->do_shelly = $do_shelly;

          if ( ! empty($flag_object ) ) $this->push_flag_changes_to_local_server( 0, $wp_user_ID, $flag_object );

          // Grid ON/OFF is determoned in the CRON loop as usual.
          wp_send_json(null);
    }    
    
    /**
     * @param stdClass:studer_readings_obj contains details of all the readings
     * @return stdClass:format_object contains html for all the icons to be updatd by JS on Ajax call return
     * determine the icons based on updated data
     */
    public function prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $readings_obj )
    {
      if ( empty($readings_obj))
      {
        error_log("LogEmpty-Readings object passed into format data is empty");
        return null;
      }
      $config         = $this->config;

      // last time when the battery was measured
      $previous_timestamp = get_transient("timestamp_battery_last_measurement");

      // Initialize object to be returned
      $format_object  = new stdClass();

      $status = "";

      $shelly_switch_acin_details_arr = (array) $readings_obj->shelly_switch_acin_details_arr;

      $shelly_water_heater_kw       = 0;
      $shelly_water_heater_status   = null;

      // extract and process Shelly 1PM switch water heater data
      if ( ! empty($readings_obj->shelly_water_heater_data) )
      {
        $shelly_water_heater_data     = $readings_obj->shelly_water_heater_data;     // data object
        $shelly_water_heater_kw       = $shelly_water_heater_data->shelly_water_heater_kw;
        $shelly_water_heater_status   = $shelly_water_heater_data->shelly_water_heater_status;  // boolean variable
        $shelly_water_heater_current  = $shelly_water_heater_data->shelly_water_heater_current; // in Amps
      }
      

      $main_control_site_avasarala_is_offline_for_long = false; // $readings_obj->main_control_site_avasarala_is_offline_for_long;

      // solar power calculated from Shelly measurements of battery Grid and Load
      $psolar_kw              =   round($readings_obj->psolar_kw, 2);

      // Esimated total solar power available now assuming a cloudless sky
      $est_solar_total_kw = $readings_obj->est_solar_total_kw;

      // Potentially available solar power not being consumed right now
      $excess_solar_available = $readings_obj->excess_solar_available;
      $excess_solar_kw        = $readings_obj->excess_solar_kw;

      // approximate solar current into battery
      $solar_amps_at_49V      = $readings_obj->pv_current_now_total_xcomlan;

      // 
      $shelly_em_home_kw      =   $readings_obj->shelly_em_home_kw;

      // changed to avg July 15 2023 was battery_voltage_vdc before that
      // $battery_voltage_vdc    =   round( (float) $readings_obj->battery_voltage_avg, 1);

      // Positive is charging and negative is discharging We use this as the readings have faster update rate
      $battery_amps           =   $readings_obj->batt_amps;

      $battery_power_kw       = abs(round($readings_obj->battery_power_kw, 2));

      $battery_avg_voltage    =   $readings_obj->batt_voltage_xcomlan_avg;

      $home_grid_kw_power     =   $readings_obj->home_grid_kw_power;
      // $home_grid_voltage      =   $readings_obj->home_grid_voltage;
      $home_grid_voltage      =   $shelly_switch_acin_details_arr['shelly1pm_acin_voltage'];

      $seconds_elapsed_grid_status = (int) $readings_obj->seconds_elapsed_grid_status;
      $grid_seconds_in_hms = $this->format_seconds_to_hms_format ($seconds_elapsed_grid_status );

      $shelly1pm_acin_switch_status = (string) $shelly_switch_acin_details_arr['shelly1pm_acin_switch_status'];

      // This is the AC voltage of switch:0 of Shelly 4PM
      $shelly1pm_acin_voltage = $shelly_switch_acin_details_arr['shelly1pm_acin_voltage'];

      $control_shelly         = (bool) $shelly_switch_acin_details_arr['control_shelly'];

      $soc_percentage_now     = round($readings_obj->soc_percentage_now, 1);

      $soc_percentage_now_calculated_using_shelly_bm  = round($readings_obj->soc_percentage_now_calculated_using_shelly_bm, 1);

      if ( ! empty( $readings_obj->soc_percentage_now_using_dark_shelly ) )
      {
        $soc_percentage_now_using_dark_shelly = round($readings_obj->soc_percentage_now_using_dark_shelly, 1);
      }

      // If power is flowing OR switch has ON status then show CHeck and Green
      $grid_arrow_size = $this->get_arrow_size_based_on_power($home_grid_kw_power);

      switch (true)
      {   // choose grid icon info based on switch status
          case ( $shelly1pm_acin_switch_status === "OFFLINE" ): // No Grid OR switch is OFFLINE
              $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Yellow;"></i>';

              $grid_arrow_icon = ''; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

              $grid_info = 'No<br>Grid';

              break;


          case ( $shelly1pm_acin_switch_status === "ON" ): // Switch is ON
              $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Blue;"></i>';

              $grid_arrow_icon  = '<i class="fa-solid' . $grid_arrow_size .  'fa-arrow-right-long fa-rotate-by"
                                                                                style="--fa-rotate-angle: 45deg;">
                                  </i>';
              $grid_info = '<span style="font-size: 18px;color: Red;"><strong>' . $home_grid_kw_power . 
                            ' KW</strong><br>' . $home_grid_voltage . ' V</span>';
              break;


          case ( $shelly1pm_acin_switch_status === "OFF" ):   // Switch is online and OFF
              $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Red;"></i>';

              $grid_arrow_icon = '';  // '<i class="fa-solid fa-1x fa-circle-xmark"></i>';
  
              $grid_info = '<span style="font-size: 18px;color: Red;">' . $home_grid_kw_power . 
                      ' KW<br>' . $home_grid_voltage . ' V</span>';
              break;

          default:  
            $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Brown;"></i>';

            $grid_arrow_icon = 'XX'; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

            $grid_info = '???';
      }

      { // set the ev charge icon and power values based on status
        switch(true)
        {
          // when Car charger is OFFLINE. Indicate Yellow icon
          case ( $readings_obj->grid_present_status === 'offline'):
            $ev_charge_icon = '<i class="fa-solid fa-2x fa-charging-station" style="color: Yellow;"></i>';

            $car_charger_grid_kw_power = 0;
          break;

          // Car charger is online but no power is being drawn
          case ( $readings_obj->grid_present_status === 'online' && $readings_obj->car_charger_grid_kw_power <= 0.05 ):
            $ev_charge_icon = '<i class="fa-solid fa-2x fa-charging-station" style="color: Black;"></i>';

            $car_charger_grid_kw_power = 0;
          break;

          // Car charger is online and power is being drawn
          case ( $readings_obj->grid_present_status === 'online' && $readings_obj->car_charger_grid_kw_power > 0.05 ):
            $ev_charge_icon = '<i class="fa-solid fa-2x fa-charging-station" style="color: Blue;"></i>';

            $car_charger_grid_kw_power = round( $readings_obj->car_charger_grid_kw_power, 2);

            $car_charger_grid_kw_power = '<span style="font-size: 18px;color: Black;">
                                            <strong>' . $car_charger_grid_kw_power . '</strong>
                                          </span>';
          break;
        }

        $format_object->ev_charge_icon  = $ev_charge_icon;
        $format_object->car_charger_grid_kw_power    = $car_charger_grid_kw_power;
      } 

      { // wall socket for EV charging outside Garage from Yellow Phase
        switch(true)
        {
          // when wall charger is OFFLINE. Indicate Yellow icon
          case ( $readings_obj->grid_present_status === 'offline'):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: Yellow;"></i>';

            $wallcharger_grid_kw_power = 0;
          break;

          // when wall charger is ONLINE but not drawing power Indicate Red icon and power to 0
          case ( $readings_obj->grid_present_status === 'online' && $readings_obj->wallcharger_grid_kw_power <= 0.05):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: Black;"></i>';

            $wallcharger_grid_kw_power = 0;
          break;

          // when wall charger is ONLINE and drawing power Indicate Blue icon and power actulas
          case ( $readings_obj->grid_present_status === 'online' && $readings_obj->wallcharger_grid_kw_power > 0.05):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: Blue;"></i>';

            $wallcharger_grid_kw_power = round( $readings_obj->wallcharger_grid_kw_power, 2);

            $wallcharger_grid_kw_power = '<span style="font-size: 18px;color: Black;">
                                            <strong>' . $wallcharger_grid_kw_power . '</strong>
                                          </span>';
          break;
        }

        $format_object->wall_charge_icon  = $wall_charge_icon;
        $format_object->wallcharger_grid_kw_power    = $wallcharger_grid_kw_power;
      }


      $format_object->grid_status_icon  = $grid_status_icon;
      $format_object->grid_arrow_icon   = $grid_arrow_icon;

      // grid power and voltage info
      $format_object->grid_info       = $grid_info;

      // PV arrow icon psolar_info
      $pv_arrow_size = $this->get_arrow_size_based_on_power($psolar_kw);

      if ($psolar_kw > 0.1) 
      {
          $pv_arrow_icon = '<i class="fa-solid' . $pv_arrow_size . 'fa-arrow-down-long fa-rotate-by"
                                                                         style="--fa-rotate-angle: 45deg;
                                                                                            color: Green;"></i>';
          if ( $excess_solar_available === true )
          {
            // we potentially have solar power available for consumption that is being thrown away now
            $psolar_info =  '<span style="font-size: 18px;color: DarkViolet;"><strong>' . $psolar_kw . 
                            ' KW</strong><br>' . $excess_solar_kw . ' KW</span>';
          }
          else
          {
            $psolar_info =  '<span style="font-size: 18px;color: Green;"><strong>' . $psolar_kw . 
                            ' KW</strong><br>' . $solar_amps_at_49V . ' A</span>';
          }
      }
      else {
          $pv_arrow_icon = ''; //'<i class="fa-solid fa-1x fa-circle-xmark"></i>';
          $psolar_info =  '<span style="font-size: 18px;">' . $psolar_kw . 
                          ' KW<br>' . $solar_amps_at_49V . ' A</span>';
      }

      $pv_panel_icon =  '<span style="color: Green;">
                            <i class="fa-solid fa-3x fa-solar-panel"></i>
                        </span>';

      $format_object->pv_panel_icon = $pv_panel_icon;
      $format_object->pv_arrow_icon = $pv_arrow_icon;
      $format_object->psolar_info   = $psolar_info;

      // Studer Inverter icon
      $studer_icon = '<i style="display:block; text-align: center;" class="clickableIcon fa-solid fa-3x fa-cog" style="color: Green;"></i>';
      $format_object->studer_icon = $studer_icon;

      if ( $control_shelly === true )
      {
          // Local computer over LAN will be controlling the ACIN switch
          // a green cloud icon signifies that local site is in control
          $shelly_servo_icon = '<span style="color: Green; display:block; text-align: center;">
                                    <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                </span>';
      }
      else
      {
          // Local site is not in control
          $shelly_servo_icon = '<span style="color: Red; display:block; text-align: center;">
                                    <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                </span>';
      }
      $format_object->shelly_servo_icon = $shelly_servo_icon;

      // battery status icon: select battery icon based on charge level
      switch(true)
      {
          case ($soc_percentage_now < 25):
            $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
          break;

          case ($soc_percentage_now >= 25 &&
                $soc_percentage_now <  37.5 ):
            $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
          break;

          case ($soc_percentage_now >= 37.5 &&
                $soc_percentage_now <  50 ):
            $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
          break;

          case ($soc_percentage_now >= 50 &&
                $soc_percentage_now <  77.5):
            $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
          break;

          case ($soc_percentage_now >= 77.5):
            $battery_icon_class = "fa fa-3x fa-solid fa-battery-full";
          break;
      }

      // now determione battery arrow direction and battery color based on charge or discharge
      // conditional class names for battery charge down or up arrow
      $battery_arrow_size = $this->get_arrow_size_based_on_power($battery_power_kw);

      if ($battery_amps > 0.0)
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
          $battery_info =   '<span style="font-size: 18px;color: Green;"><strong>'  . $battery_power_kw     . ' KW</strong><br>' 
                                                                                    . abs($battery_amps)    . 'A<br>'
                                                                                    . $battery_avg_voltage  . 'V' .
                            '</span>';
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
        $battery_info =  '<span style="font-size: 18px;color: Red;"><strong>' . $battery_power_kw     . ' KW</strong><br>' 
                                                                              . abs($battery_amps)    . 'A<br>'
                                                                              . $battery_avg_voltage  . 'V' .
                         '</span>';
      }

      if  ($battery_power_kw < 0.01 ) $battery_arrow_icon = ''; // '<i class="fa-solid fa-1x fa-circle-xmark"></i>';

      $format_object->battery_arrow_icon  = $battery_arrow_icon;

      $battery_status_icon = '<i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

      $format_object->battery_status_icon = $battery_status_icon;
      $format_object->battery_arrow_icon  = $battery_arrow_icon;
      $format_object->battery_info        = $battery_info;
      

      // Shelly 4PM load breakout data
      $power_total_to_home    = $readings_obj->power_total_to_home;
      $power_total_to_home_kw = $readings_obj->power_total_to_home_kw; // round( $power_total_to_home * 0.001, 2);

      $power_to_home_kw = $readings_obj->power_to_home_kw;
      $power_to_ac_kw   = $readings_obj->power_to_ac_kw;
      $power_to_pump_kw = $readings_obj->power_to_pump_kw;

      $pump_ON_duration_mins = (int) round( $readings_obj->pump_ON_duration_secs / 60, 0);

      $pump_switch_status_bool  = $readings_obj->pump_switch_status_bool;
      $ac_switch_status_bool    = $readings_obj->ac_switch_status_bool;
      $home_switch_status_bool  = $readings_obj->home_switch_status_bool;

      $switch_tree_obj            = $readings_obj->switch_tree_obj;
      $switch_tree_exit_condition = $switch_tree_obj->switch_tree_exit_condition;
      $switch_tree_exit_timestamp = $switch_tree_obj->switch_tree_exit_timestamp;

      // $load_arrow_size = $this->get_arrow_size_based_on_power($pout_inverter_ac_kw);
      $load_arrow_size = $this->get_arrow_size_based_on_power($power_total_to_home_kw);

      $load_info = '<span style="font-size: 18px;color: Black;"><strong>' . $power_total_to_home_kw . ' KW</strong></span>';
      $load_arrow_icon = '<i class="fa-solid' . $load_arrow_size . 'fa-arrow-right-long fa-rotate-by"
                                                                        style="--fa-rotate-angle: 45deg;">
                          </i>';

      $load_icon = '<span style="color: Black;">
                        <i class="fa-solid fa-3x fa-house"></i>
                    </span>';

      $format_object->load_info        = $load_info;
      $format_object->load_arrow_icon  = $load_arrow_icon;
      $format_object->load_icon        = $load_icon;

      If ( $power_to_ac_kw > 0.2 )
      {
        $ac_icon_color = 'blue';
      }
      elseif ( ! $ac_switch_status_bool )
      {
        $ac_icon_color = 'red';
      }
      else
      {
        $ac_icon_color = 'black';
      }

      If ( $power_to_pump_kw > 0.1 )
      {
        $pump_icon_color = 'blue';
      }
      elseif ( ! $pump_switch_status_bool )
      {
        $pump_icon_color = 'red';
      }
      else
      {
        $pump_icon_color = 'black';
      }

      // Water Heater Icon color determination tree
      If ( $shelly_water_heater_kw > 0.1 )
      {
        $water_heater_icon_color = 'blue';
      }
      elseif ( $shelly_water_heater_status === false )
      {
        $water_heater_icon_color = 'red';
      }
      else
      {
        $water_heater_icon_color = 'yellow';
      }


      // Get the icoms for the load breakout table such as AC, home, pump, etc.
      $format_object->home_icon = '<span style="color: Black;">
                                      <i class="fa-solid fa-2x fa-house"></i>
                                    </span>';

      $format_object->ac_icon   = '<span style="color: ' . $ac_icon_color . ';">
                                      <i class="fa-solid fa-2x fa-wind"></i>
                                    </span>';

      $format_object->pump_icon = '<span style="color: ' . $pump_icon_color . '">
                                      <i class="fa-solid fa-2x fa-arrow-up-from-water-pump"></i>
                                  </span>';

      $format_object->water_heater_icon =   '<span style="color: ' . $water_heater_icon_color . '">
                                                <i class="fa-solid fa-2x fa-hot-tub-person"></i>
                                            </span>';

      $format_object->power_to_home_kw = '<span style="font-size: 18px;color: Black;">
                                              <strong>' . $readings_obj->power_to_home_kw . '</strong>
                                          </span>';

      $format_object->power_to_ac_kw = '<span style="font-size: 18px;color: Black;">
                                              <strong>' . $readings_obj->power_to_ac_kw . '</strong>
                                          </span>';

      $format_object->power_to_pump_kw = '<span style="font-size: 18px;color: Black;">
                                            <strong>' . $pump_ON_duration_mins . ' m</strong>
                                        </span>';

      $format_object->shelly_water_heater_kw = '<span style="font-size: 18px;color: Black;">
                                                  <strong>' . $shelly_water_heater_kw . '</strong>
                                                </span>';

      if ( ! empty( $readings_obj->cloudiness_average_percentage_weighted ) )
      {
        $status .= " Cloud: " . round($readings_obj->cloudiness_average_percentage_weighted,1) . "%";
      }

      if ( ! empty( $readings_obj->est_solar_total_kw ) )
      {
        $status .= " Pest: " . $readings_obj->est_solar_total_kw . " KW";
      }

      // present time
      $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
      $now_format = $now->format("H:i:s");

      $exit_datetimeobj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
      $exit_datetimeobj->setTimestamp($switch_tree_exit_timestamp);

      $interval_since_last_change = $now->diff($exit_datetimeobj);
      $formatted_interval = $this->format_interval($interval_since_last_change);

      $xcomlan_status  = "Xcomlan TS: " . $readings_obj->seconds_elapsed_xcomlan_ts;
      $shellybm_status = "ShellyBM TS: " . $readings_obj->seconds_elapsed_shellybm_ts;

      $status .= " " . $now_format;

      
      $status_html = '<span style="color: Blue; display:block; text-align: center;">' .
                        $status   . '<br>' . 
                        'LVDS: ' . $readings_obj->soc_percentage_lvds_setting  . '% ' . $readings_obj->average_battery_voltage_lvds_setting . 'V' .
                      '</span>';

      
      
      $format_object->soc_percentage_now_html = 
                      '<span style="font-size: 20px;color: Blue; display:block; text-align: center;">' . 
                          '<strong>' . $soc_percentage_now  . '</strong>%<br>' .
                      '</span>';
      $status_html .= '<span style="color: Blue; display:block; text-align: center;">' .
                          $formatted_interval   . ' ' . $switch_tree_exit_condition  .
                      '</span>';

      $status_html .= '<span style="color: Blue; display:block; text-align: center;">' .
                          $xcomlan_status   . ' ' . $shellybm_status  .
                      '</span>';

      $status_html .= '<span style="color: Blue; display:block; text-align: center;">' .
                          'Grid Status unchanged'   . ' ' . $grid_seconds_in_hms  .
                      '</span>';               
  
      $format_object->status = $status_html;

      $format_object->status = $status_html;

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
    public function format_interval(DateInterval $interval) 
    {
      $result = "";
      if ($interval->y) { $result .= $interval->format("%y years "); }
      if ($interval->m) { $result .= $interval->format("%m months "); }
      if ($interval->d) { $result .= $interval->format("%d d "); }
      if ($interval->h) { $result .= $interval->format("%h h "); }
      if ($interval->i) { $result .= $interval->format("%i m "); }
      if ($interval->s) { $result .= $interval->format("%s s "); }

      return $result;
    }

    /**
     * 
     */
    public function format_interval_in_minutes(DateInterval $interval) 
    {
      $result = 0;
      // if ($interval->y) { $result .= $interval->format("%y years "); }
      // if ($interval->m) { $result .= $interval->format("%m months "); }
      if ($interval->d) { $result = $interval->d * 24*60; }
      if ($interval->h) { $result += $interval->h * 60; }
      if ($interval->i) { $result += $interval->i; }
      if ($interval->s) { $result += $interval->s / 60; }

      return round( $result, 2);
    }

    /**
     * 
     */
    public function format_seconds_to_hms_format( int $duration_in_seconds ): string
    {
      $hms_string = '';

      if( $duration_in_seconds >= 86400 )
      {
        $hms_string = floor($duration_in_seconds / 86400) . 'd:';
        $duration_in_seconds = ($duration_in_seconds % 86400);
      }
      if( $duration_in_seconds >= 3600 )
      {
        $hms_string .= floor($duration_in_seconds / 3600) . 'h:';
        $duration_in_seconds = ($duration_in_seconds % 3600);
      }
      if( $duration_in_seconds >= 60 )
      {
        $hms_string .= floor($duration_in_seconds / 60) . 'm:';
        $duration_in_seconds = ($duration_in_seconds % 60);
      }

      $hms_string .= floor($duration_in_seconds) . 's';

      return $hms_string;
      /*
      // Calculate the hours 
      $hours = floor($duration_in_seconds / 3600); 
    
      // Calculate the remaining seconds 
      // into minutes 
      $minutes = floor(($duration_in_seconds % 3600) / 60);

      $secs = $duration_in_seconds - $hours * 3600 -  $minutes * 60;
    
      // Return the result as a string
      $hms_string = (string) $hours . "h:" . $minutes . "m:" . $secs . "s";

      return $hms_string;
      */
    }

    /**
     * 
     */
    public function get_grid_voltage_data_from_obj_in_transient()
    {
      // read in the object from transient if it exists process the grid values for display
      if ( false !== ( $shelly_readings_obj = get_transient( 'shelly_readings_obj' ) ) )
      {
        $grid_present_status          = (string)  $shelly_readings_obj->grid_present_status;
        $seconds_elapsed_grid_status  = (int)     $shelly_readings_obj->seconds_elapsed_grid_status;

        $time_formatted_string = $this->format_seconds_to_hms_format( $seconds_elapsed_grid_status );

        if ( $grid_present_status === "online" && ! empty( $shelly_readings_obj ) )
        {
          $a_phase_grid_voltage = (float) $shelly_readings_obj->red_phase_grid_voltage;
          $b_phase_grid_voltage = (float) $shelly_readings_obj->yellow_phase_grid_voltage;
          $c_phase_grid_voltage = (float) $shelly_readings_obj->blue_phase_grid_voltage;

          if ( ! empty( $a_phase_grid_voltage ) ) $a_phase_grid_voltage = (int) round( $a_phase_grid_voltage ,0 );
          if ( ! empty( $b_phase_grid_voltage ) ) $b_phase_grid_voltage = (int) round( $b_phase_grid_voltage ,0 );
          if ( ! empty( $c_phase_grid_voltage ) ) $c_phase_grid_voltage = (int) round( $c_phase_grid_voltage ,0 );

          // voltage processing for fluctuations and averages
          $phase_voltage_peak_percentage_array = $this->grid_voltage_processing(  $a_phase_grid_voltage, 
                                                                                  $b_phase_grid_voltage, 
                                                                                  $c_phase_grid_voltage );

          // check range and format each number individually for html color
          if ( $a_phase_grid_voltage < 245 && $a_phase_grid_voltage > 190 )
          {
            // in range and so color is green
            $a_phase_grid_voltage_html = '<span style="font-size: 22px;color: Green;"><strong>' . $a_phase_grid_voltage . '</span>';
          }
          else 
          {
            // cnot in range olor is red
            $a_phase_grid_voltage_html = '<span style="font-size: 22px;color: Red;"><strong>' . $a_phase_grid_voltage . '</span>';
          }

          // p phase range check
          if ( $b_phase_grid_voltage < 245 && $b_phase_grid_voltage > 190 )
          {
            $b_phase_grid_voltage_html = '<span style="font-size: 22px;color: Green;"><strong>' . $b_phase_grid_voltage . '</span>';
          }
          else 
          {
            $b_phase_grid_voltage_html = '<span style="font-size: 22px;color: Red;"><strong>' . $b_phase_grid_voltage . '</span>';
          }

          if ( $c_phase_grid_voltage < 245 && $c_phase_grid_voltage > 190 )
          {
            $c_phase_grid_voltage_html = '<span style="font-size: 22px;color: Green;"><strong>' . $c_phase_grid_voltage . '</span>';
          }
          else 
          {
            $c_phase_grid_voltage_html = '<span style="font-size: 22px;color: Red;"><strong>' . $c_phase_grid_voltage . '</span>';
          }
        }
        else
        {
          $a_phase_grid_voltage_html = '<span style="font-size: 22px;color: Yellow;"><strong>' . 'Offline' . '</span>';
          $b_phase_grid_voltage_html = '<span style="font-size: 22px;color: Yellow;"><strong>' . 'Offline' . '</span>';
          $c_phase_grid_voltage_html = '<span style="font-size: 22px;color: Yellow;"><strong>' . 'Offline' . '</span>'; 

          $phase_voltage_peak_percentage_array = ['NA', 'NA', 'NA'];
        }

        // prepare object to return data
        $grid_obj = new stdClass;

        $grid_obj->a_phase_grid_voltage_html = $a_phase_grid_voltage_html;
        $grid_obj->b_phase_grid_voltage_html = $b_phase_grid_voltage_html;
        $grid_obj->c_phase_grid_voltage_html = $c_phase_grid_voltage_html;

        $grid_obj->phase_voltage_peak_percentage_array = $phase_voltage_peak_percentage_array;

        $grid_obj->a_phase_voltage_peak_percentage = $phase_voltage_peak_percentage_array[0];
        $grid_obj->b_phase_voltage_peak_percentage = $phase_voltage_peak_percentage_array[1];
        $grid_obj->c_phase_voltage_peak_percentage = $phase_voltage_peak_percentage_array[2];

        $grid_obj->time_formatted_string = $time_formatted_string;
        
        return $grid_obj;
      }
      else
      {
        // could not get object from transient
        return null;
      }
    }
}