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
      $this->cnt        =  $cnt ??  3;
    }       // end construct function

    /**
     * 
     */
    public function forecast_is_cloudy()
    {
      $cloudiness_forecast = new stdClass;

      $forecast = $this->get_weather_forecast();

      $clouds_all = 0;

      foreach ($forecast->list as $key => $weather) 
      {
         $clouds_all += $weather->clouds->all;
      }

      $cloudiness_average_percentage = $clouds_all /  $forecast->cnt;

      if ( $cloudiness_average_percentage > 50 )
      {
        $it_is_a_cloudy_day = true;
      }
      else 
      {
        $it_is_a_cloudy_day = false;
      }
      $cloudiness_forecast->it_is_a_cloudy_day            = $it_is_a_cloudy_day;
      $cloudiness_forecast->cloudiness_average_percentage  = $cloudiness_average_percentage;

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
