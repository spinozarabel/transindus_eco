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
  public $all_usermeta, $index_of_logged_in_user, $wp_user_name_logged_in_user, $wp_user_obj;
  public $user_meta_defaults_arr, $timezone, $verbose, $lat, $lon, $utc_offset, $cloudiness_forecast;

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
        $defaults['pump_duration_control']                            = ['default' => false,   'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['track_ats_switch_to_grid_switch']                  = ['default' => false,  'lower_limit' =>true,  'upper_limit' =>true];
        $defaults['pump_duration_secs_max']                           = ['default' => 2700,   'lower_limit' => 0,    'upper_limit' =>7200];
        $defaults['pump_power_restart_interval_secs']                 = ['default' => 120,    'lower_limit' => 0,    'upper_limit' =>86400];
        $defaults['studer_battery_charging_current']                  = ['default' => 5,      'lower_limit' => 0,    'upper_limit' =>30];   // studer supplied battery charging current DC Amps
        $defaults['studer_battery_priority_voltage']                  = ['default' => 51.1,   'lower_limit' => 50,   'upper_limit' =>54];   // studer battery priority voltage in Volts DC

        // save the data in a transient indexed by the user ID. Expiration is 30 minutes
        set_transient( $wp_user_ID . 'user_meta_defaults_arr', $defaults, 30*60 );

        foreach ($defaults as $user_meta_key => $default_row) {
          $user_meta_value  = get_user_meta($wp_user_ID, $user_meta_key,  true);
  
          // check that the user meta value is set or not. If not yet set, set it to the default from table above
          if ( ! isset( $user_meta_value ) ) {
            
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

              case ( stripos( $field[ 'settings' ][ 'key' ], 'track_ats_switch_to_grid_switch' )!== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'track_ats_switch_to_grid_switch',  true);

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

              case ( stripos( $field[ 'settings' ][ 'key' ], 'studer_charger_enabled' ) !== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'studer_charger_enabled',  true);

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

              case ( stripos( $field[ 'settings' ][ 'key' ], 'studer_battery_priority_enabled' ) !== false ):
                // get the user's metadata for this flag
                $user_meta_value = get_user_meta($wp_user_ID, 'studer_battery_priority_enabled',  true);

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

        { // read in the data from the home computer and set as transient for access from other routines
          $object_from_linux_home_desktop = $this->get_mqtt_data_from_from_linux_home_desktop( $user_index );

          // how do we determine that a notification needs to be sent?
          // we send notifications for any LDS, switch relese, float release, pump overflow type events
          // we need to detect these events since now they are controlled locally by LAN


          // how do we determine that the data from home computer is valid?
          // data is valid if the timestamp from object received using mqtt, is not stale.

          // get the ts that was sent by xcomlan and shellybm
          $xcomlan_ts   = $object_from_linux_home_desktop->xcomlan_studer_data_obj->xcomlan_ts;
          $shellybm_ts  = $object_from_linux_home_desktop->timestamp_shellybm;

          if ( ! empty( $xcomlan_ts ) )
          {
            $obj_check_ts_validity_xcomlan  = $this->check_validity_of_timestamp( $xcomlan_ts,  120 );
          }

          if ( ! empty( $xcomlan_ts ) )
          {
            $obj_check_ts_validity_shellybm = $this->check_validity_of_timestamp( $shellybm_ts, 120 );
          }
          
          

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

            // get the timestamp of the last notification from transint
            if ( false === ( $last_notification_ts = get_transient('last_notification_ts') ) )
            {
              // if transient is non-existent then re-enable the notifications
              $notifications_enabled = (bool) true;
            }
            else
            {
              if ( $this->check_validity_of_timestamp( $last_notification_ts, 1800 )->elapsed_time_exceeds_duration_given )
              {
                // enable notifications since it has been more than 30m since last notification
                $notifications_enabled = (bool) true;
              }
              else
              {
                // disable notifications since less than 30m since last notification was issued
                $notifications_enabled = (bool) false;
              }
            }

            $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
            $now_ts = $now->getTimestamp();

            // get the switch tree object
            $switch_tree_obj = $object_from_linux_home_desktop->switch_tree_obj;

            // Get the studer clock offset lag in minutes from Linux home server clock
            $studer_time_offset_in_mins_lagging = $object_from_linux_home_desktop->studer_time_offset_in_mins_lagging;

            if ( $studer_time_offset_in_mins_lagging > 10 )
            {
              // issue notification if enabled
              $notification_title   = "Studer Clock";
              $notification_message = "Studer CLock lag " . $studer_time_offset_in_mins_lagging . "m";

            }

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
            return null;
          }
          
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

              // record this in the object that is then sent to the local Linux WP site where it is mirrored for implementation
              $settings_obj_to_local_wp->keep_shelly_switch_closed_always =  $submitted_field_value;

              error_log( "Updated User Meta - keep_shelly_switch_closed_always - from Settings Form: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'track_ats_switch_to_grid_switch' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "track_ats_switch_to_grid_switch",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'track_ats_switch_to_grid_switch', $submitted_field_value);

              // record this in the object that is then sent to the local Linux WP site where it is mirrored for implementation
              $settings_obj_to_local_wp->track_ats_switch_to_grid_switch =  $submitted_field_value;

              error_log( "Updated User Meta - track_ats_switch_to_grid_switch - from Settings Form: " . $field[ 'value' ] );
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

              $settings_obj_to_local_wp->pump_duration_control = (bool)  $submitted_field_value;
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


          case ( stripos( $field[ 'key' ], 'studer_charger_enabled' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "studer_charger_enabled",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'studer_charger_enabled', $submitted_field_value);

              // record this in the object that is then sent to the local Linux WP site where it is mirrored for implementation
              $settings_obj_to_local_wp->studer_charger_enabled =  $submitted_field_value;

              error_log( "Updated User Meta - studer_charger_enabled - from Settings Form: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'studer_battery_priority_enabled' ) !== false ):
            if ( $field[ 'value' ] )
            {
              $submitted_field_value = true;
            }
            else 
            {
              $submitted_field_value = false;
            }

            // get the existing user meta value
            $existing_user_meta_value = get_user_meta($wp_user_ID, "studer_battery_priority_enabled",  true);

            if ( $existing_user_meta_value != $submitted_field_value )
            {
              // update the user meta with value from form since it is different from existing setting
              update_user_meta( $wp_user_ID, 'studer_battery_priority_enabled', $submitted_field_value);

              // record this in the object that is then sent to the local Linux WP site where it is mirrored for implementation
              $settings_obj_to_local_wp->studer_battery_priority_enabled =  $submitted_field_value;

              error_log( "Updated User Meta - studer_battery_priority_enabled - from Settings Form: " . $field[ 'value' ] );
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


          case ( stripos( $field[ 'key' ], 'studer_battery_charging_current' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'studer_battery_charging_current';

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

                $settings_obj_to_local_wp->studer_battery_charging_current =  $field[ 'value' ];
              }
            }
            else
            {
              error_log( "Updated User Meta - " . $user_meta_key . " - NOT Updated - invalid input: " . $field[ 'value' ] );
            }
          break;


          case ( stripos( $field[ 'key' ], 'studer_battery_priority_voltage' ) !== false ):

            // define the meta key of interest
            $user_meta_key = 'studer_battery_priority_voltage';

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

                $settings_obj_to_local_wp->studer_battery_priority_voltage =  $field[ 'value' ];
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

                $settings_obj_to_local_wp->pump_duration_secs_max = (int)  $field[ 'value' ];
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

                $settings_obj_to_local_wp->pump_power_restart_interval_secs = (int)  $field[ 'value' ];
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
      if ( ! empty($settings_obj_to_local_wp ) ) 
      {
        $this->push_flag_changes_to_local_server( 0, $wp_user_ID, $settings_obj_to_local_wp );
      }

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
                <td id="cloud_info">'         . $format_object->cloud_info         . '</td>
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
 *  Function to test code conveniently.
 */
    public function my_api_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
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

      $shellyplus1pm_grid_switch_obj  = $readings_obj->shellyplus1pm_grid_switch_obj;
      $shellyem_readings_obj          = $readings_obj->shellyem_readings_obj;
      $shellyplus1pm_water_heater_obj = $readings_obj->shellyplus1pm_water_heater_obj;
      $shellyplus1pm_water_pump_obj   = $readings_obj->shellyplus1pm_water_pump_obj;

      // Status of the Shelly EM contactor: OFFLINE/ON/OFF
      // ON means the contactor is active so the ATS bypass is Active. OFF means Solar is supplying all loads
      // OFFLINE means the API call to SHelly EM has failed
      $shellyem_contactor_status_string = (string) $shellyem_readings_obj->output_state_string;
      $shellyem_contactor_is_active     = $shellyem_contactor_status_string === "ON";

      $shelly_water_heater_kw       = 0;
      $shelly_water_heater_status_bool   = null;

      // extract and process Shelly 1PM switch water heater data
      if ( ! empty( $shellyplus1pm_water_heater_obj ) )
      {
        

        $shelly_water_heater_kw            = (float)  $shellyplus1pm_water_heater_obj->switch[0]->power_kw;
        $shelly_water_heater_status_bool   = (bool)   $shellyplus1pm_water_heater_obj->switch[0]->output_state_bool;    // boolean variable
        $shelly_water_heater_status_string = (string) $shellyplus1pm_water_heater_obj->switch[0]->output_state_string;  // boolean variable
        $shelly_water_heater_current       = (float)  $shellyplus1pm_water_heater_obj->switch[0]->current;              // in Amps
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
      $solar_amps_at_49V      = $readings_obj->xcomlan_studer_data_obj->pv_current_now_total_xcomlan;

      // 
      $shelly_em_home_kw      =   $shellyem_readings_obj->emeters[0]->power_kw;

      // Positive is charging and negative is discharging We use this as the readings have faster update rate
      $battery_amps           =   $readings_obj->batt_amps;

      $battery_power_kw       = abs(round($readings_obj->battery_power_kw, 2));

      $battery_avg_voltage    =   $readings_obj->xcomlan_studer_data_obj->batt_voltage_xcomlan_avg;

      // $home_grid_kw_power  is as measured by ShellyPro3PM at the busbars just after the energy meter
      $home_grid_kw_power     =   $readings_obj->shellypro3em_3p_grid_obj->home_grid_kw_power;

      // $home_grid_voltage  is as measured by ShellyPro3PM at the busbars just after the energy meter
      $home_grid_voltage      =   $readings_obj->shellypro3em_3p_grid_obj->home_grid_voltage;

      $seconds_elapsed_grid_status = (int) $readings_obj->shellypro3em_3p_grid_obj->seconds_elapsed_grid_status;
      $grid_seconds_in_hms = $this->format_seconds_to_hms_format ($seconds_elapsed_grid_status );

      $shellyplus1pm_grid_switch_output_status_string = (string) $shellyplus1pm_grid_switch_obj->switch[0]->output_state_string;

      // This is the AC voltage as measured by the Grid Switch to Studer AC IN
      $shellyplus1pm_grid_switch_voltage = (int) $shellyplus1pm_grid_switch_obj->switch[0]->voltage;

      // This is the do_shelly value on the LAN Linux machine. This is only controlled locally over LAN.
      // The remote settings don't do anything.
      $do_shelly         = (bool) $readings_obj->do_shelly;

      // This is the value selected from the 3 possible methods available.
      $soc_percentage_now     = round($readings_obj->soc_percentage_now, 1);

      $keep_shelly_switch_closed_always = (bool) $readings_obj->keep_shelly_switch_closed_always;

      if ( ! empty( $readings_obj->soc_percentage_now_using_dark_shelly ) )
      {
        $soc_percentage_now_using_dark_shelly = round($readings_obj->soc_percentage_now_using_dark_shelly, 1);
      }

      // If power is flowing OR switch has ON status then show CHeck and Green
      $grid_arrow_size = $this->get_arrow_size_based_on_power($home_grid_kw_power);

      switch (true)
      {   // choose grid icon info based on switch status
          case ( $shellyplus1pm_grid_switch_output_status_string === "OFFLINE" ): // No Grid OR switch is OFFLINE
              $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Yellow;"></i>';

              $grid_arrow_icon = ''; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

              $grid_info = 'No<br>Grid';

              break;


          case ( $shellyplus1pm_grid_switch_output_status_string === "ON" && $keep_shelly_switch_closed_always === true ): // Switch is ON
              $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Blue;"></i>';

              $grid_arrow_icon  = '<i class="fa-solid' . $grid_arrow_size .  'fa-arrow-right-long fa-rotate-by"
                                                                                style="--fa-rotate-angle: 45deg;">
                                  </i>';
              $grid_info = '<span style="font-size: 18px;color: Red;"><strong>' . $home_grid_kw_power . 
                            ' KW</strong><br>' . $home_grid_voltage . ' V</span>';
              break;

          case ( $shellyplus1pm_grid_switch_output_status_string === "ON" && $keep_shelly_switch_closed_always === false ): // Switch is ON
            $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Green;"></i>';

            $grid_arrow_icon  = '<i class="fa-solid' . $grid_arrow_size .  'fa-arrow-right-long fa-rotate-by"
                                                                              style="--fa-rotate-angle: 45deg;">
                                </i>';
            $grid_info = '<span style="font-size: 18px;color: Red;"><strong>' . $home_grid_kw_power . 
                          ' KW</strong><br>' . $home_grid_voltage . ' V</span>';
            break;


          case ( $shellyplus1pm_grid_switch_output_status_string === "OFF" ):   // Switch is online and OFF
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
          case ( $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'OFFLINE'):
            $ev_charge_icon = '<i class="fa-solid fa-2x fa-charging-station" style="color: Yellow;"></i>';

            $car_charger_grid_kw_power = 0;
          break;

          // Car charger is online but no power is being drawn
          case (  $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'ONLINE' && 
                  $readings_obj->shellypro3em_3p_grid_obj->evcharger_grid_kw_power <= 0.05 ):
            $ev_charge_icon = '<i class="fa-solid fa-2x fa-charging-station" style="color: Black;"></i>';

            $car_charger_grid_kw_power = 0;
          break;

          // Car charger is online and power is being drawn
          case (  $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'ONLINE' && 
                  $readings_obj->shellypro3em_3p_grid_obj->evcharger_grid_kw_power > 0.05 ):
            $ev_charge_icon = '<i class="fa-solid fa-2x fa-charging-station" style="color: Blue;"></i>';

            $car_charger_grid_kw_power = round( $readings_obj->shellypro3em_3p_grid_obj->evcharger_grid_kw_power, 2);

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
          case ( $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'OFFLINE'):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: Yellow;"></i>';

            $wallcharger_grid_kw_power = 0;
          break;

          // when wall charger is ONLINE but not drawing power Indicate Red icon and power to 0
          case (  $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'ONLINE' && 
                  $readings_obj->shellypro3em_3p_grid_obj->wallcharger_grid_kw_power <= 0.05):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: Black;"></i>';

            $wallcharger_grid_kw_power = 0;
          break;

          // when wall charger is ONLINE and drawing power and Solar supplying power, Indicate Green icon and power actulas
          case (  $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'ONLINE' && 
                ! $shellyem_contactor_is_active &&
                  $readings_obj->shellypro3em_3p_grid_obj->wallcharger_grid_kw_power > 0.05):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: green;"></i>';

            $wallcharger_grid_kw_power = round( $readings_obj->shellypro3em_3p_grid_obj->wallcharger_grid_kw_power, 2);

            $wallcharger_grid_kw_power = '<span style="font-size: 18px;color: Black;">
                                            <strong>' . $wallcharger_grid_kw_power . '</strong>
                                          </span>';
          break;

          // when wall charger is ONLINE and drawing power and Grid is supplying power, Indicate Orange icon
          case (  $readings_obj->shellypro3em_3p_grid_obj->output_state_string === 'ONLINE' && 
                  $shellyem_contactor_is_active &&
                  $readings_obj->shellypro3em_3p_grid_obj->wallcharger_grid_kw_power > 0.05):
            $wall_charge_icon = '<i class="fa-solid fa-2x fa-plug-circle-bolt" style="color: orange;"></i>';

            $wallcharger_grid_kw_power = round( $readings_obj->shellypro3em_3p_grid_obj->wallcharger_grid_kw_power, 2);

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

      if ( $do_shelly === true )
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
      $power_total_to_home_kw = $shellyem_readings_obj->emeters[0]->power_kw; // round( $power_total_to_home * 0.001, 2);

      $power_to_home_kw = $readings_obj->shellypro4pm_load_obj->switch[2]->power_kw + 
                          $readings_obj->shellypro4pm_load_obj->switch[3]->power_kw;

      $power_to_ac_kw   = $readings_obj->shellypro4pm_load_obj->switch[0]->power_kw +
                          $readings_obj->shellypro4pm_load_obj->switch[1]->power_kw +
                          $readings_obj->shellypro4pm_load_obj->switch[2]->power_kw +
                          $readings_obj->shellypro4pm_load_obj->switch[3]->power_kw;
      $power_to_pump_kw = $readings_obj->shellyplus1pm_water_pump_obj->switch[0]->power_kw;

      $pump_ON_duration_mins = (int) round( $readings_obj->shellyplus1pm_water_pump_obj->pump_ON_duration_secs / 60, 0);

      $pump_switch_status_bool  = $readings_obj->shellyplus1pm_water_pump_obj->switch[0]->output_state_bool;
      $ac_switch_status_bool    = $readings_obj->shellypro4pm_load_obj->switch[0]->output_state_bool ||
                                  $readings_obj->shellypro4pm_load_obj->switch[1]->output_state_bool ||
                                  $readings_obj->shellypro4pm_load_obj->switch[2]->output_state_bool ||
                                  $readings_obj->shellypro4pm_load_obj->switch[3]->output_state_bool;

      $home_switch_status_bool  = $readings_obj->shellypro4pm_load_obj->switch[2]->output_state_bool;

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

      If ( $power_to_ac_kw > 0.2 && ! $shellyem_contactor_is_active )
      {
        $ac_icon_color = 'green';
      }
      elseif ( $power_to_ac_kw > 0.2 && $shellyem_contactor_is_active )
      {
        $ac_icon_color = 'orange';
      }
      elseif ( ! $ac_switch_status_bool )
      {
        $ac_icon_color = 'yellow';
      }
      else
      {
        $ac_icon_color = 'black';
      }

      If ( $power_to_pump_kw > 0.1 && ! $shellyem_contactor_is_active)
      {
        $pump_icon_color = 'green';
      }
      elseif ( $power_to_pump_kw > 0.1 && $shellyem_contactor_is_active)
      {
        $pump_icon_color = 'orange';
      }
      elseif ( ! $pump_switch_status_bool )
      {
        $pump_icon_color = 'yellow';
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
      elseif ( $shelly_water_heater_status_bool === false )
      {
        $water_heater_icon_color = 'red';
      }
      elseif ( $shelly_water_heater_status_string === 'OFFLINE' )
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
                                              <strong>' . $power_to_home_kw . '</strong>
                                          </span>';

      $format_object->power_to_ac_kw = '<span style="font-size: 18px;color: Black;">
                                              <strong>' . $power_to_ac_kw . '</strong>
                                          </span>';

      $format_object->power_to_pump_kw = '<span style="font-size: 18px;color: Black;">
                                            <strong>' . $pump_ON_duration_mins . ' m</strong>
                                        </span>';

      $format_object->shelly_water_heater_kw = '<span style="font-size: 18px;color: Black;">
                                                  <strong>' . $shelly_water_heater_kw . '</strong>
                                                </span>';

      if ( ! empty( $readings_obj->cloudiness_average_percentage_weighted ) && $psolar_kw > 0.1 )
      {
        if ( ! empty( $readings_obj->est_solar_total_kw ) )
        {
          $format_object->cloud_info = $readings_obj->est_solar_total_kw . " KW";
        }
        // cloudinesss percentage information to be printed below the cloud servo icon serving 2 purposes
        $format_object->cloud_info .= '<br>' . round($readings_obj->cloudiness_average_percentage_weighted,1) . "%";
      }
      else
      {
        $format_object->cloud_info = "";
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

      $soc_update_method = $readings_obj->soc_update_method;

      $soc_percentage_now_calculated_using_shelly_bm      = round($readings_obj->soc_percentage_now_calculated_using_shelly_bm,   1);
      $soc_percentage_now_calculated_using_studer_xcomlan = round($readings_obj->soc_percentage_now_calculated_using_studer_xcomlan,     1);
      $soc_percentage_now_studer_kwh                = round($readings_obj->soc_percentage_now_studer_kwh,  1);

      // string of all soc's soc_studer, soc_xcomlan, and soc_shellybm strung together as 1 string for display
      $soc_all_methods =  $soc_percentage_now_studer_kwh . " " . 
                          $soc_percentage_now_calculated_using_shelly_bm . " " .
                          $soc_percentage_now_calculated_using_studer_xcomlan;

      // $status .= " " . $now_format;

      
      $status_html = '<span style="color: Blue; display:block; text-align: center;">' .
                        'LVDS: ' . $readings_obj->soc_percentage_lvds_setting  . '% ' . $readings_obj->average_battery_voltage_lvds_setting . 'V ' .
                        $soc_update_method .
                      '</span>';

      
      
      $format_object->soc_percentage_now_html = 
                      '<span style="font-size: 20px;color: Blue; display:block; text-align: center;">' . 
                          '<strong>' . $soc_percentage_now  . '</strong>%<br>' .
                      '</span>';
      $status_html .= '<span style="color: Blue; display:block; text-align: center;">' .
                          $formatted_interval   . ' ' . $switch_tree_exit_condition .
                      '</span>';

      $status_html .= '<span style="color: Blue; display:block; text-align: center;">' .
                          $xcomlan_status   . ' ' . $shellybm_status  .
                      '</span>';

                      
      if ( $readings_obj->studer_charger_enabled )
      {
        $status_html .= '<span style="color: green; display:block; text-align: center;">' .
                          'Charger Enabled: '  . $readings_obj->studer_battery_charging_current . 'A' .
                        '</span>';
      }
      else
      { // don't display anything if charger is disabled to not clutter display
        /*
        $status_html .= '<span style="color: red; display:block; text-align: center;">' .
                          'Charger Disabled: '  . $readings_obj->studer_battery_charging_current . 'A' .
                        '</span>';
        */
      }

      $status_html .= '<span style="color: Blue; display:block; text-align: center;">' .
                          $soc_all_methods   . 
                      '</span>';

  
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
        $grid_present_status          = (string)  $shelly_readings_obj->shellypro3em_3p_grid_obj->grid_present_status;
        $seconds_elapsed_grid_status  = (int)     $shelly_readings_obj->shellypro3em_3p_grid_obj->seconds_elapsed_grid_status;

        $time_formatted_string = $this->format_seconds_to_hms_format( $seconds_elapsed_grid_status );

        if ( $grid_present_status === "ONLINE" && ! empty( $shelly_readings_obj->shellypro3em_3p_grid_obj ) )
        {
          $a_phase_grid_voltage = (float) $shelly_readings_obj->shellypro3em_3p_grid_obj->red_phase_grid_voltage;
          $b_phase_grid_voltage = (float) $shelly_readings_obj->shellypro3em_3p_grid_obj->yellow_phase_grid_voltage;
          $c_phase_grid_voltage = (float) $shelly_readings_obj->shellypro3em_3p_grid_obj->blue_phase_grid_voltage;

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