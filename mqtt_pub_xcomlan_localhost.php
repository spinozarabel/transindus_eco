<?php

// define a unique constant to check inside of config
define('MyConst', TRUE);

require_once(__DIR__."/class_my_mqtt.php");

// execute the python code to get data from XCOM-LAN as a JSON string
$mystuder_readings_json_string = shell_exec( "python3 mystuder.py" );

// open a MQTT channel to localhost at 1883 with no authentication
$test = new my_mqtt();

$topic = "iot_data_over_lan/studerxcomlan";

$retain = true;

$clientId = 'StuderXcomLanLocalPub';

// publish the json string obtained from xcom-lan studer readings as the message
$test->mqtt_pub_local_qos_0( $topic, $mystuder_readings_json_string, $retain, $clientId );

