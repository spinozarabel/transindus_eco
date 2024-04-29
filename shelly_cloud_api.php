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
class shelly_cloud_api
{
    const VERBOSE     = false;

    public function __construct(    string $auth_key, 
                                    string $server_uri, 
                                    string $shelly_device_id,
                                    string $shelly_device_static_ip, 
                                    string $shelly_gen = "gen2",
                                    int $channel = 0 )
    {
      $this->verbose  = self::VERBOSE;

      $this->auth_key		          = $auth_key;        // Auth key to access account
	  $this->server_uri	              = $server_uri;      // The server uri can be obtained 
                                                          // on the same page where the authorization key is generated
      $this->shelly_device_id         = $shelly_device_id;
      $this->channel                  = $channel;         // channel of interest. Defaults to 0 if not specified
      $this->shelly_device_static_ip  = $shelly_device_static_ip;
      $this->shelly_gen               = $shelly_gen;      // Shelly device generation. Defaults to 2 if not specified

    }       // end construct function

    /**
     * @param string:$desired_state is "on" or 'off'
     */
    public function turn_on_off_shelly_switch( string $desired_state ) : ? object
    {
       // parameters for query string
      $params     = array
      (
          "channel"     => $this->channel           ,
          'turn'        => $desired_state           ,
          "id"          => $this->shelly_device_id  ,
          "auth_key"    => $this->auth_key          ,
      );
      
      $headers  = [];

      $endpoint = $this->server_uri . "/device/relay/control";

      // error_log( 'This is the Shelly API URI for turn-on-off:' . $endpoint );

      $curlResponse   = $this->postCurl($endpoint, $headers, $params);

      if ( $curlResponse->isok )
          {
              return $curlResponse;
          }
      else
          {
                error_log( "This is the response when turn ON_OFF of your Shelly device ID: $this->shelly_device_id " . print_r($curlResponse, true) );
              
                return null;
          }
    }


    /**
     * @param $desired_state is true or false 1 or 0 "true" or "false"
     */
    public function turn_on_off_shelly_switch_over_lan( $desired_state ) : ? object
    {
        // parse the desired state so end result is 1 or 0
        if ( $desired_state == "true" || $desired_state === true || $desired_state === 1 || strtolower($desired_state) === "on")
        {
            $desired_switch_state = "true";
        }
        elseif ( $desired_state == "false" || $desired_state === false || $desired_state === 0 || strtolower($desired_state) === "off" )
        {
            $desired_switch_state = "false";
        }
       // parameters for query string
      $params     = array
      (
          "id"          => $this->channel           ,
          'on'          => $desired_switch_state    ,
      );
      
      $headers  = [];

      $endpoint = $this->shelly_device_static_ip . "/rpc/Switch.Set";

      $curlResponse   = $this->getCurl($endpoint, $headers, $params);

      if ( $curlResponse )
          {
              return $curlResponse;
          }
      else
          {
                echo( "This is the response when turn ON_OFF of your Shelly device ID: $this->shelly_device_id " . print_r($curlResponse, true) );
              
                return null;
          }
    }


    /**
    * read status of Shelly Device using Shelly CLoud API
    * @return object:$curlResponse if not a valid response, a null object is returned
    */
    public function get_shelly_device_status_over_lan(): ? object
    {
        $shelly_gen = $this->shelly_gen;

        if ( $shelly_gen === 'gen2' )
        {
            $endpoint_addon = "/rpc/Shelly.GetStatus";
        }
        else
        {
            $endpoint_addon = "/status";
        }
      // parameters for query string
      $params     = [];

      $headers  = [];

      $endpoint = $this->shelly_device_static_ip . $endpoint_addon;

      // already json decoded into object
      $curlResponse   = $this->getCurl($endpoint, $headers, $params);

      if ( $curlResponse )
          {
              return $curlResponse;
          }
      else
          {
              if ($this->verbose)
              {
                  error_log( "Shelly device Cloud not responding or device offline" . print_r($curlResponse, true) );
              }
              return null;
          }
    }


    /**
    * read status of Shelly Device using Shelly CLoud API
    * @return object:$curlResponse if not a valid response, a null object is returned
    */
    public function get_shelly_device_status(): ? object
    {
      // parameters for query string
      $params     = array
                          (
                              "id"        => $this->shelly_device_id  ,
                              "auth_key"  => $this->auth_key          ,
                          );

      $headers  = [];

      $endpoint = $this->server_uri . "/device/status";

      // already json decoded into object
      $curlResponse   = $this->getCurl($endpoint, $headers, $params);

      if ( $curlResponse->isok && $curlResponse->data->online)
          {
              return $curlResponse;
          }
      else
          {
              if ($this->verbose)
              {
                  error_log( "Shelly device Cloud not responding or device offline" . print_r($curlResponse, true) );
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
      curl_reset($ch);

      curl_setopt($ch, CURLOPT_URL, $endpoint);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/x-www-form-urlencoded'));
      // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
      curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
      curl_setopt($ch, CURLOPT_TIMEOUT, 5);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

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
        // check if anything exists in $params. $this->verbose ? error_log("Shelly Switch ACIN State: $shelly1pm_acin_switch_status"): false;If so make a query string out of it
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

       $this->verbose ? error_log("getCurl resposne from Shelly Device" . print_r($returnData,true)) : false;
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
