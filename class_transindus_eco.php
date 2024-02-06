<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 * Ver 3.0
 *     Added Shelly EM and removed Shelly 1PM for ACIN control
 *     Added Shelly 4 PM for energy readings to home. 
 *      During dark SOC updates can use this if Studer API calls fail
 *     
 * 
 *
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, andF
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
        add_shortcode( 'transindus-studer-readings',  [$this, 'studer_readings_page_render'] );

        // Action to process submitted data from a Ninja Form.
        add_action( 'ninja_forms_after_submission',   [$this, 'my_ninja_forms_after_submission'] );

        // This is the page that displays the Individual Studer with All powers, voltages, currents, and SOC% and Shelly Status
        add_shortcode( 'my-studer-readings',          [$this, 'my_studer_readings_page_render'] );

        // Define shortcode to prepare for my-studer-settings page
        add_shortcode( 'my-studer-settings',          [$this, 'my_studer_settings'] );

        // Define shortcode to prepare for view-power-values page. Page code for displaying values todo.
        add_shortcode( 'view-grid-values',          [$this, 'view_grid_values_page_render'] );
    }

    /**
     *  Separated this init function so that it can execute frequently rather than just at class construct
     */
    public function init()
    {
      //

      // set the logging
      $this->verbose = false;

      // lat and lon at Trans Indus from Google Maps
      $this->lat        = 12.83463;
      $this->lon        = 77.49814;

      // UTC offset for local timezone
      $this->utc_offset = 5.5;

      // Get this user's usermeta into an array and set it as property the class
      // $this->get_all_usermeta( $wp_user_ID );

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

            error_log(" Captured cloudiness forecast");
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

            error_log(" made up cloudiness forecast due to API failure over time window and lack of yesterdays forecast");
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
     *  @param int:$wp_user_ID is the WP user ID
     *  @return array:$all_usermeta is the return array containing all of the user meta for the user with user ID passed in.
     * 
     *  The property of $this is also set for what its worth
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
     * 
     *  Checks the validity of Shelly switch configuration required for program
     *  Makes an API call on the Shelly ACIN switch and return the ststus such as State, Voltage, etc.
     */
    public function get_shelly_switch_acin_details_over_lan( int $user_index) : array
    {
      $return_array = [];

      // set default timezone to Asia Kolkata
      //

      $config     = $this->config;

      // get WP user object and so get its ID
      $wp_user_ID = $this->get_wp_user_from_user_index( $user_index )->ID;

      // ensure that the data below is current before coming here
      $all_usermeta = $this->all_usermeta ?? $this->get_all_usermeta( $wp_user_ID );

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

      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];
      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

      // Shelly Pro 1PM is a Gen2 device and we only have 1 channel=0
      $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, 'gen2', 0 );

      // this is curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

      if ( is_null($shelly_api_device_response) ) 
      { // switch status is unknown

          error_log("Shelly Grid Switch API call failed - Grid power failure Assumed");

          $shelly_api_device_status_ON = null;

          $shelly_switch_status             = "OFFLINE";
          $shelly_api_device_status_voltage = "NA";

          $return_array['shelly1pm_acin_switch_config']   = $valid_shelly_config;
          $return_array['control_shelly']                 = $control_shelly;
          $return_array['shelly1pm_acin_switch_status']   = $shelly_switch_status;
          $return_array['shelly1pm_acin_voltage']         = $shelly_api_device_status_voltage;
          $return_array['shelly1pm_acin_current']         = 'NA';
          $return_array['shelly1pm_acin_power_kw']        = 'NA';
          $return_array['shelly1pm_acin_minute_ts']       = 'NA';

          $this->shelly_switch_acin_details = $return_array;

          return $return_array;
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

    /**
     *  Measure current energy counter of Shelly EM measuring load consumption in WH
     *  Obtain the energy counter reading stored just after midnight
     *  The difference between them will give the consumed energy in WH since midnight
     *  The counter is a perpetual counter and is never erased or reset
     */
    public function get_shellyem_accumulated_load_wh_since_midnight_over_lan( int $user_index, string $wp_user_name, int $wp_user_ID ): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;

      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_em_load'];
      $ip_shelly_load_em  = $config['accounts'][$user_index]['ip_shelly_load_em'];

      $shelly_gen         = 'gen1'; // Shelly 1EM is a Gen1 device

      $returned_obj = new stdClass;

      $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_shelly_load_em, $shelly_gen );

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

      // check to make sure that it exists. If null API call was fruitless
      if (  empty(      $shelly_api_device_response ) || 
            empty(      $shelly_api_device_response->emeters[0]->total ) 
          )
      { // Shelly Load EM did not respond over LAN
        $this->verbose ? error_log( "Shelly EM Load Energy API call failed - See below for response" ): false;
        $this->verbose ? error_log( print_r($shelly_api_device_response , true) ): false;

        return null;
      }

      // Shelly API call was successfull and we have useful data. Round to 0 and convert to integer to get WattHours
      $shelly_em_home_wh = (int) round($shelly_api_device_response->emeters[0]->total, 0);

      // get the energy counter value set at midnight. Assumes that this is an integer
      $shelly_em_home_energy_counter_at_midnight = (int) round(get_user_meta( $wp_user_ID, 'shelly_em_home_energy_counter_at_midnight', true), 0);

      $returned_obj->shelly_em_home_voltage = (int)   round($shelly_api_device_response->emeters[0]->voltage, 0);
      $returned_obj->shelly_em_home_kw      = (float) round( 0.001 * $shelly_api_device_response->emeters[0]->power, 3 );

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
     *  LEGACY - NOT USED HERE
     *  @param float:energy_total_to_home_ts is the total energy measured by Shelly4PM energy meter upto this point
     *  @param int:user_index
     *  @param int:wp_user_ID
     *  @return int:shelly_energy_counter_midnight is the accumulated load energy as measured by Shelly Pro 4PM
     */
    public function get_accumulated_wh_since_midnight_shelly4pm_over_lan(  float $energy_total_to_home_ts, int $user_index, int $wp_user_ID ) : ? int
    {
      // set default timezone to Asia Kolkata
      //

      // read in the config array from the class property
      $config = $this->config;

      $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

      // get the energy consumed since midnight stored in user meta
      $shelly_energy_counter_midnight = $all_usermeta[ 'shelly_energy_counter_midnight' ];

      // get the previous cycle energy counter value. 1st time when not set yet set to current value
      $previous_energy_counter_wh_tmp = $all_usermeta[ 'shelly_energy_counter_now' ] ?? $current_energy_counter_wh;

      $previous_energy_counter_wh     = (int) round( $previous_energy_counter_wh_tmp, 0 );

      // this is passed in so just round it off
      $current_energy_counter_wh      = (int) round( $energy_total_to_home_ts, 0 );

      if ( ( $current_energy_counter_wh - $previous_energy_counter_wh ) >= 0 )
      {
        // the counter has not reset so calculate the energy consumed since last measurement
        $delta_increase_wh = $current_energy_counter_wh - $previous_energy_counter_wh;
      }
      else
      {
        // counter has reset so ignore the previous counter reading we lose a little bit of the reading
        $delta_increase_wh = $current_energy_counter_wh;
      }

      // check that the increase in energy WH is reasonable
      // The increase should be greater than 0 and less than 1KWH
      // The assumption is that between any 2 readings the difference shouldnt be more. 
      // The only way the difference can be more is if the internet was down and readings get separated in time very long
      // That is not being handled currently
      if ( $delta_increase_wh < 0 || $delta_increase_wh > 500 )
      {
        error_log( "Delta Increase in shelly_energy_counter_midnight is Bad: " . $delta_increase_wh . "And was ignored");
        // we ignore this accumulation
        // update the current energy counter with current reading for next cycle
        update_user_meta( $wp_user_ID, 'shelly_energy_counter_now', $current_energy_counter_wh );

        return (int) $shelly_energy_counter_midnight;
      }

      // accumulate the energy from this cycle to accumulator
      $shelly_energy_counter_midnight = (int) ($shelly_energy_counter_midnight + $delta_increase_wh);

      // update the accumulator user meta for next cycle
      update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', $shelly_energy_counter_midnight );

      // update the current energy counter with current reading for next cycle
      update_user_meta( $wp_user_ID, 'shelly_energy_counter_now', $current_energy_counter_wh );

      // return the energy consumed since midnight in WH
      return (int) $shelly_energy_counter_midnight;
    }


    /**
     *  @param int:$user_index of user in the config array
     *  @param int:$wp_user_ID of above user
     *  @param string:$wp_user_name of above user
     *  @param string:$shelly_switch_status is 'ON' 'OFF' or 'OFFLINE'
     *  @param object:$return_obj has as properties, values from API call on Shelly 4PM and calculations thereof
     * 
     *  1. Calculate SOC making an API call for Shelly energy readings -  usermeta for soc_percentage_now not updated here
     *  2. the update happens if SOC after dark baselining has happened and it is still dark now
     *  3. The check to see if it is dark and if SOC capture after dark etc., should be done before comin here
     *  4 This routine is typically called when it is still dark and Solar is not present
     *  4. If GRID is ON then SOC is kept constant but SOC after dark reference is reset to current values
     *  5. Shelly 4PM energy counter reset is checked for and baseline values (after dark values) updated just after any reset.
     *  6. SOC@6AM is estimated using averaged load values. If value is less than 40% a flag is set. No action is taken here on this
     */
    public function compute_soc_from_shelly_energy_readings(  int     $user_index, 
                                                              int     $wp_user_ID, 
                                                              string  $wp_user_name,
                                                              string  $shelly_switch_status ) : ? object
    {
      // set default timezone to Asia Kolkata
      //

      // instantiate the return object
      $return_obj = new stdClass;

      // Initialize return object properties to defaults
      $return_obj->soc_predicted_at_6am   = 0.0; // 
      $return_obj->minutes_now_to_6am     = 0.0;
      $return_obj->load_kw_avg            = 0.0;

      // The default value of boolean flag indicating if Shelly energy counter has reset due to Studer overload shutdown or OTA update
      $shelly_energy_counter_has_reset =  false;

      //  default value of  ACIN switch due to soc at 6am prediction
      $turn_on_acin_switch_soc6am_low = false;

      // set the flag to see if OSC discharge rate needs to be calculated. This is between 8PM and 5AM
      // permanantly disable this function
      $check_for_soc_rate_bool = false; // $this->nowIsWithinTimeLimits( "23:00", "midnight tomorrow" ) || $this->nowIsWithinTimeLimits( "midnight today", "05:00" );

      // read in the config array from the class property
      $config = $this->config;

      // The main foreach loop should have triggered a refresh so just read the user meta array from class property
      $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

      // get the energy consumed since midnight stored in user meta
      $shelly_energy_counter_midnight     = $all_usermeta[ 'shelly_energy_counter_midnight' ];

      // get the installed battery capacity in KWH from config
      $SOC_capacity_KWH                   = $config['accounts'][$user_index]['battery_capacity'];

      // This is the value of the SOC as updated by Studer API, captured just after dark.
      // This reference gets reset each time there is a Shelly 4PM reset and or if ACIN switch is ON
      $soc_update_from_studer_after_dark  = $all_usermeta[ 'soc_update_from_studer_after_dark' ];

      // This is the tiestamp at the moent of SOC capture just after dark or when reference is reset
      $timestamp_soc_capture_after_dark   = $all_usermeta[ 'timestamp_soc_capture_after_dark' ];

      // Keep the SOC from previous update handy for when the SOC does not change due to ACIN swith ON status
      $SOC_percentage_previous            = $all_usermeta[ 'soc_percentage_now' ];

      // This is the Shelly energy counter at the moment of SOC capture just after dark or when reference reset
      $tmp_shelly_energy_counter_after_dark   = $all_usermeta[ 'shelly_energy_counter_after_dark' ];
      $shelly_energy_counter_after_dark       = (int) round( $tmp_shelly_energy_counter_after_dark, 0 );

      // get the previous cycle energy counter value. 1st time when not set yet set to current value
      $previous_energy_counter_wh_tmp = $all_usermeta[ 'shelly_energy_counter_now' ] ?? $current_energy_counter_wh;
      $previous_energy_counter_wh     = (int) round($previous_energy_counter_wh_tmp, 0);
      

      // API call to get a reading now from the Shelly 4PM device for energy, power, and timestamp
      $shelly_homwpwr_obj = $this->get_shelly_device_status_homepwr( $user_index );

      if ( empty( $shelly_homwpwr_obj ) )
      {   // API call returned an empty object
        return null;
      }

      // Also check and control pump ON duration
      $this->control_pump_on_duration( $wp_user_ID, $user_index, $shelly_homwpwr_obj);

      // exctract needed properties from Shelly homepower object
      $current_energy_counter_wh  = (int) round($shelly_homwpwr_obj->energy_total_to_home_ts, 0);

      if ( ( $current_energy_counter_wh - $previous_energy_counter_wh ) >= 0 )
      {
        // the counter has not reset so calculate the energy consumed since last measurement
        $delta_increase_wh = $current_energy_counter_wh - $previous_energy_counter_wh;
      }
      else
      {
        // counter has reset so ignore the previous counter reading we lose a little bit of the reading
        $delta_increase_wh = $current_energy_counter_wh;
      }

      // accumulate the energy from this cycle to accumulator
      $shelly_energy_counter_midnight = $shelly_energy_counter_midnight + $delta_increase_wh;

      // update the accumulator user meta for next cycle
      update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', $shelly_energy_counter_midnight );

      $current_power_to_home_wh   = $shelly_homwpwr_obj->power_total_to_home;
      $current_timestamp          = $shelly_homwpwr_obj->minute_ts;
      $current_power_to_home_kw   = $current_power_to_home_wh * 0.001;

      // Check if energy counter has reset due to OTA update or power reset. The counter monoticity will break
      // we add compare integers here not floats, see above for int conversion
      if ( ( $current_energy_counter_wh ) < ( $shelly_energy_counter_after_dark  ) ) // SOC after dark happened before roll over
      {
        // Yes the counter has reset. This flow does NOT happen often. The Flag default value is false
        $shelly_energy_counter_has_reset =  true;
      }

      // Check if energy counter has reset OR the ACIN switch was ON. In both cases SOC after dark needs to be rest to current values
      // if the ACIN switch was ON then we want to keep the SOC the same since the Grid is supplying the HOME at night
      //                           but we still want to reset the after dark reference values continuously
      //                           till the switch is OFF again and when the SOC discharge happens and needs updating
      
      if ( ! $shelly_energy_counter_has_reset && ! ($shelly_switch_status === 'ON' ) )      // 0 0 state Most common flow
      { // Update SOC usng counters. DO NOT reset SOC after dark values, they are still valid
        $energy_consumed_since_after_dark_update_kwh = ( $current_energy_counter_wh - $shelly_energy_counter_after_dark ) * 0.001;

        $soc_percentage_discharged = round( $energy_consumed_since_after_dark_update_kwh / $SOC_capacity_KWH * 107, 3);

        // Change in SOC ( a decrease) from value captured just after dark to now based on energy consumed by home during dark
        $soc_percentage_now_computed_using_shelly  = round($soc_update_from_studer_after_dark - $soc_percentage_discharged, 3);
    
        // no need to worry about SOC clamp to 100 since value will only decrease never increase, no solar
        // update_user_meta( $wp_user_ID, 'soc_percentage_now', $soc_percentage_now_computed_using_shelly );

        // log if verbose is set to true
        $this->verbose ? error_log( "SOC at dusk: " . $soc_update_from_studer_after_dark . 
                                    "%,  SOC NOW using Shelly: " . 
                                    $soc_percentage_now_computed_using_shelly . " %") : false;

        // SOc usermeta is updated in calling routine and counter is updated commonly below
      }
      elseif ( $shelly_energy_counter_has_reset && ! ($shelly_switch_status === 'ON' ) )  // 1 0 state
      {
        // Compute updated SOC using modified counter and reset SOC after dark to current readings
        // Since the energy counter reset we need to add this to our previous energy counter value for correct curremt value
        $modified_energy_counter_due_to_reset_wh = $previous_energy_counter_wh + $current_energy_counter_wh;

        // Calculate the energy in KWH from now to the reference point  which is after dark if no shelly reset happened
        $energy_consumed_since_after_dark_update_kwh = (  $modified_energy_counter_due_to_reset_wh - $shelly_energy_counter_after_dark ) * 0.001;

        // Energy in terms of percentage Battery SOC capacity discharged from battery. 107 is 1.07 for inverter loss * 100%
        $soc_percentage_discharged = round( $energy_consumed_since_after_dark_update_kwh / $SOC_capacity_KWH * 107, 3);

        // Change in SOC ( a decrease) from just after dark (reference) to now based on energy consumed only
        $soc_percentage_now_computed_using_shelly  = $soc_update_from_studer_after_dark - $soc_percentage_discharged;

        // reset reference counter sto current value
        update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $current_energy_counter_wh );
        update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $current_timestamp );

        if ( $soc_percentage_now_computed_using_shelly >= 20 && $soc_percentage_now_computed_using_shelly <= 100 )
        {
          // reset reference SOC to updated value calculated using modified counter due to reset
          update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $soc_percentage_now_computed_using_shelly );

          error_log("Shelly SOC after dark value has been reset to Curr: "    . $soc_percentage_now_computed_using_shelly );
        }
        else 
        {
          error_log("Shelly SOC after dark value has NOT been reset due to bad SOC: " . $soc_percentage_now_computed_using_shelly );
        }

        error_log("Shelly Energy Counter has reset ");
        error_log("Shelly Energy Counter after dark - value before reset: " . $previous_energy_counter_wh );
        error_log("Shelly Energy Counter after dark - value is reset to: "  . $current_energy_counter_wh );
        error_log("Shelly timestamp after dark has been reset to NOW: "     . $current_timestamp );
        error_log("Shelly SOC after dark - value before reset: "            . $soc_update_from_studer_after_dark );
        
      }
      elseif ( $shelly_switch_status === 'ON' )    // 0 1 or 1 1 states are same
      {
        // ACIN switch is ON so keep SOC same as previous cycle but reset SOC after dark values to current readings
        $energy_consumed_since_after_dark_update_kwh = ( $current_energy_counter_wh - $shelly_energy_counter_after_dark ) * 0.001;

        $soc_percentage_discharged = 0; // set value to not get a ,notice due to undefined variable in returned object
        
        $soc_percentage_now_computed_using_shelly  = $SOC_percentage_previous;

        $this->verbose ? error_log( "Shelly SOC not updated since ACIN switch was ON and kept at previous value of: "
                                    . $SOC_percentage_previous ) : false;

        // reset reference counters to current values
        update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $current_energy_counter_wh );
        update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $current_timestamp );

        // reset reference SOC to SOC now 
        update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $soc_percentage_now_computed_using_shelly );

        // No SOC after dark reference update since unchanged
      }

      // end of IF ELSEIF ELSE tree

      // finally we also update the current energy counter This is common to all cases
      update_user_meta( $wp_user_ID, 'shelly_energy_counter_now', $current_energy_counter_wh );
      
      // no need to worry about SOC clamp to 100 since value will only decrease never increase, no solar

      // do the check only between 10PM and 5AM
      if ( $check_for_soc_rate_bool )
      { // Predict the SOC at 6AM based on load averaged over last 10 readings

        $load_kw_avg = $this->get_load_average( $wp_user_name, $current_power_to_home_kw );

        // how many minutes from now to 6AM. We will only do thiss if now is between 10PM to 5AM. Expect positive number of minutes
        $minutes_now_to_6am = $this->minutes_now_to_future('06:00');

        // Energy consumed in KWH by load during this time
        $est_kwh_discharged_till_6am = $load_kw_avg * $minutes_now_to_6am / 60.0;

        // estimated SOC% points discharged assuming usuaul conversion efficiency of 107%
        $est_soc_percentage_discharged_till_6am = $est_kwh_discharged_till_6am / $SOC_capacity_KWH * 107;
        
        // how many elapsed minutes from Past reference timestamp given to now. Positive minutes if timestamp is in past
        $delta_minutes_from_reference_time = abs( $this->minutes_from_reference_to_now( $timestamp_soc_capture_after_dark ) );

        $soc_predicted_at_6am_raw = $soc_percentage_now_computed_using_shelly - $est_soc_percentage_discharged_till_6am;

        $soc_predicted_at_6am = round( $soc_predicted_at_6am_raw , 1 );

        // $return_obj->turn_on_acin_switch_soc6am_low    = $turn_on_acin_switch_soc6am_low;
        $return_obj->soc_predicted_at_6am              = $soc_predicted_at_6am;
        $return_obj->minutes_now_to_6am                = $minutes_now_to_6am;
        $return_obj->load_kw_avg                       = $load_kw_avg;

        $return_obj->delta_minutes_from_reference_time = $delta_minutes_from_reference_time;

        $this->verbose ? error_log( "SOC predicted for 0600: "  . $soc_predicted_at_6am . " %"): false;
        $this->verbose ? error_log( "Minutes NOW to 0600: "     . $minutes_now_to_6am . " mins"): false;
        $this->verbose ? error_log( "delta_minutes_from_reference_time: "     . $delta_minutes_from_reference_time . " mins"): false;
        $this->verbose ? error_log( "load_kw_avg: "     . $load_kw_avg . " KW"): false;
        // $this->verbose ? error_log( "Flag to turn-ON ACIN due to low Predicted SOC at 6AM: " . $turn_on_acin_switch_soc6am_low ): false;
      }

      $return_obj->check_for_soc_rate_bool           = $check_for_soc_rate_bool;

      $return_obj->SOC_percentage_previous           = $SOC_percentage_previous;
      $return_obj->SOC_percentage_now                = $soc_percentage_now_computed_using_shelly;

      $return_obj->previous_energy_counter_wh        = $previous_energy_counter_wh;
      $return_obj->current_energy_counter_wh         = $current_energy_counter_wh;
      $return_obj->current_power_to_home_wh          = $current_power_to_home_wh;
      $return_obj->current_timestamp                 = $current_timestamp;
      $return_obj->soc_percentage_discharged         = $soc_percentage_discharged;
      $return_obj->energy_consumed_since_after_dark_update_kwh = $energy_consumed_since_after_dark_update_kwh;

      $return_obj->shelly_energy_counter_has_reset = $shelly_energy_counter_has_reset;
      $return_obj->modified_energy_counter_due_to_reset_wh = $modified_energy_counter_due_to_reset_wh ?? null;

      // the variable name is due to compatibility with Studer values for display purposes. Power is calculated from Shelly 4PM
      $return_obj->pout_inverter_ac_kw               = round( $current_power_to_home_kw, 2);

      // power to main and Gadigappa's home from channels 2 and 3 of Shelly 4PM
      $return_obj->power_to_home_kw = $shelly_homwpwr_obj->power_to_home_kw;

      // power to ACs from channel 1 of Shelly 4PM
      $return_obj->power_to_ac_kw   = $shelly_homwpwr_obj->power_to_ac_kw;

      // power to pump in kw
      $return_obj->power_to_pump_kw = $shelly_homwpwr_obj->power_to_pump_kw;

      // pump switch status boolean
      $return_obj->pump_switch_status_bool = $shelly_homwpwr_obj->pump_switch_status_bool;

      // AC switch status boolean
      $return_obj->ac_switch_status_bool = $shelly_homwpwr_obj->ac_switch_status_bool;

      // Home switch status boolean
      $return_obj->home_switch_status_bool = $shelly_homwpwr_obj->home_switch_status_bool;

      // total power from Shelly 4PM
      $return_obj->power_total_to_home_kw = $shelly_homwpwr_obj->power_total_to_home_kw;

      // pump duration time
      $return_obj->pump_ON_duration_secs = $shelly_homwpwr_obj->pump_ON_duration_secs;
      
      return $return_obj;
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
     *  The current is measured using a hall effect sensor. The sensor output voltage is rread by the ADC in the shelly UNI
     *  The transducer function is: V(A) = (Vout - 2.5)/0.0294 using 29.375 mv/A around 2.5V reference
     *  Trapezoidal rule is used to calculate Area
     *  Current measurements are used to update user meta for accumulated Solar AH since Studer Midnight
     *  in user meta 'battery_soc_percentage_accumulated_since_midnight'. This must be reset to 0 just aftermidnight elsewhere.
     */
    public function get_shelly_battery_measurement_over_lan(  int     $user_index,            
                                                              string  $wp_user_name,  
                                                              int     $wp_user_ID, 
                                                              string  $shelly_switch_status,  
                                                              float   $home_grid_kw_power, 
                                                              bool    $it_is_still_dark) : ? object
    {
        // set default timezone to Asia Kolkata
        //

        // initialize the object to be returned
        $battery_measurements_object = new stdClass;

        // Make an API call on the Shelly UNI device
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_plus_addon'];
        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_addon'];

        // Total Installed BAttery capacity in AH, in my case it is 3 x 100 AH or 300 AH
        $battery_capacity_ah = (float) $config['accounts'][$user_index]['battery_capacity_ah']; // 300AH

        // uses default values of 'gen2' and channel 0 for last 2 unsoecified parameters 
        $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

        // this is $curl_response.
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        // check to make sure that it exists. If null call was fruitless
        if ( empty( $shelly_api_device_response ) )
        {
          error_log("Shelly Battery Measurement API call over LAN failed");

          return null;
        }

        // get the unix time stamp when measurement was made
        $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $timestamp = $now->getTimestamp();

        // The measure ADC voltage is in percent of 10V. So a 25% reading indicates 2.5V measured
        $adc_voltage_shelly = $shelly_api_device_response->{"input:100"}->percent;

        // calculate the current using the 65mV/A formula around 2.5V. Positive current is battery discharge
        $delta_voltage = $adc_voltage_shelly * 0.1 - 2.54;

        // 100 Amps gives a voltage of 0.625V amplified by opamp by 4.7. So voltas/amp measured by Shelly Addon is
        $volts_per_amp = 0.625 * 4.7 / 100;

        // Convert Volts to Amps using the value above. SUbtract an offest of 8A noticed, probably due to DC offset
        $battery_amps_raw_measurement = ($delta_voltage / $volts_per_amp);

        // +ve value indicates battery is charging. Due to our inverting opamp we have to reverse sign and educe the current by 5%
        // This is because the battery SOC numbers tend about 4 points more from about a value of 40% which indicates about 10^ over measurement
        // so to be conservative we are using a 5% reduction to see if this corrects the tendency.
        $battery_amps = -1.0 * round( $battery_amps_raw_measurement * 0.90, 1);

        // get the previous reading's timestamp from transient. If transient doesnt exist set the value to current measurement
        $previous_timestamp = get_transient( 'timestamp_battery_last_measurement' ) ?? $timestamp;

        // get the previous reading from transient. If doesnt exist set it to current measurement
        $previous_battery_amps = (float) get_transient( 'amps_battery_last_measurement' ) ?? $battery_amps;

        // update transients with current measurements. These will be used as previous measurements for next cycle
        set_transient( 'timestamp_battery_last_measurement',  $timestamp,     60 * 60 );
        set_transient( 'amps_battery_last_measurement',       $battery_amps,  60 * 60 );

        $prev_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $prev_datetime_obj->setTimeStamp($previous_timestamp);

        // get Battery SOC percentage accumulated till last measurement
        $battery_soc_percentage_accumulated_since_midnight = (float) get_user_meta(  $wp_user_ID, 
                                                                              'battery_soc_percentage_accumulated_since_midnight', true);

        if (  $it_is_still_dark               &&  // No solar
              $shelly_switch_status === 'ON'  &&  // Grid switch is ON
              $home_grid_kw_power > 0.1            )   // Power supplied by grid to home is greater than 0.1 KW
        {
          // There is no solar and the grid is supplying the load.
          // Any small battery current is just noise and so can be set to 0 for accuracy
          $battery_amps = 0;                // set battery current to truly 0 for more accurate calculation

          $previous_battery_amps = 0;       // also 0 this

          $battery_ah_this_measurement = 0; // accumulation of charge this cycle is 0

          $battery_soc_percent_this_measurement = 0;  // delta SOC% accumulated this sycle is 0

          // unchanged but needed to prevent error in assignment later on
          $battery_accumulated_ah_since_midnight = $battery_soc_percentage_accumulated_since_midnight / 100 * $battery_capacity_ah;
        }
        else
        { // Battery probably charging or discharhing so take into account
          // find out the time interval between the last timestamp and the present one in seconds
          $diff = $now->diff( $prev_datetime_obj );

          // take total seconds of difference between timestamp and divide by 3600
          $hours_between_measurement = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 ) / 3600;

          // AH of battery charge - +ve is charging and -ve is discharging
          // use trapezoidal rule for integration
          $battery_ah_this_measurement = 0.5 * ( $previous_battery_amps + $battery_amps ) * $hours_between_measurement;

          $battery_soc_percent_this_measurement = $battery_ah_this_measurement / $battery_capacity_ah * 100;

          
          // accumulate  present measurement
          $battery_soc_percentage_accumulated_since_midnight += $battery_soc_percent_this_measurement;

          $battery_accumulated_ah_since_midnight = $battery_soc_percentage_accumulated_since_midnight / 100 * $battery_capacity_ah;

          // update accumulated battery charge back to user meta
          update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', $battery_soc_percentage_accumulated_since_midnight);
        }
        /*
        $this->verbose ? error_log("Battery % added today: $battery_soc_percentage_accumulated_since_midnight, 
                                    % accumulated just now: $battery_soc_percent_this_measurement, 
                                    Batt Amps: $battery_amps"
                                  ) : false;
        */

        

        // write variables as properties to returned object
        $battery_measurements_object->battery_ah_this_measurement                       = $battery_ah_this_measurement;
        $battery_measurements_object->battery_soc_percent_this_measurement              = $battery_soc_percent_this_measurement;
        $battery_measurements_object->battery_soc_percentage_accumulated_since_midnight = $battery_soc_percentage_accumulated_since_midnight;
        $battery_measurements_object->battery_amps                                      = $battery_amps;
        $battery_measurements_object->battery_capacity_ah                               = $battery_capacity_ah;
        $battery_measurements_object->battery_accumulated_ah_since_midnight             = $battery_accumulated_ah_since_midnight;
        
        return $battery_measurements_object;
    }

    /**
     *  @param int:$user_index of user in config array
     *  @return object:$shelly_device_data contains energy counter and its timestamp along with switch status object
     *  Gets the power readings supplied to Home using Shelly Pro 4PM
     */
    public function get_shelly_device_status_homepwr_over_lan(int $user_index): ?object
    {
        // get API and device ID from config based on user index
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_homepwr'];
        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_4pm'];

        // gen2 is default as pass parameter
        $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

        // this is $curl_response.
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        // check to make sure that it exists. If null API call was fruitless
        if ( empty( $shelly_api_device_response ) || empty( $shelly_api_device_response->{"switch:3"}->aenergy->total ) )
        {
          $this->verbose ? error_log("Shelly Homepwr switch API call failed"): false;

          return null;
        }

        // Since this is the switch that also measures the power and energy to home, let;s extract those details
        $power_channel_0 = $shelly_api_device_response->{"switch:0"}->apower; // sump pump
        $power_channel_1 = $shelly_api_device_response->{"switch:1"}->apower; // Ac units except in Dad;s room
        $power_channel_2 = $shelly_api_device_response->{"switch:2"}->apower; // Home
        $power_channel_3 = $shelly_api_device_response->{"switch:3"}->apower; // Home

        $power_to_home_kw = round( ( $power_channel_2 + $power_channel_3 ) * 0.001, 3 );  // sum of channels 2 and 3 supplying Home
        $power_to_ac_kw   = round( ( $power_channel_1 * 0.001 ), 3 );                     // Supplying all ACs except in Dad's BR
        $power_to_pump_kw = round( ( $power_channel_0 * 0.001 ), 3 );                     // channel 0 supplying Sump Pump

        $power_total_to_home    = $power_channel_0 + $power_channel_1 + $power_channel_2 + $power_channel_3;  // total from Shelly 4PM
        $power_total_to_home_kw = round( ( $power_total_to_home * 0.001 ), 3 );

        $energy_channel_0_ts = $shelly_api_device_response->{"switch:0"}->aenergy->total;
        $energy_channel_1_ts = $shelly_api_device_response->{"switch:1"}->aenergy->total;
        $energy_channel_2_ts = $shelly_api_device_response->{"switch:2"}->aenergy->total;
        $energy_channel_3_ts = $shelly_api_device_response->{"switch:3"}->aenergy->total;

        $energy_total_to_home_ts = (float) ($energy_channel_0_ts + 
                                            $energy_channel_1_ts + 
                                            $energy_channel_2_ts + 
                                            $energy_channel_3_ts);

        $current_total_home =  $shelly_api_device_response->{"switch:0"}->current;
        $current_total_home += $shelly_api_device_response->{"switch:1"}->current;
        $current_total_home += $shelly_api_device_response->{"switch:2"}->current;
        $current_total_home += $shelly_api_device_response->{"switch:3"}->current;


        // Unix minute time stamp for the power and energy readings
        $minute_ts = $shelly_api_device_response->{"switch:0"}->aenergy->minute_ts;

        $shelly_4pm_obj = new stdClass;

        // add these to returned object for later use in calling program
        $shelly_4pm_obj->power_total_to_home_kw   = $power_total_to_home_kw;
        $shelly_4pm_obj->power_total_to_home      = $power_total_to_home;

        $shelly_4pm_obj->power_to_home_kw         = $power_to_home_kw;
        $shelly_4pm_obj->power_to_ac_kw           = $power_to_ac_kw;
        $shelly_4pm_obj->power_to_pump_kw         = $power_to_pump_kw;

        $shelly_4pm_obj->energy_total_to_home_ts  = $energy_total_to_home_ts;
        $shelly_4pm_obj->minute_ts                = $minute_ts;
        $shelly_4pm_obj->current_total_home       = $current_total_home;
        $shelly_4pm_obj->voltage_home             = $shelly_api_device_response->{"switch:3"}->voltage;

        // set the state of the channel if OFF or ON. ON switch will be true and OFF will be false
        $shelly_4pm_obj->pump_switch_status_bool  = $shelly_api_device_response->{"switch:0"}->output;
        $shelly_4pm_obj->ac_switch_status_bool    = $shelly_api_device_response->{"switch:1"}->output;
        $shelly_4pm_obj->home_switch_status_bool  = $shelly_api_device_response->{"switch:2"}->output || $shelly_api_device_response->{"switch:3"}->output;

        return $shelly_4pm_obj;
    }

    /**
     * 'grid_wh_counter_midnight' user meta is set at midnight elsewhere using the current reading then
     *  At any time after, this midnight reading is subtracted from current reading to get consumption since midnight
     *  @param string:$phase defaults to 'c' as the home is supplied by the B phase of RYB
     *  @return object:$shelly_3p_grid_energy_measurement_obj contsining all the measurements
     *  There is a slight confusion now since the a,b,c variable names don't alwyas correspond to the actual R/Y/B phases.
     *  Murty keeps switching the phase to the home and so we pass that in as phase for the main a based variable names
     *  This is so we don't keep changing the code
     */
    public function get_shelly_3p_grid_wh_since_midnight_over_lan(  int     $user_index, 
                                                                    string  $wp_user_name, 
                                                                    int     $wp_user_ID     ): ? object
    {
      // Blue phase of RYB is assigned to home so this corresponds to c phase of abc
      $home_total_act_energy_string  = "c_total_act_energy"; 
      $home_act_power_string         = "c_act_power";
      $home_voltage_string           = "c_voltage";

      // Yellow phase of RYB is assigned to car charger so this corresponds to b phase of abc sequence
      $car_charger_total_act_energy_string  = "b_total_act_energy";
      $car_charger_act_power_string         = "b_act_power"; 

      // get value of Shelly Pro 3EM Red phase watt hour counter as set at midnight
      $grid_wh_counter_at_midnight = (int) round( (float) get_user_meta( $wp_user_ID, 'grid_wh_counter_at_midnight', true), 0);

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

      $shelly_3p_grid_energy_measurement_obj = new stdClass;

      // check to make sure that it exists. If null API call was fruitless
      if (  empty(        $shelly_api_device_response ) || 
            empty(        $shelly_api_device_response->{"emdata:0"}->$home_total_act_energy_string )         ||
            (int) round(  $shelly_api_device_response->{"emdata:0"}->$home_total_act_energy_string, 0 ) < 0
          )
      {
        $this->verbose ? error_log("Shelly 3EM Grid Energy API call failed"): false;

        // since no valid reading so lets use the reading from transient.
        // since no AC power the readings don't change from whats in the transient anyway.
        // since no valid reading so lets use the reading from transient
        $home_grid_wh_counter_now_from_transient        = (float) get_transient('home_grid_wh_counter');
        $car_charger_grid_wh_counter_now_from_transient = (float) get_transient('car_charger_grid_wh_counter');

        $shelly_3p_grid_energy_measurement_obj->home_grid_wh_counter_now        = $home_grid_wh_counter_now_from_transient;
        $shelly_3p_grid_energy_measurement_obj->car_charger_grid_wh_counter_now = $car_charger_grid_wh_counter_now_from_transient;

        $home_grid_wh_accumulated_since_midnight = $home_grid_wh_counter_now_from_transient - $grid_wh_counter_at_midnight;


        $shelly_3p_grid_energy_measurement_obj->home_grid_wh_accumulated_since_midnight  = $home_grid_wh_accumulated_since_midnight;
        $shelly_3p_grid_energy_measurement_obj->home_grid_kwh_accumulated_since_midnight = round( $home_grid_wh_accumulated_since_midnight * 0.001, 3);

        // offline so no power
        $shelly_3p_grid_energy_measurement_obj->home_grid_kw_power        = 0;
        $shelly_3p_grid_energy_measurement_obj->car_charger_grid_kw_power = 0;

        return $shelly_3p_grid_energy_measurement_obj;
      }
      else
      { // we have a valid reading from SHelly 3EM device

        // main power to home. The phase is deretermined by passed in string: a/b/c corresponding to R/Y/B
        $home_grid_wh_counter_now  = $shelly_api_device_response->{"emdata:0"}->$home_total_act_energy_string;

        $home_grid_w_power           = $shelly_api_device_response->{"em:0"}->$home_act_power_string;
        $home_grid_kw_power          = round( 0.001 * $home_grid_w_power, 3);

        $home_grid_voltage           = $shelly_api_device_response->{"em:0"}->$home_voltage_string;

        // get energy counter value and power values of phase supplying car charger, assumed b or Y phase
        $car_charger_grid_wh_counter_now  = $shelly_api_device_response->{"emdata:0"}->$car_charger_total_act_energy_string;
        $car_charger_grid_w_power         = $shelly_api_device_response->{"em:0"}->$car_charger_act_power_string;
        $car_charger_grid_kw_power        = round( 0.001 * $car_charger_grid_w_power, 3);
        
        // update the transient with most recent measurement
        set_transient( 'home_grid_wh_counter',        $home_grid_wh_counter_now,        24 * 60 * 60 );
        set_transient( 'car_charger_grid_wh_counter', $car_charger_grid_wh_counter_now, 24 * 60 * 60 );

        $home_grid_wh_accumulated_since_midnight  = $home_grid_wh_counter_now - $grid_wh_counter_at_midnight;
        $home_grid_kwh_accumulated_since_midnight = round( 0.001 * $home_grid_wh_accumulated_since_midnight, 3);

        $shelly_3p_grid_energy_measurement_obj->home_grid_wh_counter_now        = $home_grid_wh_counter_now;
        $shelly_3p_grid_energy_measurement_obj->car_charger_grid_wh_counter_now = $car_charger_grid_wh_counter_now;

        $shelly_3p_grid_energy_measurement_obj->home_grid_kw_power         = $home_grid_kw_power;
        $shelly_3p_grid_energy_measurement_obj->car_charger_grid_kw_power  = $car_charger_grid_kw_power;

        $shelly_3p_grid_energy_measurement_obj->home_grid_voltage         = $home_grid_voltage;

        $shelly_3p_grid_energy_measurement_obj->home_grid_wh_accumulated_since_midnight   = $home_grid_wh_accumulated_since_midnight;
        $shelly_3p_grid_energy_measurement_obj->home_grid_kwh_accumulated_since_midnight  = $home_grid_kwh_accumulated_since_midnight;

        return $shelly_3p_grid_energy_measurement_obj;
      }
      
    }


    /**
     *  @param int:$user_index in the config file
     *  @return int:$studer_time_offset_in_mins_lagging is the number of minutes that the Studer CLock is Lagging the server
     */
    public function get_studer_clock_offset( int $user_index )
    {
      $config = $this->config;

      $wp_user_name = $config['accounts'][$user_index]['wp_user_name'];

      // Get transient of Studer offset if it exists
      if ( false === get_transient( $wp_user_name . '_' . 'studer_time_offset_in_mins_lagging' ) )
      {
        // make an API call to get value of parameter 5002 which is the UNIX time stamp including the UTC offest
        $base_url  = $config['studer_api_baseurl'];
        $uhash     = $config['accounts'][$user_index]['uhash'];
        $phash     = $config['accounts'][$user_index]['phash'];

        $studer_api = new studer_api($uhash, $phash, $base_url);
          $studer_api->paramId = 5002;
          $studer_api->device = "RCC1";
          $studer_api->paramPart = "Value";

        // Make the API call to get the parameter value
        $studer_clock_unix_timestamp_with_utc_offset = $studer_api->get_parameter_value();

        $this->verbose ? error_log( "studer_clock_unix_timestamp_with_utc_offset: " . $studer_clock_unix_timestamp_with_utc_offset ): false;
        
        // if the value is null due to a bad API response then do nothing and return
        if ( empty( $studer_clock_unix_timestamp_with_utc_offset )) return;

        // create datetime object from studer timestamp. Note that this already has the UTC offeset for India
        $rcc_datetime_obj = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $rcc_datetime_obj->setTimeStamp($studer_clock_unix_timestamp_with_utc_offset);

        $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

        // form interval object between now and Studer's time stamp under investigation
        $diff = $now->diff( $rcc_datetime_obj );

        // positive means lagging behind, negative means leading ahead, of correct server time.
        // If Studer clock was correctr the offset should be 0 but Studer clock seems slow for some reason
        // 330 comes from pre-existing UTC offest of 5:30 already present in Studer's time stamp
        $studer_time_offset_in_mins_lagging = 330 - ( $diff->i  + $diff->h *60);

        set_transient(  $wp_user_name . '_' . 'studer_time_offset_in_mins_lagging',  
                        $studer_time_offset_in_mins_lagging, 
                        1*60*60 );

        $this->verbose ? error_log( "Studer clock offset lags Server clock by: " . $studer_time_offset_in_mins_lagging . " mins"): false;
      }
      else
      {
        // offset already computed and transient still valid, just read in the value
        $studer_time_offset_in_mins_lagging = get_transient(  $wp_user_name . '_' . 'studer_time_offset_in_mins_lagging' );
      }
      return $studer_time_offset_in_mins_lagging;
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
    public function is_time_just_pass_midnight( int $user_index, string $wp_user_name ): bool
    {
      // if not within an hour of server clocks midnight return false. Studer offset will never be allowed to be more than 1h
      if ($this->nowIsWithinTimeLimits("00:03:00", "23:59:00") )
      {
        return false;
      }
      // we only get here betweeon 23:59:00 and 00:02:59
      // if the transient is expired it means we need to check
      if ( false === get_transient( 'is_time_just_pass_midnight' ) )
      {
        // this could also be leading in which case the sign will be automatically negative
        $studer_time_offset_in_mins_lagging = (int) 0;

        // get current time compensated for our timezone
        $test = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $h=$test->format('H');
        $m=$test->format('i');
        $s=$test->format('s');

        // if hours are 0 and offset adjusted minutes are 0 then we are just pass midnight per Studer clock
        // we added an additional offset just to be sure to account for any seconds offset
        if( $h == 0 && $m  > 0 ) 
        {
          // We are just past midnight on Studer clock, so return true after setiimg the transient
          set_transient( 'is_time_just_pass_midnight',  'yes', 5 * 60 );
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
    public function check_if_soc_after_dark_happened( int $user_index, string $wp_user_name, int $wp_user_ID ) :bool
    {
      //

      // Get the transient if it exists
      if (false === ($timestamp_soc_capture_after_dark = get_transient( 'timestamp_soc_capture_after_dark' ) ) )
      {
        // if transient DOES NOT exist then read in value from user meta
        $timestamp_soc_capture_after_dark = get_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', true);
      }
      else
      {
        // transient exists so get it
        $timestamp_soc_capture_after_dark = get_transient( 'timestamp_soc_capture_after_dark' );
      }

      if ( empty( $timestamp_soc_capture_after_dark ) )
      {
        // timestamp is not valid
        $this->verbose ? error_log( "Time stamp for SOC capture after dark is empty or not valid") : false;

        return false;
      }

      // we have a non-emtpy timestamp. We have to check its validity.
      // It is valid if the timestamp is after sunset and is within 12h of it
      $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

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
        if ( false === ( $timestamp_soc_capture_after_dark = get_transient( 'timestamp_soc_capture_after_dark' ) ) 
                    ||
                       empty(get_user_meta($wp_user_ID, 'timestamp_soc_capture_after_dark', true))
            )
        {
          // transient has expired or doesn't exist, OR meta data also is empty
          // Capture the after dark SOC, energy cunter and timestamp
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $timestamp_soc_capture_after_dark = $now->getTimestamp();

          update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $present_home_wh_reading);
          update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $timestamp_soc_capture_after_dark);
          update_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark', $SOC_percentage_now);

          // set transient to last for 13h only
          set_transient( 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark,  13 * 3600 );
          set_transient( 'shelly_energy_counter_after_dark',  $present_home_wh_reading,           13 * 3600 );
          set_transient( 'soc_percentage_update_after_dark',  $SOC_percentage_now,                13 * 3600 );

          error_log("SOC Capture after dark Done - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $present_home_wh_reading);

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

            set_transient( 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark,  13 * 3600 );
            set_transient( 'shelly_energy_counter_after_dark',  $present_home_wh_reading,           13 * 3600);
            set_transient( 'soc_percentage_update_after_dark',  $SOC_percentage_now,                13 * 3600 );


            update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark',  $present_home_wh_reading);
            update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark);
            update_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark',  $SOC_percentage_now);

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

        $account = $config['accounts'][0];
        
        $wp_user_name = $account['wp_user_name'];

        // Get the wp user object given the above username
        $wp_user_obj  = get_user_by('login', $wp_user_name);

        $wp_user_ID   = $wp_user_obj->ID;

        if ( $wp_user_ID )
        { // we have a valid user
          
          // Trigger an all usermeta get such that all routines called from this loop will have a valid updated usermeta
          // The call also updates the all usermeta as a property of this object for access from anywahere in the class
          $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

          // extract the control flag for the servo loop to pass to the servo routine
          $do_shelly  = $all_usermeta['do_shelly'];

          // extract the control flag to perform minutely updates
          $do_minutely_updates  = $all_usermeta['do_minutely_updates'];

          // Check if the control flag for minutely updates is TRUE. If so get the readings
          if( $do_minutely_updates ) 
          { // get all the readings for a user. User Index is 0 since only one user, this logged in admin user
            
            // 4 loops for each wp-cron trigger spaced by 15s due to the sleep function. wp-cron every 60s
            for ($i=0; $i < 4; $i++) 
            { 
              $this->get_readings_and_servo_grid_switch( 0, $wp_user_ID, $wp_user_name, $do_shelly, false );

              sleep(10);
            }
            
          }
        }
        else
        {
          error_log("WP user ID: $wp_user_ID is not valid");
        }
    }


    /**
     *  Assumes pump is channel 0 of the Shelly Pro 4PM supplying the entire Home Load
     */
    public function turn_pump_on_off_over_lan( int $user_index, string $desired_state ) : bool
    {
        // get the config array from the object properties
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_homepwr'];
        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_4pm'];

        // set the channel of the switch that the pump is on
        $channel_pump  = 0;

        $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, $channel_pump );

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
            error_log( "No Action in Pump Switch done since no change is desired - Initial State: $pump_initial_switch_state, desired State: $desired_state" );
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
          return true;
        }
        else
        {
          error_log( "Failed to switch - Initial State: $pump_initial_switch_state, desired State: $desired_state, Final State: $pump_final_switch_state" );
          return false;
        }
    }
    

    /**
     * 
     */
    public function control_pump_on_duration( int $wp_user_ID, int $user_index, object $shelly_4pm_readings_object )
    {
      if ( empty( $shelly_4pm_readings_object ) )
      {
        // bad data passed in do nothing
        error_log( "Pump bad data passed in do nothing in function control_pump_on_duration" );
        return null;
      }

      // get the user meta, all of it as an array for fast retrieval rtahr than 1 by 1 as done before
      $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

      // get webpshr subscriber id for this user
      // $webpushr_subscriber_id = $all_usermeta['webpushr_subscriber_id'];

      // Webpushr Push notifications API Key
      // $webpushrKey            = $this->config['accounts'][$user_index]['webpushrKey'];

      // Webpushr Token
      // $webpushrAuthToken      = $this->config['accounts'][$user_index]['webpushrAuthToken'];

      // pump_duration_secs_max
      $pump_duration_secs_max           = $all_usermeta['pump_duration_secs_max'];

      // pump_duration_control flag
      $pump_duration_control            = $all_usermeta['pump_duration_control'];

      // pump_power_restart_interval_secs
      $pump_power_restart_interval_secs = $all_usermeta['pump_power_restart_interval_secs'];

      // set property in case pump was off so that this doesnt give a php notice otherwise
      $shelly_4pm_readings_object->pump_ON_duration_secs = 0;

      // set default timezone
      //

      // Is the pump enabled or not?
      $power_to_pump_is_enabled = $shelly_4pm_readings_object->pump_switch_status_bool;

      // Pump power consumption in watts
      $pump_power_watts = (int) round(  $shelly_4pm_readings_object->power_to_pump_kw * 1000, 0 );

      // determine if pump is drawing power or not
      $pump_is_drawing_power = ( $pump_power_watts > 50 );

      // if we are here it means pump is ON or power is disabled
      // check if required transients exist
      if ( false === ( $pump_already_ON = get_transient( 'pump_already_ON' ) ) )
      {
        // the transient does NOT exist so lets initialize the transients to state that pump is OFF
        // This happens rarely, when transients get wiped out or 1st time code is run
        set_transient( 'pump_already_ON', 0,  3600 );

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
        // pump power is Enabled by Shelly 4PM but pump is OFF due to tank level controller
        case ( ! $pump_is_drawing_power && $power_to_pump_is_enabled ):

          // Check to see if pump just got auto turned OFF by pump controller  as it normally should when tank is full
          if ( ! empty( $pump_already_ON ) )
          {
            // reset the transient so next time it wont come here due to above if condition
            set_transient( 'pump_already_ON', 0,  3600 );

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

            // set pump start time as current time stamp. So the duration will be small from now on
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
            // Pump was OFF long back so we just need to reset the transients
            set_transient( 'pump_already_ON', 0,  3600 );

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
        case ( $pump_is_drawing_power &&  empty( $pump_already_ON ) ) :

          $this->verbose ? error_log("Pump Just turned ON") : false;

          // set the flag to indicate that pump is already on for next cycle check
          $pump_already_ON = 1;
          
          // update the transient so next check will work
          set_transient( 'pump_already_ON', 1, 2 * 3600 );

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
        case ( $pump_is_drawing_power &&  ( ! empty( $pump_already_ON ) ) ):

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
          if ( $pump_ON_duration_secs > 3600 && $this->check_if_main_control_site_avasarala_is_offline_for_long() && $pump_duration_control)
          {
            // turn shelly power for pump OFF and update transients but only if control site is offline for more than 15m
            $status_turn_pump_off = $this->turn_pump_on_off_over_lan( $user_index, 'off' );


            $pump_notification_count = get_transient( 'pump_notification_count' );

            if ( $status_turn_pump_off )
            {
              // the pump was tuneed off per status
              error_log("Pump turned OFF after duration of: $pump_ON_duration_secs Seconds");

              // pump is not ON anymore so set the flag to false
              set_transient( 'pump_already_ON', 0, 12 * 3600 );

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
                // the pump was ordered to turn off but it did not
                error_log("Problem - Pump could NOT be turned OFF after duration of: $pump_ON_duration_secs Seconds");
                
                $notification_title   = "Pump OFF problem";
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

          if ( $pump_OFF_duration_secs >= 120 && $pump_OFF_duration_secs <= 360 && $pump_duration_control )
          {
            // turn the shelly 4PM pump control back ON after 2m
            $status_turn_pump_on = $this->turn_pump_on_off_over_lan( $user_index, 'on' );

            if ( ! $status_turn_pump_on )
            {
              error_log("Pump could NOT be turned back ON after duration of: $pump_OFF_duration_secs Seconds after Pump OFF - investigate");
            }
            else
            {
              error_log("Pump turned back ON after duration of: $pump_OFF_duration_secs Seconds after Pump OFF");
            }

            

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
                                                        bool    $make_studer_api_call = true ) : void
    {
        { // Define boolean control variables for various time intervals

          // This is the main object that we deal with  for storing and processing data gathered from our IOT devices
          $shelly_readings_obj = new stdClass;

          { // get the estimated solar power object from calculations for a clear day
              
            $est_solar_obj = $this->estimated_solar_power($user_index);
            // error_log(print_r($est_solar_obj, true));

            $est_solar_total_kw = $est_solar_obj->est_solar_total_kw;

            $total_to_west_panel_ratio = $est_solar_obj->total_to_west_panel_ratio;

            $est_solar_kw_arr = $est_solar_obj->est_solar_kw_arr;

            $sunrise_decimal_hours  = $est_solar_obj->sunrise;
            $sunset_decimal_hours   = $est_solar_obj->sunset;

            $sunrise_hms_format_solarcalc = gmdate('H:i:s', floor( $sunrise_decimal_hours * 3600  ) );
            $sunset_hms_format_solarcalc  = gmdate('H:i:s', floor( $sunset_decimal_hours  * 3600  ) );

            $sunset_plus_10_minutes_hms_format_solarcalc = gmdate('H:i:s', floor( $sunset_decimal_hours * 3600 + 10 * 60  ) );
            $sunset_plus_15_minutes_hms_format_solarcalc = gmdate('H:i:s', floor( $sunset_decimal_hours * 3600 + 15 * 60  ) );

            // Boolean Variable to designate it is a cloudy day. This is derived from a free external API service
            $it_is_a_cloudy_day   = $this->cloudiness_forecast->it_is_a_cloudy_day_weighted_average;

            // copy variables to shelly readings pobject properties
            

            $shelly_readings_obj->est_solar_total_kw          = $est_solar_total_kw;
            $shelly_readings_obj->total_to_west_panel_ratio   = $total_to_west_panel_ratio;
            $shelly_readings_obj->est_solar_kw_arr            = $est_solar_kw_arr;

            $shelly_readings_obj->it_is_a_cloudy_day            = $it_is_a_cloudy_day;
            $shelly_readings_obj->cloudiness_average_percentage = $this->cloudiness_forecast->cloudiness_average_percentage;
            $shelly_readings_obj->cloudiness_average_percentage_weighted = $this->cloudiness_forecast->cloudiness_average_percentage_weighted;
          }
          
          $sunrise_hms_format   = $sunrise_hms_format_solarcalc ?? '06:00:00';
          $sunset_hms_format    = $sunset_hms_format_solarcalc  ?? '18:00:00';

          $shelly_readings_obj->sunrise = $sunrise_hms_format;
          $shelly_readings_obj->sunset  = $sunset_hms_format;

          // error_log("Sunrise: $sunrise_hms_format, Sunset: $sunset_hms_format");

          $sunset_plus_10_minutes_hms_format  = $sunset_plus_10_minutes_hms_format_solarcalc ?? "18:10:00";
          $sunset_plus_15_minutes_hms_format  = $sunset_plus_15_minutes_hms_format_solarcalc ?? "18:15:00";

          // error_log("Sunset0: $sunset_plus_10_minutes_hms_format, Sunset15: $sunset_plus_15_minutes_hms_format");

          // From sunset to 15m after, the total time window for SOC after Dark Capture
          $time_window_for_soc_dark_capture_open = 
                              $this->nowIsWithinTimeLimits( $sunset_hms_format, $sunset_plus_15_minutes_hms_format );

          // From sunset to 10m after is the time window alloted for SOC capture after dark, using Studder 1st.
          $time_window_open_for_soc_capture_after_dark_using_studer = 
                              $this->nowIsWithinTimeLimits( $sunset_hms_format, $sunset_plus_10_minutes_hms_format );

          // From 10m after sunset to 5m after is the time window for SOC capture after dark using Shelly, if Studer fails in 1st window
          $time_window_open_for_soc_capture_after_dark_using_shelly = 
                              $this->nowIsWithinTimeLimits( $sunset_plus_10_minutes_hms_format, $sunset_plus_15_minutes_hms_format );

          // it is still dark if now is after sunset to midnight and from midnight to sunrise.
          $it_is_still_dark = $this->nowIsWithinTimeLimits( $sunset_hms_format, "23:59:59" ) || 
                              $this->nowIsWithinTimeLimits( "00:00", $sunrise_hms_format );

          // Boolean values for checking is present time is within defined time intervals
          $now_is_daytime       = $this->nowIsWithinTimeLimits("08:30", "16:30"); // changed from 17:30  on 7/28/22
          $now_is_sunset        = $this->nowIsWithinTimeLimits("16:31", "16:41");
        }

        { // Get user meta for limits and controls. These should not change inside of the for loop in cron exec
          $all_usermeta                           = $this->get_all_usermeta( $wp_user_ID );
          // SOC percentage needed to trigger LVDS
          $soc_percentage_lvds_setting            = $all_usermeta['soc_percentage_lvds_setting']  ?? 40;

          // Avg Battery Voltage lower threshold for LVDS triggers
          $average_battery_voltage_lvds_setting   = $all_usermeta['average_battery_voltage_lvds_setting']  ?? 48.3;

          // Switch releases if SOC is above this level 
          $soc_percentage_switch_release_setting  = $all_usermeta['soc_percentage_switch_release_setting']  ?? 95.0; 

          // battery float voltage setting. Only used for SOC clamp for 100%
          $average_battery_float_voltage          = $all_usermeta['average_battery_float_voltage'] ?? 51.3; 

          // Min VOltage at ACIN for RDBC to switch to GRID
          $acin_min_voltage                       = $all_usermeta['acin_min_voltage'] ?? 199;  

          // Max voltage at ACIN for RDBC to switch to GRID
          $acin_max_voltage                       = $all_usermeta['acin_max_voltage'] ?? 241; 

          // Minimum Psolar before RDBC can be actiated
          $psolar_kw_min                          = $all_usermeta['psolar_kw_min'] ?? 0.3;  

          // get operation flags from user meta. Set it to false if not set
          $keep_shelly_switch_closed_always       = $all_usermeta['keep_shelly_switch_closed_always'] ?? false;

          // get the installed battery capacity in KWH from config
          $battery_capacity_kwh                   = $this->config['accounts'][$user_index]['battery_capacity'];

          // Battery capacity for 100% SOC in AH
          $battery_capacity_ah                    = $this->config['accounts'][$user_index]['battery_capacity_ah'];
        }

        { // get the SOCs from the user meta.

          // Get the SOC percentage at beginning of Dayfrom the user meta. This gets updated only just past midnight once
          $soc_percentage_at_midnight = (float) get_user_meta($wp_user_ID, "soc_percentage_at_midnight",  true);

          // SOC percentage after dark. This gets captured at dark and gets updated every cycle
          // using only shelly devices and does NOT involve Battery Current based measurements.
          $soc_percentage_after_dark  = (float) get_user_meta( $wp_user_ID, 'soc_percentage_after_dark',  true);
        }

        { // --------------------- Shelly1PM ACIN SWITCH data after making a Shelly API call -------------------

          $shelly_switch_acin_details_arr = $this->get_shelly_switch_acin_details_over_lan( $user_index );

          $shelly1pm_acin_switch_config     = $shelly_switch_acin_details_arr['shelly1pm_acin_switch_config'];  // Is configuration valid?
          $control_shelly                   = $shelly_switch_acin_details_arr['control_shelly'];                // is switch set to be controllable?
          $shelly1pm_acin_switch_status     = $shelly_switch_acin_details_arr['shelly1pm_acin_switch_status'];  // ON/OFF/OFFLINE/Not COnfigured
          $shelly1pm_acin_voltage           = $shelly_switch_acin_details_arr['shelly1pm_acin_voltage'];
          $shelly1pm_acin_current           = $shelly_switch_acin_details_arr['shelly1pm_acin_current'];
          $shelly1pm_acin_power_kw          = $shelly_switch_acin_details_arr['shelly1pm_acin_power_kw'];

          $shelly_readings_obj->shelly_switch_acin_details_arr = $shelly_switch_acin_details_arr;
          // error_log("Shelly 1PM AC IN switch: Status: $shelly1pm_acin_switch_status, AC Voltage: $shelly1pm_acin_voltage");
        }

        
        
        {  // make all measurements
          
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $studer_measured_battery_amps_now_timestamp = $now->getTimestamp();

          { // Make Shelly pro 3EM energy measuremnts of 3phase Grid
            $shelly_3p_grid_wh_measurement_obj = $this->get_shelly_3p_grid_wh_since_midnight_over_lan( $user_index, $wp_user_name, $wp_user_ID );

            // we have a valid Shelly Pro 3EM measurement of the Grid Supply 
            $home_grid_wh_counter_now               = $shelly_3p_grid_wh_measurement_obj->home_grid_wh_counter_now;
            $car_charger_grid_wh_counter_now        = $shelly_3p_grid_wh_measurement_obj->car_charger_grid_wh_counter_now;

            $home_grid_wh_accumulated_since_midnight   = $shelly_3p_grid_wh_measurement_obj->home_grid_wh_accumulated_since_midnight;
            $home_grid_kwh_accumulated_since_midnight  = round( $home_grid_wh_accumulated_since_midnight * 0.001, 3 );
            $home_grid_kw_power                        = $shelly_3p_grid_wh_measurement_obj->home_grid_kw_power;
            $car_charger_grid_kw_power                 = $shelly_3p_grid_wh_measurement_obj->car_charger_grid_kw_power;
            $home_grid_voltage                         = $shelly_3p_grid_wh_measurement_obj->home_grid_voltage;
            
            $shelly_readings_obj->home_grid_wh_counter_now          = $home_grid_wh_counter_now;
            $shelly_readings_obj->car_charger_grid_wh_counter_now   = $car_charger_grid_wh_counter_now;
            $shelly_readings_obj->home_grid_kw_power                = $home_grid_kw_power;
            $shelly_readings_obj->home_grid_voltage                 = $home_grid_voltage;
            $shelly_readings_obj->car_charger_grid_kw_power         = $car_charger_grid_kw_power;

            $shelly_readings_obj->home_grid_wh_accumulated_since_midnight    = $home_grid_wh_accumulated_since_midnight;
            $shelly_readings_obj->home_grid_kwh_accumulated_since_midnight   = $home_grid_kwh_accumulated_since_midnight;

            $this->verbose ? error_log("home_grid_wh_counter_now: $home_grid_wh_counter_now, wh since midnight: $home_grid_wh_accumulated_since_midnight, Home Grid PowerKW: $home_grid_kw_power"): false;
          }
          
          { // Measure Battery Charging current as positive using Shelly plus Add-on
            
            $shelly_battery_measurement_object = $this->get_shelly_battery_measurement_over_lan(  $user_index, 
                                                                                                  $wp_user_name, 
                                                                                                  $wp_user_ID, 
                                                                                                  $shelly1pm_acin_switch_status, 
                                                                                                  $home_grid_kw_power, 
                                                                                                  $it_is_still_dark );
            if ( $shelly_battery_measurement_object )
            { // valid shelly battery measurement - load object with battery measurement data
              $battery_capacity_ah                                = $shelly_battery_measurement_object->battery_capacity_ah;
              $battery_ah_this_measurement                        = $shelly_battery_measurement_object->battery_ah_this_measurement;
              $battery_amps                                       = $shelly_battery_measurement_object->battery_amps;

              $battery_soc_percentage_accumulated_since_midnight  = $shelly_battery_measurement_object->battery_soc_percentage_accumulated_since_midnight;
              $battery_accumulated_ah_since_midnight              = $shelly_battery_measurement_object->battery_accumulated_ah_since_midnight;
              $battery_accumulated_kwh_since_midnight             = round( 49.8 * 0.001 * $battery_accumulated_ah_since_midnight, 3 );

              

              $shelly_readings_obj->battery_capacity_ah       = $battery_capacity_ah;
              $shelly_readings_obj->battery_amps              = $battery_amps;  
              $shelly_readings_obj->battery_power_kw          = round( 49.8 * 0.001 * $battery_amps, 3 );


              $shelly_readings_obj->battery_accumulated_ah_since_midnight             = $battery_accumulated_ah_since_midnight;
              $shelly_readings_obj->battery_soc_percentage_accumulated_since_midnight = $battery_soc_percentage_accumulated_since_midnight;
              $shelly_readings_obj->battery_ah_this_measurement                       = $battery_ah_this_measurement;
              $shelly_readings_obj->battery_accumulated_kwh_since_midnight            = $battery_accumulated_kwh_since_midnight;
            }
          }

          { // Now make a Shelly 4PM measurement to get individual powers for all channels
            
            $shelly_4pm_readings_object = $this->get_shelly_device_status_homepwr_over_lan( $user_index );

            if ( ! empty( $shelly_4pm_readings_object ) ) 
            {   // there is a valid response from the Shelly 4PM switch device
                
                // Also check and control pump ON duration
                // $this->control_pump_on_duration( $wp_user_ID, $user_index, $shelly_4pm_readings_object);

                $power_total_to_home_kw = $shelly_4pm_readings_object->power_total_to_home_kw;

                { // Load the Object with properties from the Shelly 4PM object
                  $shelly_readings_obj->power_to_home_kw    = $shelly_4pm_readings_object->power_to_home_kw;
                  $shelly_readings_obj->power_to_ac_kw      = $shelly_4pm_readings_object->power_to_ac_kw;
                  $shelly_readings_obj->power_to_pump_kw    = $shelly_4pm_readings_object->power_to_pump_kw;
                  $shelly_readings_obj->power_total_to_home = $shelly_4pm_readings_object->power_total_to_home;
                  $shelly_readings_obj->power_total_to_home_kw  = $shelly_4pm_readings_object->power_total_to_home_kw;
                  $shelly_readings_obj->current_total_home      = $shelly_4pm_readings_object->current_total_home;
                  $shelly_readings_obj->energy_total_to_home_ts = $shelly_4pm_readings_object->energy_total_to_home_ts;

                  $shelly_readings_obj->pump_switch_status_bool = $shelly_4pm_readings_object->pump_switch_status_bool;
                  $shelly_readings_obj->ac_switch_status_bool   = $shelly_4pm_readings_object->ac_switch_status_bool;
                  $shelly_readings_obj->home_switch_status_bool = $shelly_4pm_readings_object->home_switch_status_bool;
                  $shelly_readings_obj->voltage_home            = $shelly_4pm_readings_object->voltage_home;

                  // when pump duration control happens change the below property to actula variable
                  $shelly_readings_obj->pump_ON_duration_secs   = 0;
                }
            }
          }

          { // make an API call on the Shelly EM device and calculate energy consumed by Home since midnight
            $shelly_em_readings_object = $this->get_shellyem_accumulated_load_wh_since_midnight_over_lan( $user_index, $wp_user_name, $wp_user_ID );

            if ( $shelly_em_readings_object )
            {   // there is a valid response from the Shelly EM home energy WH meter
              // current home energy WH counter reading
              $shelly_em_home_kwh_since_midnight = round( $shelly_em_readings_object->shelly_em_home_wh_since_midnight * 0.001, 3 );

              $shelly_em_home_wh = $shelly_em_readings_object->shelly_em_home_wh;
              $shelly_readings_obj->shelly_em_home_wh = $shelly_em_home_wh;

              // current home power in KW supplied to home
              $shelly_em_home_kw = $shelly_em_readings_object->shelly_em_home_kw;
              $shelly_readings_obj->shelly_em_home_kw = $shelly_em_home_kw;
              
              // present AC RMS phase voltage at panel, after Studer output
              $shelly_em_home_voltage = $shelly_em_readings_object->shelly_em_home_voltage;
              $shelly_readings_obj->shelly_em_home_voltage = $shelly_em_home_voltage;

              // Energy consumed in WH by home since midnight
              $shelly_readings_obj->shelly_em_home_wh_since_midnight = $shelly_em_readings_object->shelly_em_home_wh_since_midnight;

              // energy consumed in KWH by home since midnight as measured by Shelly EM 
              $shelly_readings_obj->shelly_em_home_kwh_since_midnight = $shelly_em_home_kwh_since_midnight;
            }
          }
        }

        { // calculate non-studer based SOC using Shelly Battery Measurements
          $soc_percentage_now_calculated_using_shelly_bm = $soc_percentage_at_midnight + $battery_soc_percentage_accumulated_since_midnight;
          
          // lets update the user meta for updated SOC
          update_user_meta( $wp_user_ID, 'soc_percentage_now_calculated_using_shelly_bm', $soc_percentage_now_calculated_using_shelly_bm);

          // $surplus  power is any surplus from solar after load consumption, available for battery, etc.
          $surplus = round( $shelly_readings_obj->battery_amps * 49.8 * 0.001, 1 ); // in KW

          $shelly_readings_obj->surplus  = $surplus;
          $shelly_readings_obj->soc_percentage_now_calculated_using_shelly_bm  = round( $soc_percentage_now_calculated_using_shelly_bm, 5);

          $soc_percentage_at_midnight_display     = round( $soc_percentage_at_midnight, 1);
          $soc_percentage_shelly_bm_display_only  = round( $soc_percentage_now_calculated_using_shelly_bm, 1);
          $battery_soc_since_midnight_display     = round( $battery_soc_percentage_accumulated_since_midnight, 1);
        }

        if ( $soc_percentage_now_calculated_using_shelly_bm > 100 )
        { // 100% SOC clamp
          // recalculate Battery SOC % accumulated since midnight
          $recal_battery_soc_percentage_accumulated_since_midnight = 100 - $soc_percentage_at_midnight;

          // write this value back to the user meta
          update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', $recal_battery_soc_percentage_accumulated_since_midnight);
        }

        if ( $it_is_still_dark )
        { // Do all the SOC after Dark operations here - Capture and also SOC updation
          
          // check if capture happened. now-event time < 12h since event can happen at 7PM and last till 6:30AM
          $soc_capture_after_dark_happened = $this->check_if_soc_after_dark_happened($user_index, $wp_user_name, $wp_user_ID);

          if (  $soc_capture_after_dark_happened === false  && $shelly_em_home_wh && $time_window_for_soc_dark_capture_open )
          { // event not happened yet so make it happen with valid value for the home energy EM counter reading
            $this->capture_evening_soc_after_dark(  $user_index, 
                                                    $wp_user_name, 
                                                    $wp_user_ID, 
                                                    $soc_percentage_now_calculated_using_shelly_bm, 
                                                    $shelly_em_home_wh,
                                                    $time_window_for_soc_dark_capture_open );
          }

          if ( $soc_capture_after_dark_happened === true )
          { // SOC capture after dark is DONE and it is still dark, so use it to compute SOC after dark using only Shelly readings

            // grid is supplying power only when switch os ON and Power > 0.1KW
            if ( $shelly1pm_acin_switch_status == "ON" && $home_grid_kw_power > 0.1 )
            { // Grid is supplying Load and since Solar is 0, battery current is 0 so no change in battery SOC
              
              // update the after dark energy counter to latest value
              update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $shelly_em_home_wh);

              // SOC is unchanging due to Grid ON however set the variables using the user meta since they are undefined.
              $soc_percentage_now_using_dark_shelly = (float) get_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark',  true);

              $shelly_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;

              $soc_update_method = "shelly-after-dark";
              $soc_percentage_now = $soc_percentage_now_using_dark_shelly;
            }
            else
            { // Inverter is supplying the home since the power from Grid is <= 0.1KW as measured by Shelly 3EM
              
              // get the accumulated SOC and energy counter values from user meta
              $soc_percentage_after_dark        = (float) get_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark',  true);
              $shelly_energy_counter_after_dark = (float) get_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark',   true);

              // get the difference in energy consumed since last reading
              $home_consumption_wh_after_dark_using_shellyem = $shelly_em_home_wh - $shelly_energy_counter_after_dark;

              // convert to KW and round to 3 decimal places
              $home_consumption_kwh_after_dark_using_shellyem = round( $home_consumption_wh_after_dark_using_shellyem * 0.001, 3);

              // calculate SOC percentage discharge (since battery always discharges during dark as there is no solar)
              $soc_percentage_discharge = $home_consumption_kwh_after_dark_using_shellyem / $battery_capacity_kwh * 100;

              // round it to 3 decimal places for accuracy of arithmatic for accumulation
              $soc_percentage_now_using_dark_shelly = round( $soc_percentage_after_dark - $soc_percentage_discharge, 5);

              // check the validity of the SOC using this after dark shelly method
              $soc_after_dark_update_valid =  $soc_percentage_now_using_dark_shelly < 100 &&
                                              $soc_percentage_now_using_dark_shelly > 30;

              // update SOC only if values are reasonable
              if ( $soc_after_dark_update_valid === true )
              {
                // Valid values, update user meta to get differentials for next cycle.
                update_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark', $soc_percentage_now_using_dark_shelly);
                update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $shelly_em_home_wh);

                // update the readings object for transient and display
                $shelly_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;

                $soc_update_method = "shelly-after-dark";
                $soc_percentage_now = $soc_percentage_now_using_dark_shelly;

                // Since the soc after dark method is more accurate than the SOC shely BM
                // calibrate the shelly BM method using the SOC after dark value and soc midnight value
                $recal_battery_soc_percentage_accumulated_since_midnight = $soc_percentage_now_using_dark_shelly - $soc_percentage_at_midnight;

                // update recalibrated value of accumulated battery charge back to user meta
                update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', $recal_battery_soc_percentage_accumulated_since_midnight);
              }
              else
              {
                $soc_update_method = "shelly";
                $soc_percentage_now = $soc_percentage_now_calculated_using_shelly_bm;

                error_log("SOC using after dark Shelly not used due to bad value of: $soc_percentage_now_using_dark_shelly");
              }
            }
          }
        }
        else
        {   // it is daylight now so reset the soc_percentage_update_after_dark value.
          update_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark', 0);

          $soc_update_method = "shelly";
          $soc_percentage_now = $soc_percentage_now_calculated_using_shelly_bm;
        }

        // midnight actions
        if ( $this->is_time_just_pass_midnight( $user_index, $wp_user_name ) )
        {
          // reset Shelly EM Home WH counter to present reading in WH. This is only done once in 24h, at midnight
          update_user_meta( $wp_user_ID, 'shelly_em_home_energy_counter_at_midnight', $shelly_em_home_wh );

          // reset Shelly 3EM Grid Energy counter to present reading. This is only done once in 24h, at midnight
          update_user_meta( $wp_user_ID, 'grid_wh_counter_at_midnight', $home_grid_wh_counter_now );

          // reset the SOC at midnight value to current update. This is only done once in 24h, at midnight
          // we also have the option of using the variable: $soc_percentage_now_using_dark_shelly
          update_user_meta( $wp_user_ID, 'soc_percentage_at_midnight', $soc_percentage_now_using_dark_shelly );

          // reset battery soc accumulated value to 0. This is only done once in 24h, at midnight
          update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', 0);

          error_log("Midnight - shelly_em_home_energy_counter_at_midnight: $shelly_em_home_wh");
          error_log("Midnight - grid_wh_counter_at_midnight: $home_grid_wh_counter_now");
          error_log("Midnight - soc_percentage_at_midnight: $soc_percentage_now_calculated_using_shelly_bm");
          error_log("Midnight - battery_soc_percentage_accumulated_since_midnight: 0");
        }

        $shelly_readings_obj->soc_percentage_now  = $soc_percentage_now;
        $shelly_readings_obj->soc_update_method   = $soc_update_method;

        $soc_percentage_now_display = round( $soc_percentage_now, 1);

        { // local control of LVDS and switch release only if control site is down for long
        
          $main_control_site_avasarala_is_offline_for_long = $this->check_if_main_control_site_avasarala_is_offline_for_long();

          $shelly_readings_obj->main_control_site_avasarala_is_offline_for_long   = $main_control_site_avasarala_is_offline_for_long;

          if (  $main_control_site_avasarala_is_offline_for_long  &&    // Main control site avasarala.in is offline for more than 15m
                $soc_percentage_now < 35                          &&    // local SOC measurement is low
                $shelly1pm_acin_switch_status !== "ON"            &&    // Grid switch is OFF
                $control_shelly === true                              ) // servo control flag is enabled
          {
            // local command to turn ON Shelly 1PM Grid Switch
            error_log("Main control site is down for more than 15m and SOC ls low, commanded to turn ON Shelly 1PM Grid switch");

            // $this->turn_on_off_shelly1pm_acin_switch_over_lan( $user_index, 'on' );
          }
          elseif (  $main_control_site_avasarala_is_offline_for_long  && 
                    $soc_percentage_now > 50                          &&
                    $$helly1pm_acin_switch_status === "ON"            &&
                    $control_shelly === true                              )
          {   // switch release if control site is down for long and it is daylight and soc is above limit and Grid switch is ON still
            
            error_log("Main control site is down for more than 15m and SOC ls high, commanded to turn OFF Shelly 1PM Grid switch");

            // $this->turn_on_off_shelly1pm_acin_switch_over_lan( $user_index, 'off' );
          }
        }


        $this->control_pump_on_duration( $wp_user_ID, $user_index, $shelly_4pm_readings_object );

        $pump_ON_duration_secs = $shelly_4pm_readings_object->pump_ON_duration_secs;

        $shelly_water_heater_data       = $this->get_shelly_device_status_water_heater_over_lan($user_index);

        if ( $shelly_water_heater_data )
        {
          $shelly_water_heater_status_ON  = $shelly_water_heater_data->shelly_water_heater_status_ON;
          $shelly_water_heater_voltage    = $shelly_water_heater_data->shelly_water_heater_voltage;
          $shelly_water_heater_kw         = $shelly_water_heater_data->shelly_water_heater_kw;

          $shelly_readings_obj->shelly_water_heater_data = $shelly_water_heater_data;
        }

        // Since we calculate Psolar indirectly, that depends on conditions as below
        if ($it_is_still_dark)
        { // solar power is 0 as it is still dark
          $shelly_readings_obj->psolar_kw = 0;
        }
        elseif ( $shelly1pm_acin_switch_status != "ON" && ! $it_is_still_dark )
        { // As it is daylight, solar is rpovising both battery charge and home load. Note that battery power can be negative
          $shelly_readings_obj->psolar_kw = ($shelly_readings_obj->battery_power_kw + 1.07 * $shelly_em_home_kw) / 0.96;
        }
        elseif ( $shelly1pm_acin_switch_status == "ON" && ! $it_is_still_dark )
        { // Grid is supplying the load so all of solar supplies the battery charging power
          $shelly_readings_obj->psolar_kw = $shelly_readings_obj->battery_power_kw  / 0.96;
        }
        
        $log_string = "";
        $log_string .= "Batt(A): " . number_format($battery_amps, 1) . " Grid: " . $shelly1pm_acin_switch_status;
        $log_string .= " ShellyEM(V): " . number_format($shelly_em_home_voltage, 0) . " Load(KW): " . number_format($shelly_em_home_kw, 3);
        $log_string .= " pump secs: " . $pump_ON_duration_secs . " Water Heater: " . $shelly_water_heater_status_ON;
        $log_string .= " SOC: " . number_format($soc_percentage_now_display, 1);

        // error_log("Batt(A): $battery_amps, Grid: $shelly1pm_acin_switch_status, ShellyEM(V): $shelly_em_home_voltage, Load(KW): $shelly_em_home_kw, pump ON for: $pump_ON_duration_secs, Water Heater: $shelly_water_heater_status_ON, SOC: $soc_percentage_now_display");
        // error_log($log_string);

        // update transient with new data. Validity is 10m
        set_transient( 'shelly_readings_obj', $shelly_readings_obj, 10 * 60 );

        // set transient for soc update method for 10m
        set_transient( 'soc_update_method', $soc_update_method, 10 * 60 );
    }


    /**
     *  @param int:$number_of_loops each loop is roughly 10s.
     *  counts the number of loops. Each time a loop completes a counter is incremented
     *  increment happens only when the remote control site is not accessible.
     *  WHen the site is accessible the counter is reset to 0. The counter is stored as a transient
     *  This function must be called on every iteration of the main cron loop
     */
    public function check_if_main_control_site_avasarala_is_offline_for_long( int $number_of_loops = 60 ) : bool
    {

      if ( false === ( $main_cron_loop_counter = (int) get_transient( 'main_cron_loop_counter') ) )
      { // if 1st time or transient has expired, reset the counter
        $main_cron_loop_counter = 0;
      }

      // increment counter by 1 for this iteration
      $main_cron_loop_counter += 1;

      // update transient value of iteration counter for next check. The transient duration is 10 mins
      set_transient( 'main_cron_loop_counter', $main_cron_loop_counter, 10 * 60 );

      if ( $main_cron_loop_counter >= 10 )
      { // For every 10 iterations or about 200 secs, do this internet check
        $fp = fsockopen("www.avasarala.in", 80, $errno, $errstr, 5);  // returns handle if successful or bool false if fail

        if ( $fp !== false )
        { // control site is up so reset counters and transients and return false
          // use the returned handle to close the connection
          fclose($fp);

          // reset timers to 0 since site is online
          set_transient( 'minutes_that_site_avasarala_in_is_offline', 0, 10 * 60 );

          // reset the counter to 0 since we just finished checking if contrl site is alive
          set_transient( 'main_cron_loop_counter', 0, 10 * 60 );

          // control site being offline for long is false
          return false;
        }
        else
        { // connection not open returned bool false. Log the error
          error_log("control site www.avasarala.in is NOT reachable, so local intervention may be required");
          error_log("This is the error message: $errstr ($errno)");

          // no need to close the connection that did not open anyway

          // get counter value from transient
          if( false === ( $minutes_that_site_avasarala_in_is_offline = (int) get_transient( 'minutes_that_site_avasarala_in_is_offline') ) )
          {
            // 1st time or transient has expired so reset the counter
            $minutes_that_site_avasarala_in_is_offline = 0;
          }

          // accumulate the counter
          $minutes_that_site_avasarala_in_is_offline += $main_cron_loop_counter;

          // rewrite timer accumulated value for later recall
          set_transient( 'minutes_that_site_avasarala_in_is_offline', $minutes_that_site_avasarala_in_is_offline, 10 * 60 );

          // reset loop counter for site check
          set_transient( 'main_cron_loop_counter', 0, 10 * 60 );

          if ( $minutes_that_site_avasarala_in_is_offline > $number_of_loops )
          { // Site offline for 60 iterations or about 10m as each tick is about 1/4 of a minute
            error_log( "Avasarala site is down for about - $minutes_that_site_avasarala_in_is_offline continuous loops");

            return true;
          }
          else
          {
            // site is offline but not long enough
            return false;
          }
        }
      }
      else
      {
        // not 10 loops yet to check the control site
        return false;
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
    public function check_if_forecast_is_cloudy() : ? object
    {
        $config = $this->config;
        $lat    = $this->lat;
        $lon    = $this->lon;
        $appid  = $config['openweather_appid'];
        $cnt    = 3;

        $current_wether_api   = new openweathermap_api($lat, $lon, $appid, $cnt);
        $cloudiness_forecast   = $current_wether_api->forecast_is_cloudy();

        if ( $cloudiness_forecast )
        {
          return $cloudiness_forecast;
        }
        else
        {
          return null;
        }
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

        $now =  new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $begin = new DateTime($start_time, new DateTimeZone('Asia/Kolkata'));
        $end   = new DateTime($stop_time,  new DateTimeZone('Asia/Kolkata'));

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

        //
        $shelly_readings_obj  = get_transient( "shelly_readings_obj" );

        $it_is_still_dark = $this->nowIsWithinTimeLimits( "18:55", "23:59:59" ) || $this->nowIsWithinTimeLimits( "00:00", "06:30" );

        // check for valid studer values. Return if empty.
        if( empty(  $shelly_readings_obj ) )
        {
            if ( $it_is_still_dark )
            {
              $output .= "Could not get a valid Shelly readings for Home Power at night, using API";
            }
            else
            {
              $output .= "Could not get a valid Studer Reading using API";
            }

            return $output;
        }

        // get the format of all the information for the table in the page
        $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $shelly_readings_obj );

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
                <td id="ac_icon">'            . $format_object->ac_icon            . '</td>
                <td id="pump_icon">'          . $format_object->pump_icon          . '</td>
            </tr>
            <tr>
                <td id="shelly_water_heater_kw">' . $format_object->shelly_water_heater_kw    . '</td>
                <td id="power_to_ac_kw">'   . $format_object->power_to_ac_kw      . '</td>
                <td id="power_to_pump_kw">' . $format_object->power_to_pump_kw    . '</td>
            </tr>
            
        </table>';


        $output .= '<div id="status_html">'. $format_object->status     . '</div>';

        return $output;
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
                $studer_readings_obj = $this->get_studer_min_readings($config_index);

                echo "<pre>" . "Studer Inverter Output (KW): " .    $studer_readings_obj->pout_inverter_ac_kw . "</pre>";
                echo "<pre>" . "Studer Solar Output(KW): " .        $studer_readings_obj->psolar_kw .           "</pre>";
                echo "<pre>" . "Battery Voltage (V): " .            $studer_readings_obj->battery_voltage_vdc . "</pre>";
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

            case "check_if_soc_after_dark_happened":

              // get timestamp for soc after dark capture
              $wp_user_ID = $this->get_wp_user_from_user_index( $config_index )->ID;

              $timestamp_soc_capture_after_dark = get_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', true );

              if ( $this->check_if_soc_after_dark_happened( $timestamp_soc_capture_after_dark ) )
              {
                print ("SOC after dark already happened");
              }
              else
              {
                print ("SOC after dark DID NOT happen yet");
              }

            break;

            case "get_studer_clock_offset":

              $studer_time_offset_in_mins_lagging = $this->get_studer_clock_offset( $config_index );

              print( "Studer time offset in mins lagging = " . $studer_time_offset_in_mins_lagging);
              
            break;

            case "get_shelly_battery_measurement":

              $count = 1;

              

              $wp_user_ID = $this->get_wp_user_from_user_index( $config_index )->ID;

              
                
                $est_solar_kw = $this->estimated_solar_power($config_index);

                // $ratio_west_total = array_sum( $est_solar_kw ) / $est_solar_kw[1];

                $solar_measurement_object = $this->get_shelly_battery_measurement( $config_index, 'transindus_admin', $wp_user_ID, $ratio_west_total );

                $total_solar_current = 1.00 * round( $solar_measurement_object->solar_amps, 1);

                // print( "ADC voltage (V): " .                                $battery_measurement_object->voltage . PHP_EOL );
                print( $total_solar_current . PHP_EOL);
                print( " Time interval (H: " .                              $solar_measurement_object->hours_between_measurement . PHP_EOL);
                print( " Solar (AH) accumulated since last measurement: " . $solar_measurement_object->solar_ah_this_measurement .PHP_EOL);
                

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
     *  @param int:$user_index
     *  @param string:$desired_state can be either 'on' or 'off' to turn on the switch to ON or OFF respectively
     *  @param string:$shelly_switch_name is the name of the switch used to index its shelly_device_id in the config array
     *  @return object:$shelly_device_data is the curl response stdclass object from Shelly device
     */
    public function turn_on_off_shelly1pm_acin_switch_over_lan( int $user_index, string $desired_state ) : ? bool
    {
        // get the config array from the object properties
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];
        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

        // set the channel of the switch
        $channel  = 0;

        $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly, $channel  );

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
            error_log( "No Action in ACIN Switch - Initial Switch State: $initial_switch_state, Desired State: $desired_state" );
            return true;
          }
        }
        else
        {
          // we didn't get a valid response but we can continue and try switching
          error_log( "we didn't get a valid response for ACIN switch initial status check but we can continue and try switching" );
        }

        // Now lets change the switch state by command over LAN
        $shelly_api_device_response = $shelly_api->turn_on_off_shelly_switch_over_lan( $desired_state );
        sleep (1);

        // lets get the new state of the ACIN shelly switch
        $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

        $final_switch_state = $shelly_api_device_response->{'switch:0'}->output;

        if (  ( $final_switch_state === true  &&  ( strtolower( $desired_state) === "on"  || $desired_state === true  || $desired_state == 1) ) || 
              ( $final_switch_state === false &&  ( strtolower( $desired_state) === "off" || $desired_state === false || $desired_state == 0) ) )
        {
          // Final state is same as desired final state so return success
          return true;
        }
        else
        {
          error_log( "ACIN Switch to desired state Failed - Desired State: desired_state, Final State: $final_switch_state" );
          return false;
        }
    }


    /**
     * 
     */
    public function get_shelly_device_status_water_heater_over_lan(int $user_index): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;
      $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_water_heater'];
      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_water_heater'];

      $shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

      // this is $curl_response.
      $shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

      if ( is_null($shelly_api_device_response) ) 
      { // No response for Shelly water heater switch API call

        error_log("Shelly Water Heater Switch over LAN call failed - Reason unknown");
        return null;
      }
      else 
      {  // Switch is ONLINE - Get its status, Voltage, and Power
          // switch ON is true OFF is false boolean variable
          $shelly_water_heater_status         = $shelly_api_device_response->{'switch:0'}->output;
          

          if ($shelly_water_heater_status)
          {
              $shelly_water_heater_status_ON      = (string) "ON";
              $shelly_water_heater_voltage        = $shelly_api_device_response->{'switch:0'}->voltage;
              $shelly_water_heater_current        = $shelly_api_device_response->{'switch:0'}->current;
              $shelly_water_heater_w              = $shelly_api_device_response->{'switch:0'}->apower;
              $shelly_water_heater_kw             = round( $shelly_water_heater_w * 0.001, 3);
          }
          else
          {
              $shelly_water_heater_status_ON      = (string) "OFF";
              $shelly_water_heater_voltage        = $shelly_api_device_response->{'switch:0'}->voltage;
              $shelly_water_heater_current        = 0;
              $shelly_water_heater_kw             = 0;
          }
      }

      $shelly_water_heater_data =  new stdclass;
      $shelly_water_heater_data->shelly_water_heater_status     = $shelly_water_heater_status;
      $shelly_water_heater_data->shelly_water_heater_status_ON  = $shelly_water_heater_status_ON;
      $shelly_water_heater_data->shelly_water_heater_kw         = $shelly_water_heater_kw;
      $shelly_water_heater_data->shelly_water_heater_current    = $shelly_water_heater_current;
      $shelly_water_heater_data->shelly_water_heater_voltage    = $shelly_water_heater_voltage;

      return $shelly_water_heater_data;
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

        $solar_amps_into_battery = 0;
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
              $inverter_current_amps = round($user_value->value, 1);
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
              $solar_amps_into_battery += $user_value->value;

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

        $solar_amps_into_battery = round( $solar_amps_into_battery, 1 );

        // calculate the current into/out of battery and battery instantaneous power
        $battery_charge_amps  = round(  $solar_amps_into_battery + $inverter_current_amps, 1 );   // + is charge, - is discharge

        // conditional class names for battery charge down or up arrow
        if ( $battery_charge_amps > 0.0 )
        {
          // current is positive so battery is charging so arrow is down and to left. Also arrow shall be red to indicate charging
          $battery_charge_arrow_class = "fa fa-long-arrow-down fa-rotate-45 rediconcolor";
          // battery animation class is from ne-sw
          $battery_charge_animation_class = "arrowSliding_ne_sw";

          $battery_color_style = 'greeniconcolor';

          // also good time to compensate for IR drop.
          // Actual voltage is smaller than indicated, when charging
          $battery_voltage_vdc = round($battery_voltage_vdc + abs( $inverter_current_amps ) * $Ra - abs( $battery_charge_amps ) * $Rb, 2);
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
          $battery_charge_animation_class = "arrowSliding_sw_ne";
          $battery_color_style = 'rediconcolor';

          // Actual battery voltage is larger than indicated when discharging
          $battery_voltage_vdc  = round( $battery_voltage_vdc + abs( $inverter_current_amps ) * $Ra + abs( $battery_charge_amps ) * $Rb, 2 );
        }

        $pbattery_kw            = round( $battery_voltage_vdc * $battery_charge_amps * 0.001, 3 );  //$psolar_kw - $pout_inverter_ac_kw;

        switch(true)
        {
          case (abs($battery_charge_amps) < 27 ) :
            $battery_charge_arrow_class .= " fa-1x";
          break;

          case (abs($battery_charge_amps) < 54 ) :
            $battery_charge_arrow_class .= " fa-2x";
          break;

          case (abs($battery_charge_amps) >=54 ) :
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
      $studer_readings_obj->battery_charge_amps         = $battery_charge_amps;
      $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
      $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
      $studer_readings_obj->battery_charge_arrow_class  = $battery_charge_arrow_class;
      $studer_readings_obj->battery_icon_class          = $battery_icon_class;
      $studer_readings_obj->battery_charge_animation_class = $battery_charge_animation_class;
      // $studer_readings_obj->energyout_battery_yesterday    = $energyout_battery_yesterday;

      // update the object with Solar data read
      $studer_readings_obj->psolar_kw                   = $psolar_kw;
      $studer_readings_obj->solar_amps_into_battery     = $solar_amps_into_battery;
      $studer_readings_obj->solar_pv_vdc                = $solar_pv_vdc;
      $studer_readings_obj->solar_arrow_class           = $solar_arrow_class;
      $studer_readings_obj->solar_arrow_animation_class = $solar_arrow_animation_class;
      $studer_readings_obj->psolar_kw_yesterday         = $psolar_kw_yesterday;

      //update the object with Inverter Load details
      $studer_readings_obj->pout_inverter_ac_kw         = $pout_inverter_ac_kw;
      $studer_readings_obj->inverter_current_amps       = $inverter_current_amps;

      // update the Grid input values
      $studer_readings_obj->transfer_relay_state        = $transfer_relay_state;
      $studer_readings_obj->grid_pin_ac_kw              = $grid_pin_ac_kw;
      $studer_readings_obj->grid_input_vac              = $grid_input_vac;
      $studer_readings_obj->grid_input_arrow_class      = $grid_input_arrow_class;
      $studer_readings_obj->aux1_relay_state            = $aux1_relay_state;

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

      // Ensures nonce is correct for security
      check_ajax_referer('my_solar_app_script');

      if ($_POST['data']) {   // extract data from POST sent by the Ajax Call and Sanitize
          
          $data = $_POST['data'];

          // get my user index knowing my login name
          $wp_user_ID   = $data['wp_user_ID'];

          // sanitize the POST data
          $wp_user_ID   = sanitize_text_field($wp_user_ID);
      }

      {    // get user_index based on user_name
        $current_user = get_user_by('id', $wp_user_ID);
        $wp_user_name = $current_user->user_login;
        $user_index   = array_search( $wp_user_name, array_column($this->config['accounts'], 'wp_user_name')) ?? 0;

        // error_log('from CRON Ajax Call: wp_user_ID:' . $wp_user_ID . ' user_index:'   . $user_index);
      }


      
      // assignment inside conditional check, not an equibvalence operator
      if ( $readings_obj = get_transient( 'shelly_readings_obj' ) )
      {   // transient exists so we can send it
          
          $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $readings_obj );

          // send JSON encoded data to client browser AJAX call and then die
          wp_send_json($format_object);
      }
      else 
      {   // transient does not exist so send null
        wp_send_json(null);
      }
    }



    /**
     *  This AJAX handler server side function generates 5s data from measurements
     *  The data is sent by AJAX to  client browser using the AJAX call.
     */
    public function ajax_my_solar_update_handler()     
    {   // service AJax Call
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

            if ( $this->verbose)
            {

            
              error_log("from Ajax Call: toggleGridSwitch Value: " . $toggleGridSwitch . 
                                                    ' wp_user_ID:' . $wp_user_ID       . 
                                              ' doShellyToggle:'   . $doShellyToggle   . 
                                                  ' user_index:'   . $user_index);
            }
        }

        // extract the do_shelly control flag as set in user meta
        $do_shelly  = get_user_meta($wp_user_ID, "do_shelly", true);

        if ($toggleGridSwitch)  
        { // User has requested to toggle the GRID ON/OFF Shelly Switch
          // the current interpretation is that this is the toggle for the keep_always_on flag
          // Find the current status and just toggle the status
          $current_state_keep_always_on =  get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always",  true);

          if ($current_state_keep_always_on == true)
          {
            update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', false);

            error_log('Changed keep always ON flag from true-->false due to Ajax Request');
          }
          else {
            update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', true);
            error_log('Changed keep always ON flag from false-->true due to Ajax Request');
          }
          // Grid ON/OFF is determoned in the CRON loop as usual. 
          return;

          // The code below is obsolete and will never execute

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
                                                                          $do_shelly);

        $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

        wp_send_json($format_object);
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
          error_log("Readings object passed into format data is empty");
          return null;
        }
        $config         = $this->config;

        $soc_update_method = get_transient( 'soc_update_method' );

        // last time when the battery was measured
        $previous_timestamp = get_transient("timestamp_battery_last_measurement");

        // Initialize object to be returned
        $format_object  = new stdClass();

        $status = "";

        $shelly_switch_acin_details_arr = $readings_obj->shelly_switch_acin_details_arr;

        // extract and process Shelly 1PM switch water heater data
        $shelly_water_heater_data     = $readings_obj->shelly_water_heater_data;     // data object
        $shelly_water_heater_kw       = $shelly_water_heater_data->shelly_water_heater_kw;
        $shelly_water_heater_status   = $shelly_water_heater_data->shelly_water_heater_status;  // boolean variable
        $shelly_water_heater_current  = $shelly_water_heater_data->shelly_water_heater_current; // in Amps

        // solar power calculated from Shelly measurements of battery Grid and Load
        $psolar_kw              =   round($readings_obj->psolar_kw, 2);

      // approximate solar current into battery
        $solar_amps_at_49V      =   round($psolar_kw * 1000 / 48.9, 1);

        // 
        $shelly_em_home_kw      =   $readings_obj->shelly_em_home_kw;

        // changed to avg July 15 2023 was battery_voltage_vdc before that
        // $battery_voltage_vdc    =   round( (float) $readings_obj->battery_voltage_avg, 1);

        // Positive is charging and negative is discharging
        $battery_amps           =   $readings_obj->battery_amps;

        $battery_power_kw       = abs(round($readings_obj->battery_power_kw, 2));

        $home_grid_kw_power     =   $readings_obj->home_grid_kw_power;
        $home_grid_voltage      =   $readings_obj->home_grid_voltage;

        $shelly1pm_acin_switch_status = $shelly_switch_acin_details_arr['shelly1pm_acin_switch_status'];

        // This is the AC voltage of switch:0 of Shelly 4PM
        $shelly1pm_acin_voltage = $shelly_switch_acin_details_arr['shelly1pm_acin_voltage'];

        $soc_percentage_now = round($readings_obj->soc_percentage_now, 1);

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

                $grid_arrow_icon = ''; //'<i class="fa-solid fa-1x fa-circle-xmark"></i>';
    
                $grid_info = '<span style="font-size: 18px;color: Red;">' . $home_grid_kw_power . 
                        ' KW<br>' . $home_grid_voltage . ' V</span>';
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
                            ' KW</strong><br>' . $solar_amps_at_49V . ' A</span>';
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

        if ($shelly_switch_acin_details_arr['control_shelly'])
        {
            $shelly_servo_icon = '<span style="color: Green; display:block; text-align: center;">
                                      <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                  </span>';
        }
        else
        {
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
            $battery_info =  '<span style="font-size: 18px;color: Green;"><strong>' . $battery_power_kw  . ' KW</strong><br>' 
                                                                            . abs($battery_amps)  . 'A</span>';
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
          $battery_info =  '<span style="font-size: 18px;color: Red;"><strong>' . $battery_power_kw . ' KW</strong><br>' 
                                                                        . abs($battery_amps)  . 'A</span>';
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
        elseif ( ! $shelly_water_heater_status )
        {
          $water_heater_icon_color = 'red';
        }
        else
        {
          $water_heater_icon_color = 'black';
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
                                                <strong>' . $readings_obj->power_to_home_kw . ' KW</strong>
                                            </span>';

        $format_object->power_to_ac_kw = '<span style="font-size: 18px;color: Black;">
                                                <strong>' . $readings_obj->power_to_ac_kw . ' KW</strong>
                                            </span>';

        $format_object->power_to_pump_kw = '<span style="font-size: 18px;color: Black;">
                                              <strong>' . $pump_ON_duration_mins . ' mins</strong>
                                          </span>';

        $format_object->shelly_water_heater_kw = '<span style="font-size: 18px;color: Black;">
                                                    <strong>' . $shelly_water_heater_kw . ' KW</strong>
                                                  </span>';

        if ( ! empty( $readings_obj->cloudiness_average_percentage_weighted ) )
        {
          $status .= " Cloud: " . round($readings_obj->cloudiness_average_percentage_weighted,1) . " %";
        }

        if ( ! empty( $readings_obj->est_solar_total_kw ) )
        {
          $status .= " Pest: " . $readings_obj->est_solar_total_kw . " KW";
        }

        // present time
        $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

        $now_format = $now->format("H:i:s");
        // timestamp at last measurement exit
        // $past_unixdatetime = $cron_exit_condition_user_meta_arr['unixdatetime'];
        // get datetime object from timestamp
        $past = (new DateTime('@' . $previous_timestamp))->setTimezone(new DateTimeZone("Asia/Kolkata"));
        // get the interval object
        $interval_since_last_change = $now->diff($past);
        // format the interval for display
        $formatted_interval = $this->format_interval($interval_since_last_change);

        $status .= " " . $now_format;

        
        $status_html = '<span style="color: Blue; display:block; text-align: center;">' .
                                                  $status   . '<br>' . 
                                                  'sunrise: ' . $readings_obj->sunrise  . 'sunset: ' . $readings_obj->sunset .
                                  '</span>';

        
        
        $format_object->soc_percentage_now_html = '<span style="font-size: 20px;color: Blue; display:block; text-align: center;">' . 
                                                      '<strong>' . $soc_percentage_now  . ' %' . '</strong><br>' .
                                                  '</span>';
        /*
        $format_object->cron_exit_condition = '<span style="color: Blue; display:block; text-align: center;">' .
                                                    $formatted_interval   . ' ' . $saved_cron_exit_condition  . $soc_update_method .
                                                    // $readings_obj->battery_current_comparison . 
                                              '</span>';
        */
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
    
}