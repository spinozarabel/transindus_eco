<?php

// define a unique constant to check inside of config
define('MyConst', TRUE);

require_once(__DIR__."/class_my_mqtt.php");

$mystuder_readings_json_string = shell_exec( "python3 mystuder.py" );

$test = new my_mqtt();

$topic = "iot_data_over_lan/studerxcomlan";

$message = "Hello Studer";

$test->mqtt_publish_with_qos_0( $topic, $mystuder_readings_json_string );

