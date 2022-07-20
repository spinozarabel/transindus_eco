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

define('TRANSINDUS_ECO_VERSION', '1.0');

require_once(__DIR__."/class_transindus_eco.php");         // contains the main class

// instantiate the class for head start admission
$transindus_eco           = new class_transindus_eco();

$user_readings_array = [];
  
add_action ( 'shellystuder_task_hook', [$transindus_eco, 'shellystuder_cron_exec'] );

// wait for all plugins to be loaded before initializing our code
add_action('plugins_loaded', 'this_plugin_init');

// add action to load the javascripts on non-admin page
add_action( 'wp_enqueue_scripts', 'add_my_scripts' );

// add action for the ajax handler on server side.
// the 1st argument is in update.js, action: "get_studer_readings"
// the 2nd argument is the local callback function as the ajax handler
add_action('wp_ajax_nopriv_my_solar_update', [$transindus_eco, 'ajax_my_solar_update_handler'] );


add_filter( 'cron_schedules',  'shelly_studer_add_new_cron_interval' );

if (!wp_next_scheduled('shellystuder_task_hook')) 
{
    wp_schedule_event( time(), 'sixty_seconds', 'shellystuder_task_hook' );
}


/**
 * 
 */
function shelly_studer_add_new_cron_interval( $schedules ) 
{ 
    $schedules['sixty_seconds'] = array(
                                    'interval' => 1*60,
                                    'display'  => esc_html__( 'Every 60 seconds' ),
                                    );
    return $schedules;
}


/**
 *  Instantiate the main class that the plugin uses
 *  Setup webhook to be cauught when Order is COmpleted on the WooCommerce site.
 *  Setup wp-cron schedule and eveent for hourly checking to see if SriToni Moodle Accounts have been created
 *  Setup wp-cron schedule and event for hourly checking to see if user has replied to ticket with payment UTR
 */
function this_plugin_init()
{
  // add_action('init','custom_login');
}


/**
*   register and enque jquery scripts with nonce for ajax calls. Load only for desired page
*   called by add_action( 'wp_enqueue_scripts', 'add_my_scripts' );
*/
function add_my_scripts($hook)
// register and enque jquery scripts wit nonce for ajax calls
{
    // if not the intended page then return and do nothing.
    if ( ! is_page( 'mysolar' ) ) return;

    // https://developer.wordpress.org/plugins/javascript/enqueuing/
    //wp_register_script($handle            , $src                                 , $deps         , $ver, $in_footer)
    wp_register_script('my_solar_app_script', plugins_url('update.js', __FILE__), array('jquery'),'1.0', true);

    wp_enqueue_script('my_solar_app_script');

    $my_solar_app_nonce = wp_create_nonce('my_solar_app_script');
    // note the key here is the global my_ajax_obj that will be referenced by our Jquery in update.js
    //  wp_localize_script( string $handle,       string $object_name, associative array )
    wp_localize_script('my_solar_app_script', 'my_ajax_obj', array(
                                                                   'ajax_url' => admin_url( 'admin-ajax.php' ),
                                                                   'nonce'    => $my_solar_app_nonce,
                                                                   )
                      );
}

function ajax_my_solar_update_handler($transindus_eco, $user_readings_array)
{
    // Ensures nonce is correct for security
    check_ajax_referer('my_solar_app_script');

    $toggleGridSwitch = $_POST['toggleGridSwitch'];

    // sanitize the POST data
    $toggleGridSwitch = sanitize_text_field($toggleGridSwitch);
    error_log("toggleGridSwitch Value: " . $toggleGridSwitch);

    // get the Shelly Grid Switch areadings
    // get my user index knowing my login name
    $current_user = wp_get_current_user();
    $wp_user_name = $current_user->user_login;

    $config       = $transindus_eco->config;


    // Now to find the index in the config array using the above
    $user_index = array_search( $wp_user_name, array_column($config['accounts'], 'wp_user_name')) ;

    if ($user_index === false)
      {
        return "You DO NOT have a Studer Install";
      }

    $data = $user_readings_array[$user_index];

    error_log(print_r($data, true));

	wp_send_json($data);

	// finished now die
    wp_die(); // all ajax handlers should die when finished
}

