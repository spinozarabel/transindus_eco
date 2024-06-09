#! /usr/bin/env python3

from xcom_proto import XcomP as param
from xcom_proto import XcomC
from xcom_proto import XcomRS232
from xcom_proto import XcomLANTCP

import json

import time

# Get the current timestamp in seconds since the Unix epoch.
timestamp_xcomlan_call = time.time()

with XcomLANTCP(port=4001) as xcom:
    #solar_kw_now1           = xcom.getValue(param.PV_POWER, dstAddr=301)               # Solar power at Panel
    #solar_kw_now2           = xcom.getValue(param.PV_POWER, dstAddr=302)               # Solar power at Panel
    solar_kwh_today1        = xcom.getValue(param.PV_ENERGY_CURR_DAY, dstAddr=301)      # solar energy in KWH since midnight panel 1
    solar_kwh_today2        = xcom.getValue(param.PV_ENERGY_CURR_DAY, dstAddr=302)      # solar energy in KWH since midnight panel 2
    inverter_kwh_today      = xcom.getValue(param.AC_ENERGY_OUT_CURR_DAY)               # Inverter energy in KWH since midnight
    battery_voltage_xtender = xcom.getValue(param.BATT_VOLTAGE_XTENDER)			# raw batttery voltage 
    inverter_current        = xcom.getValue(param.DC_CURRENT_INVERTER)
    grid_kwh_today          = xcom.getValue(param.AC_ENERGY_IN_CURR_DAY)

    # xcom.setValue(param.PARAMS_SAVED_IN_FLASH, 0) # disable writing to flash
    # xcom.setValue(param.CHARGER_ALLOWED, 0)
    # xcom.setValue(param.BATTERY_CHARGE_CURR, 5)   # writes into RAM the float value for the Battery charge current supplied from GRID when GRID connected

    pv_current_now_1        = xcom.getValue(param.PV_CURRENT_NOW, dstAddr=301)       # DC from VT1 into DC junction
    pv_current_now_2        = xcom.getValue(param.PV_CURRENT_NOW, dstAddr=302)       # DC from VT2 into DC junction

    pv_current_now_total    = pv_current_now_1 + pv_current_now_2 # total PV DC current supplied into Battery interface
    solar_kwh_today         = solar_kwh_today1 + solar_kwh_today2 # Total solar energy supplied today from both panels
    

MyStuderData = {
    "pv_current_now_1":         pv_current_now_1,
    "pv_current_now_2":         pv_current_now_2,
    "pv_current_now_total":     pv_current_now_total,
    "inverter_current":         inverter_current,
    "battery_voltage_xtender":  battery_voltage_xtender,
    "timestamp_xcomlan_call":   timestamp_xcomlan_call,
    "inverter_kwh_today":       inverter_kwh_today,
    "solar_kwh_today":          solar_kwh_today,
    "grid_kwh_today":           grid_kwh_today
}

# convert to JSON
MyStuderDataJsonString = json.dumps(MyStuderData)

print(MyStuderDataJsonString)
