<?php
$obj = [];

$arg1 = (int) 1;
$arg2 = (float) 5.5;
$arg3 = (string) "False";

$obj['arg1'] = $arg1;
$obj['arg2'] = $arg2;
$obj['arg3'] = $arg3;


$args = json_encode($obj);

print "json: $args";

$cmd1 = "/usr/bin/python3 /home/madhu/github/transindus_eco/test.py";

 
$command = $cmd1 . " " . escapeshellarg($args);

$mystuder_readings_json_string = shell_exec( $command );

print $mystuder_readings_json_string;