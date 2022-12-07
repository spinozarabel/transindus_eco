<?php

/*  Created by Madhu Avasarala 06/24/2022
*   all data returned as objects instead of arrays in json_decode
*   https://www.pveducation.org/pvcdrom/properties-of-sunlight/solar-time
*/

// if directly called die. Use standard WP and Moodle practices
if (!defined( "ABSPATH" ) && !defined( "MOODLE_INTERNAL" ) )
    {
    	die( 'No script kiddies please!' );
    }

// class definition begins
class solar_calculation
{
    public function __construct(array $panel_array_info, 
                                array $lat_long_array,
                                float $utc_offset)
    {
        $this->panel_array_info     =   $panel_array_info;
        $this->panel_kw_peak        =   $panel_array_info[0];
        $this->panel_azimuth_deg    =   $panel_array_info[1];
        $this->panel_slope_deg      =   $panel_array_info[2];

        $this->lat_deg              =   $lat_long_array[0];
        $this->long_deg             =   $lat_long_array[1];

        $this->lat_rad              =   $lat_long_array[0] * pi()/180;

        $this_utc_offset            =   $utc_offset;
        $this->timezone             =   'Asia/Kolkata';

        $this->now                  =   $this->now();
        $this->d                    =   $this->days_into_year();
        $this->B_rad                =   $this->B_rad();

        // 15 deg of longitude difference is 1 hour UTC offset
        $this->long_time_zone_deg   =   15 * $utc_offset; 

        // time correction due to orbit eccentricity and tilt precession
        $this->eot                  =   $this->eot();

        // Hour angle. 0 at Solar noon -ve in AM, + in PM varies from -90 to 0 to +90 sunrise to sunset
        $this->hra_rad              =   $this->hra_rad();

        // declination
        $this->delta_rad            =   $this->delta_rad();

    }

    public function est_power()
    {
        $efficiency = 0.93;

        $est_solar_kw   = $efficiency * $this->panel_kw_peak * $this->reductionfactor();

        if ($est_solar_kw < 0 ) return 0;

        return $est_solar_kw;
    }

    public function now()
    {
        date_default_timezone_set($this->timezone);

        $now = new DateTime();

        return $now;
    }

    public function days_into_year()
    {
        date_default_timezone_set($this->timezone);
        
        $year_begin = DateTime::createFromFormat('Y-m-d', '2022-01-01');

        $datediff = date_diff($this->now, $year_begin);

        $d = $datediff->format('%a');

        if ($d > 365) $d = $d-365;

        return $d;
    }

    public function B_rad()
    {
        $B_rad = 360/365*($this->d - 81) * pi()/180; 

        return $B_rad;
    }

    public function eot()
    {
        $B_rad  =   $this->B_rad;
        $eot = 9.87 * sin(2 * $B_rad) - 7.53 * cos($B_rad) - 1.5 * sin($B_rad);

        return $eot;
    }

    public function hra_rad()
    {
        // correct time for longitude and eot in minutes
        $time_correction_factor = round(4 * ($this->long_deg - $this->long_time_zone_deg) + $this->eot ,  0);
        
        $tcf = $time_correction_factor . " minutes";

        $local_solar_time = new DateTime($tcf);

        $formatted_lst = $local_solar_time->format('H:i:s');

        $arr = explode(":", $formatted_lst);

        $hours = $arr[0] + $arr[1]/60;

        // calculate hour angle based on time. Hour angle is negaitive in AM and positive in PM and 0 at local solar noon
        $hra = 15 * ($hours - 12);

        $hra_rad = $hra * pi()/180;

        return $hra_rad;
    }

    public function delta_rad()
    {
        //  calculate declination based on days from start of year
        $delta = -23.45 * cos(360/365 * ($this->d + 10) * pi()/180);

        $delta_rad = $delta * pi()/180;

        return $delta_rad;
    }

    public function reductionfactor()
    {
        // calculate elevation angle of the Sun from the Horizon
        $delta_rad  =       $this->delta_rad;
        $lat_rad    =       $this->lat_rad;
        $hra_rad    =       $this->hra_rad;

        // panel tilt to horizon
        $panel_beta_rad =   $this->panel_slope_deg      * pi()/180;

        // panel facing direction whose Azimuth from North is
        $panel_tsi_rad  =   $this->panel_azimuth_deg    * pi()/180;

        // Sun's elevation
        $alpha_rad = asin(sin($delta_rad) * sin($lat_rad) + cos($delta_rad) * cos($lat_rad) * cos($hra_rad));

        // calculate the Azimuth angle of the SUn from the North. Ideally it should be 90 +- 23.5 deg
        $theta_rad =    acos( ( sin($delta_rad) * cos($lat_rad) - 
                                cos($delta_rad) * sin($lat_rad) * cos($hra_rad) ) / cos($alpha_rad) ) ;

        $reductionfactor =  cos($alpha_rad) * sin($panel_beta_rad) * cos($panel_tsi_rad - $theta_rad) + 
                            sin($alpha_rad) * cos($panel_beta_rad);

        return $reductionfactor;
    }
}

