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
                                array $lat_long_array = [12.83463, 77.49814],
                                float $utc_offset = 5.5 )
    {
        $this->panel_array_info     =   $panel_array_info;
        $this->panel_kw_peak        =   $panel_array_info[0];   // peak power rating of panel array
        $this->panel_azimuth_deg    =   $panel_array_info[1];   // azimuth degrees from South. East is +90 and West is -90
        $this->panel_slope_deg      =   $panel_array_info[2];   // slope of panel with horizontal
        $this->panel_efficiency     =   $panel_array_info[3];   // Fractional efficiency

        $this->lat_deg              =   $lat_long_array[0];
        $this->lat_rad              =   $lat_long_array[0] * pi()/180;

        $this->long_deg             =   $lat_long_array[1];
        $this->long_rad              =  $lat_long_array[1] * pi()/180;

        $this_utc_offset            =   $utc_offset;    // this defaults to 5.5h or 5h 30m
        $this->timezone             =   new DateTimeZone('Asia/Kolkata');

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
        $this->delta_deg            =   $this->delta_rad * 180 / pi();

        $this->sunrise              =   $this->sunrise();

        $this->sunset              =   $this->sunset();

    }

    public function est_power()
    {
        $panel_efficiency = $this->panel_efficiency;

        $est_solar_kw   = $panel_efficiency * $this->panel_kw_peak * $this->reductionfactor();

        if ($est_solar_kw < 0 ) return 0;

        return $est_solar_kw;
    }



    /**
     * 
     */
    public function now()
    {
        $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

        return $now;
    }



    /**
     * 
     */
    public function days_into_year()
    {
        // get a new datetime object for 1st midnight GMT
        $year_begin = new DateTime();

        // now set the timezone to the local one
        $year_begin->setTimezone($this->timezone);

        $year_begin->setDate($year_begin->format('Y'), 1, 1);     
        $year_begin->setTime(0, 0, 0);  

        $now = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));

        $datediff = date_diff($now, $year_begin);

        $d = $datediff->format('%a');

        return $d;
    }



    /**
     * 
     */
    public function B_rad()
    {
        $B_rad = 360/365*($this->d - 81) * pi()/180; 

        return $B_rad;
    }

    /**
     * 
     */
    public function eot()
    {
        $B_rad  =   $this->B_rad;
        $eot = 9.87 * sin(2 * $B_rad) - 7.53 * cos($B_rad) - 1.5 * sin($B_rad);

        return $eot;
    }

    /**
     * 
     */
    public function hra_rad()
    {
        // correct time for longitude and eot in minutes
        // The longitude of place varies from local timezone as the longitudes are slightly different.
        // The time correction also due to fluctuations in Earth's orbit etc.
        $time_correction_factor = round(4 * ($this->long_deg - $this->long_time_zone_deg) + $this->eot ,  0);

        $this->time_correction_factor = $time_correction_factor;
        
        $tcf = $time_correction_factor . " minutes";

        $local_solar_time = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $lt_timestamp = $local_solar_time->getTimestamp();

        $lst_timestamp = $lt_timestamp + $time_correction_factor * 60;

        $lst = new DateTime('NOW', new DateTimeZone('Asia/Kolkata'));
        $lst->setTimestamp($lst_timestamp);

        $formatted_lst = $local_solar_time->format('H:i:s');

        $arr = explode(":", $formatted_lst);

        $hours = $arr[0] + $arr[1] / 60;

        // calculate hour angle based on local solar time. Hour angle is positive in AM and negative in PM and 0 at local solar noon
        // This is the book's convention. The PV website is opposite to this.
        $hra = 15 * (12 - $hours);

        $this->hra_degs = $hra;

        $hra_rad = $hra * pi()/180;

        return $hra_rad;
    }

    /**
     * 
     */
    public function delta_rad()
    {
        //  calculate declination based on days from start of year. The declination is 23.5deg on June21 and 0 on March 21st.
        // Check for that. It is a sinewave inbetween
        $delta_deg = 23.5 * cos( 360/365*($this->d - 172)* pi() / 180 );

        $delta_rad = $delta_deg * pi()/180;

        return $delta_rad;
    }

    /**
     *  This is from Equation 3.3 of book on page 74. by S. P. Sukhatme
     */
    public function reductionfactor()
    {
        // calculate elevation angle of the Sun from the Horizon
        $delta_rad  =       $this->delta_rad;
        $lat_phi_rad    =   $this->lat_rad;
        $hra_rad    =       $this->hra_rad;

        // panel tilt to horizon
        $panel_beta_rad =   $this->panel_slope_deg      * pi()/180;

        // panel facing direction whose Azimuth from South is. Convention is +90 if facing East and -90 if facing West and 0 if South
        $panel_gamma_rad  = $this->panel_azimuth_deg    * pi()/180;

        // sun's zenith angle in degrees
        $zenith_theta_s_deg = 180 / pi() * acos(    sin($lat_phi_rad) * sin($delta_rad) + 
                                                    cos($lat_phi_rad) * cos($delta_rad) * cos($hra_rad)
                                                );
        $zenith_theta_s_rad = $zenith_theta_s_deg * pi() / 180;

        

        // Sun's elevation
        $alpha_rad = asin(sin($delta_rad) * sin($lat_phi_rad) + cos($delta_rad) * cos($lat_phi_rad) * cos($hra_rad));

        // Sun's Azimuth angle of the SUn from the North. Ideally it should be 90 +- 23.5 deg
        $theta_rad =    acos( ( sin($delta_rad) * cos($lat_phi_rad) - 
                                cos($delta_rad) * sin($lat_phi_rad) * cos($hra_rad) ) / cos($alpha_rad) ) ;
        $this->theta_rad = $theta_rad;
        $this->alpha_rad = $alpha_rad;

        $this->sun_azimuth_deg      = round( $theta_rad * 180 / pi(), 1);
        $this->sun_elevation_deg    = round( $alpha_rad * 180 / pi(), 1);
        $this->declination_deg      = round( $delta_rad * 180 / pi(), 1);
        $this->zenith_theta_s_deg   = round( $zenith_theta_s_deg, 1);



        $reductionfactor =      sin($lat_phi_rad) * (   sin($delta_rad) * cos($panel_beta_rad) + 
                                                        cos($delta_rad) * cos($panel_gamma_rad) * cos($hra_rad) * sin($panel_beta_rad)
                                                    ) 
                            +   cos($lat_phi_rad) * (   cos($delta_rad) * cos($hra_rad) * cos($panel_beta_rad) - 
                                                        sin($delta_rad) * cos($panel_gamma_rad) * sin($panel_beta_rad) 
                                                    ) 
                            +   cos($delta_rad) * sin($panel_gamma_rad) * sin($hra_rad) * sin($panel_beta_rad);

        return $reductionfactor;
    }

    /**
     * 
     */
    public function sunrise()
    {
        $delta_rad      =       $this->delta_rad;
        $lat_phi_rad    =       $this->lat_rad;

        $time_correction_factor = round(4 * ($this->long_deg - $this->long_time_zone_deg) + $this->eot ,  0);

        $sunrise = 12 - (1 / 15) * (180 / pi()) * acos(-1 * tan($lat_phi_rad) * tan($delta_rad) ) - $time_correction_factor/60;

        return $sunrise;
    }

    /**
     * 
     */
    public function sunset()
    {
        $delta_rad  =       $this->delta_rad;
        $lat_phi_rad    =       $this->lat_rad;
        
        $time_correction_factor = round(4 * ($this->long_deg - $this->long_time_zone_deg) + $this->eot ,  0);

        $sunset = 12 + (1 / 15) * (180 / pi()) * acos(-1 * tan($lat_phi_rad) * tan($delta_rad) ) - $time_correction_factor/60;

        return $sunset;
    }
}

