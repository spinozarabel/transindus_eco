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

require_once(__DIR__."/studer_api.php");          // contains studer api class
require_once(__DIR__."/shelly_cloud_api.php");    // contains Shelly Cloud API class

class class_transindus_eco
{
	// The loader that's responsible for maintaining and registering all hooks that power
	protected $loader;

	// The unique identifier of this plugin.
	protected $plugin_name;

	// The current version of the plugin.
	protected $version;

  //
  protected $config;

  public $bv_avg_arr;
  public $psolar_avg_arr;
  public $pload_avg;
  public $count_for_averaging;
  public $counter;

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
      $this->bv_avg_arr       = [0, 0, 0, 0, 0];
      $this->psolar_avg_arr   = [];
      $this->pload_avg        = [];

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
     * Define all of the public facing hooks and filters required for this plugin
     * @return null
     */
    private function define_public_hooks()
    {
        // register shortcode for pages. This is for showing the page with studer readings
        add_shortcode( 'transindus-studer-readings',  [$this, 'studer_readings_page_render'] );
    }

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
     *  The ACIN switch is turned ON or OFF based on a complex algorithm.
     *  The algorithm trie sto ensure that the following: This assumes that Studer Relay is always ON
     *  1. When battery voltage goes below the set value at any time, the ACIN is ON for ON-GRID operation
     *  2. When 
     */
    public function shellystuder_cron_exec()
    {
        // Loop over all of the eligible users
        foreach ($this->config['accounts'] as $user_index => $account) 
        {
          $wp_user_name = $this->config['accounts'][$user_index]['wp_user_name'];

          // Get the wp user object given the above username
          $wp_user_obj          = get_user_by('login', $wp_user_name);
          $wp_user_ID           = $wp_user_obj->ID;
          $do_shelly_user_meta  = get_user_meta($wp_user_ID, "do_shelly", true);

          $this->verbose ? print("<pre>username: " . $wp_user_name . " has do_shelly set to: "  . 
                                  $do_shelly_user_meta . "</pre>" ) : false;

          // Check if this's control flag is even set to do this control
          if( !$do_shelly_user_meta || empty($do_shelly_user_meta))
          {
              // this user not interested, go to next user in config
              $this->verbose ? print("<pre>username: " . $wp_user_name . " do_shelly skipped, user meta is empty or false</pre>" ) : false;
              continue;
          }

          // get the current ACIN Shelly Switch Status. This returns null if not a valid response
          $shelly_api_device_response = $this->get_shelly_device_status($user_index);

          if ( empty($shelly_api_device_response) )
          {
              // The switch status is unknown and so no point worrying about it, exit
              $this->verbose ? print("<pre>username: " . $wp_user_name . 
                                     " Shelly Switch Status Unknown, exiting</pre>" ) : false;
              continue;
          }

          // Ascertain switch status: True if Switch is closed, false if Switch is open
          $shelly_api_device_status   = $shelly_api_device_response->data->device_status->{"switch:0"}->output;
          $this->verbose ? print("<pre>username: " . $wp_user_name . " Shelly Switch Status is:" . 
                                 $shelly_api_device_status . "</pre>") : false;

          // get the Studer status using the minimal set of readings
          $studer_readings_obj        = $this->get_studer_min_readings($user_index);

          // check for valid studer values. Return if not valid
          if( empty(  $studer_readings_obj->battery_voltage_vdc )     || 
                      $studer_readings_obj->battery_voltage_vdc < 40  ||
              empty(  $studer_readings_obj->pout_inverter_ac_kw ) 
            ) 
          {
            // cannot trust this Studer reading, skipping this user
            continue;
          }

          // if we get this far it means that the readings are reliable. Drop the last reading.
          array_shift($this->bv_avg_arr);

          // add the latest reaiding at the top
          array_push($this->bv_avg_arr, $studer_readings_obj->battery_voltage_vdc);

          $battery_voltage_avg = $this->get_battery_voltage_avg();

          if ($this->verbose)
          {
              if ($shelly_api_device_status)
              {
                  $shelly_switch_status = "ON";
              }
              else {
                  $shelly_switch_status = "OFF";
              }
    
              print("<pre>user: "                 . $wp_user_name                             . "Shelly and Studer Values</pre>");
              print("<pre>Shelly Switch State: "  . $shelly_switch_status                     . "</pre>");
              print("<pre>Battery Avg Voltage: "  . $battery_voltage_avg                      . "Vdc </pre>");
              print("<pre>Battery Current: "      . $studer_readings_obj->battery_charge_adc  . "Adc </pre>");
              print("<pre>Solar PowerGen: "       . $studer_readings_obj->psolar_kw           . "KW </pre>");
              print("<pre>AC at Studer Input: "   . $studer_readings_obj->grid_input_vac      . "Vac</pre>");
              print("<pre>Inverter PowerOut: "    . $studer_readings_obj->pout_inverter_ac_kw . "KW </pre>");
          }

          switch(true)
          {
              // if Shelly switch is OPEN but Studer transfer relay is closed and Studer AC voltage is present
              // it means that the ACIN is manually overridden at control panel
              // so ignore attempting any control and skip this user
              case (  empty($shelly_api_device_status ) && $studer_readings_obj->grid_input_vac >= 190 ):
                    // ignore this user
                    $this->verbose ? print("<pre>username: " . $wp_user_name . " Shelly Switch Open but Studer already has AC, exiting</pre>" ) : false;
              break;

              // <1> If switch is OPEN and running average Battery voltage from 5 readings is lower than limit, go ON-GRID
              case (  $battery_voltage_avg      < 48.7        &&
                      $shelly_api_device_status === false ):
                  
                  $this->turn_on_off_shelly_switch($user_index, "on");

                  error_log($wp_user_name. " Case 1 fired- Shelly Switch turned ON - Vbatt: " 
                            . $battery_voltage_avg . " < 48.7V and Switch was OFF");

                  $this->verbose ? print("<pre>username: " . $wp_user_name . 
                       " Case 1 - Shelly Switch turned ON - Vbatt < 48.7 and Switch was OFF</pre>" ) : false;
              break;

              // <2> if switch is ON and the Vbatt > 49.5V and Solar can supply the Load in full
              // then turn-off the ACIN switch
              case (  $studer_readings_obj->battery_voltage_vdc > 49.5      &&
                      $shelly_api_device_status === true                    &&
                      ($studer_readings_obj->psolar_kw - $studer_readings_obj->pout_inverter_ac_kw) > 0.2 ):
                  
                  // $this->turn_on_off_shelly_switch($user_index, "off");

                  $this->verbose ? print("<pre>username:" . $wp_user_name . 
                       " Case 2 - Shelly Switch turned OFF - Vbatt > 49.5, Switch was ON, Psolar more than Pload</pre>" ) : false;

                  error_log($wp_user_name . " Case 2 fired - Shelly turned OFF - Vbatt: " . 
                       $studer_readings_obj->battery_voltage_vdc . 
                       " > 49.5, Switch was ON, Psolar: " . $studer_readings_obj->psolar_kw . 
                       " more than Pload: " .  $studer_readings_obj->pout_inverter_ac_kw);
              break;

              // <3> Daytime, with cloud cover, and Psol < Pload, Pload > 1.0KW: turn switch ON
              case ( $shelly_api_device_status === false                    &&
                     $this->nowIsWithinTimeLimits("09:30", "16:00")         &&
                     $shelly_api_device_status === false                    &&
                     $studer_readings_obj->pout_inverter_ac_kw > 1.0        &&
                     ($studer_readings_obj->pout_inverter_ac_kw - $studer_readings_obj->psolar_kw) > 0.2 ):

                  // $this->turn_on_off_shelly_switch($user_index, "on");

                  $this->verbose ? print("<pre>username:" . $wp_user_name . 
                       " Case 3 fired - Daytime, Pload >= Psolar+0.2, Load > 1KW</pre>" ) : false;

                  error_log($wp_user_name . " Case 3 fired - Shelly turned ON -" . 
                  $studer_readings_obj->battery_voltage_vdc . 
                  " Switch was OFF, Psolar: " . $studer_readings_obj->psolar_kw . 
                  " less than Pload: " .  $studer_readings_obj->pout_inverter_ac_kw . 
                  " and current time is within specified limits");

              break;

              default:
                  $this->verbose ? print("<pre>username: " . $wp_user_name . " No Switch action - didn't Fire any CASE</pre>" ) : false;

              break;
          }
          
        }

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

        return ($sum / $count);
    }

    /**
     *  @param string:$start
     *  @param string:$stop
     *  @return bool true if current time is within the time limits specified otherwise false
     */
    public function nowIsWithinTimeLimits(string $start_time, string $stop_time): bool
    {
        $now =  new DateTime("now");
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
                $grid_staus_icon = '<i class="fa-solid fa-2xl fa-plug-circle-xmark"></i>';
            }
            else
            {
                $grid_staus_icon = '<i class="fa-solid fa-2xl fa-plug-circle-check"></i>';
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

            $output .= $this->print_row_table(  $home, $solar_capacity, $battery_capacity, 
                                                $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                                $battery_span_fontawesome, $solar, $grid_staus_icon, $pout_inverter_ac_kw   );
        }
        $output .= '</table>';

        return $output;
    }

    public function print_row_table(    $home, $solar_capacity, $battery_capacity, 
                                        $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                        $battery_span_fontawesome, $solar, $grid_staus_icon, $pout_inverter_ac_kw   )
    {
        $returnstring =
        '<tr>' .
            '<td>' . $home .                                            '</td>' .
            '<td>' . '<font color="green">' . $solar_yesterday .        '</td>' .
            '<td>' . '<font color="red">' .   $grid_yesterday .         '</td>' .
            '<td>' . $battery_span_fontawesome .      '</td>' .
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
                $shelly_api_device_status = $shelly_api_device_response->data->device_status;
            break;

            case "turn_Shelly_Switch_ON":
                // command the Shelly ACIN switch to ON
                $shelly_api_device_response = $this->turn_on_off_shelly_switch($config_index, "on");
                sleep(1);

                // get a fresh status
                $shelly_api_device_response = $this->get_shelly_device_status($config_index);
                $shelly_api_device_status   = $shelly_api_device_response->data->device_status;
            break;

            case "turn_Shelly_Switch_OFF":
                // command the Shelly ACIN switch to ON
                $shelly_api_device_response = $this->turn_on_off_shelly_switch($config_index, "off");
                sleep(1);

                // get a fresh status
                $shelly_api_device_response = $this->get_shelly_device_status($config_index);
                $shelly_api_device_status   = $shelly_api_device_response->data->device_status;
            break;

            case "run_cron_exec_once":
                $this->verbose = true;
                $this->shellystuder_cron_exec();
                $this->verbose = false;
            break;
        }
        if($shelly_api_device_status->{"switch:0"}->output)
        {
            $switch_state = "Closed";
        }
        else
        {
          $switch_state = "Open";
        }
        echo "<pre>" . "ACIN Shelly Switch State: " .    $switch_state . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Voltage: " .  $shelly_api_device_status->{"switch:0"}->voltage . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Power: " .    $shelly_api_device_status->{"switch:0"}->apower . "</pre>";
        echo "<pre>" . "ACIN Shelly Switch Current: " .  $shelly_api_device_status->{"switch:0"}->current . "</pre>";
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
            $battery_icon_class = "fa fa-2xl fa-solid fa-battery-empty";
          break;

          case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] &&
                $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
            $battery_icon_class = "fa fa-2xl fa-solid fa-battery-quarter";
          break;
       
          case ($battery_voltage_vdc >= $config['battery_vdc_state']["50p"] && 
                $battery_voltage_vdc <  $config['battery_vdc_state']["75p"] ):
            $battery_icon_class = "fa fa-2xl fa-solid fa-battery-half";
          break;
       
          case ($battery_voltage_vdc >= $config['battery_vdc_state']["75p"] && 
                $battery_voltage_vdc <  $config['battery_vdc_state']["100p"] ):
            $battery_icon_class = "fa fa-2xl fa-solid fa-battery-three-quarters";
          break;
       
          case ($battery_voltage_vdc >= $config['battery_vdc_state']["100p"] ):
            $battery_icon_class = "fa fa-2xl fa-solid fa-battery-full";
          break;
        }

        $battery_span_fontawesome = '
                                      <i class="' . $battery_icon_class . ' ' . $battery_color_style . '"></i>';

        // select battery icon color: Green if charging, Red if discharging

       
        // update the object with battery data read
        $studer_readings_obj->battery_charge_adc          = abs($battery_charge_adc);
        $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
        $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
        $studer_readings_obj->battery_charge_arrow_class  = $battery_charge_arrow_class;
        $studer_readings_obj->battery_icon_class          = $battery_icon_class;
        $studer_readings_obj->battery_charge_animation_class = $battery_charge_animation_class;
        $studer_readings_obj->energyout_battery_yesterday    = $energyout_battery_yesterday;
       
        // update the object with SOlar data read
        $studer_readings_obj->psolar_kw                   = $psolar_kw;
        $studer_readings_obj->solar_pv_adc                = $solar_pv_adc;
        $studer_readings_obj->solar_pv_vdc                = $solar_pv_vdc;
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
        * 
        */
        public function get_studer_min_readings(int $user_index): ?object
        {
            $config = $this->config;

            $Ra = 0.0;       // value of resistance from DC junction to Inverter
            $Rb = 0.025;     // value of resistance from DC junction to Battery terminals
          
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
                                  "userRef"       =>  3011,   // Grid AC in Voltage Vac
                                  "infoAssembly"  => "Master"
                                ),
                          array(
                                  "userRef"       =>  3000,   // Battery Voltage
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
                                  "userRef"       =>  11004,   // Psolkw from VT1
                                  "infoAssembly"  => "1"
                                ),
                          array(
                                  "userRef"       =>  11004,   // Psolkw from VT2
                                  "infoAssembly"  => "2"
                                ),
                          );

            $studer_api->body   = $body;
          
            // POST curl request to Studer
            $user_values  = $studer_api->get_user_values();
          
            $solar_pv_adc = 0;
            $psolar_kw    = 0;
          
          
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
              // also good time to compensate for IR drop.
              // Actual voltage is smaller than indicated, when charging 
              $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
            }
            else
            {
              // current is -ve so battery is discharging so arrow is up and icon color shall be red
              // Actual battery voltage is larger than indicated when discharging
              $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra + abs($battery_charge_adc) * $Rb, 2);
            }

            // update the object with battery data read
            $studer_readings_obj->battery_charge_adc          = $battery_charge_adc;
            $studer_readings_obj->pbattery_kw                 = abs($pbattery_kw);
            $studer_readings_obj->battery_voltage_vdc         = $battery_voltage_vdc;
          
            // update the object with SOlar data read
            $studer_readings_obj->psolar_kw                   = $psolar_kw;
            $studer_readings_obj->solar_pv_adc                = $solar_pv_adc;
          
            //update the object with Inverter Load details
            $studer_readings_obj->pout_inverter_ac_kw         = $pout_inverter_ac_kw;
          
            // update the Grid input values
            $studer_readings_obj->grid_pin_ac_kw              = $grid_pin_ac_kw;
            $studer_readings_obj->grid_input_vac              = $grid_input_vac;
          
            return $studer_readings_obj;
          }          
}