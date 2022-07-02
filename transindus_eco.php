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

// wait for all plugins to be loaded before initializing our code
add_action('plugins_loaded', 'this_plugin_init');


add_filter( 'cron_schedules',  'shelly_studer_add_new_cron_interval' );
/*
if (!wp_next_scheduled('shelly_studer_task_hook')) 
{
    wp_schedule_event( time(), 'sixty_seconds', 'shelly_studer_task_hook' );
}
*/

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

  // instantiate the class for head start admission
  $transindus_eco           = new class_transindus_eco();
  
  //add_action ( 'shellystuder_task_hook', [$transindus_eco, 'shellystuder_cron_exec'] );
}

