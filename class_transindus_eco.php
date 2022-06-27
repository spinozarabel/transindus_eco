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

require_once(__DIR__."/studer_api.php");         // contains studer api class

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
		} else
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
        $this->verbose = true;
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
        </style>';

        $output .= '
        <table style="width:100%">
        <tr>
            <th>Install</th>
            <th>Solar KWPk</th>
            <th>Batt.KWH</th>
            <th>Solar Ysrdy</th>
            <th>Grid Ysrdy</th>
            <th>Cnsmd Ysrdy</th>
            <th>BattStatus</th>
            <th><i class="fa-solid fa-solar-panel"></i></th>
            <th>Status</th>
        </tr>';

        // loop through all of the users in the config
        foreach ($this->config['accounts'] as $user_index => $account) 
        {
            $home = $account['home'];

            $studer_readings_obj = $this->get_studer_readings($user_index);

            if ($studer_readings_obj->grid_pin_ac_kw < 0.1)
            {
                $grid_staus = 'Off-Grid';
            }
            else
            {
                $grid_staus = 'On-Grid';
            }
            $solar_capacity         =   $account['solar_pk_install'];
            $battery_capacity       =   $account['battery_capacity'];
            $solar_yesterday        =   $studer_readings_obj->psolar_kw_yesterday;
            $grid_yesterday         =   $studer_readings_obj->energy_grid_yesterday;
            $consumed_yesterday     =   $studer_readings_obj->energy_consumed_yesterday;
            $battery_icon_class     =   $studer_readings_obj->battery_icon_class;
            $solar                  =   $studer_readings_obj->psolar_kw;

            $output .= $this->print_row_table(  $home, $solar_capacity, $battery_capacity, 
                                                $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                                $battery_icon_class, $solar, $grid_staus   );
        }
        $output .= '</table>';

        return $output;
    }

    public function print_row_table(    $home, $solar_capacity, $battery_capacity, 
                                        $solar_yesterday, $grid_yesterday, $consumed_yesterday,
                                        $battery_icon_class, $solar, $grid_staus   )
    {
        $battery_icon_class = '<i class="' . $battery_icon_class . '"></i>';

        if (stripos($param_value, "yes") !== false)
        {
            // the 2 strings are equal. So it means a Yes! so colour it Green
            $param_value = '<font color="green">' . $param_value;
        }
        elseif (stripos($param_value, "no") !== false)
        {
            $param_value = '<font color="red">' . $param_value;
        }
        else
        {
            // no class applied so do nothing
        }

        $returnstring =
        '<tr>' .
            '<td>' . $home .                                            '</td>' .
            '<td>' . $solar_capacity .                                  '</td>' .
            '<td>' . $battery_capacity .                                '</td>' .
            '<td>' . '<font color="green">' . $solar_yesterday .        '</td>' .
            '<td>' . '<font color="red">' .   $grid_yesterday .         '</td>' .
            '<td>' . $consumed_yesterday .      '</td>' .
            '<td>' . $battery_icon_class .      '</td>' .
            '<td>' . '<font color="green">' . $solar .                  '</td>' .
            '<td>' . $grid_staus .              '</td>' .
        '</tr>';
        return $returnstring;
    }

    public function my_api_tools_render()
    {
        // this is for rendering the API test onto the sritoni_tools page
        ?>
            <h1> Input index of config and Click on desired button to test</h1>
            <form action="" method="post" id="mytoolsform">
                <input type="text"   id ="config_index" name="config_index"/>
                <input type="submit" name="button" 	value="Get_Studer_Readings"/>
                <input type="submit" name="button" 	value="Get_Shelly_Device_Status"/>
            </form>


        <?php

        switch ($_POST['button'])
        {
            case 'Get_Studer_Readings':
                $config_index = sanitize_text_field( $_POST['config_index'] );

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
        }
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
        $Rb = 0.03;       // value of resistance from DC junction to Battery terminals
       
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
       
          // also good time to compensate for IR drop.
          // Actual voltage is smaller than indicated, when charging 
          $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
        }
        else
        {
          // current is -ve so battery is discharging so arrow is up and icon color shall be red
          $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
          $battery_charge_animation_class = "arrowSliding_sw_ne";
       
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
       $battery_vdc_state_json = get_user_meta($current_user_ID, "json_battery_voltage_state", true);
       $battery_vdc_state      = json_decode($battery_vdc_state_json, true);
       
       // select battery icon based on charge level
        switch(true)
        {
          case ($battery_voltage_vdc < $config['battery_vdc_state']["25p"] ):
            $battery_icon_class = "fa fa-3x fa-regular fa-battery-quarter";
          break;
       
          case ($battery_voltage_vdc >= $config['battery_vdc_state']["25p"] && 
                $battery_voltage_vdc <  $config['battery_vdc_state']["50p"] ):
            $battery_icon_class = "fa fa-3x fa-regular fa-battery-half";
          break;
       
          case ($battery_voltage_vdc >= $$battery_vdc_state["50p"] && $battery_voltage_vdc < $battery_vdc_state["75p"] ):
            $battery_icon_class = "fa fa-3x fa-regular fa-battery-three-quarters";
          break;
       
          case ($battery_voltage_vdc >= $battery_vdc_state["75p"] ):
            $battery_icon_class = "fa fa-3x fa-regular fa-battery-full";
          break;
        }
       
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
       
       
        // update the object with the fontawesome cdn from Studer API object
        $studer_readings_obj->fontawesome_cdn             = $studer_api->fontawesome_cdn;
       
        return $studer_readings_obj;
       }
}