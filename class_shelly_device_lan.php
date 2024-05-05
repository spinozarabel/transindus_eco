<?php
/*  Created by Madhu Avasarala 05/05/2024
*   all data returned as objects
*   
*/

// if directly called die. Use standard WP and Moodle practices
if (!defined( "ABSPATH" ) && !defined( "MOODLE_INTERNAL" ) )
    {
    	die( 'No script kiddies please!' );
    }


require_once(__DIR__."/class_my_mqtt.php");

class shelly_device
{

    const VERBOSE     = true;

    public string   $verbose;
    private string  $server_uri;
    private string  $auth_key;
    public string   $shelly_device_id;

    public string   $shelly_device_static_ip;
    public string   $shelly_device_model;
    public string   $shelly_device_type;
    public bool     $input_0_state_bool;
    public bool     $switch_0_output_bool;
    public float    $switch_0_power_w, $switch_0_power_kw;
    public int      $number_of_channels;
    public object   $shelly_device_details;

    public function __construct(    
                                  string $auth_key, 
                                  string $server_uri, 
                                  string $shelly_device_id,
                                  string $shelly_device_static_ip, 
                                  string $shelly_device_model = "shellyplus1pm",
                                )
    {
      $this->verbose  = self::VERBOSE;

      $this->auth_key		              = $auth_key;                  // Auth key to access account
	    $this->server_uri	              = $server_uri;                // The server uri can be obtained 
                                                                    // on the same page where the authorization key is generated
      $this->shelly_device_id         = $shelly_device_id;

      $this->shelly_device_static_ip  = $shelly_device_static_ip;
      $this->shelly_device_model      = $shelly_device_model;

      $this->shelly_device_details    = $this->get_device_details_from_model();

    } // end construct function


    /**
     * 
     */
    public function get_device_details_from_model(): ? object
    {
      $shelly_device_type_obj = new stdClass;

      $shelly_device_model = $this->shelly_device_model;

      switch ( $shelly_device_model )
      {
        case "shellyplus1pm":
          $shelly_device_type_obj->channels   = (int)   1;
          $shelly_device_type_obj->switch     = (bool)  true;
          $shelly_device_type_obj->powermeter = (bool)  true;
          $shelly_device_type_obj->voltmeter  = (bool)  false;
          $shelly_device_type_obj->gen        = 2;

          break;

        case "shellyplus1-voltmeter":
          $shelly_device_type_obj->channels   = (int)   1;
          $shelly_device_type_obj->switch     = (bool)  true;
          $shelly_device_type_obj->powermeter = (bool)  false;
          $shelly_device_type_obj->gen        = 2;
          break;

        case "shellypro4pm":
          $shelly_device_type_obj->channels   = (int)   4;
          $shelly_device_type_obj->switch     = (bool)  true;
          $shelly_device_type_obj->powermeter = (bool)  true;
          $shelly_device_type_obj->voltmeter  = (bool)  false;
          $shelly_device_type_obj->gen        = (int)   2;
          break;

        case "shellyem":
          $shelly_device_type_obj->channels   = (int)   2;
          $shelly_device_type_obj->switch     = (bool)  true;
          $shelly_device_type_obj->powermeter = (bool)  true;
          $shelly_device_type_obj->voltmeter  = (bool)  false;
          $shelly_device_type_obj->gen        = (int)   1;
          break; 

        case "shellypro3em":
          $shelly_device_type_obj->channels   = (int)   3;
          $shelly_device_type_obj->switch     = (bool)  false;
          $shelly_device_type_obj->powermeter = (bool)  true;
          $shelly_device_type_obj->voltmeter  = (bool)  false;
          $shelly_device_type_obj->gen        = (int)   2;
          break;

        default:
          $shelly_device_type_obj->channels   = (int)   0;
          $shelly_device_type_obj->switch     = (bool)  false;
          $shelly_device_type_obj->powermeter = (bool)  false;
          $shelly_device_type_obj->voltmeter  = (bool)  false;
          $shelly_device_type_obj->gen        = (int)   0;
      }
      
      return $shelly_device_type_obj;
    }


    /**
     * 
     */
    public function get_shelly_device_data() : ? object
    {

        if ( $this->shelly_device_details->gen === '2' )
        {
            $protocol_method = "/rpc/Shelly.GetStatus";
        }
        else
        {
            // assumes gen1
            $protocol_method = "/status";
        }
        

        // parameters for query string
        $params   = [];

        $headers  = [];

        $endpoint = $this->shelly_device_static_ip . $protocol_method;

        // already json decoded into object or null
        $curlResponse   = $this->getCurl($endpoint, $headers, $params);

        if ( empty( $curlResponse ) )
        {
            return null;
        }

        switch (true)
        {
          case 
        }


        // build the shelly device object from data obtained
        $this->input_0_state_bool   = $curlResponse->{"input:0"};
        $this->switch_0_output_bool = $curlResponse->{"switch:0"}->output;
        $this->switch_0_power_w     = (int)     round( $curlResponse->{"switch:0"}->apower, 0 );
        $this->switch_0_power_kw    = (float)   round( $this->switch_0_power_w * 0.001, 3 );

    }


    /**
    *  @param string:$endpoint is the full path url of endpoint, not including any parameters
    *  @param array:$headers is the array conatining a single item, the bearer token
    *  @param array:$params is the optional array containing the get parameters
    */
    protected function getCurl ( string $endpoint, array $headers, array $params = [] ): ? object
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
       curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
       curl_setopt($ch, CURLOPT_TIMEOUT, 5);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
       curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0); // local LAN no SSL
       curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

       $curl_response = curl_exec($ch);

       $this->verbose ? error_log("GET Curl resposne from Shelly Device" . print_r($curl_response,true)) : false;

       curl_close($ch);

       if ( !empty( $curl_response ) ) 
        {
          return json_decode($curl_response, false);     // returns object not array
        }
        else
        {
          return NULL;
        }
    }

    /**
     * 
     */
    protected function postCurl (  string $endpoint, array $headers = [], array $params = [] ): ? object 
    {
      $postFields = json_encode($params);

      array_push($headers,
         'Content-Type: application/json', 
         'Content-Length: ' . strlen($postFields));

      // check if anything exists in $params. If so make a query string out of it
      if ( $params )
      {
         if ( count( $params ) )
         {
             $postFields = http_build_query( $params );
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

      $curl_response = curl_exec($ch);
      $this->verbose ? error_log("POST curl response Shelly Device" . print_r($curl_response,true)) : false;

      curl_close($ch);

      if ( !empty( $curl_response ) ) 
      {
        return json_decode($curl_response, false);     // returns object not array
      }
      else
      {
        return NULL;
      }
    }

    /**
     * send a command over MQTT to local MQTT broker on a topic that the shelly device is supposed to listen to
     */
    protected function issue_command_over_mqtt_to_shelly_device( string $command, string $topic ): ? bool
    {
        return false;
    }
}