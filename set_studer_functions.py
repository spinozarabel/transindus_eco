#! /usr/bin/env python3

from xcom_proto import XcomP as param
from xcom_proto import XcomC
from xcom_proto import XcomRS232
from xcom_proto import XcomLANTCP

import json

import time

import sys


# Get the current timestamp in seconds since the Unix epoch.
timestamp_xcomlan_call = time.time()

# Get the only argumebt sent, the JSON encoded array.
array_as_json_string = sys.argv[1]

# decode the JSON string back into the array as sent from PHP destination
studer_settings_array = json.loads(array_as_json_string)

with XcomLANTCP(port=4001) as xcom:
    xcom.setValue(param.PARAMS_SAVED_IN_FLASH, 0) # disable writing to flash

    for key in studer_settings_array:                                   # loop through the given array
        if key == "CHARGER_ALLOWED":                                    # if key is CHARGER_ALLOWED
            charger_allowed = studer_settings_array["CHARGER_ALLOWED"]  # get value to be used from array
            if charger_allowed == 0  or  charger_allowed == 1:          # check if value is in bounds
                xcom.setValue(param.CHARGER_ALLOWED, charger_allowed)   # set the value in Studer
        elif key == "BATTERY_CHARGE_CURR":
            amps = studer_settings_array["BATTERY_CHARGE_CURR"]
            if amps <= 40 and amps >= 0:
                xcom.setValue(param.BATTERY_CHARGE_CURR, amps)
        else:
            print("key value does not match any - is equal to:", key, studer_settings_array[key] ) 