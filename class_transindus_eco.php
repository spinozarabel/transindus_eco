<?php

/**
 * The file that defines the  class with the main functionality
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 * Ver 3.1
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

require_once(__DIR__."/studer_api.php");       
require_once(__DIR__."/class_solar_calculation.php");
require_once(__DIR__."/openweather_api.php");         // contains openweather class
require_once(__DIR__."/class_my_mqtt.php");
require_once(__DIR__."/class_shelly_device_lan.php"); // contains the class to get shelly device data over LAN

// require_once(__DIR__."/shelly_cloud_api.php");       

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
  public $user_meta_defaults_arr;
  public $timezone;
  public $verbose, $lat, $lon, $utc_offset, $cloudiness_forecast;
  public $index_of_logged_in_user, $wp_user_name_logged_in_user, $wp_user_obj;
  public $shelly_switch_acin_details;
  public $studer_time_offset_in_mins_lagging;

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
      // set the logging
      $this->verbose = true;

      // lat and lon at Trans Indus from Google Maps
      $this->lat        = 12.83463;
      $this->lon        = 77.49814;

      // UTC offset for local timezone
      $this->utc_offset = 5.5;

      // Get this user's usermeta into an array and set it as property the class
      // $this->get_all_usermeta( $wp_user_ID );

      // ................................ Cloudiness management ---------------------------------------------->

      $window_open  = $this->nowIsWithinTimeLimits("05:00", "05:15");

      if ( false !== get_transient( 'timestamp_of_last_weather_forecast_acquisition' ) )
      { // transient exists, get it and check its validity

        $ts           = get_transient( 'timestamp_of_last_weather_forecast_acquisition' );
        $invalid_ts   = $this->check_validity_of_timestamp( $ts, 86400 )->elapsed_time_exceeds_duration_given;
      }
      else
      { // cloudinesss acquisition timestamp transient does not exist. 
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
     *  Turn Shelly Plus 1PM GRID AC-IN switch to 'on' or 'off' as desired state passed in
     *  @param int:user_index 0
     *  @param string:desired_state 'on' | 'off' are the choices
     *  @return bool:operation_result true if final state is same as desired false otherwise
     */
    public function turn_on_off_shellyplus1pm_grid_switch_over_lan( int $user_index, string $desired_state ) :  bool
    {
      $config = $this->config;

      // $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      // $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      // $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];

      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

      $shellyplus1pm_grid_switch =  new shelly_device( $ip_static_shelly, 'shellyplus1pm' );

      // We know that the Grid Switch is a ShellyPlus1PM so channel = 0
      $operation_result = $shellyplus1pm_grid_switch->turn_on_off_shelly_x_plus_pm_switch_over_lan( $desired_state, 0 );

      return $operation_result;
    }


    /**
     * "on"  corresponds to the ATS transferring GRID to the load
     * "off" corresponds to the ATS transferring Solar to the load
     */
    public function change_grid_ups_ats_using_shellyem_switch_over_lan( int $user_index, string $desired_state ) :  bool
    {
      $config = $this->config;

      // $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      // $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      // $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin'];

      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_em'];

      $shellyem =  new shelly_device( $ip_static_shelly, 'shellyem' );

      // We know that the ATS contactor primary or Grid Side Switch is controlled by a Shelly EM so channel = 0
      // The ATS secondary contactor is directly tied to the Solar Power
      $operation_result = $shellyem->turn_on_off_shellyem_switch_over_lan( $desired_state, 0 );

      return $operation_result;
    }


    /**
     *  Read the energy counter now
     *  Subtract the reading captured at midnight
     *  Use this as the energy accumulted since midnight delivered to home
     */
    public function get_shellyem_readings_over_lan( int $user_index, string $wp_user_name, int $wp_user_ID ): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;

      // $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      // $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      // $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_em_load'];

      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_em'];

      $shelly_device =  new shelly_device( $ip_static_shelly, 'shellyem' );

      $shellyem_data_obj = $shelly_device->get_shelly_device_data();

      // error_log(print_r($shellyem_data_obj, true));

      // check to make sure that it is online
      if ( $shellyem_data_obj->output_state_string === "OFFLINE" )
      {
        $this->verbose ? error_log( "LogApi: Shelly EM Load Energy API call failed" ): false;
        return null;
      }

      // Shelly EM API call was successfull and we have useful data. Round to 0 and convert to integer to get WattHours
      $shelly_em_home_wh_counter_now = (int) $shellyem_data_obj->emeters[0]->total;

      // get the energy counter value set at midnight. Assumes that this is an integer
      $shelly_em_home_energy_counter_at_midnight = (int) ( get_user_meta( $wp_user_ID, 
                                                                          'shelly_em_home_energy_counter_at_midnight',
                                                                           true) );

      // subtract the 2 integer counter readings to get the accumulated WH since midnight
      $shelly_em_home_wh_since_midnight = $shelly_em_home_wh_counter_now - $shelly_em_home_energy_counter_at_midnight;
        
      $shellyem_data_obj->wh_since_midnight       = $shelly_em_home_wh_since_midnight;
      $shellyem_data_obj->wh_counter_at_midnight  = $shelly_em_home_energy_counter_at_midnight;
      $shellyem_data_obj->wh_counter_now          = $shelly_em_home_wh_counter_now;

      // update the accumulatuon value in user meta for use in next cycle
      update_user_meta( $wp_user_ID, 'shelly_em_home_wh_since_midnight', $shelly_em_home_wh_since_midnight);

      return $shellyem_data_obj;
    }


     /**
     * 'grid_wh_counter_midnight' user meta is set at midnight elsewhere
     *  At any time after, this midnight reading is subtracted from current reading to get consumption since midnight
     *  @param string:$phase defaults to 'c' as the home is supplied by the B phase of RYB
     *  @return object:$shelly_3p_grid_energy_measurement_obj contsining all the measurements
     *  There is a slight confusion now since the a,b,c variable names don't alwyas correspond to the actual R/Y/B phases.
     *  Murty keeps switching the phase to the home and so we pass that in as phase for the main a based variable names
     *  This is so we don't keep changing the code
     */
    public function get_shellypro3em_3p_grid_wh_since_midnight_over_lan(  int     $user_index, 
                                                                          string  $wp_user_name, 
                                                                          int     $wp_user_ID     ): object
    {
      // Red phase of RYB is assigned to 7.2KW car charger inside garage. This corresponds to a phase of abc sequence
      $index_evcharger    = 0;

      // Yellow phase feeds the wall charger outside the garage, with a 15A plug inside a box.
      $index_wallcharger  = 1;

      // Blue phase of RYB is assigned to home so this corresponds to c phase of abc. This goes directly to the Studer AC input
      $index_home         = 2;

      // get value of ShellyPro3EM Home phase watt hour counter as captured at midnight
      $grid_wh_counter_at_midnight = (int) round( (float) get_user_meta( $wp_user_ID, 'grid_wh_counter_at_midnight', true), 0);

      // get API and device ID from config based on user index
      $config = $this->config;

      // $shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
      // $shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
      // $shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_acin_3p'];

      $ip_static_shelly = $config['accounts'][$user_index]['ip_shelly_acin_3em'];

      $shelly_device    =  new shelly_device( $ip_static_shelly, 'shellypro3em' );

      $shellypro3em_3p_grid_obj = $shelly_device->get_shelly_device_data();

      $grid_present_status = (string) $shellypro3em_3p_grid_obj->output_state_string;

      // get the grid status in the previous measurement
      $grid_previous_status = (string) get_transient( 'grid_status' ) ?? $grid_present_status;

      // If the grid status is OFFLINE then use previous data from transients
      if ( $grid_present_status === "OFFLINE" )
      {
        $this->verbose ? error_log("Shellypro3em 3P Grid API call failed - Grid is probably OFFLINE"): false;

        // since no valid reading lets use the reading from transient,
        // since the energy readings don't change from when the grid was last ON
        $home_grid_wh_counter_now_from_transient        = (int) get_transient('home_grid_wh_counter');
        $evcharger_grid_wh_counter_now_from_transient   = (int) get_transient('evcharger_grid_wh_counter');
        $wallcharger_grid_wh_counter_now_from_transient = (int) get_transient('wallcharger_grid_wh_counter');

        $shellypro3em_3p_grid_obj->home_grid_wh_counter_now         = $home_grid_wh_counter_now_from_transient;
        $shellypro3em_3p_grid_obj->evcharger_grid_wh_counter_now    = $evcharger_grid_wh_counter_now_from_transient;

        // Difference between WH counter now to that at midnight got from user meta, set at midnight
        $home_grid_wh_since_midnight = $home_grid_wh_counter_now_from_transient - $grid_wh_counter_at_midnight;


        $shellypro3em_3p_grid_obj->home_grid_wh_since_midnight  = (int)           $home_grid_wh_since_midnight;
        $shellypro3em_3p_grid_obj->home_grid_kwh_since_midnight = (float) round(  $home_grid_wh_since_midnight * 0.001, 3);

        // offline so no power in all 3 phases
        $shellypro3em_3p_grid_obj->home_grid_kw_power         = 0;
        $shellypro3em_3p_grid_obj->evcharger_grid_kw_power    = 0;
        $shellypro3em_3p_grid_obj->wallcharger_grid_kw_power  = 0;
        $shellypro3em_3p_grid_obj->home_grid_voltage          = 0;

        $shellypro3em_3p_grid_obj->red_phase_grid_voltage    = 0;
        $shellypro3em_3p_grid_obj->yellow_phase_grid_voltage = 0;
        $shellypro3em_3p_grid_obj->blue_phase_grid_voltage   = 0;


        if ( $grid_previous_status !==  $grid_present_status )
        {
          // We have a grid status transition - capture the details
          // record the timestamp when this transition happened to keep track of the grid status duration
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $ts_now = $now->getTimestamp();

          set_transient( 'grid_status_change_ts',   $ts_now,                24 * 60 * 60 );
          set_transient( 'grid_status',             $grid_present_status,   24 * 60 * 60 );
        }
        else
        { // grid status continues to be same
          // lets determine the accumulated time in this state
          $grid_status_change_ts       = (int)  get_transient('grid_status_change_ts');
          $seconds_elapsed_grid_status = $this->check_validity_of_timestamp( $grid_status_change_ts, 3600 )->seconds_elapsed;
        }

        $shellypro3em_3p_grid_obj->seconds_elapsed_grid_status = $seconds_elapsed_grid_status;

        return $shellypro3em_3p_grid_obj;
      }
      else
      { // we have a valid reading from SHelly 3EM device

        if ( $grid_previous_status !== $grid_present_status )
        {   // grid status transition from offline to online
          // record the timestamp when this transition happened to keep track of the grid status duration
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $ts_now = $now->getTimestamp();

          set_transient( 'grid_status_change_ts',   $ts_now,                24 * 60 * 60 );
          set_transient( 'grid_status',             $grid_present_status,   24 * 60 * 60 );
        }
        else
        { // grid status continues to be online from last call
          // lets determine the accumulated time in this state
          $grid_status_change_ts          = (int)  get_transient('grid_status_change_ts');
          $seconds_elapsed_grid_status = $this->check_validity_of_timestamp( $grid_status_change_ts, 3600 )->seconds_elapsed;
        }
        $shellypro3em_3p_grid_obj->seconds_elapsed_grid_status = $seconds_elapsed_grid_status;

        // main power to home. The phase is deretermined by passed in string: a/b/c corresponding to R/Y/B
        $home_grid_wh_counter_now  = (int) $shellypro3em_3p_grid_obj->em1data[$index_home]->energy;

        $home_grid_w_power         = (float) $shellypro3em_3p_grid_obj->em1[$index_home]->power;
        $home_grid_kw_power        = (float) $shellypro3em_3p_grid_obj->em1[$index_home]->power_kw;
        $home_grid_voltage         = (int)   $shellypro3em_3p_grid_obj->em1[$index_home]->voltage;

        $red_phase_voltage    = (int)   $shellypro3em_3p_grid_obj->em1[0]->voltage;
        $yellow_phase_voltage = (int)   $shellypro3em_3p_grid_obj->em1[1]->voltage;
        $blue_phase_voltage   = (int)   $shellypro3em_3p_grid_obj->em1[2]->voltage;

        // get energy counter value and power values of phase supplying car charger, assumed b or Y phase
        $evcharger_grid_wh_counter_now  = (int) $shellypro3em_3p_grid_obj->em1data[$index_evcharger]->energy;
        
        $evcharger_grid_w_power         = (float) $shellypro3em_3p_grid_obj->em1[$index_evcharger]->power;
        $evcharger_grid_kw_power        = (float) $shellypro3em_3p_grid_obj->em1[$index_evcharger]->power_kw;
        $evcharger_grid_voltage         = (int)   $shellypro3em_3p_grid_obj->em1[$index_evcharger]->voltage;

        // get energy counter and power values of phase supplying the wall charger outside garage
        $wallcharger_grid_wh_counter_now  = (int) $shellypro3em_3p_grid_obj->em1data[$index_wallcharger]->energy;
        
        $wallcharger_grid_w_power         = (float) $shellypro3em_3p_grid_obj->em1[$index_wallcharger]->power;
        $wallcharger_grid_kw_power        = (float) $shellypro3em_3p_grid_obj->em1[$index_wallcharger]->power_kw;
        $wallcharger_grid_voltage         = (int)   $shellypro3em_3p_grid_obj->em1[$index_wallcharger]->voltage;

        // store 3P grid voltages as transients for access elsewhere in site
        set_transient( 'red_phase_voltage',        $red_phase_voltage,    1 * 60 );
        set_transient( 'yellow_phase_voltage',     $yellow_phase_voltage, 1 * 60 );
        set_transient( 'blue_phase_voltage',       $blue_phase_voltage,   1 * 60 );
        
        // update the transient with most recent measurement
        set_transient( 'home_grid_wh_counter',            $home_grid_wh_counter_now,        24 * 60 * 60 );
        set_transient( 'evcharger_grid_wh_counter',       $evcharger_grid_wh_counter_now,   24 * 60 * 60 );
        set_transient( 'wallcharger_grid_wh_counter_now', $wallcharger_grid_wh_counter_now, 24 * 60 * 60 );

        $home_grid_wh_since_midnight  = $home_grid_wh_counter_now - $grid_wh_counter_at_midnight;
        $home_grid_kwh_since_midnight = round( 0.001 * $home_grid_wh_since_midnight, 3);

        $shellypro3em_3p_grid_obj->home_grid_wh_counter_now         = $home_grid_wh_counter_now;
        $shellypro3em_3p_grid_obj->evcharger_grid_wh_counter_now    = $evcharger_grid_wh_counter_now;

        $shellypro3em_3p_grid_obj->home_grid_kw_power         = $home_grid_kw_power;
        $shellypro3em_3p_grid_obj->evcharger_grid_kw_power    = $evcharger_grid_kw_power;
        $shellypro3em_3p_grid_obj->wallcharger_grid_kw_power  = $wallcharger_grid_kw_power;

        $shellypro3em_3p_grid_obj->home_grid_voltage         = $home_grid_voltage;

        // 3 phase voltage properties for returned object
        $shellypro3em_3p_grid_obj->red_phase_grid_voltage    = $red_phase_voltage;
        $shellypro3em_3p_grid_obj->yellow_phase_grid_voltage = $yellow_phase_voltage;
        $shellypro3em_3p_grid_obj->blue_phase_grid_voltage   = $blue_phase_voltage;

        // present grid status and how long it has been in that state since last changed
        $shellypro3em_3p_grid_obj->grid_present_status         = $grid_present_status;
        $shellypro3em_3p_grid_obj->seconds_elapsed_grid_status = $seconds_elapsed_grid_status;

        $shellypro3em_3p_grid_obj->home_grid_wh_since_midnight   = $home_grid_wh_since_midnight;
        $shellypro3em_3p_grid_obj->home_grid_kwh_since_midnight  = $home_grid_kwh_since_midnight;

        return $shellypro3em_3p_grid_obj;
      }
      
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

          error_log("Cal-SOC Capture after dark Done - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $present_home_wh_reading);

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

            error_log("Cal-SOC Capture after dark took place - SOC: " . $SOC_percentage_now . " % Energy Counter: " . $present_home_wh_reading);

            return true;
          }
        }
      }
      //  Window is closed so event cannot happen return false
      return false;
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

        $user_index = (int) 0;

        $this->get_flag_data_from_master_remote($user_index, $wp_user_ID);

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
            // for ($i=0; $i < 2; $i++) 
            { 
              $this->get_readings_and_servo_grid_switch( 0, $wp_user_ID, $wp_user_name, $do_shelly );
              sleep(11);

              $this->get_readings_and_servo_grid_switch( 0, $wp_user_ID, $wp_user_name, $do_shelly );
              sleep(11);

              $this->get_readings_and_servo_grid_switch( 0, $wp_user_ID, $wp_user_name, $do_shelly );
            }
            
          }
        }
        else
        {
          error_log("Log-WP user ID: $wp_user_ID is not valid");
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
            error_log( "LogSw-No Action in Pump Switch done since no change is desired - Initial State: $pump_initial_switch_state, desired State: $desired_state" );
            return true;
          }
        }
        else
        {
          // we didn't get a valid response but we can continue and try switching
          error_log( "LogSw: we didn't get a valid response for pump switch initial status check but we can continue and try switching" );
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
          error_log( "LogSw: Danger-Failed to switch - Initial State: $pump_initial_switch_state, desired State: $desired_state, Final State: $pump_final_switch_state" );
          return false;
        }
    }
    

    /**
     * 
     */
    public function control_pump_on_duration( int $wp_user_ID, int $user_index, object $shellyplus1pm_water_pump_obj )
    {
      if ( $shellyplus1pm_water_pump_obj->switch[0]->output_state_string === "OFFLINE" )
      {
        // bad data passed in do nothing
        error_log( "Log-Pump bad data passed in do nothing in function control_pump_on_duration" );
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
      $pump_duration_control            = false; // $all_usermeta['pump_duration_control'];

      // pump_power_restart_interval_secs
      $pump_power_restart_interval_secs = $all_usermeta['pump_power_restart_interval_secs'];

      // set property in case pump was off so that this doesnt give a php notice otherwise
      // $shellyplus1pm_water_pump_obj->pump_ON_duration_secs = 0;

      // Has the pump been disabled?
      $power_to_pump_is_enabled = (bool) $shellyplus1pm_water_pump_obj->switch[0]->output_state_bool;

      // Pump power consumption in watts
      $pump_power_watts = (int) round(  $shellyplus1pm_water_pump_obj->switch[0]->power, 0 );

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
        // set_transient( 'tiget_shellyplus1_battery_readings_over_lanmestamp_pump_ON_start',  $timestamp,  12 * 60 * 60 );
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
            $shellyplus1pm_water_pump_obj->pump_ON_duration_secs = $pump_ON_duration_secs;

            $log_string = "Log-Pump ON for: " . $pump_ON_duration_secs . "s Pump Watts: " .  $pump_power_watts;

            $this->verbose ? error_log($log_string) : false;

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

          $this->verbose ? error_log("Log-Pump Just turned ON") : false;

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
          $shellyplus1pm_water_pump_obj->pump_ON_duration_secs = $pump_ON_duration_secs;

          $log_string = "Log-Pump ON for: " . $pump_ON_duration_secs . "s Pump Watts: " .  $pump_power_watts;

            $this->verbose ? error_log($log_string) : false;

          // if pump ON duration is more than 1h then switch the pump power OFF in Shelly 4PM channel 0
          if ( $pump_ON_duration_secs > 3600 && $pump_duration_control )
          {
            // turn shelly power for pump OFF and update transients but only if control site is offline for more than 15m
            $status_turn_pump_off = $this->turn_pump_on_off_over_lan( $user_index, 'off' );


            $pump_notification_count = get_transient( 'pump_notification_count' );

            if ( $status_turn_pump_off )
            {
              // the pump was tuneed off per status
              error_log("Log-Pump turned OFF after duration of: $pump_ON_duration_secs Seconds");

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
                error_log("Danger-Problem - Pump could NOT be turned OFF after duration of: $pump_ON_duration_secs Seconds");
                
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
              error_log("LogSw: Danger-Pump could NOT be turned back ON after duration of: $pump_OFF_duration_secs Seconds after Pump OFF - investigate");
            }
            else
            {
              error_log("LogSw: Danger-Pump turned back ON after duration of: $pump_OFF_duration_secs Seconds after Pump OFF");
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
     *  ver 2.0 added call_ok bool flags to indicate if scuuessful call and value in range
     *  if ($shellyplus1_batt_obj->shellybm_call_ok === true) check to validate this call
     *  $shellyplus1_batt_obj->batt_amps, $shellyplus1_batt_obj->timestamp are the returned properties of interest
     * 
     *  @param int:$user_index index of user in config array
     *  @return object:$shellyplus1_batt_obj contains the measurements of the battery using the Shelly Plus 1 device
     *  
     *  The current is measured using a hall effect sensor. The sensor output voltage is read by the ADC in the shelly Plus Addon
     *  The transducer function is used to translate the ADC voltage to Battery current estimated.
     */
    public function get_shellyplus1_battery_readings_over_lan( int $user_index ) : ? object
    {
        // Make an API call on the Shelly UNI device
        $config = $this->config;

        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_addon'];

        // new instance of shelly device for status and control over LAN
        $shelly_device    =  new shelly_device( $ip_static_shelly, 'shellyplus1-v' );

        // device details and status
        $shellyplus1_batt_obj = $shelly_device->get_shelly_device_data();

        // check to make sure that response exists. If null call was fruitless
        if (  $shellyplus1_batt_obj->switch[0]->output_state_string === "OFFLINE")
        {
          error_log("LogApi: Danger-Shelly Battery Measurement API call over LAN failed");

          $shellyplus1_batt_obj->batt_amps        = null;
          $shellyplus1_batt_obj->timestamp        = null;
          $shellyplus1_batt_obj->shellybm_call_ok = false;

          return $shellyplus1_batt_obj;
        }

        // The measure ADC voltage is in percent of 10V. So a 25% reading indicates 2.5V measured
        $adc_voltage_shelly = (float) $shellyplus1_batt_obj->voltmeter[0]->percent;
        $timestamp          = (int)   $shellyplus1_batt_obj->timestamp;

        // calculate the current using the 65mV/A formula around 2.5V. Positive current is battery discharge
        $delta_voltage = $adc_voltage_shelly * 0.1 - 2.54;

        // 100 Amps gives a voltage of 0.625V amplified by opamp by 4.7. So voltas/amp measured by Shelly Addon is
        $volts_per_amp = 0.625 * 4.7 / 100;

        // Convert Volts to Amps using the value above. SUbtract an offest of 8A noticed, probably due to DC offset
        $battery_amps_raw_measurement = ($delta_voltage / $volts_per_amp);

        // +ve value indicates battery is charging. Due to our inverting opamp we have to reverse sign and educe the current by 5%
        // This is because the battery SOC numbers tend about 4 points more from about a value of 40% which indicates about 10% over measurement
        // so to be conservative we are using a 10% reduction to see if this corrects the tendency.
        $batt_amps = -1.0 * round( $battery_amps_raw_measurement, 1) * 0.87;

        $shellyplus1_batt_obj->batt_amps      = $batt_amps;
        $shellyplus1_batt_obj->timestamp      = $timestamp;

        if (        $timestamp   > 1577817000 &&      // timestamp corresponds to after 2020
              abs(  $batt_amps ) >= 0         &&      // battery current is between 0 and 100A
              abs(  $batt_amps ) < 100 )
        {
          // timestamp is after 2020 and current is between expected min and max levels
          $shellyplus1_batt_obj->shellybm_call_ok = true;
        }

        // add the API object as property to control included switch directly in the main routine
        $shellyplus1_batt_obj->shelly_device  = $shelly_device;

        return $shellyplus1_batt_obj;
    }


    /**
     *  ver 2.0
     *  @param int:$user_index of user in config array
     *  @return object:$shellypro4pm_load_obj contains energy counter and its timestamp along with switch status object
     *  Gets the power readings supplied to Home using Shelly Pro 4PM
     */
    public function get_shellypro4pm_readings_over_lan(int $user_index): object
    {
        // get API and device ID from config based on user index
        $config = $this->config;

        $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_load_4pm'];

        $shelly_device    =  new shelly_device( $ip_static_shelly, 'shellypro4pm' );

        $shellypro4pm_load_obj = $shelly_device->get_shelly_device_data();

        // check to make sure that it exists. If null API call was fruitless
        if ( $shellypro4pm_load_obj->switch[0]->output_state_string === "OFFLINE" )
        {
          error_log("LogApi: ShellyPro4PM LOAD switch API call failed");
          // no further processing. Object contains properties with no data except OFFLINE status as in above
        }
        else
        {
          $shellypro4pm_load_obj->shelly_device = $shelly_device;
        }

        return $shellypro4pm_load_obj;
    }

    

     /**
     *  ver 2.0 Battery delta AH and accumulation since midnight for xcom-lan and shelly-bm methods
     *   returned bool flags shelly_bm_ok_bool, shelly_xcomlan_ok_bool indicate if delta soc < 5 points.
     * @param int:$user_index of user in config array
     * @param int:$wp_user_ID is the WP user ID
     * @param string:$shelly_switch_status 'ON" value is checked for to set SOC as unchanged since Grid is ON when dark
     * @param float:$home_grid_kw_power check is made for positive finite grid power to determine that Grid is ON
     * @param bool:$it_is_still_dark this is checked to make sure that at dark when Grid is ON SOC is unchanged
     * @param null|float:$batt_amps_shelly_now is the immediate shelly measured value of battery current
     * @param null|int:$ts_shellybm_now is the timestamp of the immediate shelly measurement
     * @param null|float:$batt_amps_xcomlan_now is the immediate value of the xcom-lan measured battery current
     * @param null|int:$ts_xcomlan_now is the timestamp of the xcom-lan battery current measurement
     * @param null|bool:$studer_charger_enabled
     * @param null|float:$studer_battery_charging_current
     * @param null|bool:$xcomlan_call_ok indicates success of xcom-lan call for immediate measurement of battery current
     * @param null|bool:$shellybm_call_ok indicates success of shelly-bm call for immediate measurement of battery current
     * @return object:$battery_soc_since_midnight_obj contains the returned object with accumulated AH data since midnight
     * 
     * relevant properties returned are: 
     * shelly_bm_ok_bool, shelly_xcomlan_ok_bool, delta_secs_shellybm, delta_secs_xcomlan
     * soc_shellybm_since_midnight, soc_xcomlan_since_midnight
     * 
     */
    public function get_battery_delta_soc_for_both_methods( int     $user_index, 
                                                            int     $wp_user_ID, 
                                                            string  $shelly_switch_status,
                                                            float   $home_grid_kw_power,
                                                            bool    $it_is_still_dark,
                                                            ? float $batt_amps_shelly_now,
                                                            ? int   $ts_shellybm_now,
                                                            ? float $batt_amps_xcomlan_now,
                                                            ? int   $ts_xcomlan_now,
                                                            ? bool  $studer_charger_enabled,
                                                            ? float $studer_battery_charging_current,
                                                            ? bool  $xcomlan_call_ok,
                                                            ? bool  $shellybm_call_ok
                                                          ) : object
    {
      $config = $this->config;

      // intialize a new stdclass object to be returned by this function
      $battery_soc_since_midnight_obj = new stdClass;

      // initialize flags to false
      $battery_soc_since_midnight_obj->shelly_bm_ok_bool      = false;
      $battery_soc_since_midnight_obj->shelly_xcomlan_ok_bool = false;

      // Total Installed BAttery capacity in AH, in my case it is 3 x 100 AH or 300 AH
      $battery_capacity_ah = (float) $config['accounts'][$user_index]['battery_capacity_ah']; // 300AH in our case

      // get SOC percentage accumulated till last measurement for both shelly_bm and xcomlan methods
      $soc_shellybm_since_midnight  = (float) get_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight',         true);
      $soc_xcomlan_since_midnight   = (float) get_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', true);

      switch (true) 
      {
        case ( $xcomlan_call_ok && $shellybm_call_ok ):  // both measurement cases have valid data

          // get shellybm previous cycle values from transient. Reset to present values if non-existent
          $previous_ts_shellybm         = (int)   get_transient(  'timestamp_battery_last_measurement' )  ?? $ts_shellybm_now;
          $previous_batt_amps_shellybm  = (float) get_transient(  'amps_battery_last_measurement' )       ?? $batt_amps_shelly_now;

           // get previous xcomlan measurements. Reset to present values if non-existent
          $previous_ts_xcomlan =          (int)   get_transient( 'timestamp_xcomlan_battery_last_measurement' ) ?? $ts_xcomlan_now;
          $previous_batt_amps_xcomlan =   (float) get_transient( 'previous_batt_current_xcomlan' )              ?? $batt_amps_xcomlan_now;

          // set present values of shellybm for next cycle
          set_transient( 'timestamp_battery_last_measurement',  $ts_shellybm_now,       5 * 60 * 60 );
          set_transient( 'amps_battery_last_measurement',       $batt_amps_shelly_now,  5 * 60 * 60 );

          // set present values of xcomlan for next cycle
          set_transient( 'timestamp_xcomlan_battery_last_measurement',  $ts_xcomlan_now,        5 * 60 * 60 );
          set_transient( 'previous_batt_current_xcomlan',               $batt_amps_xcomlan_now, 5 * 60 * 60 );

          // duration in seconds between timestamps for shelly_bm method
          $delta_secs_shellybm  = (int)   ( $ts_shellybm_now - $previous_ts_shellybm );
          $delta_hours_shellybm = (float) ( $delta_secs_shellybm / 3600 );

          // duration in secs between xcom-lan measurements
          $delta_secs_xcomlan   = (int)   ( $ts_xcomlan_now - $previous_ts_xcomlan );
          $delta_hours_xcomlan  = (float) ($delta_secs_xcomlan / 3600);

          $battery_soc_since_midnight_obj->delta_secs_shellybm  = $delta_secs_shellybm;
          $battery_soc_since_midnight_obj->delta_secs_xcomlan   = $delta_secs_xcomlan;

        break;

        // xcomlan method has failed this cycle so use shellybm delta values for both
        case (  ! $xcomlan_call_ok  && $shellybm_call_ok ): 
          
          error_log("XCOMLAN call failed, using shelly BM data for xcom-lan accumulation also");

          $previous_ts_shellybm         = (int)   get_transient(  'timestamp_battery_last_measurement' )  ?? $ts_shellybm_now;
          $previous_batt_amps_shellybm  = (float) get_transient(  'amps_battery_last_measurement' )       ?? $batt_amps_shelly_now;

          // duration in seconds between timestamps for shelly_bm method
          $delta_secs_shellybm  = (int)   ($ts_shellybm_now - $previous_ts_shellybm);
          $delta_hours_shellybm = (float) ($delta_secs_shellybm / 3600);

          // set present values of shellybm for next cycle
          set_transient( 'timestamp_battery_last_measurement',  $ts_shellybm_now,       5 * 60 * 60 );
          set_transient( 'amps_battery_last_measurement',       $batt_amps_shelly_now,  5 * 60 * 60 );

          // use values of shellybm for scomlan since xcomlan set is empty
          set_transient( 'timestamp_xcomlan_battery_last_measurement',  $ts_shellybm_now,      5 * 60 * 60 );
          set_transient( 'previous_batt_current_xcomlan',               $batt_amps_shelly_now, 5 * 60 * 60 );

          $battery_soc_since_midnight_obj->delta_secs_shellybm  = $delta_secs_shellybm;
          $battery_soc_since_midnight_obj->delta_secs_xcomlan   = null;

        break;

        case (  $xcomlan_call_ok  && ! $shellybm_call_ok ):    
          
          error_log("Shelly BM API call has failed so only using XCOM-LAN for both accumulations");

          // get previous xcomlan measurements
          $previous_ts_xcomlan =          (int)   get_transient( 'timestamp_xcomlan_battery_last_measurement' ) ?? $ts_xcomlan_now;
          $previous_batt_amps_xcomlan =   (float) get_transient( 'previous_batt_current_xcomlan' )              ?? $batt_amps_xcomlan_now;

          // duration in secs between measurements
          $delta_secs_xcomlan   = (int)   ($ts_xcomlan_now - $previous_ts_xcomlan);
          $delta_hours_xcomlan  = (float) ($delta_secs_xcomlan / 3600);

          set_transient( 'timestamp_battery_last_measurement',  $ts_xcomlan_now,        5 * 60 * 60 );
          set_transient( 'amps_battery_last_measurement',       $batt_amps_xcomlan_now, 5 * 60 * 60 );

          // use values of xcomlan since shellybm is absent
          set_transient( 'timestamp_xcomlan_battery_last_measurement',  $ts_xcomlan_now,        5 * 60 * 60 );
          set_transient( 'previous_batt_current_xcomlan',               $batt_amps_xcomlan_now, 5 * 60 * 60 );

          $battery_soc_since_midnight_obj->delta_secs_shellybm  = null;
          $battery_soc_since_midnight_obj->delta_secs_xcomlan   = $delta_secs_xcomlan;

        break;
        
        default:
        
          error_log("Both Shelly BM and XCOM-LAN methods have failed. No accumulation this cycle");

          // the flags indicate which methods were used for SOC accumulation. 
          $battery_soc_since_midnight_obj->shelly_bm_ok_bool      = false;
          $battery_soc_since_midnight_obj->shelly_xcomlan_ok_bool = false;

          $battery_soc_since_midnight_obj->delta_ah_shellybm = 0;
          $battery_soc_since_midnight_obj->delta_soc_shellybm = 0;
          
          // return value read from usermeta, unchanged
          $battery_soc_since_midnight_obj->soc_shellybm_since_midnight = $soc_shellybm_since_midnight;

          $battery_soc_since_midnight_obj->delta_ah_xcomlan = 0;
          $battery_soc_since_midnight_obj->delta_soc_xcomlan = 0;

          // return value read from usermetaunchanged this cycle
          $battery_soc_since_midnight_obj->soc_xcomlan_since_midnight = $soc_xcomlan_since_midnight;

          // set the default battery current
          $battery_soc_since_midnight_obj->batt_amps = 0;

          $battery_soc_since_midnight_obj->delta_secs_shellybm  = null;
          $battery_soc_since_midnight_obj->delta_secs_xcomlan   = null;
          
          return $battery_soc_since_midnight_obj;

        break;
      }

      // Determine if battery current is zero because it is dark and Grid is ON supplying the load and Grid charging of Battery is OFF
      if (  $it_is_still_dark                     &&        // No solar
            $shelly_switch_status === 'ON'        &&        // Grid switch is ON
            $home_grid_kw_power > 0.05            &&        // power is being drawn from Grid  
            $studer_charger_enabled === false               // Studer Battery Charger id NOT enabled
          )   
      { // battery is not charging or discharging so delta soc is 0

        $this->verbose ? error_log(" Battery current is 0: No SOC update done"): false;

        // since delta soc = 0 the flags are irrelevant so set to true
        $battery_soc_since_midnight_obj->shelly_bm_ok_bool      = true;
        $battery_soc_since_midnight_obj->shelly_xcomlan_ok_bool = true;

        // delta SOC accumulations are 0 since on Grid and no charging or discharging of the battery
        $battery_soc_since_midnight_obj->delta_ah_shellybm = 0;
        $battery_soc_since_midnight_obj->delta_soc_shellybm = 0;

        // return value read from usermeta, unchanged
        $battery_soc_since_midnight_obj->soc_shellybm_since_midnight = $soc_shellybm_since_midnight;

        // delta SOC accumulations are 0 since on Grid and no charging or discharging of the battery
        $battery_soc_since_midnight_obj->delta_ah_xcomlan = 0;
        $battery_soc_since_midnight_obj->delta_soc_xcomlan = 0;

        // return value read from usermetaunchanged this cycle
        $battery_soc_since_midnight_obj->soc_xcomlan_since_midnight = $soc_xcomlan_since_midnight;

        // battery current is hard set to 0
        $battery_soc_since_midnight_obj->batt_amps = 0;
        
        return $battery_soc_since_midnight_obj;
      }
      else
      { 
        // battery is charging or discharging
        if ( $xcomlan_call_ok && $shellybm_call_ok )
        { 
          // both methods are valid so update using both

          // shelly-bm method update -----------------------------------------------
          $delta_ah_shellybm = 0.5 * ( $previous_batt_amps_shellybm + $batt_amps_shelly_now ) * $delta_hours_shellybm;

          // delta charge in %SOC added this cycle algebraically
          $delta_soc_shellybm = $delta_ah_shellybm / $battery_capacity_ah * 100;    // delta soc% from delta AH

          // Total accumulated charge added algebraically since nidnight in %SOC. Note the += accumulation operation
          $soc_shellybm_since_midnight += $delta_soc_shellybm;                      // accumulate delta soc shellyBM

          $battery_soc_since_midnight_obj->delta_ah_shellybm            = $delta_ah_shellybm;
          $battery_soc_since_midnight_obj->delta_soc_shellybm           = $delta_soc_shellybm;
          $battery_soc_since_midnight_obj->soc_shellybm_since_midnight  = $soc_shellybm_since_midnight;

          // update the usermeta only delta %SOC added is smaller than what is expected in about 5m gap
          // So for a discharge of 3KW over 5m is equal to 5%
          if ( abs( $delta_soc_shellybm ) <= 5.0 )
          {
            update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', $soc_shellybm_since_midnight );

            $battery_soc_since_midnight_obj->shelly_bm_ok_bool      = true;
          }
          else
          {
            error_log(" Delta SOC% from Shelly BM was unacceptable at: $delta_soc_shellybm, so no update");
          }

          // xcom-lan method update ---------------------------------------------------
          $delta_ah_xcomlan   = 0.5 * ( $previous_batt_amps_xcomlan + $batt_amps_xcomlan_now ) * $delta_hours_xcomlan;
          $delta_soc_xcomlan  = $delta_ah_xcomlan / $battery_capacity_ah * 100;

          $soc_xcomlan_since_midnight += $delta_soc_xcomlan;    // accumulate

          $battery_soc_since_midnight_obj->delta_ah_xcomlan           = $delta_ah_xcomlan;
          $battery_soc_since_midnight_obj->delta_soc_xcomlan          = $delta_soc_xcomlan;
          $battery_soc_since_midnight_obj->soc_xcomlan_since_midnight = $soc_xcomlan_since_midnight;

          // set the default battery current as xcomlan method
          $battery_soc_since_midnight_obj->batt_amps = $batt_amps_xcomlan_now;

          /// update the usermeta only delta %SOC added is smaller than what is expected in about 5m gap
          // So for a discharge of 3KW over 5m is equal to 5%
          if ( abs( $delta_soc_xcomlan ) <= 5.0 )
          {
            update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', $soc_xcomlan_since_midnight );

            $battery_soc_since_midnight_obj->shelly_xcomlan_ok_bool = true;
          }
          else
          {
            error_log(" Delta SOC% from xcom-lan was unacceptable at: $delta_soc_xcomlan, so no update");
          }
            
          return $battery_soc_since_midnight_obj;
        }

        if ( ! $xcomlan_call_ok && $shellybm_call_ok )
        { 
          // shellybm method is used for both updates. xcomlan call was not OK.
          $delta_ah_shellybm  = 0.5 * ( $previous_batt_amps_shellybm + $batt_amps_shelly_now ) * $delta_hours_shellybm;
          $delta_soc_shellybm = $delta_ah_shellybm / $battery_capacity_ah * 100;

          $soc_shellybm_since_midnight += $delta_soc_shellybm;          // accumulate

          $battery_soc_since_midnight_obj->delta_ah_shellybm            = $delta_ah_shellybm;
          $battery_soc_since_midnight_obj->delta_soc_shellybm           = $delta_soc_shellybm;
          $battery_soc_since_midnight_obj->soc_shellybm_since_midnight  = $soc_shellybm_since_midnight;

          // set the default battery current
          $battery_soc_since_midnight_obj->batt_amps = $batt_amps_shelly_now;

          $soc_xcomlan_since_midnight += $delta_soc_shellybm;         // accumulate but using shellybm updates

          $battery_soc_since_midnight_obj->delta_ah_xcomlan           = $delta_ah_shellybm;
          $battery_soc_since_midnight_obj->delta_soc_xcomlan          = $delta_soc_shellybm;
          $battery_soc_since_midnight_obj->soc_xcomlan_since_midnight = $soc_xcomlan_since_midnight;
          
          // update the usermeta
          if ( abs( $delta_soc_shellybm ) <= 5.0 )
          {
            update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight',         $soc_shellybm_since_midnight );
            update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', $soc_xcomlan_since_midnight );

            $battery_soc_since_midnight_obj->shelly_bm_ok_bool      = true;
            $battery_soc_since_midnight_obj->shelly_xcomlan_ok_bool = true;
          }
          else
          {
            error_log(" Delta SOC% from Shelly BM was unacceptable at: $delta_soc_shellybm, so no update");
          }
          
          return $battery_soc_since_midnight_obj;
        }

        if ( $xcomlan_call_ok  && ! $shellybm_call_ok )
        { 
          // only xcomlan method is valid and is as update for both methods
          $delta_ah_xcomlan   = 0.5 * ( $previous_batt_amps_xcomlan + $batt_amps_xcomlan_now ) * $delta_hours_xcomlan;
          $delta_soc_xcomlan  = $delta_ah_xcomlan / $battery_capacity_ah * 100;

          $soc_xcomlan_since_midnight += $delta_soc_xcomlan;

          $battery_soc_since_midnight_obj->delta_ah_xcomlan           = $delta_ah_xcomlan;
          $battery_soc_since_midnight_obj->delta_soc_xcomlan          = $delta_soc_xcomlan;
          $battery_soc_since_midnight_obj->soc_xcomlan_since_midnight = $soc_xcomlan_since_midnight;

          // set the default battery current
          $battery_soc_since_midnight_obj->batt_amps = $batt_amps_xcomlan_now;

          // we use the delta values from xcomlan but accumulate to original shellybm values
          $soc_shellybm_since_midnight += $delta_soc_xcomlan;      // accumulate but using xcom-lan values

          $battery_soc_since_midnight_obj->delta_ah_shellybm            = $delta_ah_xcomlan;
          $battery_soc_since_midnight_obj->delta_soc_shellybm           = $delta_soc_xcomlan;         
          $battery_soc_since_midnight_obj->soc_shellybm_since_midnight  = $soc_shellybm_since_midnight;

          // update the usermeta
          if ( abs( $delta_soc_xcomlan ) <= 5.0 )
          {
            update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight',         $soc_shellybm_since_midnight );
            update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', $soc_xcomlan_since_midnight );

            $battery_soc_since_midnight_obj->shelly_bm_ok_bool      = true;
            $battery_soc_since_midnight_obj->shelly_xcomlan_ok_bool = true;
          }
          else
          {
            error_log(" Delta SOC% from xcom-lan was unacceptable at: $delta_soc_xcomlan, so no updates");
          }

          return $battery_soc_since_midnight_obj;
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
    public function get_readings_and_servo_grid_switch( int     $user_index, 
                                                        int     $wp_user_ID, 
                                                        string  $wp_user_name, 
                                                        bool    $do_shelly      ) : void
    {
        // This is the main object that we deal with for storing and processing data gathered from our IOT devices
        $shelly_readings_obj = new stdClass;

        { // Define boolean control variables required time intervals

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

          $it_is_still_light = $this->nowIsWithinTimeLimits( $sunrise_hms_format, $sunset_hms_format );

          $shelly_readings_obj->time_window1_open_for_soc_capture_after_dark = $time_window_open_for_soc_capture_after_dark_using_studer;
          $shelly_readings_obj->time_window2_open_for_soc_capture_after_dark = $time_window_open_for_soc_capture_after_dark_using_shelly;
          $shelly_readings_obj->it_is_still_dark = $it_is_still_dark;
        }

        { // Get user meta for limits and controls. These should not change inside of the for loop in cron exec
          $all_usermeta                           = $this->get_all_usermeta( $wp_user_ID );
          // SOC percentage needed to trigger LVDS
          $soc_percentage_lvds_setting            = (float) $all_usermeta['soc_percentage_lvds_setting']  ?? 50;

          // Avg Battery Voltage lower threshold for LVDS triggers
          $average_battery_voltage_lvds_setting   = (float) $all_usermeta['average_battery_voltage_lvds_setting']  ?? 48.5;

          // Switch releases if SOC is above this level 
          $soc_percentage_switch_release_setting  = (float) $all_usermeta['soc_percentage_switch_release_setting']  ?? 95.0; 

          // battery float voltage setting. Only used for SOC clamp for 100%
          $average_battery_float_voltage          = (float) $all_usermeta['average_battery_float_voltage'] ?? 51.8;

          // Min VOltage at ACIN for RDBC to switch to GRID
          $acin_min_voltage                       = (float) $all_usermeta['acin_min_voltage'] ?? 199;  

          // Max voltage at ACIN for RDBC to switch to GRID
          $acin_max_voltage                       = (float) $all_usermeta['acin_max_voltage'] ?? 247; 

          // Minimum Psolar before RDBC can be actiated
          $psolar_kw_min                          = (float) $all_usermeta['psolar_kw_min'] ?? 0.3;  

          // get operation flags from user meta. Set it to false if not set
          $keep_shelly_switch_closed_always       = (bool) $all_usermeta['keep_shelly_switch_closed_always'] ?? false;

          // get the installed battery capacity in KWH from config
          $battery_capacity_kwh                   = (float) $this->config['accounts'][$user_index]['battery_capacity'];

          // Battery capacity for 100% SOC in AH
          $battery_capacity_ah                    = (float) $this->config['accounts'][$user_index]['battery_capacity_ah'];

          $shelly_readings_obj->soc_percentage_lvds_setting           = $soc_percentage_lvds_setting;
          $shelly_readings_obj->average_battery_voltage_lvds_setting  = $average_battery_voltage_lvds_setting;

          $studer_charger_enabled           = (bool)  $all_usermeta['studer_charger_enabled']           ?? false;
          $studer_battery_charging_current  = (float) $all_usermeta['studer_battery_charging_current']  ?? 0; 

          $track_ats_switch_to_grid_switch  = (bool)  $all_usermeta['track_ats_switch_to_grid_switch']  ?? false;
        }

        { // get the SOCs from the user meta.

          // Get the SOC percentage at beginning of Dayfrom the user meta. This gets updated only just past midnight once
          // This is used as base for all SOC calculations
          $soc_percentage_at_midnight = (float) get_user_meta($wp_user_ID, "soc_percentage_at_midnight",  true);

          // SOC percentage after dark. This gets captured at dark and gets updated every cycle
          $soc_percentage_after_dark  = (float) get_user_meta( $wp_user_ID, 'soc_percentage_after_dark',  true);
        }

        { // --------------------- Shelly1PM ACIN SWITCH data after making a Shelly API call -------------------
          $shellyplus1pm_grid_switch_obj          = $this->get_shellyplus1pm_grid_switch_data_over_lan( $user_index );
          $shelly_readings_obj->shellyplus1pm_grid_switch_obj = $shellyplus1pm_grid_switch_obj;

          $shellyplus1pm_grid_switch_state_string = $shellyplus1pm_grid_switch_obj->switch[0]->output_state_string;
          // $this->verbose ? error_log("Shelly Grid Switch State: $shellyplus1pm_grid_switch_state_string"): false;
        }

        {  // .................... make all measurements .......................................................
          { // ..................... ShellyPro3EM power, voltage, and energy measuremnts of 3phase Grid at Bus Bars ...
            $shellypro3em_3p_grid_obj = $this->get_shellypro3em_3p_grid_wh_since_midnight_over_lan( $user_index, $wp_user_name, $wp_user_ID );

            $shelly_readings_obj->shellypro3em_3p_grid_obj = $shellypro3em_3p_grid_obj;

            // error_log("Log-home_grid_wh_counter_now: $shellypro3em_3p_grid_obj->home_grid_wh_counter_now, wh since midnight: $shellypro3em_3p_grid_obj->home_grid_wh_since_midnight, Home Grid PowerKW: $shellypro3em_3p_grid_obj->home_grid_kw_power"): false;

            $home_grid_wh_counter_now               = $shellypro3em_3p_grid_obj->home_grid_wh_counter_now;
            $evcharger_grid_wh_counter_now          = $shellypro3em_3p_grid_obj->evcharger_grid_wh_counter_now;

            $home_grid_wh_since_midnight              = $shellypro3em_3p_grid_obj->home_grid_wh_since_midnight;
            $home_grid_kwh_since_midnight             = $shellypro3em_3p_grid_obj->home_grid_kwh_since_midnight;
            $home_grid_kw_power                       = $shellypro3em_3p_grid_obj->home_grid_kw_power;
            $evcharger_grid_kw_power                  = $shellypro3em_3p_grid_obj->evcharger_grid_kw_power;
            $wallcharger_grid_kw_power                = $shellypro3em_3p_grid_obj->wallcharger_grid_kw_power;
            $seconds_elapsed_grid_status              = $shellypro3em_3p_grid_obj->seconds_elapsed_grid_status;
            $home_grid_voltage                        = $shellypro3em_3p_grid_obj->home_grid_voltage ?? 0;
          }
          
          { // ..................... shellyplus1 w/addon Battery current measurement using Hall Effect sensor
            // Measure Battery current. Postitive is charging. Returns battery current and associated timestamp

            $shellyplus1_batt_obj = $this->get_shellyplus1_battery_readings_over_lan(  $user_index );

            // add the object as property to the main readings object
            $shelly_readings_obj->shellyplus1_batt_obj        = $shellyplus1_batt_obj;

            $batt_amps_shellybm   = (float) $shellyplus1_batt_obj->batt_amps;
            $timestamp_shellybm   = (int)   $shellyplus1_batt_obj->timestamp;

            $shelly_readings_obj->battery_capacity_ah       = $battery_capacity_ah; // this is obtianed from config
            $shelly_readings_obj->batt_amps_shellybm        = $batt_amps_shellybm;  
            $shelly_readings_obj->timestamp_shellybm        = $timestamp_shellybm;
          }

          { // ..................... ShellyPro4PM Home AC Measurement and Control ..................--------
            $shellypro4pm_load_obj = $this->get_shellypro4pm_readings_over_lan( $user_index );

            // add the object as property to the main readings object
            $shelly_readings_obj->shellypro4pm_load_obj        = $shellypro4pm_load_obj; 
          }

          { // ..................... water pump data acquisition -------------------------------------------
            $shellyplus1pm_water_pump_obj = $this->get_shellyplus1pm_water_pump_data_over_lan( $user_index );

            $shellyplus1pm_water_pump_obj->pump_ON_duration_secs = 0;

            // If pump is NOT OFFLINE then check pump control duration
            if ( $shellyplus1pm_water_pump_obj->switch[0]->output_state_string !== "OFFLINE" )
            {
              // Control Pump ON max duration if enabled
              $this->control_pump_on_duration( $wp_user_ID, $user_index, $shellyplus1pm_water_pump_obj);
            }

            $shelly_readings_obj->shellyplus1pm_water_pump_obj = $shellyplus1pm_water_pump_obj;
          }

          { // ..................... water heater data acquisition -------------------------------------------------
            $shellyplus1pm_water_heater_obj = $this->get_shellyplus1pm_water_heater_data_over_lan( $user_index );
            $shelly_readings_obj->shellyplus1pm_water_heater_obj = $shellyplus1pm_water_heater_obj;
          }

          { // ..................... Shelly EM device Home Energy, Power, and Voltage Measurements -----------------
            $shellyem_readings_obj = $this->get_shellyem_readings_over_lan( $user_index, $wp_user_name, $wp_user_ID );

            if ( $shellyem_readings_obj )   
            { // if API had failed a null would have been returned
              $shelly_readings_obj->shellyem_readings_obj = $shellyem_readings_obj;

              $shelly_em_home_kwh_since_midnight = round( $shellyem_readings_obj->wh_since_midnight * 0.001, 3 );
              $shelly_em_home_kw = (float) $shellyem_readings_obj->emeters[0]->power_kw;

              // $this->verbose ? error_log("Shelly EM Power to Home KW:  $shelly_em_home_kw"): false;
            }
          }

          { // ..................... Studer data using xcom-lan python script ......................................
            $xcomlan_studer_data_obj = $this->get_studer_readings_over_xcomlan_without_mqtt();

            $batt_voltage_xcomlan_avg     = $xcomlan_studer_data_obj->batt_voltage_xcomlan_avg;
            $raw_batt_voltage_xcomlan     = $xcomlan_studer_data_obj->raw_batt_voltage_xcomlan;
            $east_panel_current_xcomlan   = $xcomlan_studer_data_obj->east_panel_current_xcomlan;
            $west_panel_current_xcomlan   = $xcomlan_studer_data_obj->west_panel_current_xcomlan;
            $pv_current_now_total_xcomlan = $xcomlan_studer_data_obj->pv_current_now_total_xcomlan;
            $inverter_current_xcomlan     = $xcomlan_studer_data_obj->inverter_current_xcomlan;
            $psolar_kw                    = $xcomlan_studer_data_obj->psolar_kw;
            $batt_current_xcomlan         = $xcomlan_studer_data_obj->batt_current_xcomlan;
            $xcomlan_ts                   = $xcomlan_studer_data_obj->xcomlan_ts;

            // this is the not averaged but IR compensated latest reading of Battery Voltage
            $ir_drop_compensated_battery_voltage_xcomlan = $xcomlan_studer_data_obj->ir_drop_compensated_battery_voltage_xcomlan;

            // write this as property to the main readings object
            $shelly_readings_obj->xcomlan_studer_data_obj = $xcomlan_studer_data_obj;

            $shelly_readings_obj->psolar_kw = $psolar_kw;
          }
        }

        { // ..................... calculate the SOC for all methods using the measurement data ................"

          // call the routine to accumulate the battery charge this cycle based on current measurements this cycle
          $batt_soc_accumulation_obj = $this->get_battery_delta_soc_for_both_methods
                                              (  
                                                  $user_index, 
                                                  $wp_user_ID, 
                                                  $shellyplus1pm_grid_switch_state_string, 
                                                  $shellypro3em_3p_grid_obj->home_grid_kw_power,
                                                  $it_is_still_dark,
                                                  $batt_amps_shellybm,
                                                  $timestamp_shellybm,
                                                  $batt_current_xcomlan,
                                                  $xcomlan_ts,
                                                  $studer_charger_enabled,
                                                  $studer_battery_charging_current,
                                                  $xcomlan_studer_data_obj->xcomlan_call_ok,
                                                  $shellyplus1_batt_obj->shellybm_call_ok,
                                                );

          $soc_shellybm_since_midnight  = $batt_soc_accumulation_obj->soc_shellybm_since_midnight;
          $soc_xcomlan_since_midnight   = $batt_soc_accumulation_obj->soc_xcomlan_since_midnight;

          $soc_percentage_now_calculated_using_shelly_bm      = $soc_percentage_at_midnight + $soc_shellybm_since_midnight;
          $soc_percentage_now_calculated_using_studer_xcomlan = $soc_percentage_at_midnight + $soc_xcomlan_since_midnight;

          $batt_amps  = $batt_soc_accumulation_obj->batt_amps;
          
          // lets update the user meta for updated SOC for shelly bm this is not really used anymore
          update_user_meta( $wp_user_ID, 'soc_percentage_now_calculated_using_shelly_bm', $soc_percentage_now_calculated_using_shelly_bm);

          // $surplus power means any power available for battery charging. Wrong terminology!!!
          $surplus = round( $batt_amps * 49.8 * 0.001, 1 ); // in KW

          // update readings object with SOC's
          $shelly_readings_obj->surplus   = $surplus;
          $shelly_readings_obj->batt_amps = $batt_amps;
          $shelly_readings_obj->soc_percentage_now_calculated_using_shelly_bm       = $soc_percentage_now_calculated_using_shelly_bm;
          $shelly_readings_obj->soc_percentage_now_calculated_using_studer_xcomlan  = $soc_percentage_now_calculated_using_studer_xcomlan;
          
          // calculate battery power in KW                                      
          if ( $batt_voltage_xcomlan_avg > 47 )
          {
            // if xcomlan measurements get a valid battery voltage use it for best accuracy
            $shelly_readings_obj->battery_power_kw = round( $batt_voltage_xcomlan_avg * $batt_amps * 0.001, 3 );
          }
          else
          {
            // if not use an average battery voltage of 49.8V over its cycle of 48.5 - 51.4 V
            $shelly_readings_obj->battery_power_kw = round( 49.8 * $batt_amps * 0.001, 3 );
          }

          { // calculate the SOC from the Studer readings of day energy balance over xcom-lan just as a backup
            $inverter_kwh_today = $xcomlan_studer_data_obj->inverter_kwh_today;
            $solar_kwh_today    = $xcomlan_studer_data_obj->solar_kwh_today;
            $grid_kwh_today     = $xcomlan_studer_data_obj->grid_kwh_today;

            // Net battery charge in KWH (discharge if minus) as measured by STUDER
            $kwh_batt_charge_net_today_studer_kwh  = $solar_kwh_today * 0.96 + (0.96 * $grid_kwh_today - $inverter_kwh_today) * 1.07;
    
            // Calculate percentage of installed battery capacity accumulated as measured by studer KWH method
            $soc_batt_charge_net_percent_today_studer_kwh = $kwh_batt_charge_net_today_studer_kwh / $battery_capacity_kwh * 100;

            // SOC% using STUDER Measurements
            $soc_percentage_now_studer_kwh = round( $soc_percentage_at_midnight + $soc_batt_charge_net_percent_today_studer_kwh, 1);
            $shelly_readings_obj->soc_percentage_now_studer_kwh = $soc_percentage_now_studer_kwh;
            $shelly_readings_obj->solar_kwh_today     = $solar_kwh_today;
            $shelly_readings_obj->inverter_kwh_today  = $inverter_kwh_today;
            $shelly_readings_obj->grid_kwh_today      = $grid_kwh_today;
            $shelly_readings_obj->soc_batt_charge_net_percent_today_studer_kwh = $soc_batt_charge_net_percent_today_studer_kwh;
          }
        }

        { // select most likely SOC value from the 3 methods available
          // 1. Do a basic check to see if the new update is present and broadly within limits
          // 2. For midnight capture Studer value is not used due to exact Studer midnight rollover
          // 2. If a current based method is offline for more than 4m reset it using Studer Energy method
          // 4. order of preference: xcom-lan (1), Studer Energy (2), ShellyBM (3) 

          $soc_array = [];  // initialize to blank

          // calculate the differences between the various SOC's in 3 different ways
          $offset_soc_studerkwh_xcomlan   = $soc_percentage_now_studer_kwh - $soc_percentage_now_calculated_using_studer_xcomlan;
          $offset_soc_studerkwh_shellybm  = $soc_percentage_now_studer_kwh - $soc_percentage_now_calculated_using_shelly_bm;
          $offset_soc_xcomlan_shellybm    = $soc_percentage_now_calculated_using_studer_xcomlan - $soc_percentage_now_calculated_using_shelly_bm;

          if ( abs( $offset_soc_studerkwh_xcomlan ) < 5 )
          {
            $soc_studerkwh_tracks_xcomlan_bool = true;
          }
          if ( abs( $offset_soc_studerkwh_shellybm ) < 5 )
          {
            $soc_studerkwh_tracks_shellybm_bool = true;
          }
          if ( abs( $offset_soc_xcomlan_shellybm ) < 5 )
          {
            $soc_xcomlan_tracks_shellybm_bool = true;
          }

          $studer_reading_is_ok_bool    =  $xcomlan_studer_data_obj->studer_call_ok && // valid reading and value in range
                                  ! empty( $soc_percentage_now_studer_kwh )  && // soc value exists
                                            // SOC value is between LVDS and 100 roughly
                                           $soc_percentage_now_studer_kwh >= ($soc_percentage_lvds_setting - 5) &&
                                           $soc_percentage_now_studer_kwh < 101;
          if ( $studer_reading_is_ok_bool === false && $solar_kwh_today && $inverter_kwh_today && $grid_kwh_today )
          {
            // log details to help in debugging
            error_log( "Log-Studer Solar: $solar_kwh_today, Load: $inverter_kwh_today, Grid: $grid_kwh_today");
          }

          $xcom_lan_reading_is_ok_bool  = 
                      $xcomlan_studer_data_obj->xcomlan_call_ok              &&  // delta soc is present and valid
            ! empty(  $soc_percentage_now_calculated_using_studer_xcomlan )  &&  // SOC value exists
                      // soc value is roughly between LVDS and 100
                      $soc_percentage_now_calculated_using_studer_xcomlan >= ($soc_percentage_lvds_setting - 5) &&
                      $soc_percentage_now_calculated_using_studer_xcomlan < 101;
                                          

          $shelly_bm_reading_is_ok_bool = 
                      $shellyplus1_batt_obj->shellybm_call_ok          &&  // delta soc exists and is valid
            ! empty(  $soc_percentage_now_calculated_using_shelly_bm ) &&  // soc is not empty
                      // SOC value is between LVDS and 100% roughly
                      $soc_percentage_now_calculated_using_shelly_bm  >= ($soc_percentage_lvds_setting - 5) &&
                      $soc_percentage_now_calculated_using_shelly_bm  < 101;
                          
          // calculate offsets between studer method and other's when all methods are valid
          if ( $this->nowIsWithinTimeLimits("00:20:00", "23:40:00") === true )
          {
            // we are not too close to Studer clock midnight rollover so that studer KWH based SOC is reliable
            // if all readings are OK calculate offsets and set transients
            if (  $studer_reading_is_ok_bool          &&  // Studer KWH based call is OK and values are in limits
                  $xcom_lan_reading_is_ok_bool        &&  // xcom-lan call was OK and values are in limits
                  $soc_studerkwh_tracks_xcomlan_bool  &&  // delta studer-xcomlan < 5
                  // delta-T between latest measurement and past one is less than 5m
                  $batt_soc_accumulation_obj->delta_secs_xcomlan <= 240 || $batt_soc_accumulation_obj->delta_secs_shellybm <= 240 )
            { 
              set_transient('offset_soc_studerkwh_xcomlan',   $offset_soc_studerkwh_xcomlan,  1 * 60 * 60 );
            }
            elseif (  $studer_reading_is_ok_bool  && 
                      $xcom_lan_reading_is_ok_bool && 
                      $batt_soc_accumulation_obj->delta_secs_xcomlan  > 240  &&
                      $batt_soc_accumulation_obj->delta_secs_shellybm > 240    )
            {
              // reading is OK but there is a gap between xcom-lan measurements
              // therefore get the offset from transient
              $offset_soc_studerkwh_xcomlan   = get_transient( 'offset_soc_studerkwh_xcomlan' ) ?? 0;

              $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight =  
                                        $soc_batt_charge_net_percent_today_studer_kwh - $offset_soc_studerkwh_xcomlan;

              // update the usermeta xcomlan soc since midnight value
              update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', 
                                            $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight);


              // calculate the new soc xcomlan value
              $soc_percentage_now_calculated_using_studer_xcomlan = 
                        $soc_percentage_at_midnight + $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight;
              error_log("SOC xcom-lan accumulation reset using offset from studer-KWH as delta_secs_both > 240s");
            }

            // do the same treament for SOC using Shelly BM method
            if (  $studer_reading_is_ok_bool          &&  
                  $shelly_bm_reading_is_ok_bool       && 
                  $soc_studerkwh_tracks_shellybm_bool &&
                  $batt_soc_accumulation_obj->delta_secs_xcomlan <= 240 || $batt_soc_accumulation_obj->delta_secs_shellybm <= 240 )
            { 
              set_transient('offset_soc_studerkwh_shellybm',   $offset_soc_studerkwh_shellybm,  1 * 60 * 60 );
            }
            elseif (  $studer_reading_is_ok_bool && 
                      $shelly_bm_reading_is_ok_bool && 
                      $batt_soc_accumulation_obj->delta_secs_xcomlan  > 240  &&
                      $batt_soc_accumulation_obj->delta_secs_shellybm > 240    )
            {
              // reading is OK but there is a gap between xcom-lan measurements
              // therefore get the offset from transient
              $offset_soc_studerkwh_shellybm  = get_transient( 'offset_soc_studerkwh_shellybm' );

              $recal_battery_soc_percentage_accumulated_since_midnight = 
                                        $soc_batt_charge_net_percent_today_studer_kwh - $offset_soc_studerkwh_shellybm;

              // update the usermeta shelly BM soc since midnight value    
              update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', 
                                              $recal_battery_soc_percentage_accumulated_since_midnight);
              // calculate the new soc shellyBM value
              $soc_percentage_now_calculated_using_shelly_bm = 
                        $soc_percentage_at_midnight + $recal_battery_soc_percentage_accumulated_since_midnight;
              error_log("SOC shelly-bm accumulation reset using offset from studer-KWH as delta_secs_both > 240s");
            }
          }

          switch (true)
          { 
            case ( $xcom_lan_reading_is_ok_bool  ):
              $this->verbose ? error_log("1st preference - All conditions for xcom-lan soc value satisfied"): false;
              $soc_percentage_now = $soc_percentage_now_calculated_using_studer_xcomlan;
              $soc_update_method = 'xcom-lan';
            break;

            // 2nd preference for Shelly BM in case xcom-lan and studer readings are not there
            case (  $shelly_bm_reading_is_ok_bool ):
              $this->verbose ? error_log("2nd preference - All conditions for shelly-bm soc value satisfied"): false;
              $soc_percentage_now = $soc_percentage_now_calculated_using_shelly_bm;
              $soc_update_method = 'shelly-bm';
            break;

            // 3rd preference - xcom-lan and shelly-BM are not OK for example because delta-T > 5m or delta soc > 5%
            case ( $studer_reading_is_ok_bool ):
              $this->verbose ? error_log("3rd preference - Using Studer KWH SOC"): false;
              $soc_percentage_now = $soc_percentage_now_studer_kwh;
              $soc_update_method = 'studer-kwh';

              // reset the xcom-lan and shelly-bm accumulated values to the studer-kwh based method
              update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', 
                                              $soc_batt_charge_net_percent_today_studer_kwh);
              update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', 
                                              $soc_batt_charge_net_percent_today_studer_kwh);
              
              error_log("Reset the xcom-lan and shelly-bm soc accumulated today to studer value of: $soc_batt_charge_net_percent_today_studer_kwh");
            break;
              
            // in case everything breaks
            default:
              $soc_percentage_now = 40;
              $soc_update_method = 'none';
          }

          $shelly_readings_obj->soc_percentage_now  = $soc_percentage_now;
          $shelly_readings_obj->soc_update_method   = $soc_update_method;
        }

        // ....................... Battery FLOAT or SOC overflow past 100%, Clamp SOC at 100% ...................
        {
          $soc_percentage_now_is_greater_than_100 = $soc_percentage_now > 100;

          $battery_float_state_achieved = 
            $xcomlan_studer_data_obj->batt_voltage_xcomlan_avg  >=  $average_battery_float_voltage &&
            abs($batt_amps) < 5;
          
          switch ( true )
          {
              case ( $battery_float_state_achieved ):
                // since battery float state means all SOC's must read 100% lets check for that condition first
                error_log( "Battery in Float State - normalizing all SOCs to 100%" );

                // Since Studer KWH based SOC can only be normalized using the midnight SOC value
                if ( $soc_studerkwh_tracks_xcomlan_bool || $soc_studerkwh_tracks_shellybm_bool )
                {
                  // 2 out of 3 measurements track each other to within 5 % points so we can trust the studer KWH measurement
                  $new_soc_percentage_at_midnight = 100.0 - $soc_batt_charge_net_percent_today_studer_kwh;

                  update_user_meta( $wp_user_ID, 'soc_percentage_at_midnight', $new_soc_percentage_at_midnight );

                  error_log("updated SOC midnight value from: $soc_percentage_at_midnight to $new_soc_percentage_at_midnight");

                  $soc_percentage_at_midnight = $new_soc_percentage_at_midnight;
                }
                else
                {
                  // we let the studer SOC-KWH alone without normalizing it. It can be over 100 or below LVDS setting
                }
                 
                // Now lets adjust the accumulated values of xcom-lan SOC to make the SOC 100% for xcom-lan
                $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight = 100 - $soc_percentage_at_midnight;

                update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', 
                                              $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight);

                error_log("Adjusted xcom-lan accumulated SOC to: $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight");

                // Adjust accumulated value for Shelly-BM to make SOC of Shelly BM to 100% at float
                $recal_battery_soc_percentage_accumulated_since_midnight = 100 - $soc_percentage_at_midnight;

                update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', 
                                              $recal_battery_soc_percentage_accumulated_since_midnight);
                error_log("Adjusted shelly-BM accumulated SOC to: $recal_battery_soc_percentage_accumulated_since_midnight");
              break;
   
              case ( $soc_percentage_now_is_greater_than_100 && $soc_update_method === 'xcom-lan' ):
                // lets adjust the accumulated values of xcom-lan SOC only, to make its SOC 100%.
                $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight = 100 - $soc_percentage_at_midnight;
                update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', 
                                              $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight);
                error_log("Adjusted only the xcom-lan accumulated SOC to: $recal_battery_xcomlan_soc_percentage_accumulated_since_midnight");
              break;

              case ( $soc_percentage_now_is_greater_than_100 && $soc_update_method === 'shelly-bm' ):
                $recal_battery_soc_percentage_accumulated_since_midnight = 100 - $soc_percentage_at_midnight;
                update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', 
                                              $recal_battery_soc_percentage_accumulated_since_midnight);
                error_log("Adjusted only the shelly-BM accumulated SOC to: $recal_battery_soc_percentage_accumulated_since_midnight");
              break;

              case ( $soc_percentage_now_is_greater_than_100 && $soc_update_method === 'studer-kwh' ):
                // This is the case where the Studer SOC > 100 but it could be even if battery is NOT yet in FLOAT state
                // This adjustment will also affect the other 2 methods since we are adjusting the midnight SOC value
                $new_soc_percentage_at_midnight = 100.0 - $soc_batt_charge_net_percent_today_studer_kwh;
                update_user_meta( $wp_user_ID, 'soc_percentage_at_midnight', $new_soc_percentage_at_midnight );
                error_log("updated SOC midnight value from: $soc_percentage_at_midnight to $new_soc_percentage_at_midnight");
                $soc_percentage_at_midnight = $new_soc_percentage_at_midnight;
              break;
          }

          if ( false === get_transient( 'soc_daily_error' ) )
          {
            // the transient does not exist so the daily error has not yet been captured so capture the error
            $soc_daily_error = number_format( 100 - $soc_percentage_now, 1 );

            // write this as transient so it will be checked, will exist and so won't get overwritten and will last say till early AM next day
            set_transient( 'soc_daily_error' , $soc_daily_error, 15 * 60 * 60 );

            error_log("LogSocDailyError: $soc_daily_error");
          }
          
        }
        
        // midnight actions
        if ( $this->is_time_just_pass_midnight( $user_index, $wp_user_name ) )
        {
          // get the difference in counter redings to get home energy WH over last 24h before counter resets at midnight
          $kwh_energy_consumed_by_home_today = $shelly_em_home_kwh_since_midnight;

          $wh_energy_from_grid_last_24h = $shellypro3em_3p_grid_obj->home_grid_wh_counter_now - 
                                          (float) get_user_meta( $wp_user_ID, 'grid_wh_counter_at_midnight', true );

          $kwh_solar_generated_today    = $solar_kwh_today; // data from syuder via xcom-lan

          // get the total SOC% accumulated in the battery during the last 24h before it is reset for new day
          // This seems meaningleass to me on 2nd thought :-(
          $battery_soc_percentage_accumulated_last24h = 
            (float) get_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', true );

          $kwh_accumulated_in_battery_today = round($battery_soc_percentage_accumulated_last24h * $battery_capacity_kwh / 100, 2);

          // get this for previous day as it will be reset to value for the upcoming new day
          $soc_value_at_beginning_of_today    = (float) get_user_meta( $wp_user_ID, 'soc_percentage_at_midnight', true) ?? 0;

          if (false !== ( $soc_daily_error = get_transient( 'soc_daily_error' ) ) )
          {
            // the transient exists and is already read into the variable for use
            // expire this transient soon.
            set_transient( 'soc_daily_error', $soc_daily_error, 60 );
          }
          else
          {
            // transient does not exist most probably because float did not happen, so set it to 0
            $soc_daily_error = 0;
          }
          

          // lets get the date for the dailylog post
          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $ts = $now->getTimestamp() - 300;
          $now->setTimestamp($ts);
          $date_formatted = $now->format('Y-m-d H:i');

          // create a new custom post type daily_log
          $post_arr = array(
                            'post_type'       => 'daily_log',
                            'post_title'      => 'Solar Log ' . $date_formatted ,
                            'post_content'    => '',
                            'post_status'     => 'publish',
                            'post_author'     => $wp_user_ID,
                            'post_date'       => $date_formatted,
                            'comment_status'  => 'closed',
                            'ping_status'     => 'closed',
                            'meta_input'   => array(
                                                    'kwh_solar_generated_today'         => $kwh_solar_generated_today,
                                                    'kwh_energy_consumed_by_home_today' => $kwh_energy_consumed_by_home_today,
                                                    'soc_daily_error'                   => $soc_daily_error,
                                                    'soc_value_at_beginning_of_today'   => $soc_value_at_beginning_of_today,
                                                    'kwh_accumulated_in_battery_today'  => $kwh_accumulated_in_battery_today,
                                                  ) ,
                          );
          /*
          $post_id = wp_insert_post($post_arr);

          if(!is_wp_error($post_id))
          {
            //the post is valid
            error_log("Today's Daily Log Custom Post was created successfully as Post ID:  $post_id");
          }
          else
          {
            //there was an error in the post insertion, 
            error_log("Error in Daily Log Custom Post CReation:");
            error_log($post_id->get_error_message());
          }
          */
          

          // Now we reset values for the new day, starting at midnight
          // reset Shelly EM Home WH counter to present reading in WH. This is only done once in 24h, at midnight
          update_user_meta( $wp_user_ID, 'shelly_em_home_energy_counter_at_midnight', $shellyem_readings_obj->emeters[0]->total );

          // reset Shelly 3EM Grid Energy counter to present reading. This is only done once in 24h, at midnight
          $update_operation = 
            update_user_meta( $wp_user_ID, 'grid_wh_counter_at_midnight', $shellypro3em_3p_grid_obj->home_grid_wh_counter_now );

          if ($update_operation === false )
          {
            error_log("Cal-Midnight - The midnight update for Grid Home WH counter either failed or was unchanged");
          }
          // reset the SOC at midnight value to current update. This is only done once in 24h, at midnight
          // but check the value before reset
          if ( $soc_percentage_now < 40 || $soc_percentage_now > 100 )
          {
            error_log("Cal-Midnight - SOC midnight value: $soc_percentage_now reset to 64 as it was out of bounds");
            $soc_percentage_now = 40.0;
          }
          update_user_meta( $wp_user_ID, 'soc_percentage_at_midnight', $soc_percentage_now );

          // reset battery soc accumulated value to 0. This is only done once in 24h, at midnight
          update_user_meta( $wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight', 0);

          // reset battery accumulated using xcomlan measurements
          update_user_meta( $wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight', 0);

          error_log("Cal-Midnight - shelly_em_home_energy_counter_at_midnight: " . $shellyem_readings_obj->emeters[0]->total);
          error_log("Cal-Midnight - grid_wh_counter_at_midnight: $shellypro3em_3p_grid_obj->home_grid_wh_counter_now");
          error_log("Cal-Midnight - soc_percentage_at_midnight: $soc_percentage_now");
          error_log("Cal-Midnight - battery_soc_percentage_accumulated_since_midnight: 0");
          error_log("Cal-Midnight - battery_xcomlan_soc_percentage_accumulated_since_midnight: 0");
          error_log("Cal-Midnight - Studer clock offset in minutes: " . $this->studer_time_offset_in_mins_lagging);
        }

        // add property of studer clock offest. The remote should send a notification if this is above a limit
        $shelly_readings_obj->studer_time_offset_in_mins_lagging = $this->studer_time_offset_in_mins_lagging;

        if ( $it_is_still_dark )
        { // Do all the SOC after Dark operations here - Capture and also update SOC
          
          // check if capture happened. now-event time < 12h since event can happen at 7PM and last till 6:30AM
          $soc_capture_after_dark_happened = $this->check_if_soc_after_dark_happened($user_index, $wp_user_name, $wp_user_ID);

          if (  $soc_capture_after_dark_happened === false  && $shellyem_readings_obj->emeters[0]->total && $time_window_for_soc_dark_capture_open )
          { // event not happened yet so make it happen with valid value for the home energy EM counter reading

            // 1st preference is given to SOC value calculated by xcom-LAN method. So let's check its value
            if ( ! empty( $soc_percentage_now_calculated_using_studer_xcomlan )         && 
                          $soc_percentage_now_calculated_using_studer_xcomlan <= 100    &&
                          $soc_percentage_now_calculated_using_studer_xcomlan > 70
                         )
            {
              $soc_used_for_dark_capture = $soc_percentage_now_calculated_using_studer_xcomlan;
              error_log("xcom-lan SOC value used for dark capture");
            }
            else
            {
              $soc_used_for_dark_capture = $soc_percentage_now_calculated_using_shelly_bm;
              error_log("shelly BM based SOC value used for dark capture");
            }
            
            $this->capture_evening_soc_after_dark(  $user_index, 
                                                    $wp_user_name, 
                                                    $wp_user_ID, 
                                                    $soc_used_for_dark_capture, 
                                                    $shellyem_readings_obj->emeters[0]->total,
                                                    $time_window_for_soc_dark_capture_open );
          }

          if ( $soc_capture_after_dark_happened === true )
          { // SOC capture after dark is DONE and it is still dark, so use it to compute SOC after dark using only Shelly readings

            // grid is supplying power only when switch os ON and Power > 0.1KW
            if ( $shellyplus1pm_grid_switch_state_string == "ON" && $home_grid_kw_power > 0.1 )
            { // Grid is supplying Load and since Solar is 0, battery current is 0 so no change in battery SOC
              
              // update the after dark energy counter to latest value
              update_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark', $shellyem_readings_obj->emeters[0]->total);

              // SOC is unchanging due to Grid ON however set the variables using the user meta since they are undefined.
              $soc_percentage_now_using_dark_shelly = (float) get_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark',  true);

              $shelly_readings_obj->soc_percentage_now_using_dark_shelly = $soc_percentage_now_using_dark_shelly;
            }
            else
            { // Inverter is supplying the home since the power from Grid is <= 0.1KW as measured by Shelly 3EM
              
              // get the accumulated SOC and energy counter values from user meta
              $soc_percentage_after_dark        = (float) get_user_meta( $wp_user_ID, 'soc_percentage_update_after_dark',  true);
              $shelly_energy_counter_after_dark = (float) get_user_meta( $wp_user_ID, 'shelly_energy_counter_after_dark',   true);

              // get the difference in energy consumed since last reading
              $home_consumption_wh_after_dark_using_shellyem = $shellyem_readings_obj->emeters[0]->total - $shelly_energy_counter_after_dark;

              // convert to KW and round to 3 decimal places
              $home_consumption_kwh_after_dark_using_shellyem = round( $home_consumption_wh_after_dark_using_shellyem * 0.001, 3);

              // calculate SOC percentage discharge (since battery always discharges during dark as there is no solar)
              $soc_percentage_discharge = $home_consumption_kwh_after_dark_using_shellyem / $battery_capacity_kwh * 100;

              // round it to 3 decimal places for accuracy of arithmatic for accumulation
              $soc_percentage_now_using_dark_shelly = $soc_percentage_after_dark - $soc_percentage_discharge;
            }
          }
        }
        else
        {   // it is daylight now
          $soc_capture_after_dark_happened = false;
        }

        { // Switch control Tree decision

          { // determine if switch is flapping
            // Get flap transient - If transient doesnt exist rebuild
            $switch_flap_array = get_transient( 'switch_flap_array' ); 

            if ( ! is_array($switch_flap_array))
            {
              $switch_flap_array = [];
            }

            $switch_flap_amount = array_sum( $switch_flap_array);

            if ( $switch_flap_amount  > 2 )
            {
              // This means that over a 15m running average, there are more than 2 switch operations from ON->OFF or from OFF->ON
              $switch_is_flapping = true;
            }
            else
            {
              $switch_is_flapping = false;
            }
          }

          $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
          $now_timestamp = $now->getTimestamp();
          
          // $main_control_site_avasarala_is_offline_for_long = $this->check_if_main_control_site_avasarala_is_offline_for_long();
          // $shelly_readings_obj->main_control_site_avasarala_is_offline_for_long   = $main_control_site_avasarala_is_offline_for_long;

          // Turn ON switch if SOC is below limit and Switch is now OFF and Servo control is enabled
          // ---------- most important Event in entire scheme of things ............
          $LVDS_VBAT =  
              $xcomlan_studer_data_obj->xcomlan_call_ok === true                      &&
              property_exists( $xcomlan_studer_data_obj, "batt_voltage_xcomlan_avg")  &&
              $batt_voltage_xcomlan_avg < $average_battery_voltage_lvds_setting;

          $LVDS = 
              $shellyplus1pm_grid_switch_state_string === "OFF"             &&   // Grid switch is OFF
              $do_shelly                              === true              &&   // Grid Switch is Controllable
              ( $soc_percentage_now < $soc_percentage_lvds_setting ); // less than threshold settings

          

          // -- GRID switch OFF as SOC has recovered from LVDS. Solarmust be greater than Load also 
          $switch_release_LVDS = 
              $soc_percentage_now >=  ( $soc_percentage_lvds_setting + 2 ) &&  // SOC has recovered 2 points past LVDS minimum setting
              $batt_amps          >     6                                  &&  // battery is charging. This cannot happen when dark
              $psolar_kw          >   ( 0.3 + $shelly_em_home_kw )         &&  // Solar must exceed home consumption by 0.3KW
              $shellyplus1pm_grid_switch_state_string === "ON"             &&  // Grid switch is ON
              $do_shelly                              === true             &&  // Grid Switch is Controllable
              $keep_shelly_switch_closed_always       === false            &&  // keep switch ON always is False
              $switch_is_flapping                     === false;               // switch is NOT flapping.

          // GRID switch OFF as keep_shelly_switch_closed_always changed from TRUE to FALSE
          // As soon as always_on is released if SOC > LVDS + 10 it releases GRID switch
          $always_on_switch_release = 
              $soc_percentage_now                         >= ( $soc_percentage_lvds_setting + 10 )  &&    // If Grid switch is ON AND KEEP ALWAYS IS false, this variable is TRUE
              $shellyplus1pm_grid_switch_state_string     === "ON"                          &&    // Grid switch is ON
              $do_shelly                                  === true                          &&    // Crid Switch is Controllable
              $keep_shelly_switch_closed_always           === false                         &&    // keep switch ON always is true
              $switch_is_flapping                         === false;

          // GRID switch OFF to prevent High Battery Voltage when close to Float Voltage and when Solar is active
          // Since this is important, no dependency on controllabilty or flapping are checked.
          // If the condition is true then switch is OFF even if keep always on flag is still true
          $grid_switch_off_float_release =  
            $it_is_still_light                          === true              &&    // Active only in daytime
            $shellyplus1pm_grid_switch_state_string     === "ON"              &&    // Grid switch is alreay ON
            ( $batt_voltage_xcomlan_avg                 >= 51.8  ||                 // Close to Float
              $soc_percentage_now                       >= 95        );

          // evaluate condition to keep Grid switch closed. This is dependen on keep_shelly_switch_closed_always flag
          $keep_shelly_switch_closed_always_bool = 
              ( $soc_percentage_now       < 90 )                      &&        // hysterysis from float release
              $shellyplus1pm_grid_switch_state_string === "OFF"       &&        // Grid switch is OFF
              $do_shelly                              === true        &&        // Grid Switch is Controllable
              $keep_shelly_switch_closed_always       === true        &&        // keep switch ON always flag is SET
              $switch_is_flapping                     === false;

          // switch ATS to Grid if Grid Switch to Studer is also ON and currently ATS is on Solar
          $switch_ats_to_grid_bool = $shellyem_readings_obj->output_state_bool === "OFF"  &&  // ATS on Solar
                                    ( $keep_shelly_switch_closed_always_bool || $LVDS )   &&  // Studer on Grid
                                    $track_ats_switch_to_grid_switch === true;                // ATS Tracking ON

          $switch_ats_to_solar_bool = $shellyem_readings_obj->output_state_bool === "ON"  &&  // ATS on GRID
                                    ( $always_on_switch_release       ||                      // Studer on Solar
                                      $grid_switch_off_float_release  ||                      // Studer on Solar
                                      $switch_release_LVDS                )               &&
                                      $track_ats_switch_to_grid_switch === false;             // ATS tracking OFF

          // get the switch tree exit condition from transient. Recreate if it doesn't exist
          if ( false === ( $switch_tree_obj = get_transient( 'switch_tree_obj') ) )
          { // doesn't exist so recreate a fresh default one
            $switch_tree_obj = new stdClass;
            $switch_tree_obj->switch_tree_exit_condition = "no_action";
            $switch_tree_obj->switch_tree_exit_timestamp = $now_timestamp;
          }
          {
            // switch exit transient exists and has been read into object for use
          }

          $success_off = false;
          $success_on  = false;

          switch (true) 
          { // decision tree to determine switching based on logic determined above
            case ( $grid_switch_off_float_release ):

              $success_off = $this->turn_on_off_shellyplus1pm_grid_switch_over_lan( $user_index, 'off' );

              if ( $success_off )
              {
                error_log("LogFloatRelease: Prevent Battery Over Voltage at Float due to Solar, turn Grid switch OFF - SUCCESS");
                $switch_tree_obj->switch_tree_exit_condition = "float_release";
                $present_switch_tree_exit_condition = "float_release";
                $switch_tree_obj->switch_tree_exit_timestamp = $now_timestamp;
              }
              else
              {
                error_log("LogFloatRelease: Prevent Battery Over Voltage at Float due to Solar, turn Grid switch OFF - FAIL");
              }

              $success_off = $this->change_grid_ups_ats_using_shellyem_switch_over_lan( $user_index, 'off' );
              if ( $success_off )
              {
                error_log("LogFloatRelease: turn ATS to Solar/Studer - SUCCESS");
              }
              else
              {
                error_log("LogFloatRelease: turn ATS to Solar/Studer - FAIL");
              }

            break;

            case ( $LVDS ):

              $success_on = $this->turn_on_off_shellyplus1pm_grid_switch_over_lan( $user_index, 'on' );

              if ( $success_on )
              {
                error_log("LogLvds: SOC is LOW, commanded to turn ON Shelly 1PM Grid switch - SUCCESS");
                $switch_tree_obj->switch_tree_exit_condition = "LVDS";
                $present_switch_tree_exit_condition = "LVDS";
                $switch_tree_obj->switch_tree_exit_timestamp = $now_timestamp;
              }
              else
              {
                error_log("LogLvds: SOC is LOW, commanded to turn ON Shelly 1PM Grid switch - FAILED!!!!!!");
              }

              $success_on = $this->change_grid_ups_ats_using_shellyem_switch_over_lan( $user_index, 'on' );
              if ( $success_on )
              {
                error_log("LogFloatRelease: turn ATS to GRID - SUCCESS");
              }
              else
              {
                error_log("LogFloatRelease: turn ATS to GRID - FAIL");
              }

            break;

            case ( $switch_release_LVDS ):
              $success_off = $this->turn_on_off_shellyplus1pm_grid_switch_over_lan( $user_index, 'off' );
              
              if ( $success_off )
              {
                error_log("LogLvds: SOC has recovered, Solar is charging Battery, turn Grid switch OFF - SUCCESS");
                $switch_tree_obj->switch_tree_exit_condition = "lvds_release";
                $present_switch_tree_exit_condition = "lvds_release";
                $switch_tree_obj->switch_tree_exit_timestamp = $now_timestamp;
              }
              else
              {
                error_log("LogLvds: SOC has recovered, Solar is charging Battery, turn Grid switch OFF - FAIL");
              }

              $success_off = $this->change_grid_ups_ats_using_shellyem_switch_over_lan( $user_index, 'off' );
              if ( $success_off )
              {
                error_log("LogFloatRelease: turn ATS to Solar/Studer - SUCCESS");
              }
              else
              {
                error_log("LogFloatRelease: turn ATS to Solar/Studer - FAIL");
              }

            break;

            case ( $always_on_switch_release ):
              $success_off = $this->turn_on_off_shellyplus1pm_grid_switch_over_lan( $user_index, 'off' );

              if ( $success_off )
              {
                error_log("LogAlways_on OFF:  commanded to turn Grid switch OFF - SUCCESS");
                $switch_tree_obj->switch_tree_exit_condition  = "always_on_release";
                $present_switch_tree_exit_condition           = "always_on_release";
                $switch_tree_obj->switch_tree_exit_timestamp  = $now_timestamp;
              }
              else
              {
                error_log("LogAlways_on OFF:  commanded to turn Grid switch OFF - FAIL");
              }
              $success_off = $this->change_grid_ups_ats_using_shellyem_switch_over_lan( $user_index, 'off' );
              if ( $success_off )
              {
                error_log("LogFloatRelease: turn ATS to Solar/Studer - SUCCESS");
              }
              else
              {
                error_log("LogFloatRelease: turn ATS to Solar/Studer - FAIL");
              }

            break;

            case ( $keep_shelly_switch_closed_always_bool ):
              $success_on = $this->turn_on_off_shellyplus1pm_grid_switch_over_lan( $user_index, 'on' );
              
              if ( $success_on )
              {
                error_log("Log: Always ON - ommanded to turn ON Shelly 1PM Grid switch - SUCCESS");
                $switch_tree_obj->switch_tree_exit_condition  = "always_on";
                $present_switch_tree_exit_condition           = "always_on";
                $switch_tree_obj->switch_tree_exit_timestamp  = $now_timestamp;
              }
              else
              {
                error_log("Log: Always ON - ommanded to turn ON Shelly 1PM Grid switch - FAIL");
              }
              $success_on = $this->change_grid_ups_ats_using_shellyem_switch_over_lan( $user_index, 'on' );
              if ( $success_on )
              {
                error_log("LogFloatRelease: turn ATS to GRID - SUCCESS");
              }
              else
              {
                error_log("LogFloatRelease: turn ATS to GRID - FAIL");
              }
              
            break;
            
            default:
              // no switch action
              $this->verbose ? error_log("NOMINAL - No Action, Grid Switch: $shellyplus1pm_grid_switch_state_string"): false;
              $present_switch_tree_exit_condition = "no_action";

              // no change in switch_tree_obj
            break;
          }

          set_transient( 'switch_tree_obj', $switch_tree_obj, 60 * 60 );  // the transient contents get changed only if NOT no_action exit

          $shelly_readings_obj->switch_tree_obj = $switch_tree_obj;       // this is to record how long since last significant event

          $shelly_readings_obj->present_switch_tree_exit_condition = $present_switch_tree_exit_condition; // this is to detect remote notification event

          { // record for possible switch flap
            
            if ( $success_on || $success_off )
            {
              // push a value of 1 switch event into the holding array
              array_push( $switch_flap_array, 1 );
            }
            else
            {
              // push a zero this iteration to record no switch
              array_push( $switch_flap_array, 0 );
            }

            
            //  We want to detect over a span of 5m or 300s. Each loop is 15s so 20 loops
            // We are averaging over 100 loops or about 100 x 15 = 500s or about 10m
            if ( sizeof($switch_flap_array) > 20 )  
            {   // drop the earliest reading
                array_shift($switch_flap_array);
            }

            // Setup transiet to keep previous state for averaging
            set_transient( 'switch_flap_array', $switch_flap_array, 60 * 60 );
          }
        }

        { // ----------------->   Psolar and Psolar excess available ------------------------------------------
          if ($it_is_still_dark)
          { // solar power is 0 as it is still dark
            $shelly_readings_obj->psolar_kw = 0;
          }
          else
          { // As it is daylight, solar is present along both battery charge and home load. Note that battery power can be negative
            $shelly_readings_obj->psolar_kw = $psolar_kw;
          }

          // initialize this to false. We will evaluate this for this loop next
          $excess_solar_available = false;

          if (false !== ($excess_solar_available_loop_count = get_transient('excess_solar_available_loop_count')))
          {
            // the transient exists and is alreay loaded into the variable for processing
          }
          else
          {
            // the transient does not exist so create a fresh one
            set_transient('excess_solar_available_loop_count', 0, 5 * 60);
            $excess_solar_available_loop_count = 0;
          }

          $excess_solar_kw = round($est_solar_total_kw - $shelly_readings_obj->psolar_kw, 2);

          $excess_solar_available = 
                $est_solar_total_kw > 1.5       &&    // ensure the estimation is valid and not close to sunrise/set
                $excess_solar_kw    > 0.5       &&    // estimated excess is greater than 1KW
                $soc_percentage_now > 68;             // Once battery approcahes 50V or around 7-% SOC its current requirements throttles

                // establish duration of availability
          if ( $excess_solar_available === true )
          { 
            $excess_solar_available_loop_count += 1;    // increment averaging counter by 1

            // make sure the count never goes beyond 35.
            if ( $excess_solar_available_loop_count > 25 ) $excess_solar_available_loop_count = 25;
          }
          else
          {
            $excess_solar_available_loop_count += -1;     // decrement averaging count by 1

            // make sure the count never goes negative.
            if ( $excess_solar_available_loop_count < 0 ) $excess_solar_available_loop_count = 0;
          }

          // if excess is available for >=30 loops then we have average excess available
          // revaluate excess solar availability based on average count
          if ( $excess_solar_available === true && $excess_solar_available_loop_count >=20 )
          {
            // excess availability is true this loop and past 29 loops
            $excess_solar_available = true;
          }
          else
          {     // for all other cases ecess available is false
            $excess_solar_available = false;
          }

          // write updated aeraging count back to transient for use in next cycle
          set_transient( 'excess_solar_available_loop_count', $excess_solar_available_loop_count, 5 * 60 );

          $shelly_readings_obj->excess_solar_available  = $excess_solar_available;
          $shelly_readings_obj->excess_solar_kw         = $excess_solar_kw;
        }
        
        { // logging
          $log_string = "LogSoc xts: $xcomlan_ts";
          $log_string .= " E: "     . number_format($east_panel_current_xcomlan,1)   .  " W: "   . number_format($west_panel_current_xcomlan,1);
          $log_string .= " PV: "    . number_format($pv_current_now_total_xcomlan,1) . " Inv: "  . number_format($inverter_current_xcomlan,1);
          $log_string .= " X-A: "   . number_format($batt_current_xcomlan,1);
          $log_string .= " S-A: "   . number_format($batt_amps_shellybm,1) . ' Vbat_ir:'            .  number_format($ir_drop_compensated_battery_voltage_xcomlan,1);
          $log_string .= " SOC-St: " . number_format($soc_percentage_now_studer_kwh,1); // this is the Studer based soc%
          $log_string .= " SOC-B: " . number_format($soc_percentage_now_calculated_using_shelly_bm,1); // this is the shelly BM based soc%
          $log_string .= " SOC-X: " . number_format($soc_percentage_now_calculated_using_studer_xcomlan,1 ) . '%';                     // this is the xcom-lan current based soc%
          error_log($log_string);

          $log_string = "LogKWH GridStd: $grid_kwh_today GridShly: $home_grid_kwh_since_midnight ";
          $log_string .= "LoadStd: $inverter_kwh_today ";
          $log_string .= "LoadShly: $shelly_em_home_kwh_since_midnight ";
          $log_string .= "SolarStdr: $solar_kwh_today ";
          if ( $LVDS_VBAT ) $log_string .= "LVDS_VBAT: $LVDS_VBAT ";
          
          error_log($log_string);
        }

        // for remote pushed object we may add more data that is not needed for transient above
        {
          $shelly_readings_obj->shelly_em_home_energy_counter_at_midnight = get_user_meta($wp_user_ID, 'shelly_em_home_energy_counter_at_midnight', true);
          $shelly_readings_obj->grid_wh_counter_at_midnight = get_user_meta($wp_user_ID, 'grid_wh_counter_at_midnight', true);
          $shelly_readings_obj->soc_percentage_at_midnight  = get_user_meta($wp_user_ID, 'soc_percentage_at_midnight',  true);
          $shelly_readings_obj->battery_soc_percentage_accumulated_since_midnight         = get_user_meta($wp_user_ID, 'battery_soc_percentage_accumulated_since_midnight',  true);
          $shelly_readings_obj->battery_xcomlan_soc_percentage_accumulated_since_midnight = get_user_meta($wp_user_ID, 'battery_xcomlan_soc_percentage_accumulated_since_midnight',  true);
          $shelly_readings_obj->soc_percentage_update_after_dark = get_user_meta($wp_user_ID, 'soc_percentage_update_after_dark', true);
          $shelly_readings_obj->shelly_energy_counter_after_dark = get_user_meta($wp_user_ID, 'shelly_energy_counter_after_dark', true);
          $shelly_readings_obj->timestamp_soc_capture_after_dark = get_user_meta($wp_user_ID, 'timestamp_soc_capture_after_dark', true);
          $shelly_readings_obj->soc_capture_after_dark_happened = $soc_capture_after_dark_happened; // boolean value
          $shelly_readings_obj->shelly_bm_ok_bool       = $batt_soc_accumulation_obj->shelly_bm_ok_bool;
          $shelly_readings_obj->shelly_xcomlan_ok_bool  = $batt_soc_accumulation_obj->shelly_xcomlan_ok_bool;
          $shelly_readings_obj->studer_charger_enabled = get_user_meta($wp_user_ID, 'studer_charger_enabled', true);
          $shelly_readings_obj->studer_battery_charging_current = get_user_meta($wp_user_ID, 'studer_battery_charging_current', true);
          $shelly_readings_obj->do_shelly = $do_shelly;
          $shelly_readings_obj->keep_shelly_switch_closed_always = $keep_shelly_switch_closed_always;
        }

        // update transient with new data. Validity is 10m
        set_transient( 'shelly_readings_obj', $shelly_readings_obj, 10 * 60 );

        // publish this data to remote server. The remote server in slave mode just displays the data so it is accessible from anywhere
        $this->publish_data_to_avasarala_in_using_mqtt( $shelly_readings_obj );
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
          $current_cloud_cover_percentage = $current_weather_obj->clouds->all;
          return $current_cloud_cover_percentage;
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
     *  Takes the running average of the battery values, default is 10
     *  @preturn float:$battery_avg_voltage
     */
    public function get_battery_voltage_avg( float $new_battery_voltage_reading, int $number_of_averages = 10 ):float
    {
        // Load the voltage array that might have been pushed into transient space
        if ( false !== ( $bv_arr_transient = get_transient( 'bv_avg_arr' ) ) )
        {
          // the transient exists. Check that it is an array
          // If transient doesnt exist rebuild
          if ( ! is_array($bv_arr_transient))
          {
            $bv_avg_arr = [];
          }
          else
          {
            // transient exists and it IS an array so populate it
            $bv_avg_arr = $bv_arr_transient;
          }
        }
        else
        { // transient does not exists so start from scratch
          $bv_avg_arr = [];
        }

        
        
        // push the new voltage reading to the holding array
        array_push( $bv_avg_arr, $new_battery_voltage_reading );

        // If the array has more than 10 elements then drop the earliest one
        if ( sizeof($bv_avg_arr) > $number_of_averages )  {   // drop the earliest reading
            array_shift($bv_avg_arr);
        }
        // Write it to this object for access elsewhere easily
        $this->bv_avg_arr = $bv_avg_arr;

        // Setup transiet to keep previous state for averaging
        set_transient( 'bv_avg_arr', $bv_avg_arr, 5*60 );


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
                
            break;

            case "turn_Shelly_Switch_ON":
                // command the Shelly ACIN switch to ON
                
            break;

            case "turn_Shelly_Switch_OFF":
                // command the Shelly ACIN switch to ON
                
            break;

            case "run_cron_exec_once":
                $this->verbose = true;
                $this->shellystuder_cron_exec();
                $this->verbose = false;
            break;

            case "estimated_solar_power":
              $est_solar_kw_arr = $this->estimated_solar_power($config_index)->est_solar_kw_arr;
              foreach ($est_solar_kw_arr as $key => $value)
              {
                echo "<pre>" . "Est Solar Power, Clear Day (KW): " .    $value . "</pre>";
              }
              echo "<pre>" . "Total Est Solar Power Clear Day (KW): " .    array_sum($est_solar_kw_arr) . "</pre>";
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

              
            break;

            case "get_shelly_device_status_homepwr":

              
            break;

            case "check_if_soc_after_dark_happened":

            break;

            case "get_studer_clock_offset":

              $studer_time_offset_in_mins_lagging = $this->get_studer_clock_offset( $config_index );

              print( "Studer time offset in mins lagging = " . $studer_time_offset_in_mins_lagging);
              
            break;

            case "get_shelly_battery_measurement":

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
     * 
     */
    public function get_shellyplus1pm_grid_switch_data_over_lan(int $user_index): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;
      
      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_acin_1pm'];

      $shelly_device    =  new shelly_device( $ip_static_shelly, 'shellyplus1pm' );

      $shellyplus1pm_grid_switch_obj = $shelly_device->get_shelly_device_data();

      return $shellyplus1pm_grid_switch_obj;
    }


    /**
     * 
     */
    public function get_shellyplus1pm_water_heater_data_over_lan(int $user_index): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;
      
      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_water_heater'];

      $shelly_device    =  new shelly_device( $ip_static_shelly, 'shellyplus1pm' );

      $shellyplus1pm_water_heater_obj = $shelly_device->get_shelly_device_data();

      return $shellyplus1pm_water_heater_obj;
    }



    /**
     * 
     */
    public function get_shellyplus1pm_water_pump_data_over_lan(int $user_index): ? object
    {
      // get API and device ID from config based on user index
      $config = $this->config;
      
      $ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_water_pump'];

      $shelly_device    =  new shelly_device( $ip_static_shelly, 'shellyplus1pm' );

      $shellyplus1pm_water_pump_obj = $shelly_device->get_shelly_device_data();

      $shellyplus1pm_water_pump_obj->shelly_device = $shelly_device;

      return $shellyplus1pm_water_pump_obj;
    }



    /**
     *  Data from subscription to remote MQTT broker mqtt.avasarala.in using username/password authentication
     *  If data is different from existing no action. 
     *  If not, and if data is within limits, user meta is updated.
     *  In addition, for certain applicable STUDER variables, are include in array to be sent to set Studer Settings
     *  User Meta:  keep_shelly_switch_closed_always, studer_charger_enabled, studer_battery_priority_enabled,
     *              studer_battery_priority_voltage, studer_battery_charging_current, average_battery_float_voltage,
     *              soc_percentage_switch_release_setting, soc_percentage_lvds_setting,
     *              pump_duration_control, pump_duration_secs_max, pump_power_restart_interval_secs, 
     */
    public function get_flag_data_from_master_remote( int $user_index, int $wp_user_ID):void
    {
      $config = $this->config;

      $studer_settings_array = [];

      // set the topic to update flags from remote to local
      $topic_flg_from_remote = $config['accounts'][$user_index]['topic_flag_from_remote'];

      { 
        // subscribe to the mqtt broker. This is predefined as a localhost 1883 QOS_0 with no authentication connection
        // define a new instance of the mqtt class to subscribe and get the message.
        $mqtt_ch = new my_mqtt();

        $mqtt_ch->mqtt_sub_remote_qos_0( $topic_flg_from_remote, 'LocalWPGettingFlags' );

        // The above is blocking till it gets a message or timeout.
        $json_string = $mqtt_ch->message;

        // Check that the message is not empty
        if (! empty( $json_string ))
        {
          $flag_object = json_decode($json_string);

          if ($flag_object === null) 
          {
            error_log( 'Error parsing JSON from MQTT studerxcomlan: '. json_last_error_msg() );
          }
          elseif( json_last_error() === JSON_ERROR_NONE )
          { // do all the flag updation here


            //----------------------- pump_duration_control enable/disable ---------------------------
            if ( property_exists($flag_object, "pump_duration_control") )
            {
              $pump_duration_control_from_mqtt_update = (bool) $flag_object->pump_duration_control;

              $pump_duration_control_present_setting  = (bool) get_user_meta($wp_user_ID, "pump_duration_control", true);

              // compare the values and update if not the same
              if ( $pump_duration_control_from_mqtt_update !== $pump_duration_control_present_setting )
              {
                update_user_meta($wp_user_ID, "pump_duration_control", $pump_duration_control_from_mqtt_update);
                error_log(" Updated flag pump_duration_control From: $pump_duration_control_present_setting To $pump_duration_control_from_mqtt_update");
              }
            }


            // ----------------------- pump_duration_secs_max ...................................
            if ( property_exists($flag_object, "pump_duration_secs_max" ) )
            {
              $pump_duration_secs_max_from_mqtt_update = (float) $flag_object->pump_duration_secs_max;
              $pump_duration_secs_max_present_setting  = (float) get_user_meta($wp_user_ID, "pump_duration_secs_max", true);

              // compare the values and update if not the same provided update is meaningful
              if (  $pump_duration_secs_max_from_mqtt_update != $pump_duration_secs_max_present_setting && 
                    $pump_duration_secs_max_from_mqtt_update >= 600   &&
                    $pump_duration_secs_max_from_mqtt_update <= 3600     )
              {
                update_user_meta($wp_user_ID, "pump_duration_secs_max", $pump_duration_secs_max_from_mqtt_update);
                error_log(" Updated flag pump_duration_secs_max From: $pump_duration_secs_max_present_setting To $pump_duration_secs_max_from_mqtt_update");
              }
            }

            // ----------------------- pump_power_restart_interval_secs ...................................
            if ( property_exists($flag_object, "pump_power_restart_interval_secs" ) )
            {
              $pump_power_restart_interval_secs_from_mqtt_update = (float) $flag_object->pump_power_restart_interval_secs;
              $pump_power_restart_interval_secs_present_setting  = (float) get_user_meta($wp_user_ID, "pump_power_restart_interval_secs", true);

              // compare the values and update if not the same provided update is meaningful
              if (  $pump_power_restart_interval_secs_from_mqtt_update != $pump_power_restart_interval_secs_present_setting && 
                    $pump_power_restart_interval_secs_from_mqtt_update >= 3600   &&
                    $pump_power_restart_interval_secs_from_mqtt_update <= 3600 * 4     )
              {
                update_user_meta($wp_user_ID, "pump_power_restart_interval_secs", $pump_power_restart_interval_secs_from_mqtt_update);
                error_log(" Updated flag pump_power_restart_interval_secs From: $pump_power_restart_interval_secs_present_setting To $pump_power_restart_interval_secs_from_mqtt_update");
              }
            }
            
            // ----------------------- keep_shelly_switch_closed_always --------------------------

            if ( property_exists($flag_object, "keep_shelly_switch_closed_always") )
            {
              $keep_shelly_switch_closed_always_from_mqtt_update = (bool) $flag_object->keep_shelly_switch_closed_always;

              $keep_shelly_switch_closed_always_present_setting = (bool) get_user_meta($wp_user_ID, "keep_shelly_switch_closed_always", true);

              // compare the values and update if not the same
              if ( $keep_shelly_switch_closed_always_from_mqtt_update !== $keep_shelly_switch_closed_always_present_setting )
              {
                update_user_meta($wp_user_ID, "keep_shelly_switch_closed_always", $keep_shelly_switch_closed_always_from_mqtt_update);
                error_log(" Updated flag keep_switch_closed_always From: $keep_shelly_switch_closed_always_present_setting To $keep_shelly_switch_closed_always_from_mqtt_update");
              }
            }

            // ----------------------- track_ats_switch_to_grid_switch  --------------------------

            if ( property_exists($flag_object, "track_ats_switch_to_grid_switch") )
            {
              $track_ats_switch_to_grid_switch_from_mqtt_update = (bool) $flag_object->track_ats_switch_to_grid_switch;

              $track_ats_switch_to_grid_switch_present_setting = (bool) get_user_meta($wp_user_ID, "track_ats_switch_to_grid_switch", true);

              // compare the values and update if not the same
              if ( $track_ats_switch_to_grid_switch_from_mqtt_update !== $track_ats_switch_to_grid_switch_present_setting )
              {
                update_user_meta($wp_user_ID, "track_ats_switch_to_grid_switch", $track_ats_switch_to_grid_switch_from_mqtt_update);
                error_log(" Updated flag track_ats_switch_to_grid_switch From: $track_ats_switch_to_grid_switch_present_setting To $track_ats_switch_to_grid_switch_from_mqtt_update");
              }
            }

            // ----------------------- Studer Charger Enable/Disable ------------------------------

            if ( property_exists($flag_object, "studer_charger_enabled") )
            {
              $studer_charger_enabled_from_mqtt_update = (bool) $flag_object->studer_charger_enabled;

              $studer_charger_enabled_present_setting = (bool) get_user_meta($wp_user_ID, "studer_charger_enabled", true);

              // compare the values and update if not the same
              if ( $studer_charger_enabled_from_mqtt_update !== $studer_charger_enabled_present_setting )
              {
                update_user_meta($wp_user_ID, "studer_charger_enabled", $studer_charger_enabled_from_mqtt_update);
                error_log(" Updated flag studer_charger_enabled From: $studer_charger_enabled_present_setting To $studer_charger_enabled_from_mqtt_update");

                // add item to studer xcomlan set settings array
                $studer_charger_enabled_from_mqtt_update ? $studer_settings_array["CHARGER_ALLOWED"] = 1: $studer_settings_array["CHARGER_ALLOWED"] = 0;
              }
            }


            //----------------------- BATTERY_PRIORITY enable/disable ---------------------------

            if ( property_exists($flag_object, "studer_battery_priority_enabled") )
            {
              $studer_battery_priority_enabled_from_mqtt_update = (bool) $flag_object->studer_battery_priority_enabled;

              $studer_battery_priority_enabled_present_setting = (bool) get_user_meta($wp_user_ID, "studer_battery_priority_enabled", true);

              // compare the values and update if not the same
              if ( $studer_battery_priority_enabled_from_mqtt_update !== $studer_battery_priority_enabled_present_setting )
              {
                update_user_meta($wp_user_ID, "studer_battery_priority_enabled", $studer_battery_priority_enabled_from_mqtt_update);
                error_log(" Updated flag studer_battery_priority_enabled From: $studer_battery_priority_enabled_present_setting To $studer_battery_priority_enabled_from_mqtt_update");

                // add item to studer xcomlan set settings array
                $studer_battery_priority_enabled_from_mqtt_update ? $studer_settings_array["BATTERY_PRIORITY"] = 1: $studer_settings_array["BATTERY_PRIORITY"] = 0;
              }
            }

            // --------------------------BATTERY_PRIORITY Voltage   studer_battery_priority_voltage
            if ( property_exists($flag_object, "studer_battery_priority_voltage" ) )
            {
              $studer_battery_priority_voltage_from_mqtt_update = (float) $flag_object->studer_battery_priority_voltage;
              $studer_battery_priority_voltage_present_setting  = (float) get_user_meta($wp_user_ID, "studer_battery_priority_voltage", true);

              // compare the values and update if not the same provided update is meaningful
              if (  $studer_battery_priority_voltage_from_mqtt_update != $studer_battery_priority_voltage_present_setting && 
                    $studer_battery_priority_voltage_from_mqtt_update >= 50 &&
                    $studer_battery_priority_voltage_from_mqtt_update <= 54     )
              {
                update_user_meta($wp_user_ID, "studer_battery_priority_voltage", $studer_battery_priority_voltage_from_mqtt_update);
                error_log(" Updated flag studer_battery_priority_voltage From: $studer_battery_priority_voltage_present_setting To $studer_battery_priority_voltage_from_mqtt_update");

                // add item to studer xcomlan set settings array
                $studer_settings_array["BATTERY_PRIORITY_VOLTAGE"] = $studer_battery_priority_voltage_from_mqtt_update;
              }
            }

            // ---------------------- Studer battery charging current -----------------------------
            if ( property_exists($flag_object, "studer_battery_charging_current" ) )
            {
              $studer_battery_charging_current_from_mqtt_update = (float) $flag_object->studer_battery_charging_current;
              $studer_battery_charging_current_present_setting  = (float) get_user_meta($wp_user_ID, "studer_battery_charging_current", true);

              // compare the values and update if not the same provided update is meaningful
              if (  $studer_battery_charging_current_from_mqtt_update != $studer_battery_charging_current_present_setting && 
                    $studer_battery_charging_current_from_mqtt_update >= 0 &&
                    $studer_battery_charging_current_from_mqtt_update <= 40     )
              {
                update_user_meta($wp_user_ID, "studer_battery_charging_current", $studer_battery_charging_current_from_mqtt_update);
                error_log(" Updated flag studer_battery_charging_current From: $studer_battery_charging_current_present_setting To $studer_battery_charging_current_from_mqtt_update");

                // add item to studer xcomlan set settings array
                $studer_settings_array["BATTERY_CHARGE_CURR"] = $studer_battery_charging_current_from_mqtt_update;
              }
            }

            // ---------------------- average_battery_float_voltage -----------------------------
            if ( property_exists($flag_object, "average_battery_float_voltage" ) )
            {
              $average_battery_float_voltage_from_mqtt_update = (float) $flag_object->average_battery_float_voltage;
              $average_battery_float_voltage_present_setting = (float) get_user_meta($wp_user_ID, "average_battery_float_voltage", true);

              // compare the values and update if not the same provided update is meaningful
              if (  $average_battery_float_voltage_from_mqtt_update != $average_battery_float_voltage_present_setting && 
                    $average_battery_float_voltage_from_mqtt_update >= 51 &&
                    $average_battery_float_voltage_from_mqtt_update <= 52     )
              {
                update_user_meta($wp_user_ID, "average_battery_float_voltage", $average_battery_float_voltage_from_mqtt_update);
                error_log(" Updated flag average_battery_float_voltage From: $average_battery_float_voltage_present_setting To $average_battery_float_voltage_from_mqtt_update");
              }
            }

            // ---------------------- soc_percentage_switch_release_setting -----------------------------
            if ( property_exists($flag_object, "soc_percentage_switch_release_setting" ) )
            {
              $soc_percentage_switch_release_setting_from_mqtt_update = (float) $flag_object->soc_percentage_switch_release_setting;
              $soc_percentage_switch_release_setting_present_setting = (float) get_user_meta($wp_user_ID, "soc_percentage_switch_release_setting", true);

              // compare the values and update if not the same provided update is meaningful
              if (  $soc_percentage_switch_release_setting_from_mqtt_update != $soc_percentage_switch_release_setting_present_setting && 
              $soc_percentage_switch_release_setting_from_mqtt_update < 100 &&
              $soc_percentage_switch_release_setting_from_mqtt_update > 75      )
              {
                update_user_meta($wp_user_ID, "soc_percentage_switch_release_setting", $soc_percentage_switch_release_setting_from_mqtt_update);
                error_log(" Updated flag soc_percentage_switch_release_setting From: $soc_percentage_switch_release_setting_from_mqtt_update To $soc_percentage_switch_release_setting_from_mqtt_update");
              }
            }
                
          
            // do_shelly flag DOES NOT mirror the remote site


             
            // ---------------------- soc_percentage_lvds_setting -----------------------------
            if ( ! empty( $flag_object->soc_percentage_lvds_setting ) && $flag_object->soc_percentage_lvds_setting >= 45 && $flag_object->soc_percentage_lvds_setting < 99 )
            {
              $soc_percentage_lvds_setting_from_mqtt_update = (float) $flag_object->soc_percentage_lvds_setting;
              $soc_percentage_lvds_setting_present_setting  = (float) get_user_meta($wp_user_ID, "soc_percentage_lvds_setting", true);

              // compare the values and update if not the same
              if ( $soc_percentage_lvds_setting_from_mqtt_update != $soc_percentage_lvds_setting_present_setting )
              {
                update_user_meta($wp_user_ID, "soc_percentage_lvds_setting", $soc_percentage_lvds_setting_from_mqtt_update);
                error_log(" Updated soc_percentage_lvds_setting From: $soc_percentage_lvds_setting_present_setting To $soc_percentage_lvds_setting_from_mqtt_update");
              }
            }

            // Write settings if any updated, to xcom-lan if settings array is not empty
            if ( ! empty( $studer_settings_array ) ) $this->set_studer_settings_over_xcomlan( $wp_user_ID, $studer_settings_array ); 
          }
          else
          {
            error_log( 'Error parsing JSON from MQTT studerxcomlan: '. json_last_error_msg() );
          }
        }
        else
        {
          // error_log( "JSON string from mqtt subscription of scomlan via cron shell exec is empty");
        }
      }
    }

    /**
     * 
     */
    public function publish_data_to_avasarala_in_using_mqtt( object $data ): void
    {
      $config = $this->config;
      $topic = $config['accounts'][0]['topic_data_from_local_linux'];

      $json_data = json_encode($data);
      $mqtt_ch = new my_mqtt();

      $topic = "data_from_linux_home_desktop/solar";

      $retain = true;

      // publish the json string obtained from xcom-lan studer readings as the message
      $mqtt_ch->mqtt_pub_remote_qos_0( $topic, $json_data, $retain, 'localWPReadings' );
    }


    /**
     *  This function is scheduled by the main measurement loop
     * 
     *  This function subscribes to a pre-defined MQTT topic and extracts a message that was published.
     *  The publisher is a System triggered PHP CLI that runs a python program using <shell_exec.
     *  This way, the shell_exec doesn't seem to have any issues as compared to running Apache PHP
     *  The python takls to XCOM-LAN of Studer and gets the pre-set data into a JSOn string that is decoded by the PHP CLI
     *  The function below gets the message and checks for its validity and then sets a transient
     *  The transient is then accessed anywhere else that is needed on the site for SOC calculation and data display
     */
    public function get_studer_readings_over_xcomlan()
    {
      // load the script name from config. Not needed for anything right now.
      $config = $this->config;

      // This is the pre-defined topic
      $topic = "iot_data_over_lan/studerxcomlan";

      { 
        // subscribe to the mqtt broker. This is predefined as a localhost 1883 QOS_0 with no authentication connection
        // define a new instance of the mqtt class to subscribe and get the message.
        $mqtt_ch = new my_mqtt();

        $mqtt_ch->mqtt_sub_local_qos_0( $topic, 'localWPXcomLanData' );

        // The above is blocking till it gets a message or timeout.
        $json_string = $mqtt_ch->message;

        // Check that the message is not empty
        if (! empty( $json_string ))
        {
          $studer_data_via_xcomlan = json_decode($json_string);

          if ($studer_data_via_xcomlan === null) 
          {
            error_log( 'Error parsing JSON from MQTT studerxcomlan: '. json_last_error_msg() );
          }
          elseif( json_last_error() === JSON_ERROR_NONE )
          {
            set_transient( "studer_data_via_xcomlan", $studer_data_via_xcomlan, 2 * 60 );

            return $studer_data_via_xcomlan;
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
    }


    /**
     *  This method takes in an array of key value pairs and sends them to Studer over xcom-lan.
     *  Typical use is to enablor disable the Studer CHarger or to change the value of the charging current from the Grid
     *  This routine should be called when there is a change in the settings that need to be passed on to Studer
     *  The settings array is converted to a JSON string and then passed in as argument to the python script.
     *  The python script processes the decoded array to write array values to Studer via xcom-lan
     *  @param int:$wp_user_ID is not used so can be removed in future
     *  @param array:$settings_array contains key value pairs of parameters that need to be set in STuder using xcom-lan
     *  
     */
    public function set_studer_settings_over_xcomlan( int $wp_user_ID, array $settings_array ): void
    {
      // load the script name from config
      $config = $this->config;

      $studer_xcomlan_set_settings_script_path = $config['accounts'][0]["studer_xcomlan_set_settings_script_path"];

      $args_as_json = json_encode($settings_array);

      $command = $studer_xcomlan_set_settings_script_path . " " . escapeshellarg($args_as_json);

      error_log("Settings Array to be sent via xcom-lan");
      error_log(print_r($settings_array, true));
      error_log("Shell_exec command for Settings Array to be sent via xcom-lan : $command");

      $output_from_settings_py_script = shell_exec( $command );

      error_log("Any output from Studer Settings Python Script: $output_from_settings_py_script");
    }

    /**
     *  @return object:xcomlan_studer_data_obj  contains the Studer measurements
     *  A shell_exec script execution of a python script containing commands
     *  To get Studer Data via xcom-lan using serial protocol of Studer
     *  This needs a local installation of python library https://github.com/zocker-160/xcom-protocol
     *  
     */
    public function get_studer_readings_over_xcomlan_without_mqtt():  object
    {
      // initialize the data object to be returned
      $xcomlan_studer_data_obj = new stdClass;

      // load the script name from config. 
      $config                     = $this->config;
      $studer_xcomlan_script_path = $config['accounts'][0]["studer_xcomlan_script_path"];

      // escape the path
      $command = escapeshellcmd( $studer_xcomlan_script_path );

      // perform the shell_exec using the command string containing the script file name. The expected output is a JSON
      $mystuder_readings_json_string = shell_exec( $command );

      // Check that the message is not empty
      if ( empty( $mystuder_readings_json_string ) )
      {
          error_log( " Null output received from shell_exec command of python script xcom-lan call" );

          $xcomlan_studer_data_obj->batt_current_xcomlan = null;
          $xcomlan_studer_data_obj->xcomlan_ts           = null;
          $xcomlan_studer_data_obj->xcomlan_call_ok      = false;
          $xcomlan_studer_data_obj->studer_call_ok       = false;

          // do we need to set default battery voltage here also?

          return $xcomlan_studer_data_obj;
      }
      
      // we have non-empty output JSON from shell_exec. Lets try to decode it into an object
      $studer_data_via_xcomlan = json_decode($mystuder_readings_json_string);

      if ( $studer_data_via_xcomlan === null ) 
      {
        error_log( 'Error parsing JSON from studerxcomlan: '. json_last_error_msg() );
        error_log( print_r($studer_data_via_xcomlan , true) );

        $xcomlan_studer_data_obj->batt_current_xcomlan = null;
        $xcomlan_studer_data_obj->xcomlan_ts           = null;
        $xcomlan_studer_data_obj->xcomlan_call_ok      = false;
        $xcomlan_studer_data_obj->studer_call_ok       = false;

        // do we need to set default battery voltage here also?

        return $xcomlan_studer_data_obj;
      }
      elseif( json_last_error() === JSON_ERROR_NONE )
      {
        // we have a non-empty object to work with. Check if expected property exists
        if (  property_exists($studer_data_via_xcomlan, 'battery_voltage_xtender')  &&  
              property_exists($studer_data_via_xcomlan, 'pv_current_now_1')         &&
              property_exists($studer_data_via_xcomlan, 'pv_current_now_2')         &&
              property_exists($studer_data_via_xcomlan, 'pv_current_now_total')     &&
              property_exists($studer_data_via_xcomlan, 'inverter_current')         &&
              property_exists($studer_data_via_xcomlan, 'timestamp_xcomlan_call') 
            )
        {
          $raw_batt_voltage_xcomlan     =         $studer_data_via_xcomlan->battery_voltage_xtender;
          $east_panel_current_xcomlan   = round(  $studer_data_via_xcomlan->pv_current_now_1,     1 );
          $west_panel_current_xcomlan   = round(  $studer_data_via_xcomlan->pv_current_now_2,     1 );
          $pv_current_now_total_xcomlan = round(  $studer_data_via_xcomlan->pv_current_now_total, 1 );
          $inverter_current_xcomlan     = round(  $studer_data_via_xcomlan->inverter_current,     1 );
          $xcomlan_ts                   = (int)   $studer_data_via_xcomlan->timestamp_xcomlan_call;

          // battery current as measured by xcom-lan is got by adding + PV DC current amps and - inverter DC current amps
          $batt_current_xcomlan = ( $pv_current_now_total_xcomlan + $inverter_current_xcomlan );

          // discharge battery current is decreased by 4% to reflect higher SOC values at early AM due to night discharge
          if ( $batt_current_xcomlan <= 0 )
          {
            $batt_current_xcomlan = round( $batt_current_xcomlan * 0.960 , 1);
          }

          // if battery is charging, voltage will decrease and if discharging voltage will increase due to IR compensation
          $ir_drop_compensated_battery_voltage_xcomlan = $raw_batt_voltage_xcomlan - 0.030 * $batt_current_xcomlan;

          if ( $ir_drop_compensated_battery_voltage_xcomlan > 48 )
          { // calculate running aerage only if current measurement seems reasonable
            // calculate the running average over the last 10 readings including this one. Return is rounded to 2 decimals
            $batt_voltage_xcomlan_avg = $this->get_battery_voltage_avg( $ir_drop_compensated_battery_voltage_xcomlan, 10 );
          }
          else
          {
            // this is a safety catch in case the xcomlan voltage measurement fails
            $batt_voltage_xcomlan_avg = 49;   
          }

          // Solar power at the Battery, in KW calculated using PV current and IR compensated battery voltage
          $psolar_kw = round( $pv_current_now_total_xcomlan * $ir_drop_compensated_battery_voltage_xcomlan * 0.001, 2);

          // battery power as calculated by xcomlan

          // if tiemstamp is after 2020 and battery current is between 0-90A in any direction
          if (     $xcomlan_ts             > 1577817000 &&      // timestamp corresponds to after 2020
              abs( $batt_current_xcomlan ) >= 0         &&      // battery current is between 0 and 90A in any direction
              abs( $batt_current_xcomlan ) < 90             )
          {
            // return filled return object and set xcom-lan (current based) flag as true
            $xcomlan_studer_data_obj->xcomlan_call_ok                   = true;
            $xcomlan_studer_data_obj->xcomlan_ts                        = $xcomlan_ts;
            $xcomlan_studer_data_obj->batt_voltage_xcomlan_avg          = $batt_voltage_xcomlan_avg;
            $xcomlan_studer_data_obj->raw_batt_voltage_xcomlan          = $raw_batt_voltage_xcomlan;
            $xcomlan_studer_data_obj->east_panel_current_xcomlan        = $east_panel_current_xcomlan;
            $xcomlan_studer_data_obj->west_panel_current_xcomlan        = $west_panel_current_xcomlan;
            $xcomlan_studer_data_obj->pv_current_now_total_xcomlan      = $pv_current_now_total_xcomlan;
            $xcomlan_studer_data_obj->inverter_current_xcomlan          = $inverter_current_xcomlan;
            $xcomlan_studer_data_obj->psolar_kw                         = $psolar_kw;
            $xcomlan_studer_data_obj->batt_current_xcomlan              = $batt_current_xcomlan;
            $xcomlan_studer_data_obj->ir_drop_compensated_battery_voltage_xcomlan = $ir_drop_compensated_battery_voltage_xcomlan;
          }
          else
          {
            $xcomlan_studer_data_obj->xcomlan_call_ok = false;
          }          
        }
            
        if (  property_exists($studer_data_via_xcomlan, 'inverter_kwh_today') &&
              property_exists($studer_data_via_xcomlan, 'solar_kwh_today')    &&
              property_exists($studer_data_via_xcomlan, 'grid_kwh_today')         )
        {
          $inverter_kwh_today  = round(  $studer_data_via_xcomlan->inverter_kwh_today, 3);
          $solar_kwh_today     = round(  $studer_data_via_xcomlan->solar_kwh_today, 3);
          $grid_kwh_today      = round(  $studer_data_via_xcomlan->grid_kwh_today, 3);


          $xcomlan_studer_data_obj->inverter_kwh_today = $inverter_kwh_today;      
          $xcomlan_studer_data_obj->solar_kwh_today    = $solar_kwh_today;
          $xcomlan_studer_data_obj->grid_kwh_today     = $grid_kwh_today;

          $xcomlan_studer_data_obj->studer_call_ok     = true;
        }
        else
        {
          $xcomlan_studer_data_obj->studer_call_ok     = false;
        }
            
        return $xcomlan_studer_data_obj;
      }
      else
      {
        // we have some JSON errors so return null
        error_log( 'Error parsing JSON from studerxcomlan: '. json_last_error_msg() );
        error_log( print_r($studer_data_via_xcomlan , true) );

        $xcomlan_studer_data_obj->batt_current_xcomlan = null;
        $xcomlan_studer_data_obj->xcomlan_ts           = null;
        $xcomlan_studer_data_obj->xcomlan_call_ok      = false;
        $xcomlan_studer_data_obj->studer_call_ok       = false;

        return $xcomlan_studer_data_obj;
      }
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

            error_log('Log-Changed keep always ON flag from true-->false due to Ajax Request');
          }
          else {
            update_user_meta( $wp_user_ID, 'keep_shelly_switch_closed_always', true);
            error_log('Log-Changed keep always ON flag from false-->true due to Ajax Request');
          }
          // Grid ON/OFF is determoned in the CRON loop as usual. 
          return;
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
        $shellyem_readings_obj           = $readings_obj->shellyem_readings_obj;

        $shelly_water_heater_kw       = 0;
        $shelly_water_heater_status   = null;

        // extract and process Shelly 1PM switch water heater data
        if ( ! empty($readings_obj->shellyplus1pm_water_heater_obj) )
        {
          $shellyplus1pm_water_heater_obj = $readings_obj->shellyplus1pm_water_heater_obj;                  // data object

          $shelly_water_heater_kw         = $shellyplus1pm_water_heater_obj->switch[0]->power_kw;
          $shelly_water_heater_status     = $shellyplus1pm_water_heater_obj->switch[0]->output_state_bool;  // boolean variable
          $shelly_water_heater_current    = $shellyplus1pm_water_heater_obj->switch[0]->current;            // in Amps
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

        // changed to avg July 15 2023 was battery_voltage_vdc before that
        // $battery_voltage_vdc    =   round( (float) $readings_obj->battery_voltage_avg, 1);

        // Positive is charging and negative is discharging We use this as the readings have faster update rate
        $battery_amps           =   $readings_obj->batt_amps;

        $battery_power_kw       = abs(round($readings_obj->battery_power_kw, 2));

        $battery_avg_voltage    =   $readings_obj->xcomlan_studer_data_obj->batt_voltage_xcomlan_avg;

        $home_grid_kw_power     =   $readings_obj->shellypro3em_3p_grid_obj->home_grid_kw_power;
        $home_grid_voltage      =   $readings_obj->shellypro3em_3p_grid_obj->home_grid_voltage;

        $shellyplus1pm_grid_switch_state_string = $shellyplus1pm_grid_switch_obj->switch[0]->output_state_string;

        // This is the AC voltage of switch:0 of Shelly 4PM
        $shellyplus1pm_grid_switch_voltage = $shellyplus1pm_grid_switch_obj->switch[0]->voltage;

        $do_shelly         = (bool) $readings_obj->do_shelly;

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
            case ( $shellyplus1pm_grid_switch_state_string === "OFFLINE" ): // No Grid OR switch is OFFLINE
                $grid_status_icon = '<i class="fa-solid fa-3x fa-power-off" style="color: Yellow;"></i>';

                $grid_arrow_icon = ''; //'<i class="fa-solid fa-3x fa-circle-xmark"></i>';

                $grid_info = 'No<br>Grid';

                break;


            case ( $shellyplus1pm_grid_switch_state_string === "ON" ): // Switch is ON
                $grid_status_icon = '<i class="clickableIcon fa-solid fa-3x fa-power-off" style="color: Blue;"></i>';

                $grid_arrow_icon  = '<i class="fa-solid' . $grid_arrow_size .  'fa-arrow-right-long fa-rotate-by"
                                                                                  style="--fa-rotate-angle: 45deg;">
                                    </i>';
                $grid_info = '<span style="font-size: 18px;color: Red;"><strong>' . $home_grid_kw_power . 
                              ' KW</strong><br>' . $home_grid_voltage . ' V</span>';
                break;


            case ( $shellyplus1pm_grid_switch_state_string === "OFF" ):   // Switch is online and OFF
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
        $power_to_ac_kw   = $readings_obj->shellypro4pm_load_obj->switch[0]->power_kw +
                            $readings_obj->shellypro4pm_load_obj->switch[1]->power_kw +
                            $readings_obj->shellypro4pm_load_obj->switch[2]->power_kw +
                            $readings_obj->shellypro4pm_load_obj->switch[3]->power_kw;

        $power_to_pump_kw = $readings_obj->shellyplus1pm_water_pump_obj->switch[0]->power_kw;

        $pump_ON_duration_mins = (int) round( $readings_obj->shellyplus1pm_water_pump_obj->pump_ON_duration_secs / 60, 0) ?? 0;

        $pump_switch_status_bool  = $readings_obj->shellyplus1pm_water_pump_obj->switch[0]->output_state_bool;
        $ac_switch_status_bool    = $readings_obj->shellypro4pm_load_obj->switch[0]->output_state_bool || 
                                    $readings_obj->shellypro4pm_load_obj->switch[1]->output_state_bool ||
                                    $readings_obj->shellypro4pm_load_obj->switch[2]->output_state_bool ||
                                    $readings_obj->shellypro4pm_load_obj->switch[3]->output_state_bool;

        $switch_tree_obj            = $readings_obj->switch_tree_obj;
        $switch_tree_exit_condition = $switch_tree_obj->switch_tree_exit_condition;
        $switch_tree_exit_timestamp = $switch_tree_obj->switch_tree_exit_timestamp;

        


        // $load_arrow_size = $this->get_arrow_size_based_on_power($pout_inverter_ac_kw);
        $load_arrow_size = $this->get_arrow_size_based_on_power($shelly_em_home_kw);

        $load_info = '<span style="font-size: 18px;color: Black;"><strong>' . $shelly_em_home_kw . ' KW</strong></span>';
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
                                                <strong>' . $shelly_em_home_kw . ' KW</strong>
                                            </span>';

        $format_object->power_to_ac_kw = '<span style="font-size: 18px;color: Black;">
                                                <strong>' . $power_to_ac_kw . ' KW</strong>
                                            </span>';

        $format_object->power_to_pump_kw = '<span style="font-size: 18px;color: Black;">
                                              <strong>' . $pump_ON_duration_mins . ' mins</strong>
                                          </span>';

        $format_object->shelly_water_heater_kw = '<span style="font-size: 18px;color: Black;">
                                                    <strong>' . $shelly_water_heater_kw . ' KW</strong>
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

        $xcomlan_status  = $readings_obj->shelly_xcomlan_ok_bool ? "Xcom-Lan OK": "Xcom-Lan NOT Ok";
        $shellybm_status = $readings_obj->shelly_bm_ok_bool ? "Shelly BM OK": "Shelly BM NOT Ok";


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
     *  @param int:$ts is the timestamp referenced to whatever TZ, but shall be in the past to now
     *  @param int:$duration_in_seconds is the given duration
     * 
     *  @param int:obj
     * 
     *  The function checks that the time elapsed in seconds from now in Kolkata to the given timestamp in the past
     *  It returns int seconds_elapsed, bool elapsed_time_exceeds_duration_given, int timestamp_now
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
      $obj->timestamp_now   = $ts;

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
     *  uses Studer API over internet to get this value. So internet access is required
     * 
     *  @param int:$user_index in the config file
     *  @return int:$studer_time_offset_in_mins_lagging is the number of minutes that the Studer CLock is Lagging the server
     */
    public function get_studer_clock_offset( int $user_index )
    {
      $config = $this->config;

      $wp_user_name = $config['accounts'][$user_index]['wp_user_name'];

      // Get transient of Studer offset if it exists
      if ( false === get_transient( 'studer_time_offset_in_mins_lagging' ) )
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

        // calculate the lag positive or lead negative of studer time with now. 3600 secs check is bogus
        $clock_offset_obj = $this->check_validity_of_timestamp( $studer_clock_unix_timestamp_with_utc_offset, 3600);

        // positive means lagging behind, negative means leading ahead, of correct server time.
        // If Studer clock was correctr the offset should be 0 but Studer clock seems slow for some reason
        // 330 comes from pre-existing UTC offest of 5:30 already present in Studer's time stamp
        $studer_time_offset_in_mins_lagging = (int) ( 330 + round( $clock_offset_obj->seconds_elapsed / 60, 0) );

        $this->studer_time_offset_in_mins_lagging = $studer_time_offset_in_mins_lagging;

        if ( abs( $studer_time_offset_in_mins_lagging ) > 10 )
        {
          error_log( " Studer clock offset out of bounds and so 0 returned - check: $studer_time_offset_in_mins_lagging");

          // @TODO send a message notification to adjust studer clock ahead

          //reset value to a safe level. So this will result in a junp in SOC value of Studer KWH method, upward, briefly
          $studer_time_offset_in_mins_lagging = 0;
        }

        // transient shall exist for 8000 seconds or 2h 18m 20s
        set_transient(  'studer_time_offset_in_mins_lagging', $studer_time_offset_in_mins_lagging, 8000 );

        error_log( "Studer API call - clock offset lags Server clock by: " . $studer_time_offset_in_mins_lagging . " mins");
      }
      else
      {
        // offset already computed and transient still valid, just read in the value
        $studer_time_offset_in_mins_lagging = (int) get_transient( 'studer_time_offset_in_mins_lagging' );
        $this->studer_time_offset_in_mins_lagging = $studer_time_offset_in_mins_lagging;
      }

      if ( abs( $studer_time_offset_in_mins_lagging ) > 10 )
      {
        error_log( " Studer clock offset out of bounds and so 0 returned - check");
        return 0;
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
      // this will just return a transient or rebuild the transient every hour
      $studer_time_offset_in_mins_lagging = $this->get_studer_clock_offset( $user_index );

      // if not within a small window of server clocks midnight, return false. Studer offset will never be allowed to > 20m
      if ($this->nowIsWithinTimeLimits("00:20:00", "23:40:00") )
      {
        return false;
      }
      // we only get here betweeon 23:40:00 and 00:20:00
      // if the transient is expired it means we need to check
      if ( false === get_transient( 'is_time_just_pass_midnight' ) )
      {
        // get current time compensated for our timezone
        $test = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $h=$test->format('H');
        $m=$test->format('i');
        $s=$test->format('s');

        // if hours are 0 and offset adjusted minutes are 0 then we are just pass midnight per Studer clock
        if( $h == 0 && ( $m - $studer_time_offset_in_mins_lagging ) > 0 )
        {
          // We are just past midnight on Studer clock, so return true after setiimg the transient
          // we ensure that the transient lasts longer than the 40m window but less than 24h
          set_transient( 'is_time_just_pass_midnight',  'yes', 45 * 60 );
          return true;
        }
      }

      //  If we het here it means that the transient exists and so we are way past midnight, check was triggered already
      return false;
    }


    /**
     *  Each time the function is called increment the cron 5s counter modulo 12. 
     */
    public function count_cron_cycles_modulo( int $modulo = 3 ):bool
    {
        $modulo_cron_cycles_completed = false;

        // We need to keep track of the count each time we land here. The CRON interval is nominally 5s
        // We need a counter to count to 1 minute
        $count_cron_cycles = get_transient( 'count_cron_cycles' );
        
        if ( false === $count_cron_cycles )
        {
            // this is 1st time or transient somehow got deleted or expired
            $count_cron_cycles = 1;
        }
        else
        {
            // increment the counter by 1
            $count_cron_cycles += get_transient( 'count_cron_cycles' );

            if ( $count_cron_cycles >= $modulo ) 
            {
                // the counter overflows past 1 minute so roll sback to 1 or 5sec
                $count_cron_cycles = 1;

                $modulo_cron_cycles_completed = true;
            }
        }

        // set transient with the above value for 60s
        set_transient( 'count_cron_cycles', $count_cron_cycles, 2 * 60 );

        return $modulo_cron_cycles_completed;
    }
    
}