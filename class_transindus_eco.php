<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 * Ver 2.2
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

  // This flag is true when SOC update in cron loop is done using Shelly readings and not studer readings
  // This can only happen when it is dark and when SOC after dark capture are both true
  public $soc_updated_using_shelly_energy_readings;


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
          $this->version = '2.3';
      }

      $this->plugin_name = 'transindus_eco';

          // load actions only if admin
      if (is_admin()) $this->define_admin_hooks();

          // load public facing actions
      $this->define_public_hooks();

          // read the config file and build the secrets array for all users 
      $this->get_config();

      // Initialize the defaults array to blank
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
        // register shortcode for pages. This is for showing the page with studer readings
        add_shortcode( 'transindus-studer-readings',  [$this, 'studer_readings_page_render'] );

        // Action to process submitted data from a Ninja Form.
        add_action( 'ninja_forms_after_submission',   [$this, 'my_ninja_forms_after_submission'] );

        // This is the page that displays the Individual Studer with All powers, voltages, currents, and SOC% and Shelly Status
        add_shortcode( 'my-studer-readings',          [$this, 'my_studer_readings_page_render'] );

        // Define shortcode to prepare for my-studer-settings page
        add_shortcode( 'my-studer-settings',          [$this, 'my_studer_settings'] );
    }

    /**
     *  Separated this init function so that it can execute frequently rather than just at class construct
     */
    public function init()
    {
      date_default_timezone_set("Asia/Kolkata");;

      // set the logging
      $this->verbose = false;

      // lat and lon at Trans Indus from Google Maps
      $this->lat        = 12.83463;
      $this->lon        = 77.49814;
      $this->utc_offset = 5.5;

      // Get this user's usermeta into an array and set it as property the class
      // $this->get_all_usermeta( $wp_user_ID );

      // ................................ CLoudiness management ---------------------------------------------->

      if ( $this->nowIsWithinTimeLimits("05:00", "06:00") )
      {   // Get the weather forecast if time is between 5 to 6 in the morning.
        $this->cloudiness_forecast = $this->check_if_forecast_is_cloudy();

        // write the weatehr forecast to a transient valid for 24h
        set_transient( 'cloudiness_forecast', $this->cloudiness_forecast, 24*60*60 );
      }
      else  
      {   // Read the transient for the weatehr forecast that has already been read between 5 and 6 AM
        if ( false === get_transient( 'cloudiness_forecast' ) )
        {
          // Transient does not exist or has expired, so regenerate the cloud forecast
          $this->cloudiness_forecast = $this->check_if_forecast_is_cloudy();

          // write the weatehr forecast to a transient valid for 24h
          set_transient( 'cloudiness_forecast', $this->cloudiness_forecast, 24*60*60 );
        }
        else
        {
          // transient exists so just read it in to object property
          $this->cloudiness_forecast = get_transient( 'cloudiness_forecast' );
        }
      }
    }



    /**
     * 
     */
    public static function set_default_timezone()
    {
      date_default_timezone_set("Asia/Kolkata");
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
      date_default_timezone_set("Asia/Kolkata");;

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

      // set default timezone to Asia Kolkata
      date_default_timezone_set("Asia/Kolkata");;

      $config     = $this->config;

      $wp_user_ID = $this->get_wp_user_from_user_index( $user_index )->ID;

      // ensure that the data below is current before coming here
      $all_usermeta = $this->all_usermeta ?? $this->get_all_usermeta( $wp_user_ID );

      $valid_shelly_config  = ! empty( $config['accounts'][$user_index]['shelly_device_id_acin']   )  &&
                              ! empty( $config['accounts'][$user_index]['shelly_device_id_homepwr'] ) &&
                              ! empty( $config['accounts'][$user_index]['shelly_server_uri']  )       &&
                              ! empty( $config['accounts'][$user_index]['shelly_auth_key']    )       &&
                                $all_usermeta['do_shelly'];
    
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
     * 
     */
    public function get_accumulated_wh_since_midnight(  $energy_total_to_home_ts, int $user_index, int $wp_user_ID )
    {
      // set default timezone to Asia Kolkata
      date_default_timezone_set("Asia/Kolkata");

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

        return $shelly_energy_counter_midnight;
      }

      // accumulate the energy from this cycle to accumulator
      $shelly_energy_counter_midnight = $shelly_energy_counter_midnight + $delta_increase_wh;

      // update the accumulator user meta for next cycle
      update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', $shelly_energy_counter_midnight );

      // update the current energy counter with current reading for next cycle
      update_user_meta( $wp_user_ID, 'shelly_energy_counter_now', $current_energy_counter_wh );

      // return the energy consumed since midnight in WH
      return $shelly_energy_counter_midnight;
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
      date_default_timezone_set("Asia/Kolkata");

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
      $this->control_pump_on_duration( $user_index, $shelly_homwpwr_obj);

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

        $soc_percentage_discharged = round( $energy_consumed_since_after_dark_update_kwh / $SOC_capacity_KWH * 107, 1);

        // Change in SOC ( a decrease) from value captured just after dark to now based on energy consumed by home during dark
        $soc_percentage_now_computed_using_shelly  = round($soc_update_from_studer_after_dark - $soc_percentage_discharged, 1);
    
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
        $soc_percentage_discharged = round( $energy_consumed_since_after_dark_update_kwh / $SOC_capacity_KWH * 107, 1);

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
/*
        if ( $soc_predicted_at_6am <= 40 )
        {
          $turn_on_acin_switch_soc6am_low = true;
        }
*/
//      $return_obj->turn_on_acin_switch_soc6am_low    = $turn_on_acin_switch_soc6am_low;
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
      date_default_timezone_set("Asia/Kolkata");

      $now = new DateTime();

      if ( $this->nowIsWithinTimeLimits( '00:00', $future_time ) )
      {
        $future_datetime_object = new DateTime($future_time);

        // we are past midnight so we just calulcate mins from now to future time
        // form interval object between now and  time stamp under investigation
        $diff = $now->diff( $future_datetime_object );

        $minutes_now_to_future = $diff->s / 60  + $diff->i  + $diff->h *60;

        return $minutes_now_to_future;
      }
      else
      {
        // we are not past midnight of today so future time is past 23:59:59 into tomorrow
        $future_datetime_object = new DateTime( "tomorrow " . $future_time );

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
      date_default_timezone_set("Asia/Kolkata");

      $now = new DateTime();

      $reference_datetime_obj = new DateTime();

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
     *  @param int:$total_to_west_panel_ratio is the estimated ratio of Total Solar Amps to West Panel amps
     *  @return object:$battery_measurements_object contains the measurements of the battery using the Shelly UNI device
     *  
     *  The current is measured using a hall effect sensor. The sensor output voltage is rread by the ADC in the shelly UNI
     *  The transducer function is: V(A) = (Vout - 2.5)/0.065 for a 65mV/V aronund 2.5V in either direction
     *  Trapezoidal rule is used to calculate Area
     *  Current measurements are used to update user meta for accumulated SOlar AH since Studer Midnight
     */
    public function get_shelly_solar_measurement( int $user_index, string $wp_user_name, int $wp_user_ID, $total_to_west_panel_ratio ): ? object
    {
        // set default timezone to Asia Kolkata
        date_default_timezone_set("Asia/Kolkata");

        // initialize the object to be returned
        $battery_measurements_object = new stdClass;

        // Make an API call on the Shelly UNI device
        $config = $this->config;

        $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
        $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
        $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_battery'];

        // BAttery capacity AH for one unit of the battery. Ex: 100 AH for a unit with 3 units of 300 AH
        $battery_capacity_ah = $config['accounts'][$user_index]['battery_capacity_ah'];

        $shelly_api    =  new shelly_cloud_api($shelly_auth_key, $shelly_server_uri, $shelly_device_id);

        // this is $curl_response.
        $shelly_api_device_response = $shelly_api->get_shelly_device_status();

        // check to make sure that it exists. If null API call was fruitless
        if ( empty( $shelly_api_device_response ) )
        {
          $this->verbose ? error_log("Shelly Battery Measurement API call failed"): false;

          return null;
        }

        // If you get here, you have a valid API response from the Shelly UNI
        $adc_voltage_shelly = $shelly_api_device_response->data->device_status->adcs[0]->voltage;  // measure the ADC voltage

        // calculate the current using the 65mV/A formula around 2.5V. Positive current is battery discharge
        $delta_voltage = $adc_voltage_shelly - 2.6;

        // convention here is that battery discharge current is positive. 10% is adjustment based on comparison
        $solar_amps_west_raw_measurement = round( $delta_voltage / 0.055, 1 );

        // multiply by factor for total from west facing only measurement using calculations
        $solar_amps = round( $total_to_west_panel_ratio * $solar_amps_west_raw_measurement, 1 );

        $this->verbose ? error_log("Raw: $solar_amps_west_raw_measurement, Ratio: $total_to_west_panel_ratio, 
                                    SolarAmps: $solar_amps") : false;

        // get the unix time stamp when measurement was made
        $now = new DateTime();
        $timestamp = $now->getTimestamp();

        // get the previous reading's timestamp from transient. If transient doesnt exist set the value to current measurement
        $previous_timestamp = get_transient(  $wp_user_name . '_' . 'timestamp_battery_last_measurement' ) ?? $timestamp;
        // $previous_timestamp     = get_user_meta( $wp_user_ID, 'timestamp_battery_last_measurement', true ) ?? $timestamp;

        // get the previous reading from transient. If doesnt exist set it to current measurement
        $previous_solar_amps = get_transient(  $wp_user_name . '_' . 'amps_battery_last_measurement' ) ?? $solar_amps;
        // $previous_solar_amps    = get_user_meta( $wp_user_ID, 'amps_battery_last_measurement', true ) ?? $solar_amps;

        // update transients with current measurements
        set_transient( $wp_user_name . '_' . 'timestamp_battery_last_measurement',  $timestamp,   60 * 60 );
        set_transient( $wp_user_name . '_' . 'amps_battery_last_measurement',       $solar_amps,  60 * 60 );

        $prev_datetime_obj = new DateTime();
        $prev_datetime_obj->setTimeStamp($previous_timestamp);


        // find out the time interval between the last timestamp and the present one in seconds
        $diff = $now->diff( $prev_datetime_obj );

        $hours_between_measurement = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 ) / 3600;

        // AH of battery discharge - Convention is that discharge AH is considered positive
        // use trapezoidal rule for integration
        $solar_ah_this_measurement = 0.5 * ( $previous_solar_amps + $solar_amps ) * $hours_between_measurement;

        // get accumulated value till last measurement
        $solar_accumulated_ah_since_midnight = (float) get_user_meta( $wp_user_ID, 'solar_accumulated_ah_since_midnight', true) ?? 0;

        // accumulate  present measurement
        $solar_accumulated_ah_since_midnight += $solar_ah_this_measurement;

        $battery_measurements_object->solar_amps_west_raw_measurement           = $solar_amps_west_raw_measurement;
        $battery_measurements_object->solar_ah_this_measurement                 = $solar_ah_this_measurement;
        $battery_measurements_object->solar_accumulated_ah_since_midnight       = $solar_accumulated_ah_since_midnight;

        $battery_measurements_object->voltage                   = $adc_voltage_shelly;

        $battery_measurements_object->solar_amps                = $solar_amps;

        $battery_measurements_object->timestamp                 = $timestamp;
        $battery_measurements_object->previous_timestamp        = $previous_timestamp;
        
        $battery_measurements_object->hours_between_measurement = $hours_between_measurement;
        $battery_measurements_object->previous_solar_amps       = $previous_solar_amps;

        update_user_meta( $wp_user_ID, 'solar_accumulated_ah_since_midnight', $solar_accumulated_ah_since_midnight);

        return $battery_measurements_object;
    }

    /**
     *  @param int:$user_index of user in config array
     *  @return object:$shelly_device_data contains energy counter and its timestamp along with switch status object
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

        $energy_total_to_home_ts = $energy_channel_0_ts + $energy_channel_1_ts + $energy_channel_2_ts + $energy_channel_3_ts;

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
     * 
     */
    public function get_shelly_accumulated_grid_wh_since_midnight( int $user_index, string $wp_user_name, int $wp_user_ID ): ? object
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
        $this->verbose ? error_log("Shelly EM Grid Energy API call failed"): false;

        // since no grid get value from user meta. Also readings will not change since grid is absent :-)
        $returned_obj->grid_wh_since_midnight = $previous_grid_wh_since_midnight;
        $returned_obj->grid_kw_shelly_em = 0;
        $returned_obj->grid_voltage_em = 0;

        return $returned_obj;
      }

      // Shelly API call was successfull and we have useful data
      $present_grid_wh_reading = (int) round($shelly_api_device_response->data->device_status->emeters[0]->total, 0);

      // get the energy counter value set at midnight. Assumes that this is an integer
      $grid_wh_counter_midnight = (int) round(get_user_meta( $wp_user_ID, 'grid_wh_counter_midnight', true), 0);

      $returned_obj->grid_voltage_em = round($shelly_api_device_response->data->device_status->emeters[0]->voltage, 0);;

      // subtract the 2 integer counter readings to get the accumulated WH since midnight
      $grid_wh_since_midnight = $present_grid_wh_reading - $grid_wh_counter_midnight;

      if ( $grid_wh_since_midnight >=  0 )
      {
        // the value is positive so counter did not reset due to software update etc.
        $returned_obj->grid_wh_since_midnight = $grid_wh_since_midnight;

        update_user_meta( $wp_user_ID, 'grid_wh_since_midnight', $grid_wh_since_midnight);
      }
      else 
      {
        // value must be negative so cannot be possible set it to 0
        $returned_obj->grid_wh_since_midnight   = 0;
      }

      $grid_kw_shelly_em = round( 0.001 * $shelly_api_device_response->data->device_status->emeters[0]->power, 3 );
      $returned_obj->grid_kw_shelly_em        = $grid_kw_shelly_em;
      $returned_obj->present_grid_wh_reading  = $present_grid_wh_reading;

      return $returned_obj;
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
        $rcc_datetime_obj = new DateTime();
        $rcc_datetime_obj->setTimeStamp($studer_clock_unix_timestamp_with_utc_offset);

        $now = new DateTime();

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
     *  SOC capture can happen anytime between 7-11 PM. It is checked for till almost 7AM.
     *  SO it needs to be valid from potentially 7PM to 7Am or almost 12h.
     *  However, SOC reference can get reset upto 7AM. So it maybe valid upto 7PM the next day.
     *  For above reason it is important to delete the transient after 7AM to force a SOC reference again the following 7PM.
     *  This is done in the cron loop itself.
     *  No other check is made in the function
     */
    public function check_if_soc_after_dark_happened( int $user_index, string $wp_user_name, int $wp_user_ID ) :bool
    {
      date_default_timezone_set("Asia/Kolkata");

      // Get the transient if it exists
      if (false === ($timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' ) ) )
      {
        // if transient DOES NOT exist then read in value from user meta
        $timestamp_soc_capture_after_dark = get_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', true);
      }
      else
      {
        // transient exists so get it
        $timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' );
      }

      if ( empty( $timestamp_soc_capture_after_dark ) )
      {
        // timestamp is not valid
        $this->verbose ? error_log( "Time stamp for SOC capture after dark is empty or not valid") : false;

        return false;
      }

      // we have a non-emtpy timestamp. To check if it is valid.
      // It is valid if the timestamp is after 6:55 PM and is within the last 5h
      $now = new DateTime();

      $datetimeobj_from_timestamp = new DateTime();
      $datetimeobj_from_timestamp->setTimestamp($timestamp_soc_capture_after_dark);

      // form the intervel object
      $diff = $now->diff( $datetimeobj_from_timestamp );

      $hours = $diff->h;
      $hours = $hours + ($diff->days*24);

      if ( $hours < 12 )
      {
        return true;
      }
      // SOC capture took place more than 5h ago so SOC Capture DID NOT take place yet
      return false;
    }


    /**
     *  If now is after 6:55PM and before 11PM today and if timestamp is not yet set then capture soc
     *  The transients are set to last 4h so if capture happens at 6PM transients expire at 11PM
     *  However the captured values are saved to user meta for retrieval.
     *  @preturn bool:true if SOC capture happened today, false if it did not happen yet today.
     */
    public function capture_evening_soc_after_dark( $wp_user_name, $SOC_percentage_now, $user_index ) : bool
    {
      // set default timezone to Asia Kolkata
      date_default_timezone_set("Asia/Kolkata");

      $wp_user_ID = $this->get_wp_user_from_user_index($user_index)->ID;

      // check if it is after dark and before midnightdawn annd that the transient has not been set yet
      // The time window is large just in case Studer API fails repeatedly during this time.
      if (  $this->nowIsWithinTimeLimits("18:55", "23:05")  ) 
      {
        // so it is dark. Has this capture already happened today? let's check
        // lets get the transient. The 1st time this is tried in the evening it should be false, 2nd time onwards true
        if ( false === ( $timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' ) ) 
                                                                ||
                       empty(get_user_meta($wp_user_ID, 'timestamp_soc_capture_after_dark', true))
            )
        {
          // transient has expired or doesn't exist, so SOC dark reference Capture has NOT happend yet.
          // Now read the Shelly Pro 4 PM energy meter for energy counter and imestamp

          $shelly_device_status_obj = $this->get_shelly_device_status_homepwr( $user_index );

          if ( empty( $shelly_device_status_obj ) )
          {
            error_log( "Shelly API call to Home Power Shelly 4PM failed during cature soc after dark, did not happen");
            return false;
          }


          $timestamp_soc_capture_after_dark = $shelly_device_status_obj->minute_ts;
          $shelly_energy_counter_after_dark = $shelly_device_status_obj->energy_total_to_home_ts;

          set_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark, 4*60*60 );
          set_transient( $wp_user_name . '_' . 'shelly_energy_counter_after_dark',  $shelly_energy_counter_after_dark, 4*60*60 );
          set_transient( $wp_user_name . '_' . 'soc_update_from_studer_after_dark', $SOC_percentage_now, 4 * 60 * 60 );


          update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $shelly_energy_counter_after_dark);
          update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $timestamp_soc_capture_after_dark);
          update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $SOC_percentage_now);

          error_log("SOC Capture after dark took place - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $shelly_energy_counter_after_dark);

          return true;
        }
        else
        {
          // transient exists, but lets double check the validity
          $timestamp_soc_capture_after_dark = get_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' );

          $check_if_soc_after_dark_happened = $this->check_if_soc_after_dark_happened( $user_index, $wp_user_name, $wp_user_ID );

          if ( $check_if_soc_after_dark_happened )
          {
            // Yes it all looks good, the timestamp is less than 12h old compared with soc after dark capture timestamp
            return true;
          }
          else
          {
            // looks like the transient was bad so lets redo the capture
            // Now read the Shelly Pro 4 PM energy meter for energy counter and imestamp
            $shelly_device_status_obj = $this->get_shelly_device_status_homepwr( $user_index );

            if ( empty( $shelly_device_status_obj ) )
            {
              error_log( "Shelly API call to Home Power Shelly 4PM failed during cature soc after dark, did not happen");
              return false;
            }
  
  
            $timestamp_soc_capture_after_dark = $shelly_device_status_obj->minute_ts;
            $shelly_energy_counter_after_dark = $shelly_device_status_obj->energy_total_to_home_ts;

          set_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark',  $timestamp_soc_capture_after_dark, 4*60*60 );
          set_transient( $wp_user_name . '_' . 'shelly_energy_counter_after_dark',  $shelly_energy_counter_after_dark, 4*60*60 );
          set_transient( $wp_user_name . '_' . 'soc_update_from_studer_after_dark', $SOC_percentage_now, 4 * 60 * 60 );


          update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $shelly_energy_counter_after_dark);
          update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', $timestamp_soc_capture_after_dark);
          update_user_meta( $wp_user_ID, 'soc_update_from_studer_after_dark', $SOC_percentage_now);

          error_log("SOC Capture after dark took place - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $shelly_energy_counter_after_dark);

          return true;
          }
        }
      }
      //  before or after 4h window in the evening
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
        // increment counter each time and signal when 12 cycles have completed - counter is modulo 12
        // $this->count_5s_cron_cycles_modulo_12();

        // Loop over all of the eligible users
        $config = $this->get_config();

        foreach ($config['accounts'] as $user_index => $account)
        {
            $wp_user_name = $account['wp_user_name'];

            // Get the wp user object given the above username
            $wp_user_obj  = get_user_by('login', $wp_user_name);

            if ( empty($wp_user_obj) ) continue;

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

                // get all the readings for this user. Enable Studer measurements
                $this->get_readings_and_servo_grid_switch( $user_index, $wp_user_ID, $wp_user_name, $do_shelly, true );

                for ($i = 1; $i <= 5; $i++) 
                {
                  sleep(5);

                  // disable Studer measurements. These will complete and end the script.
                  $this->get_readings_and_servo_grid_switch( $user_index, $wp_user_ID, $wp_user_name, $do_shelly, false );
                }
              }
            }

            // loop for all users
        }
        unset( $account );

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
    public function control_pump_on_duration( int $user_index, object $shelly_4pm_readings_object )
    {
      if (empty($shelly_4pm_readings_object))
      {
        // pad data passed in do nothing
        return null;
      }

      // set default timezone
      date_default_timezone_set("Asia/Kolkata");

      // check if pump is ON now from the 4pm readings object. It is ON if status is ON and power is greater than 400W
      $pump_is_on_now =                   $shelly_4pm_readings_object->pump_switch_status_bool              && 
                          ( (int) round(  $shelly_4pm_readings_object->power_to_pump_kw * 1000, 0 ) > 400 );

      // check if required transients exist
      if ( false === ( $pump_alreay_ON = get_transient( 'pump_alreay_ON' ) ) )
      {
        // the transient does NOT exist so lets initialize the transients valid for 12 hours
        // This happens rarely, when transients get wiped out or 1st time code is run
        set_transient( 'pump_alreay_ON', false, 12 * 3600 );

        // lets also initialize the pump start time to now since this is the 1st time ever
        $now = new DateTime();
        $timestamp = $now->getTimestamp();

        // set pump start time as curreny time stamp
        set_transient( 'timestamp_pump_ON_start',  $timestamp,  12 * 60 * 60 );
        set_transient( 'timestamp_pump_OFF',  $timestamp,  12 * 60 * 60 );

        return null;
      }
      else
      {
        // the transient exists. So if pump is currently ON but was not on before set the flag
        if ( $pump_is_on_now && ! $pump_alreay_ON )
        {
          $pump_alreay_ON = true;
          
          // update the transient so next check will work
          set_transient( 'pump_alreay_ON', true, 12 * 3600 );
          
          // capture pump ON start time as now
          // get the unix time stamp when measurement was made
          $now = new DateTime();
          $timestamp = $now->getTimestamp();

          // set pump start time as curreny time stamp
          set_transient( 'timestamp_pump_ON_start',  $timestamp,   12 * 3600);

          return null;
        }
        elseif ( $pump_is_on_now && $pump_alreay_ON )
        {
          // calculate pump ON duration time. If greater than 45 minutes switch power to pump OFF
          $now = new DateTime();
          $timestamp = $now->getTimestamp();

          $previous_timestamp = get_transient(  'timestamp_pump_ON_start' );
          $prev_datetime_obj = new DateTime();
          $prev_datetime_obj->setTimeStamp($previous_timestamp);

          // find out the time interval between the start timestamp and the present one in seconds
          $diff = $now->diff( $prev_datetime_obj );

          $pump_ON_duration_secs = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 );

          // Write the duration time as property of the object
          $shelly_4pm_readings_object->pump_ON_duration_secs = $pump_ON_duration_secs;
          
          // if pump ON duration is more than 45m then switch the pump power OFF in Shelly 4PM channel 0
          if ( $pump_ON_duration_secs > 2700 )
          {
            // turn shelly power for pump OFF and update transients
            $this->turn_pump_on_off( $user_index, 'off' );

            // pump is not ON anymore
            set_transient( 'pump_alreay_ON', false, 12 * 3600 );

            // set pump STOP time as curreny time stamp
            set_transient( 'timestamp_pump_OFF',  $timestamp,   12 * 60 * 60 );
          }

          // we return true since we turned pump power OFF
          return false;
        }
        elseif ( ! $shelly_4pm_readings_object->pump_switch_status_bool ) 
        {
          // Shelly pump control is OFF because pump was ON for too long and we probably switched it off
          // check to see if the duration after switch off was greater than 2h. If so turn the shelly control back ON
          // so that when the tank sensor empty can retrigger pump
          $now = new DateTime();
          $timestamp = $now->getTimestamp();

          $previous_timestamp = get_transient(  'timestamp_pump_OFF' );
          $prev_datetime_obj = new DateTime();
          $prev_datetime_obj->setTimeStamp($previous_timestamp);

          // find out the time interval between the last timestamp and the present one in seconds
          $diff = $now->diff( $prev_datetime_obj );

          $pump_OFF_duration_secs = ( $diff->s + $diff->i * 60  + $diff->h * 60 * 60 );

          if ( $pump_OFF_duration_secs >= 7200 )
          {
            // turn the shelly 4PM pump control back ON
            $this->turn_pump_on_off( $user_index, 'on' );
          }

          return true;
        }
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
    public function get_readings_and_servo_grid_switch( int $user_index, 
                                                        int $wp_user_ID, 
                                                        string $wp_user_name, 
                                                        bool $do_shelly,
                                                        bool $make_studer_api_call = true ) : ? object
    {
        { // Define boolean control variables for various time intervals
          $it_is_still_dark = $this->nowIsWithinTimeLimits( "18:55", "23:59:59" ) || $this->nowIsWithinTimeLimits( "00:00", "06:30" );

          // Boolean values for checking is present time is within defined time intervals
          $now_is_daytime       = $this->nowIsWithinTimeLimits("08:30", "16:30"); // changed from 17:30  on 7/28/22
          $now_is_sunset        = $this->nowIsWithinTimeLimits("16:31", "16:41");

          // False implies that Studer readings are to be used for SOC update, true indicates Shelly based processing
          // set default at the beginning to Studer updates of SOC
          $soc_updated_using_shelly_after_dark_bool = false;
        }

        $RDBC = false;    // permamantly disable RDBC mode 

        // get the user meta, all of it as an array for fast retrieval rtahr than 1 by 1 as done before
        $all_usermeta = $this->get_all_usermeta( $wp_user_ID );

        { // Get user meta for limits and controls
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
        
        if ( $it_is_still_dark )
        {   //---------------- Studer Midnight Rollover and SOC from Shelly readings after dark --------
            // gets the timestamp from transient / user meta to check if time interval from now to timestamp is < 12h
          $soc_after_dark_happened = $this->check_if_soc_after_dark_happened( $user_index, $wp_user_name, $wp_user_ID );

          if ( $soc_after_dark_happened )
          {   // it is dark AND soc capture after dark has happened so we can compute SOC using Shelly readings
              // since it is dark, we don;t need Studer to calculate SOC as long as we can measure Home Energy consumption
              // And also if ACIN switch is ON battery SOC remains conastant since Grid supplies Home and no charge/discharge
            $soc_from_shelly_energy_readings = $this->compute_soc_from_shelly_energy_readings(  $user_index, 
                                                                                                $wp_user_ID, 
                                                                                                $wp_user_name,
                                                                                                $shelly_switch_status );

            if ( empty( $soc_from_shelly_energy_readings ) )
            { // log and return null
              error_log($wp_user_name . ": " . "Shelly PRO 4PM energy meter API call failed. No SOC update nor Grid Switch Control");
              return null;
            }

            // extract the updated SOC from the returned object from SHelly Readings
            $SOC_percentage_now = $soc_from_shelly_energy_readings->SOC_percentage_now; // rounded already to 1d

            if ( $SOC_percentage_now )
            {
              // we can use the shelly soc updates since it is dark and SOC after dark reference has been captured
              $soc_updated_using_shelly_after_dark_bool = true;

              // Update user meta so this becomes the previous value for next cycle
              update_user_meta( $wp_user_ID, 'soc_percentage_now', $SOC_percentage_now);

              $this->verbose ? error_log("SOC update calculated by Shelly 4PM SOC= " . $SOC_percentage_now): false;

              // Independent of Servo Control Flag  - Switch Grid ON due to Low SOC - Don't care about Grid Voltage     
              $LVDS =             ( $SOC_percentage_now   <= $soc_percentage_lvds_setting )   // SOC is at or below threshold
                                  &&
                                  ( $shelly_switch_status == "OFF" );					                // The Grid switch is OFF

              // extract the SOC predicted at 6am from the returned object
              $soc_predicted_at_6am = $soc_from_shelly_energy_readings->soc_predicted_at_6am; // rounded already to 1d

              if ( false === ( $timer_since_last_6am_switch_event_running = get_transient( 'timer_since_last_6am_switch_event' ) ) )
              { // transient not there. So 1h has not expired or not even set in the 1st place. 
                
                $timer_since_last_6am_switch_event_running = false; // This will enable the soc6am related switching if required
              }
              else
              { // Transient exists. Since expected value of transient is true set the variable to true
                
                $timer_since_last_6am_switch_event_running = true;  // disable any soc6am switching till the timer runs out.
              }

              // Calculate boolean flag to determine if GRID is to be ON depending on SOC predicted at 6AM
              $LVDS_soc_6am_grid_on = ( $soc_predicted_at_6am <= ( $soc_percentage_lvds_setting + 0.01 ) ) // below desired limit
                                    &&
                                      ( $shelly_switch_status == "OFF" ) // The Grid switch is not already ON
                                    &&
                                      ( $soc_from_shelly_energy_readings->check_for_soc_rate_bool ) // only during valid time
                                    &&
                                      ( $control_shelly == true )                                 // control flag must be true
                                    &&
                                      ( ! $keep_shelly_switch_closed_always )                     // overridden by keep on always flag
                                    &&
                                      ( ! $timer_since_last_6am_switch_event_running ); // only if allowed by timer after last switch event

              // Calculate boolean flag to determine if GRID is to be OFF depending on SOC predicted at 6AM
              $LVDS_soc_6am_grid_off = ( $soc_predicted_at_6am  >  ( $soc_percentage_lvds_setting + 4.01 ) )   // 5 points hysterisys to prevent switch chatter
                                    &&
                                      ( $shelly_switch_status == "ON" )	// The Grid switch is not already OFF
                                    &&
                                    ( $soc_from_shelly_energy_readings->check_for_soc_rate_bool ) // check only during valid times
                                    &&
                                      ( $control_shelly == true )                                 // servo flag must be set
                                    &&
                                      ( ! $keep_shelly_switch_closed_always )                    // overridden by always on flag
                                    &&
                                      ( ! $timer_since_last_6am_switch_event_running ) // prevents switch chatter - only once per interval
                                    &&
                                      ( $SOC_percentage_now  >  ( $soc_percentage_lvds_setting + 0.3 ) ); // make sure SOC is above LVDS

              // make an API call on the water heater to get its data object
              $shelly_water_heater_data = $this->get_shelly_device_status_water_heater( $user_index );

              { // prepare object for Transient for updating screen readings
                $soc_from_shelly_energy_readings->shelly_water_heater_data          = $shelly_water_heater_data;
                $soc_from_shelly_energy_readings->valid_shelly_config               = $valid_shelly_config;
                $soc_from_shelly_energy_readings->control_shelly                    = $control_shelly;
                $soc_from_shelly_energy_readings->shelly_switch_status              = $shelly_switch_status;
                $soc_from_shelly_energy_readings->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;
                $soc_from_shelly_energy_readings->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
                $soc_from_shelly_energy_readings->LVDS                              = $LVDS;
                $soc_from_shelly_energy_readings->LVDS_soc_6am_grid_on              = $LVDS_soc_6am_grid_on;
                $soc_from_shelly_energy_readings->LVDS_soc_6am_grid_off             = $LVDS_soc_6am_grid_off;

                $soc_from_shelly_energy_readings->soc_predicted_at_6am              = $soc_predicted_at_6am;

                $soc_from_shelly_energy_readings->soc_updated_using_shelly_after_dark_bool = true;

                $soc_from_shelly_energy_readings->psolar                            = 0;

                // Psurplus is the -ve of Home Load since Solar is absent when dark
                $surplus = -1.0 * $soc_from_shelly_energy_readings->pout_inverter_ac_kw;
                $soc_from_shelly_energy_readings->surplus  = $surplus;

                // the below flag is not relevant so we don't this to get triggered in the conditions checking
                $switch_override = false;
              }

              $soc_update_method = "shelly_after_dark";

              // we can now check to see if Studer midnight has happened for midnight rollover capture
              // Each time the following executes it looks at a transient. Only when it expires does an API call made on Studer for 5002
              $studer_time_just_passed_midnight = $this->is_studer_time_just_pass_midnight( $user_index, $wp_user_name );

              if ( $studer_time_just_passed_midnight )
              { // reset the shelly load energy counter to 0. Capture SOC value for beginning of day
              
                error_log("Studer Clock just passed midnight-SOC=: " . $SOC_percentage_now);

                // reset the energy since midnight counter to 0 to start accumulation of consumed load energy in WH
                update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', 0 );

                // reset the Solar AH counter also to 0
                update_user_meta( $wp_user_ID, 'solar_accumulated_ah_since_midnight', 0 );

                // reset the Grid WH accumulator also to 0
                {
                  // Make an API call to mesure the grid energy counter and calculate the accumulated grid energy since Studer midnight
                  $shelly_em_readings_object = $this->get_shelly_accumulated_grid_wh_since_midnight( $user_index, $wp_user_name, $wp_user_ID );

                  $present_grid_wh_reading = $shelly_em_readings_object->present_grid_wh_reading;

                  update_user_meta( $wp_user_ID, 'grid_wh_counter_midnight', $present_grid_wh_reading );
                }
                

                // we can use this to update the user meta for SOC at beginning of new day
                if (  $SOC_percentage_now  > 20 && $SOC_percentage_now  < 100 )
                {
                  update_user_meta( $wp_user_ID, 'soc_percentage', $soc_from_shelly_energy_readings->SOC_percentage_now );
                }
                else
                {
                  error_log("Did not Update user meta for midnight rollover from Shelly - Number was not between 100 and 20");
                }
              }
            }
          }
          else
          {
            // SOC after dark capture did not happen yet. But the flag was not set
            // Therefore the flow below will happen and SOC after dark capture will now take place
            // This else was not needed but is used for clarity in documentation
          }
        }
        else  // it is not dark now so delete the transient to force capture of SOC dusk reference for next dark
        {
          delete_transient( $wp_user_name . '_' . 'timestamp_soc_capture_after_dark' );

          // make the timestamp Jan 1 20000 so that elapsed time is >>>>>>>12h so will fail our test
          update_user_meta( $wp_user_ID, 'timestamp_soc_capture_after_dark', 946665000);
        }

        // check to ensure that SOC shelly update was not done and if so make a Studer API call
        if ( ! $soc_updated_using_shelly_after_dark_bool )
        {   // Studer API call only when NOT dark or to capture SOC after dark
          
          if ( $make_studer_api_call )
          {   // make call only if flag enabled
            $studer_readings_obj  = $this->get_studer_min_readings($user_index);

            // defined the condition for failure of the Studer API call
            $studer_api_call_failed =   ( empty(  $studer_readings_obj )                          ||
                                          empty(  $studer_readings_obj->battery_voltage_vdc )     ||
                                          $studer_readings_obj->battery_voltage_vdc < 40          ||
                                          empty(  $studer_readings_obj->pout_inverter_ac_kw ) );
          }
          else
          {
            // as flag is not enabled set api failed flag to true
            $studer_api_call_failed = true;
          }

          // make an API call on the water heater to get its data object
          $shelly_water_heater_data = $this->get_shelly_device_status_water_heater( $user_index );
          
          // instantiate object to hold all of non-studer Shelly based readings info
          $shelly_readings_obj = new stdClass;

          { // get the SOCs % from the previous reading from user meta. This is needed for Studer and Shelly SOC updates
            $SOC_percentage_previous            = get_user_meta($wp_user_ID, "soc_percentage_now",  true);

            $SOC_percentage_previous_shelly_bm  = get_user_meta($wp_user_ID, "soc_percentage_now_calculated_using_shelly_bm",  true) ?? $SOC_percentage_previous;

            // Get the SOC percentage at beginning of Dayfrom the user meta. This gets updated only at beginning of day, once.
            $SOC_percentage_beg_of_day          = get_user_meta($wp_user_ID, "soc_percentage",  true) ?? 50;
          }
          
          { // Now make a Shelly API call on the Solar current monitoring device
            // get the estimated solar power object from calculations for a clear day
            $est_solar_obj = $this->estimated_solar_power($user_index);

            $est_solar_total_kw = $est_solar_obj->est_solar_total_kw;

            $total_to_west_panel_ratio = $est_solar_obj->total_to_west_panel_ratio;

            $est_solar_kw_arr = $est_solar_obj->est_solar_kw_arr;

            // Boolean Variable to designate it is a cloudy day. This is derived from a free external API service
            $it_is_a_cloudy_day   = $this->cloudiness_forecast->it_is_a_cloudy_day_weighted_average;

            // If Studer API call was successful update SOlar accumulated since midnight with STuder value as more accurate
            // This way when STuder API call fails, updates to SOlar accumulated even if not accurate still improves overall accuracy
            // This is because our solar power measurements are not as accurate as STuder's so we use that when available
            if ( ! $studer_api_call_failed )
            {
              $AH_solar_today_studer = round($studer_readings_obj->KWH_solar_today * 1000 / 49.8, 0);

              update_user_meta( $wp_user_ID, 'solar_accumulated_ah_since_midnight', $AH_solar_today_studer);
            }
            else
            {   // create a new stdclass object since Studer object not created due to api call fail
                // we still need this for legacy purposes even though STuder API call failed
              $studer_readings_obj = new stdclass;
            }

            // get a measurement of the solar current into battery junction from the panels
            // This also updates the solar AH accumulated since midnight in the user meta
            $shelly_solar_measurement_object = $this->get_shelly_solar_measurement( $user_index, $wp_user_name, $wp_user_ID, $total_to_west_panel_ratio );

            if ( empty( $shelly_solar_measurement_object ) )
            {
              error_log("Shelly UNI Solar Measurement API call failed - No SOC update or Switch control ");
              
              if ( ! $make_studer_api_call )
              {
                // if this was not a studer call run then since API call was bad we can abort this run
                return null;
              }
            }

            $solar_kwh_since_midnight = round( 49.8 * 0.001 * $shelly_solar_measurement_object->solar_accumulated_ah_since_midnight, 3 );

            $shelly_readings_obj->est_solar_total_kw        = $est_solar_total_kw;
            $shelly_readings_obj->est_solar_kw_arr          = $est_solar_kw_arr;
            $shelly_readings_obj->total_to_west_panel_ratio = $total_to_west_panel_ratio;
            $shelly_readings_obj->solar_kwh_since_midnight  = $solar_kwh_since_midnight;
            $shelly_readings_obj->solar_amps                = $shelly_solar_measurement_object->solar_amps;
            $shelly_readings_obj->solar_amps_west_raw_measurement  = $shelly_solar_measurement_object->solar_amps_west_raw_measurement;
            
            $psolar = round( 0.001 * 49.8 * $shelly_readings_obj->solar_amps, 3);
            $shelly_readings_obj->psolar = $psolar;

            $shelly_readings_obj->psolar_kw     = $psolar;                                          // Solar power in KW
            $shelly_readings_obj->solar_pv_adc  = $shelly_solar_measurement_object->solar_amps;     // Solar DC Amps

            $shelly_readings_obj->shelly_water_heater_data  = $shelly_water_heater_data;            // water heater data object
            
          }

          { // Also make a Shelly 4PM measurement to get individual powers from each channel for granular display
            $shelly_4pm_readings_object = $this->get_shelly_device_status_homepwr( $user_index );

            if ( ! empty( $shelly_4pm_readings_object ) )
            {   // there is a valid response from the Shelly 4PM switch device
                
                // Also check and control pump ON duration
                $this->control_pump_on_duration( $user_index, $shelly_4pm_readings_object);

                // Load the Studer Object with properties from the Shelly 4PM object
                $studer_readings_obj->power_to_home_kw    = $shelly_4pm_readings_object->power_to_home_kw;
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

                // calculate the energy consumed since midnight using Shelly4PM
                $accumulated_wh_since_midnight = $this->get_accumulated_wh_since_midnight(  $shelly_4pm_readings_object->energy_total_to_home_ts, 
                                                                                            $user_index, 
                                                                                            $wp_user_ID );

                $KWH_load_today_shelly = round( $accumulated_wh_since_midnight * 0.001, 3 );
                /*
                if ( abs( $studer_readings_obj->KWH_load_today - $KWH_load_today_shelly ) > 0.5 )
                {   // value computed by shelly is way off. So we reset the baseline
                  
                  error_log(" Shelly Midnight Counter reset due to bad reading using Studer Value");

                  $value_in_wh = (int) round( $studer_readings_obj->KWH_load_today * 1000.0, 0);

                  update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', $value_in_wh );

                  $KWH_load_today_shelly = $studer_readings_obj->KWH_load_today;
                }
                */

                $studer_readings_obj->KWH_load_today_shelly = $KWH_load_today_shelly;

                // ALso load the properties to the Shelly Readings Object
                $shelly_readings_obj->KWH_load_today_shelly  = $KWH_load_today_shelly;

                // power to home over 2 channels
                $shelly_readings_obj->power_to_home_kw    = $shelly_4pm_readings_object->power_to_home_kw;
                // power to channel powering the 3 AC's except dad's
                $shelly_readings_obj->power_to_ac_kw      = $shelly_4pm_readings_object->power_to_ac_kw;
                // channel supplying the sump pump
                $shelly_readings_obj->power_to_pump_kw    = $shelly_4pm_readings_object->power_to_pump_kw;
                // Total power supplied for all channels. This is the total load power supplied by the inverter
                $shelly_readings_obj->power_total_to_home = $shelly_4pm_readings_object->power_total_to_home;
                // Total power supplied to load in KW by inverter
                $shelly_readings_obj->power_total_to_home_kw  = $shelly_4pm_readings_object->power_total_to_home_kw;
                // Total AC load current supplied by inverter at 230V AC
                $shelly_readings_obj->current_total_home      = $shelly_4pm_readings_object->current_total_home;
                // Energy counter value in WH for total load supplied
                $shelly_readings_obj->energy_total_to_home_ts = $shelly_4pm_readings_object->energy_total_to_home_ts;

                // Boolean status of switch supplying SUmp Pump
                $shelly_readings_obj->pump_switch_status_bool = $shelly_4pm_readings_object->pump_switch_status_bool;
                // Booolean status of switch supplying AC's
                $shelly_readings_obj->ac_switch_status_bool   = $shelly_4pm_readings_object->ac_switch_status_bool;
                // Status of all switches supplying Home ( 2 lines)
                $shelly_readings_obj->home_switch_status_bool = $shelly_4pm_readings_object->home_switch_status_bool;

                $shelly_readings_obj->pout_inverter_ac_kw = $KWH_load_today_shelly;

                $shelly_readings_obj->voltage_home            = $shelly_4pm_readings_object->voltage_home;

                $shelly_readings_obj->pump_ON_duration_secs   = $shelly_4pm_readings_object->pump_ON_duration_secs;
            }
            else 
            {
              error_log(" Shelly Pro 4 PM Home Load measurement API call failed - no SOC update");
              return null;
            }
          }

          { // make an API call on the Grid Power Shelly EM device and calculate the accumulated WH since midnight
            $shelly_em_readings_object = $this->get_shelly_accumulated_grid_wh_since_midnight( $user_index, $wp_user_name, $wp_user_ID );

            $grid_wh_since_midnight = $shelly_em_readings_object->grid_wh_since_midnight;

            $grid_kwh_since_midnight = round( $grid_wh_since_midnight / 1000.0, 3 );

            $shelly_readings_obj->grid_kwh_since_midnight  = $grid_kwh_since_midnight;

            $shelly_readings_obj->grid_kw_shelly_em = $shelly_em_readings_object->grid_kw_shelly_em;

            // Grid AC voltage measured by Shelly EM
            $shelly_readings_obj->grid_voltage_em = $shelly_em_readings_object->grid_voltage_em;
          }

          { // calculate non-studer based SOC during daytime using Shelly device measurements

            $KWH_batt_charge_net_today_shelly  = $solar_kwh_since_midnight * 0.96 + (0.988 * $grid_kwh_since_midnight - $KWH_load_today_shelly) * 1.07;

            $soc_charge_net_percent_today_shelly = round( $KWH_batt_charge_net_today_shelly / $SOC_capacity_KWH * 100, 1);

            $soc_percentage_now_shelly = $SOC_percentage_beg_of_day + $soc_charge_net_percent_today_shelly;
            
            // lets update the user meta for updated SOC
            update_user_meta( $wp_user_ID, 'soc_percentage_now_calculated_using_shelly_bm', $soc_percentage_now_shelly);

            // Surplus power from Solar after supplying the Load as measured by Shelly devices
            $surplus              = $shelly_readings_obj->psolar - $shelly_4pm_readings_object->power_total_to_home_kw;

            $shelly_readings_obj->surplus  = $surplus;
            $shelly_readings_obj->SOC_percentage_now  = $soc_percentage_now_shelly;


            // Independent of Servo Control Flag  - Switch Grid ON due to Low SOC - Don't care about Grid Voltage     
            $shelly_readings_obj->LVDS =  ( $soc_percentage_now_shelly  <= $soc_percentage_lvds_setting ) // SOC threshold
                                          &&
                                          ( $shelly_switch_status == "OFF" );					                    // The Grid switch is OFF
          }

          // wether STuder API fails or not this is common
          if ( $this->verbose )
          {
            error_log("Psolar_calc: " . $est_solar_total_kw . " Psolar_act: " . $psolar . " - Psurplus: " . 
                                        $surplus . " KW - Is it a Cloudy Day?: " . $it_is_a_cloudy_day);
          }

          
          if ( ! $studer_api_call_failed )
          {   // Studer SOC update calculations only if API call was successful. If needed capture SOC after dark
              // average the battery voltage over last 3 readings
            $battery_voltage_avg  = $this->get_battery_voltage_avg( $wp_user_name, $studer_readings_obj->battery_voltage_vdc );

            set_transient( $wp_user_name . '_' . 'battery_voltage_avg', $battery_voltage_avg, 60 * 60 );
  
            // Solar power Now
            $psolar               = $studer_readings_obj->psolar_kw;
  
            // Check if it is cloudy AT THE MOMENT. Yes if solar is less than half of estimate
            $it_is_cloudy_at_the_moment = $psolar <= 0.5 * array_sum($est_solar_kw_arr);
  
            // Solar Current into Battery Junction at present moment
            // $solar_pv_adc         = $studer_readings_obj->solar_pv_adc;
  
            // Inverter readings at present Instant
            $pout_inverter        = $studer_readings_obj->pout_inverter_ac_kw;    // Inverter Output Power in KW
            $grid_input_vac       = $studer_readings_obj->grid_input_vac;         // Grid Input AC Voltage measured by STuder
            // $inverter_current_adc = $studer_readings_obj->inverter_current_adc;   // DC current into Inverter to convert to AC power
  
            // Surplus power from Solar after supplying the Load
            $surplus              = $psolar - $pout_inverter;
  
            // Weighted percentage cloudiness
            $cloudiness_average_percentage_weighted = round($this->cloudiness_forecast->cloudiness_average_percentage_weighted, 0);
  
            // get the current Measurement values from the Stider Readings Object
            $KWH_solar_today      = $studer_readings_obj->KWH_solar_today;  // Net SOlar Units generated Today
            $KWH_grid_today       = $studer_readings_obj->KWH_grid_today;   // Net Grid Units consumed Today
            $KWH_load_today       = $studer_readings_obj->KWH_load_today;   // Net Load units consumed Today

            // Net battery charge in KWH (discharge if minus)
            $KWH_batt_charge_net_today  = $KWH_solar_today * 0.96 + (0.988 * $KWH_grid_today - $KWH_load_today) * 1.07;
  
            // Calculate in percentage of  installed battery capacity
            $SOC_batt_charge_net_percent_today = round( $KWH_batt_charge_net_today / $SOC_capacity_KWH * 100, 1);
  
            //  Update SOC  number 
            $SOC_percentage_now = $SOC_percentage_beg_of_day + $SOC_batt_charge_net_percent_today;

            if ( $this->verbose )
            {   // log all measurements inluding Studer and Shelly
                /*
                error_log("username: " . $wp_user_name . ' Switch: ' . $shelly_switch_status . ' ' . 
                                         $battery_voltage_avg . ' V, ' . $studer_readings_obj->battery_charge_adc . 'A ' .
                                         $shelly_api_device_status_voltage . ' VAC');
                */

                error_log("Grid Voltage (Shelly EM): $shelly_em_readings_object->grid_voltage_em");

                error_log("Load_KWH_today_Studer = " . $KWH_load_today . " KWH_load_Shelly = " . $KWH_load_today_shelly);

                error_log("Grid KWH Studer Today: $KWH_grid_today, Grid KWH Shelly Today: $grid_kwh_since_midnight");

                error_log("Solar KWH Studer Today: $KWH_solar_today, Solar KWH Shelly Today: $solar_kwh_since_midnight");

                error_log("SOC_shelly_BM: $soc_percentage_now_shelly, SOC_Studer: $SOC_percentage_now");
            }

            {   // calculate SOC update using Load KWH calculated using Shelly 4PM, not Studer. Occassionally This is bad for Studer
              $KWH_batt_charge_net_today_shelly  = $KWH_solar_today * 0.96 + (0.988 * $KWH_grid_today - $KWH_load_today_shelly) * 1.07;
              
              $SOC_batt_charge_net_percent_today_shelly = round( $KWH_batt_charge_net_today_shelly / $SOC_capacity_KWH * 100, 1);

              $SOC_percentage_now_shelly4pm = $SOC_percentage_beg_of_day + $SOC_batt_charge_net_percent_today_shelly;
            }
  
            // Check if Stider computed SOC update is reasonable
            if ( $SOC_percentage_now < 20 || $SOC_percentage_now > 105 ) 
            {
              error_log("SOC_Studer is a bad update: " .  $SOC_percentage_now . " %");

              // SO Studer SOC value is bad. Lets check if the Shelly based SOC is reasonable
              if ( $SOC_percentage_now_shelly4pm > 20 && $SOC_percentage_now_shelly4pm < 105 )
              {   // Studer SOC update is bad but the Shelly updated SOC seems reasonable so lets use that
                  // Update user meta so this becomes the previous value for next cycle
                  update_user_meta( $wp_user_ID, 'soc_percentage_now', $SOC_percentage_now_shelly4pm);
              }
              else
              {   // Both SOC updates are bad
                  error_log("SOC_Shelly is also a bad update: " .  $SOC_percentage_now_shelly4pm . " %");

                  error_log("Setting SOC to 5 points below the LVDS setting as a safety catch");

                  // set a clamp for the SOC value at Min value -3 points
                  update_user_meta( $wp_user_ID, 'soc_percentage_now', $soc_percentage_lvds_setting - 3 );
              }
            }
            else
            {   // Studer SOC update seems reasonable
                // Update user meta so this becomes the previous value for next cycle
                update_user_meta( $wp_user_ID, 'soc_percentage_now', $SOC_percentage_now);
            }

            { // Independent of Servo Control Flag  - Switch Grid ON due to Low SOC - or  battery voltage    
              $LVDS =             ( $battery_voltage_avg  <= $battery_voltage_avg_lvds_setting || 
                                    $SOC_percentage_now   <= $soc_percentage_lvds_setting           )  
                                    &&
                                  ( $shelly_switch_status == "OFF" );					  // The switch is OFF

              $switch_override =  ( $shelly_switch_status                == "OFF" )  &&
                                  ( $studer_readings_obj->grid_input_vac >= 190   );
  
              // set the switch conditions to false since we are relying on the Studer readings, usually at daytime only.
              // we don;t want these flags to cause any activity when Stder readings are relied upon
              // These should be valid only for the case when the Shelly readings are used for update
              $LVDS_soc_6am_grid_on   = false;
              $LVDS_soc_6am_grid_off  = false;

              $soc_update_method = "studer";
  
            }

            // update the object
            $studer_readings_obj->SOC_percentage_now  = $SOC_percentage_now;
            $studer_readings_obj->LVDS                = $LVDS;
            $studer_readings_obj->soc_updated_using_shelly_after_dark_bool = false;

            // capture soc after dark using shelly 4 pm. Only happens ONCE between 18:55 and 23:00 hrs
            $this->capture_evening_soc_after_dark( $wp_user_name, $SOC_percentage_now, $user_index );
          }   // endif of studer_api_failed = false
          else
          {   // set SOC now to Shelly updated one if daytime since Studer API has failed. Also log Shelly values
            $SOC_percentage_now = $soc_percentage_now_shelly;

            // LVDS already defined above for Shelly measure values
            $LVDS = false; // $shelly_readings_obj->LVDS;

            // set this flag to false since we have no way of accessing Studer's Grid measurement.
            // This flag is only valid when Studer's API call is successful.
            $switch_override = false;

            // set these flags to false since this is daytime. In anycase this is not even implemented at night anymore
            $LVDS_soc_6am_grid_on   = false;
            $LVDS_soc_6am_grid_off  = false;

            $soc_update_method = "shelly_daytime";

            if ( $this->verbose )
            {   // log measurements
              error_log( $wp_user_name . ": " . "SOC updates using Shelly EM, 4PM and Uni, Daytime" );

              /*
              error_log("username: " . $wp_user_name . ' Switch: ' . $shelly_switch_status . ' ' . 
                                         $shelly_api_device_status_voltage . ' VAC');
              */
                
              error_log("Grid Switch: " . $shelly_switch_status      .  " VOltage: " . $shelly_api_device_status_voltage . 
                        " Load KWH= "   . $KWH_load_today_shelly     . 
                        " Grid KWH= "   . $grid_kwh_since_midnight   . 
                        " Solar KWH= "  . $solar_kwh_since_midnight  .
                        " SOC %= "      . $soc_percentage_now_shelly);
            }

          } // end else of studer_api_failed = true

        }   // endif of soc_updated_using_shelly_after_dark_bool = false
        
        {   // define all the conditions for the SWITCH - CASE tree except for LVDS that is done individually
            // note that $SOC_percentage_now needs to be defined properly depending on path taken
          // AC input voltage is being sensed by Studer even though switch status is OFF meaning manual MCB before Studer is ON
          // In this case, since grid is manually switched ON there is nothing we can do
          // Keep Grid Switch CLosed Untless Solar charges Battery to $soc_percentage_switch_release_setting - 5 or say 90%
          // So between this and switch_release_float_state battery may cycle up and down by 5 points
          // Ofcourse if the Psurplus is too much it will charge battery to 100% inspite of this.
          // Obviously after sunset the battery will remain at 90% till sunrise the next day
          

          $keep_switch_closed_always =  ( $shelly_switch_status == "OFF" )             &&
                                        ( $keep_shelly_switch_closed_always == true )  &&
                                        ( $SOC_percentage_now <= ($soc_percentage_switch_release_setting - 5) )	&&  // OR SOC reached 90%
                                        ( $control_shelly == true )                    &&
                                        ( $soc_update_method === "studer");


          $reduce_daytime_battery_cycling = ( $shelly_switch_status == "OFF" )              &&  // Switch is OFF
                                            ( $SOC_percentage_now <= $soc_percentage_rdbc_setting )	&&	// Battery NOT in FLOAT state
                                            ( $shelly_api_device_status_voltage >= $acin_min_voltage_for_rdbc	)	&&	// ensure Grid AC is not too low
                                            ( $shelly_api_device_status_voltage <= $acin_max_voltage_for_rdbc	)	&&	// ensure Grid AC is not too high
                                            ( $now_is_daytime )                             &&   // Now is Daytime
                                            ( $psolar  >= $psolar_min_for_rdbc_setting )    &&   // at least some solar generation
                                            ( $surplus <= $psolar_surplus_for_rdbc_setting ) &&  // Solar Deficit is negative
                                            ( $it_is_cloudy_at_the_moment )                 &&   // Only when it is cloudy
                                            ( $RDBC == true )                               &&  // RDBC flag is ON
                                            ( $control_shelly == true );                         // Control Flag is SET
          // switch release typically after RDBC when Psurplus is positive.
          $switch_release =  ( $SOC_percentage_now >= ( $soc_percentage_lvds_setting + 0.3 ) ) &&  // SOC ?= LBDS + offset
                             ( $shelly_switch_status == "ON" )  														  &&  // Switch is ON now
                             ( $surplus >= $min_solar_surplus_for_switch_release_after_rdbc ) &&  // Solar surplus is >= 0.2KW
                             ( $keep_shelly_switch_closed_always == false )                   &&	// Emergency flag is False
                             ( $control_shelly == true )                                      &&
                             ( $soc_update_method === "studer");                                  // only for studer updated value                            

          // In general we want home to be on Battery after sunset
          $sunset_switch_release			=	( $keep_shelly_switch_closed_always == false )  &&  // Emergency flag is False
                                        ( $shelly_switch_status == "ON" )               &&  // Switch is ON now
                                        ( $now_is_sunset )                              &&  // around sunset
                                        ( $control_shelly == true );

          // This is needed when RDBC or always ON was triggered and Psolar is charging battery beyond 95%
          // independent of keep_shelly_switch_closed_always flag status
          $switch_release_float_state	= ( $shelly_switch_status == "ON" )  							  &&  // Switch is ON now
                                        ( $SOC_percentage_now >= $soc_percentage_switch_release_setting )	&&  // OR SOC reached 95%
                                        // ( $keep_shelly_switch_closed_always == false )  &&  // Always ON flag is OFF
                                        ( $control_shelly == true )                        &&
                                        ( $soc_update_method === "studer");                    // only for studer updated values
        }

        if ( ! $soc_updated_using_shelly_after_dark_bool && ! $studer_api_call_failed )
        {   // write back new values to the studer_readings_obj
          $studer_readings_obj->battery_voltage_avg               = $battery_voltage_avg;
          $studer_readings_obj->now_is_daytime                    = $now_is_daytime;
          $studer_readings_obj->now_is_sunset                     = $now_is_sunset;
          $studer_readings_obj->control_shelly                    = $control_shelly;
          $studer_readings_obj->shelly_switch_status              = $shelly_switch_status;
          $studer_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;
          $studer_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
          $studer_readings_obj->shelly_switch_acin_details_arr    = $shelly_switch_acin_details_arr;

          $studer_readings_obj->reduce_daytime_battery_cycling    = $reduce_daytime_battery_cycling;
          $studer_readings_obj->switch_release                    = $switch_release;
          $studer_readings_obj->sunset_switch_release             = $sunset_switch_release;
          $studer_readings_obj->switch_release_float_state        = $switch_release_float_state;
          
          $studer_readings_obj->cloudiness_average_percentage_weighted  = $cloudiness_average_percentage_weighted;
          $studer_readings_obj->est_solar_kw_arr  = round( array_sum($est_solar_kw_arr), 1);

          $studer_readings_obj->shelly_water_heater_data          = $shelly_water_heater_data;

          $note_exit = "Studer";
        }
        elseif ( $soc_updated_using_shelly_after_dark_bool )
        {   // writeback to soc_from_shelly_energy_readings object
          $soc_from_shelly_energy_readings->battery_voltage_avg = "NA";
          $soc_from_shelly_energy_readings->est_solar_kw        = 0;
          $soc_from_shelly_energy_readings->cloudiness_average_percentage_weighted = "NA";

          $note_exit = "Shelly Night";
        }
        elseif ( ! $soc_updated_using_shelly_after_dark_bool && $studer_api_call_failed )
        {   // write back to shelly_readings_obj object
          $note_exit = "Shelly Day";

          $shelly_readings_obj->battery_voltage_avg  = get_transient( $wp_user_name . '_' . 'battery_voltage_avg' ) ?? 49.8;

          $shelly_readings_obj->control_shelly                    = $control_shelly;
          $shelly_readings_obj->shelly_switch_status              = $shelly_switch_status;
          $shelly_readings_obj->shelly_api_device_status_voltage  = $shelly_api_device_status_voltage;
          $shelly_readings_obj->shelly_api_device_status_ON       = $shelly_api_device_status_ON;
          $shelly_readings_obj->shelly_switch_acin_details_arr    = $shelly_switch_acin_details_arr;

          $pbattery_kw =  $psolar * 0.96 + ( 0.988 * $shelly_em_readings_object->grid_kw_shelly_em 
                                              - $shelly_4pm_readings_object->power_total_to_home_kw
                                            ) * 1.07 ;
          $battery_charge_adc = round( $pbattery_kw * 1000 / 49.8, 1);

          $shelly_readings_obj->battery_charge_adc = $battery_charge_adc;
          $shelly_readings_obj->pbattery_kw = $pbattery_kw;
          $shelly_readings_obj->grid_pin_ac_kw = $shelly_em_readings_object->grid_kw_shelly_em;
          $shelly_readings_obj->grid_input_vac = $shelly_api_device_status_voltage;
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


            // <4> Daytime, reduce battery cycling, turn SWITCH ON
            case ( $reduce_daytime_battery_cycling ):

              $response = $this->turn_on_off_shelly_switch($user_index, "on", 'shelly_device_id_acin');

                error_log( 'Exited via Case 4 - reduce daytime battery cycling - Grid Switched ON' );
                $cron_exit_condition = "RDBC-Grid ON" ;
            break;

            // <7> predicted SOC at 6AM below LVDS SOC limit + margin so turn GRID Switch ON
            case ( $LVDS_soc_6am_grid_on ):

              $response = $this->turn_on_off_shelly_switch($user_index, "on", 'shelly_device_id_acin');

              error_log("Exited via Case 7 - GRID Switched OFF - Predicted SOC at 6AM low : " . $soc_predicted_at_6am );
              $cron_exit_condition = "SOC6AM LOW - GRID ON ";

              // set a transient for 30m. This will be checked for before next switching event
              set_transient( 'timer_since_last_6am_switch_event', true, 30*60 );

            break;

            // <8> predicted SOC at 6AM above LVDS SOC limit + margin so turn GRID Switch OFF
            case ( $LVDS_soc_6am_grid_off ):

              $response = $this->turn_on_off_shelly_switch($user_index, "off", 'shelly_device_id_acin');

              error_log("Exited via Case 8 - GRID Switched OFF - Predicted SOC at 6AM OK : " . $soc_predicted_at_6am );
              $cron_exit_condition = "SOC 6AM OK-GRID OFF ";

              // set a transient for 30m. This will be checked for before next switching event
              set_transient( 'timer_since_last_6am_switch_event', true, 30*60 );

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


            // <6> Turn switch OFF at 5:30 PM if emergency flag is False so that battery can supply load for the night
            case ( $sunset_switch_release ):

                $response = $this->turn_on_off_shelly_switch($user_index, "off", 'shelly_device_id_acin');

                error_log("Exited via Case 6 - sunset, Grid switched OFF");
                $cron_exit_condition = "Sunset-Grid Off ";

                if ( $response->isok )
                {
                  $notification_title = "Sunset-Grid Off";
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
        set_transient( $wp_user_name . '_' . 'soc_update_method', $soc_update_method, 5*60 );

        $now = new DateTime();

        $array_for_json = [ 'unixdatetime'        => $now->getTimestamp() ,
                            'cron_exit_condition' => $cron_exit_condition ,
                          ];

        // save the data in a transient indexed by the user name. Expiration is 5 minutes. Object depends on whether shelly or Studer
        // Update the user meta with the CRON exit condition only for definite ACtion not for No Action
        if ($cron_exit_condition !== "No Action ") 
          {
              update_user_meta( $wp_user_ID, 'studer_readings_object',  json_encode( $array_for_json ) );
          }
        
        // return object based on mode of update whetehr Studer or Shelly. If Studer, also apply 100% clamp
        if ( ! $soc_updated_using_shelly_after_dark_bool && ! $studer_api_call_failed )
        {  // SOC is updated by Studer
          set_transient( $wp_user_name . '_' . 'studer_readings_object', $studer_readings_obj, 5*60 );

          if (  $SOC_percentage_now > 100.0 || $battery_voltage_avg  >=  $battery_voltage_avg_float_setting )
          {
            // Since we know that the battery SOC is 100% use this knowledge along with
            // Energy data to recalibrate the soc_percentage user meta
            $SOC_percentage_beg_of_day_recal = 100 - $SOC_batt_charge_net_percent_today;

            update_user_meta( $wp_user_ID, 'soc_percentage', $SOC_percentage_beg_of_day_recal);

            error_log("SOC 100% clamp activated: " . $SOC_percentage_beg_of_day_recal  . " %");

            // also make the load kwh today, equal between shelly and studer.
            $WH_load_today_studer = (int) round($KWH_load_today * 1000, 0);
            update_user_meta( $wp_user_ID, 'shelly_energy_counter_midnight', $WH_load_today_studer);
            
          }
          return $studer_readings_obj;
        }
        elseif ( $soc_updated_using_shelly_after_dark_bool )
        { // SOC updates from Shelly after Dark measurements
          set_transient( $wp_user_name . '_' . 'soc_from_shelly_energy_readings', $soc_from_shelly_energy_readings, 5*60 );

          // no need to worry about 100% clamp during night time since battery will not charge unless Solar is there
          return $soc_from_shelly_energy_readings;
        }
        elseif ( ! $soc_updated_using_shelly_after_dark_bool && $studer_api_call_failed )
        { // SOC updates from Shelly Uni, Shelly EM and Shelly Pro day time measurements

          set_transient( $wp_user_name . '_' . 'shelly_readings_obj', $shelly_readings_obj, 5*60 );

          // no need to worry about 100% clamp during night time since battery will not charge unless Solar is there
          return $shelly_readings_obj;
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


        foreach ($config['accounts'] as $user_index => $account)
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
        foreach ($config['accounts'] as $user_index => $account)
        {
          $wp_user_name = $account['wp_user_name'];

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

        // get the Studer status using the minimal set of readings. At night this is the object from the Shelly readings
        $studer_readings_obj  = $this->get_readings_and_servo_grid_switch($user_index, $wp_user_ID, $wp_user_name, $do_shelly);

        $it_is_still_dark = $this->nowIsWithinTimeLimits( "18:55", "23:59:59" ) || $this->nowIsWithinTimeLimits( "00:00", "06:30" );

        // check for valid studer values. Return if empty.
        if( empty(  $studer_readings_obj ) )
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


        $output .= '<div id="cron_exit_condition">'. $format_object->cron_exit_condition     . '</div>';

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
                <input type="submit" name="button" 	value="get_shelly_solar_measurement"/>
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

            case "get_shelly_solar_measurement":

              $count = 1;

              

              $wp_user_ID = $this->get_wp_user_from_user_index( $config_index )->ID;

              
                
                $est_solar_kw = $this->estimated_solar_power($config_index);

                $ratio_west_total = array_sum( $est_solar_kw ) / $est_solar_kw[1];

                $solar_measurement_object = $this->get_shelly_solar_measurement( $config_index, $wp_user_name, $wp_user_ID, $ratio_west_total );

                $total_solar_current = 1.00 * round( $solar_measurement_object->solar_amps, 1);

                // print( "ADC voltage (V): " .                                $battery_measurement_object->voltage . PHP_EOL );
                print( round($solar_measurement_object->solar_amps_west_raw_measurement, 1) . " " . PHP_EOL);
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
          { // switch status is unknown

              error_log("Shelly Water Heater Switch API call failed - Reason unknown");

              $shelly_api_device_status_ON = null;

              $shelly_switch_status             = "OFFLINE";
              $shelly_api_device_status_voltage = "NA";
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
                  $shelly_water_heater_kw             = round( $shelly_water_heater_w * 0.001, 2);
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
              $grid_pin_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

            case ( $user_value->reference == 3078 ) :
              $KWH_battery_today = round($user_value->value, 2);

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

            case ( $user_value->reference == 5002 ) :

              $studer_clock_unix_timestamp_with_utc_offset = $user_value->value;

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
              $grid_pin_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3136 ) :
              $pout_inverter_ac_kw = round($user_value->value, 2);

            break;

            case ( $user_value->reference == 3076 ) :
               $energyout_battery_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3078 ) :
                $KWH_batt_discharged_today = round($user_value->value, 2);

            break;

             case ( $user_value->reference == 3080 ) :
               $energy_grid_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3081 ) :
                $KWH_grid_today = round($user_value->value, 2);

            break;

             case ( $user_value->reference == 3082 ) :
               $energy_consumed_yesterday = round($user_value->value, 2);

             break;

             case ( $user_value->reference == 3083 ) :
              $KWH_load_today = round($user_value->value, 2);

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

            case ( $user_value->reference == 11007 ) :
              // we have to accumulate values form 2 cases so we have used accumulation below
              $KWH_solar_today += round($user_value->value, 2);

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
      date_default_timezone_set("Asia/Kolkata");

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
        $user_index   = array_search( $wp_user_name, array_column($this->config['accounts'], 'wp_user_name')) ;

        // error_log('from CRON Ajax Call: wp_user_ID:' . $wp_user_ID . ' user_index:'   . $user_index);
      }

      $soc_update_method = get_transient( $wp_user_name . '_' . 'soc_update_method' );

      // get the transient related to this user ID that stores the latest Readingss - check if from Studer or Shelly
      // $it_is_still_dark = $this->nowIsWithinTimeLimits( "18:55", "23:59:59" ) || $this->nowIsWithinTimeLimits( "00:00", "06:30" );

      if ( $soc_update_method === "shelly_after_dark" )
      {
        $studer_readings_obj = get_transient( $wp_user_name . '_' . 'soc_from_shelly_energy_readings' );
      }
      elseif ( $soc_update_method === "studer" )
      {
        $studer_readings_obj = get_transient( $wp_user_name . '_' . 'studer_readings_object' );
      }
      elseif ( $soc_update_method === "shelly_daytime" )
      {
        $studer_readings_obj = get_transient( $wp_user_name . '_' . 'shelly_readings_obj' );
      }

      // error_log(print_r($studer_readings_obj, true));

      if ($studer_readings_obj) {   // transient exists so we can send it
          
          $format_object = $this->prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj );

          // send JSON encoded data to client browser AJAX call and then die
          wp_send_json($format_object);
      }
      else {    // transient does not exist so send null
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
    public function prepare_data_for_mysolar_update( $wp_user_ID, $wp_user_name, $studer_readings_obj )
    {
        $config         = $this->config;

        $soc_update_method = get_transient( $wp_user_name . '_' . 'soc_update_method' );

        // Initialize object to be returned
        $format_object  = new stdClass();

        // extract and process Shelly 1PM switch water heater data
        $shelly_water_heater_data     = $studer_readings_obj->shelly_water_heater_data;     // data object
        $shelly_water_heater_kw       = $shelly_water_heater_data->shelly_water_heater_kw;
        $shelly_water_heater_status   = $shelly_water_heater_data->shelly_water_heater_status;  // boolean variable
        $shelly_water_heater_current  = $shelly_water_heater_data->shelly_water_heater_current; // in Amps

        $psolar_kw              =   round($studer_readings_obj->psolar_kw, 2) ?? 0;
        $solar_pv_adc           =   $studer_readings_obj->solar_pv_adc ?? 0;

        $pout_inverter_ac_kw    =   $studer_readings_obj->pout_inverter_ac_kw;

        // changed to avg July 15 2023 was battery_voltage_vdc before that
        $battery_voltage_vdc    =   round( (float) $studer_readings_obj->battery_voltage_avg, 1);

        // Positive is charging and negative is discharging
        $battery_charge_adc     =   $studer_readings_obj->battery_charge_adc;

        $pbattery_kw            = abs(round($studer_readings_obj->pbattery_kw, 2));

        $grid_pin_ac_kw         =   $studer_readings_obj->grid_pin_ac_kw;
        $grid_input_vac         =   $studer_readings_obj->grid_input_vac;

        $shelly_api_device_status_ON      = $studer_readings_obj->shelly_api_device_status_ON;

        // This is the AC voltage of switch:0 of Shelly 4PM
        $shelly_api_device_status_voltage = $studer_readings_obj->shelly_api_device_status_voltage;

        $SOC_percentage_now = $studer_readings_obj->SOC_percentage_now;

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
                                      <i class="clickableIcon fa-solid fa-2x fa-cloud"></i>
                                  </span>';
        }
        $format_object->shelly_servo_icon = $shelly_servo_icon;

        // battery status icon: select battery icon based on charge level
        switch(true)
        {
            case ($SOC_percentage_now < 25):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-empty";
            break;

            case ($SOC_percentage_now >= 25 &&
                  $SOC_percentage_now <  37.5 ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-quarter";
            break;

            case ($SOC_percentage_now >= 37.5 &&
                  $SOC_percentage_now <  50 ):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-half";
            break;

            case ($SOC_percentage_now >= 50 &&
                  $SOC_percentage_now <  77.5):
              $battery_icon_class = "fa fa-3x fa-solid fa-battery-three-quarters";
            break;

            case ($SOC_percentage_now >= 77.5):
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

        // Shelly 4PM load breakout data
        $power_total_to_home = $studer_readings_obj->power_total_to_home;
        $power_total_to_home_kw = $studer_readings_obj->power_total_to_home_kw; // round( $power_total_to_home * 0.001, 2);

        $power_to_home_kw = $studer_readings_obj->power_to_home_kw;
        $power_to_ac_kw   = $studer_readings_obj->power_to_ac_kw;
        $power_to_pump_kw = $studer_readings_obj->power_to_pump_kw;

        $pump_ON_duration_mins = (int) round( $studer_readings_obj->pump_ON_duration_secs / 60, 0);

        $pump_switch_status_bool  = $studer_readings_obj->pump_switch_status_bool;
        $ac_switch_status_bool    = $studer_readings_obj->ac_switch_status_bool;
        $home_switch_status_bool  = $studer_readings_obj->home_switch_status_bool;


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
                                                <strong>' . $studer_readings_obj->power_to_home_kw . ' KW</strong>
                                            </span>';

        $format_object->power_to_ac_kw = '<span style="font-size: 18px;color: Black;">
                                                <strong>' . $studer_readings_obj->power_to_ac_kw . ' KW</strong>
                                            </span>';

        $format_object->power_to_pump_kw = '<span style="font-size: 18px;color: Black;">
                                              <strong>' . $pump_ON_duration_mins . ' mins</strong>
                                          </span>';

        $format_object->shelly_water_heater_kw = '<span style="font-size: 18px;color: Black;">
                                                    <strong>' . $shelly_water_heater_kw . ' KW</strong>
                                                  </span>';

        // Get Cron Exit COndition from User Meta and its time stamo
        $json_cron_exit_condition_user_meta = get_user_meta( $wp_user_ID, 'studer_readings_object', true );
        // decode the JSON encoded string into an Object
        $cron_exit_condition_user_meta_arr = json_decode($json_cron_exit_condition_user_meta, true);

        // extract the last condition saved that was NOT a No Action. Add cloudiness and Estimated Solar to message
        $saved_cron_exit_condition = $cron_exit_condition_user_meta_arr['cron_exit_condition'];

        if ( ! empty( $studer_readings_obj->cloudiness_average_percentage_weighted ) )
        {
          $saved_cron_exit_condition .= " Cloud: " . $studer_readings_obj->cloudiness_average_percentage_weighted . " %";
        }

        if ( ! empty( $studer_readings_obj->est_solar_kw ) )
        {
          $saved_cron_exit_condition .= " Pest: " . $studer_readings_obj->est_solar_kw . " KW";
        }

        if ( ! empty( $studer_readings_obj->soc_predicted_at_6am ) )
        {
          $saved_cron_exit_condition .= " Est. SOC 6AM: " . $studer_readings_obj->soc_predicted_at_6am . " %";
        }
        
        

        // present time
        $now = new DateTime();
        // timestamp at last measurement exit
        $past_unixdatetime = $cron_exit_condition_user_meta_arr['unixdatetime'];
        // get datetime object from timestamp
        $past = (new DateTime('@' . $past_unixdatetime))->setTimezone(new DateTimeZone("Asia/Kolkata"));
        // get the interval object
        $interval_since_last_change = $now->diff($past);
        // format the interval for display
        $formatted_interval = $this->format_interval($interval_since_last_change);

        /*
        $format_object->cron_exit_condition = '<span style="font-size: 18px;color: Blue; display:block; text-align: center;">' . 
                                                  'SOC: <strong>' . $SOC_percentage_now . ' %' . '</strong><br>
                                               </span>' .
                                              '<span style="color: Blue; display:block; text-align: center;">' .
                                                  $formatted_interval   . '<br>' . 
                                                  $saved_cron_exit_condition  .
                                              '</span>';
        */
        $format_object->soc_percentage_now_html = '<span style="font-size: 20px;color: Blue; display:block; text-align: center;">' . 
                                                      '<strong>' . $SOC_percentage_now . ' %' . '</strong><br>' .
                                                  '</span>';
        $format_object->cron_exit_condition = '<span style="color: Blue; display:block; text-align: center;">' .
                                                    $formatted_interval   . ' ' . $saved_cron_exit_condition  . $soc_update_method .
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