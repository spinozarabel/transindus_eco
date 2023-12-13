<?php
/*  Created by Madhu Avasarala 06/24/2022
*   all data returned as objects instead of arrays in json_decode
*/

// if directly called die. Use standard WP and Moodle practices
if (!defined( "ABSPATH" ) && !defined( "MOODLE_INTERNAL" ) )
    {
    	die( 'No script kiddies please!' );
    }

// class definition begins
class openweathermap_api
{
    const VERBOSE     = false;

    public function __construct( string $lat, string $lon, string $appid, int $cnt = null)
    {
      $this->verbose  = self::VERBOSE;

      $this->appid       = $appid;  // Auth key to access account
		  $this->lat        = $lat;     // The server uri can be obtained 
      $this->lon        = $lon;
      $this->server_uri = 'https://api.openweathermap.org/data/2.5';
      $this->cnt        =  $cnt ??  4;      // used to be 3. Changed on 10/20/2020
    }       // end construct function

    /**
     *  @return obj $cloudiness_forecast
     *  Queries the weather forecast using API. 
     *  $cloudiness_forecast->it_is_a_cloudy_day  is a boolean variable with no weighting
     *  $cloudiness_forecast->it_is_a_cloudy_day_weighted_average  is a boolean variable using weighting for midday
     *  $cloudiness_forecast->cloudiness_average_percentage  Percentage average cloudiness with no weighting
     *  $cloudiness_forecast->cloudiness_average_percentage_weighted  Weighted percentage average cloudiness
     */
    public function forecast_is_cloudy()
    {
      // initialize stdclass object that is to be returned by the function
      $cloudiness_forecast = new stdClass;

      // get the details using API from external vendor service
      $forecast = $this->get_weather_forecast();

      // initialize the variale that accumuates the cloudiness percentage over 'cnt' number of periods
      $clouds_all = 0;

      // Intilaize the variabke that acculuates cloudiness weighted by time of day
      $clouds_all_weighted = 0;

      // initialize the divider variable to calculate the wighted average
      $divider_weighted = 0.01;   // prevent division by 0

      foreach ($forecast->list as $key => $weather) 
      {
        // accumulate the cloudiness percentage for all the intervals in the list
         $clouds_all += $weather->clouds->all;

         // get the date text for each of the periods in the list
         $dt_txt = $weather->dt_txt;
         
         switch(true)
         {
          case ( stripos($dt_txt, "06:00:00") !== false ):
            // Period is from 6AM to 9AM so weight the cloudiness here by 10% since the solar here is not that important
            $clouds_all_weighted += $weather->clouds->all * 0.5;
            $divider_weighted += 0.5;

          break;

          case ( stripos($dt_txt, "09:00:00") !== false ):
            // Period is from 9AM to 12 Noon so weight the cloudiness here by 10% since the solar here is not that important
            $clouds_all_weighted += $weather->clouds->all * 1.0;
            $divider_weighted += 1.0;
          break;

          case ( stripos($dt_txt, "12:00:00") !== false ):
            // Period is from 12 Noon to 3PM so weight the cloudiness here by 10% since the solar here is not that important
            $clouds_all_weighted += $weather->clouds->all * 1.0;
            $divider_weighted += 1.0;
          break;

          case ( stripos($dt_txt, "15:00:00") !== false ):
            // Period is from 3pm TO 6pm so weight the cloudiness here by 10% since the solar here is not that important
            $clouds_all_weighted += $weather->clouds->all * 0.5;
            $divider_weighted += 0.5;
          break;
         }
      }

      // Divide to get the average cloudiness over the day starting from 0600 and ending after 3h x cnt or by 1800
      $cloudiness_average_percentage = $clouds_all /  $forecast->cnt;

      // Calculate the average weighted cloudpercentage as follows
      $cloudiness_average_percentage_weighted = $clouds_all_weighted / $divider_weighted;

      if ( $cloudiness_average_percentage > 50 )
      {
        $it_is_a_cloudy_day = true;
      }
      else 
      {
        $it_is_a_cloudy_day = false;
      }

      if ( $cloudiness_average_percentage_weighted > 50 )
      {
        $it_is_a_cloudy_day_weighted_average = true;
      }
      else 
      {
        $it_is_a_cloudy_day_weighted_average = false;
      }

      // get sunrise and sunset unix time stamps
      $cloudiness_forecast->sunrise_timestamp = $forecast->city->sunrise;
      $cloudiness_forecast->sunset_timestamp  = $forecast->city->sunset;

      $cloudiness_forecast->it_is_a_cloudy_day                      = $it_is_a_cloudy_day;
      $cloudiness_forecast->it_is_a_cloudy_day_weighted_average     = $it_is_a_cloudy_day_weighted_average;
      $cloudiness_forecast->cloudiness_average_percentage           = $cloudiness_average_percentage;
      $cloudiness_forecast->cloudiness_average_percentage_weighted  = $cloudiness_average_percentage_weighted;

      // error_log(print_r($cloudiness_forecast, true));

      return $cloudiness_forecast;
    }


    /**
     * @return object:$curlResponse if not a valid response, a null object is returned
     */
    public function get_weather_forecast(): ?object
    {
      // parameters for query string
      $params     = array
                          (
                              'lat'   => $this->lat,
                              'lon'   => $this->lon,
                              "appid" => $this->appid  ,
                              "cnt"   => $this->cnt,
                          );

      $headers  = [];

      $endpoint = $this->server_uri . "/forecast";

      // already json decoded into object
      $curlResponse   = $this->getCurl($endpoint, $headers, $params);
      
      if ( $curlResponse->cod = '200' )
      {
          return $curlResponse;
      }
      else
      {
          if ($this->verbose)
          {
              error_log( "This is the response while querying for openweathermap weather" . print_r($curlResponse, true) );
          }
          return null;
      }
    }


    /**
    * 
    * @return object:$curlResponse if not a valid response, a null object is returned
    */
    public function get_current_weather(): ?object
    {
      // parameters for query string
      $params     = array
                          (
                              'lat'   => $this->lat,
                              'lon'   => $this->lon,
                              "appid" => $this->appid  ,
                          );

      $headers  = [];

      $endpoint = $this->server_uri . "/weather";

      // already json decoded into object
      $curlResponse   = $this->getCurl($endpoint, $headers, $params);

      if ( $curlResponse->cod = '200' )
          {
              return $curlResponse;
          }
      else
          {
              if ($this->verbose)
              {
                  error_log( "This is the response while querying for openweathermap weather" . print_r($curlResponse, true) );
              }
              return null;
          }
    }


    protected function postCurl ($endpoint, $headers = [], $params = []) 
    {
      $postFields = json_encode($params);
      array_push($headers,
         'Content-Type: application/json', 
         'Content-Length: ' . strlen($postFields));

      // check if anything exists in $params. If so make a query string out of it
      if ($params)
      {
         if ( count($params) )
         {
             $postFields = http_build_query($params);
         }
      }

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $endpoint);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
      // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

      $returnData = curl_exec($ch);
      $this->verbose ? error_log("curl response Shelly Device" . print_r($returnData,true)) : false;

      curl_close($ch);

      if ( !empty( $returnData ) ) 
      {
        return json_decode($returnData, false);     // returns object not array
      }
      else
      {
        return NULL;
      }
    }

    /**
    *  @param endpoint is the full path url of endpoint, not including any parameters
    *  @param headers is the array conatining a single item, the bearer token
    *  @param params is the optional array containing the get parameters
    */
    protected function getCurl ($endpoint, $headers, $params = [])
    {
        // check if anything exists in $params. If so make a query string out of it
       if ($params)
        {
           if ( count($params) )
           {
               $endpoint = $endpoint . '?' . http_build_query($params);
           }
        }
       $ch = curl_init();
       curl_setopt($ch, CURLOPT_URL, $endpoint);
       curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
       curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1); // verifies the authenticity of the peer's certificate
       curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2); // verify the certificate's name against host
       $returnData = curl_exec($ch);

       $this->verbose ? error_log("getCurl resposne from Openweathermap" . print_r($returnData,true)) : false;
       curl_close($ch);

       if ( !empty( $returnData ) ) 
        {
          return json_decode($returnData, false);     // returns object not array
        }
        else
        {
          return NULL;
        }
    }
}
