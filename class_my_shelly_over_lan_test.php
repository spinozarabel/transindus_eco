<?php

declare(strict_types=1);

defined( 'MyConst' ) or die( 'No script kiddies please!' );


/**
 *
 */

/**
 * The test  class.
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

 

 // require __DIR__ . '/vendor/autoload.php';
 require __DIR__ . '/shelly_cloud_api.php';
 
 

class my_shelly_over_lan_test 
{

    // configuration variable
    public  $config;

    public $message;

    public function __construct()
    {
        $this->config = $this->get_config();
    }



    public  function get_config()
    {
        $config = include( __DIR__."/transindus_eco_config.php");

        // return the array of the account holder directly as there is only one account in the file
        return $config;
    }

    public  function init()
    {
        $this->config = $this->get_config();
    }



}

$test = new my_shelly_over_lan_test();

$user_index = 0;

// Make an API call on the Shelly UNI device
$config = $test->config;

$shelly_server_uri  = $config['accounts'][$user_index]['shelly_server_uri'];
$shelly_auth_key    = $config['accounts'][$user_index]['shelly_auth_key'];
$shelly_device_id   = $config['accounts'][$user_index]['shelly_device_id_plus_addon'];
$ip_static_shelly   = $config['accounts'][$user_index]['ip_shelly_addon'];


$shelly_api    =  new shelly_cloud_api( $shelly_auth_key, $shelly_server_uri, $shelly_device_id, $ip_static_shelly );

// this is $curl_response.
$shelly_api_device_response = $shelly_api->get_shelly_device_status_over_lan();

print_r($shellypro3em_acin_3p_emdata_obj);