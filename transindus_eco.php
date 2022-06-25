<?php
/**
*Plugin Name: Trans Indus Studer WebApp
*Plugin URI:
*Description: Trans Indus Web Application to display STuder Settings
*Version: 2022062300
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: transindus_eco
*Domain Path:
*/
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

// define a unique constant to check inside of config
define('MyConst', TRUE);

require_once(__DIR__."/studer_api.php");         // contains studer api class

// read the configuration parameters securely into an array
$config = include( __DIR__."/transindus_eco_config.php");

if ( is_admin() )
{
 // Add admin actions here
 add_action('admin_menu', function() use ($config) 
                                                    { 
                                                        add_my_menu($config);            
                                                    }
            );

 // add support for SVG file types
 // add_filter('upload_mimes', 'add_file_types_to_uploads');

}

// register shortcode for pages. This is for showing the page with studer readings
add_shortcode( 'transindus-studer-readings',  'studer_readings_page_render');

// add action to load the javascripts on non-admin page
// add_action( 'wp_enqueue_scripts', 'add_my_scripts' );

// add action for the ajax handler on server side.
// the 1st argument is in update.js, action: "get_studer_readings"
// the 2nd argument is the local callback function as the ajax handler
// add_action('wp_ajax_get_studer_readings', 'ajax_studer_readings_handler');

function add_my_menu($config)
{
    // add submenu page for testing various application API needed
    add_submenu_page(
        'tools.php',	                    // parent slug
        'My API Tools',                     // page title
        'My API Tools',	                    // menu title
        'manage_options',	                // capability
        'my-api-tools',	                    // menu slug
        function() use ($config)            // callback
            { 
                my_api_tools_render($config);            
            }
    );
}

function my_api_tools_render($config)
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
            $studer_readings_obj = get_studer_readings($config, $config_index);
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
 *  This is the callback for the shortcode with same name.
 *  It displays the readings from the Studer system for each home
 */
function studer_readings_page_render()
{
    // $script = '"' . $config['fontawesome_cdn'] . '"';
    // $output = '<script src="' . $config['fontawesome_cdn'] . '"></script>';
    $output = '';

    $output .= '
    <style>
       .rediconcolor {color:red;}

       .greeniconcolor {color:green;}
    </style>';
    $output .= '
    <div class="container-fluid">
        <div class="row">
            <div class="col">' . 
                'Home' . ' 
            </div>
            <div class="col">' . 
                'Solar KWH Yesterday' . ' 
            </div>
            <div class="col">' . 
                'Grid KWH yesterday' . ' 
            </div>
            <div class="col">' . 
                'Consumed KWH Yesterday' . ' 
            </div>
            <div class="col">' . 
                'Battery Vdc Now' . ' 
            </div>
            <div class="col">' . 
                'Solar KW Now' . ' 
            </div>
        </div>
    </div>

    ';

    return $output;
}

/**
** This function returns an object that comprises data read form user's installtion
*  @param array:$config is the configuration read in from config file
*  @param int:$user_index  is the numeric index to denote a particular installtion
*  @return object:$studer_readings_obj
*/
function get_studer_readings(array $config, int $user_index): ?object
{
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
                        "userRef"       =>  3080,   // Energy from Grid Yesterday
                        "infoAssembly"  => "Master"
                    ),
                array(
                        "userRef"       =>  11011,   // Energy from Battery Yesterday
                        "infoAssembly"  => "1"
                    ),
                array(
                        "userRef"       =>  11011,   // Energy from Battery Yesterday
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

 // calculate the current into/out of battery
 $battery_charge_adc  = round($solar_pv_adc + $inverter_current_adc, 1); // + is charge, - is discharge
 $pbattery_kw         = round($battery_voltage_vdc * $battery_charge_adc * 0.001, 2); //$psolar_kw - $pout_inverter_ac_kw;


 // inverter's output always goes to load never the other way around :-)
 $inverter_pout_arrow_class = "fa fa-long-arrow-right fa-rotate-45 rediconcolor";

 // conditional class names for battery charge down or up arrow
 if ($battery_charge_adc > 0.0)
 {
   // current is positive so battery is charging so arrow is down and to left. Also arrow shall be green to indicate charging
   $battery_charge_arrow_class = "fa fa-long-arrow-down fa-rotate-45 rediconcolor";
   // battery animation class is from ne-sw
   $battery_charge_animation_class = "arrowSliding_ne_sw";

   // also good time to compensate for IR drop
   $battery_voltage_vdc = round($battery_voltage_vdc + abs($inverter_current_adc) * $Ra - abs($battery_charge_adc) * $Rb, 2);
 }
 else
 {
   // current is -ve so battery is discharging so arrow is up and icon color shall be red
   $battery_charge_arrow_class = "fa fa-long-arrow-up fa-rotate-45 greeniconcolor";
   $battery_charge_animation_class = "arrowSliding_sw_ne";

   // also good time to compensate for IR drop
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
   case ($battery_voltage_vdc < $battery_vdc_state["25p"] ):
     $battery_icon_class = "fa fa-3x fa-battery-quarter fa-rotate-270";
   break;

   case ($battery_voltage_vdc >= $battery_vdc_state["25p"] && $battery_voltage_vdc < $$battery_vdc_state["50p"] ):
     $battery_icon_class = "fa fa-3x fa-battery-half fa-rotate-270";
   break;

   case ($battery_voltage_vdc >= $$battery_vdc_state["50p"] && $battery_voltage_vdc < $battery_vdc_state["75p"] ):
     $battery_icon_class = "fa fa-3x fa-battery-three-quarters fa-rotate-270";
   break;

   case ($battery_voltage_vdc >= $battery_vdc_state["75p"] ):
     $battery_icon_class = "fa fa-3x fa-battery-full fa-rotate-270";
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
