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


// require_once(__DIR__."/class_my_mqtt.php");

class shelly_device
{

    const VERBOSE     = false;

    public string   $verbose;
    private string  $server_uri;
    private string  $auth_key;
    public string   $shelly_device_id;

    public string   $shelly_device_static_ip;
    public string   $shelly_device_model;
    public string   $shelly_device_type;


    public int      $number_of_channels;
    public object   $shelly_device_details;
    

    public function __construct(    
                                  string $auth_key, 
                                  string $server_uri, 
                                  string $shelly_device_id,
                                  string $shelly_device_static_ip, 
                                  string $shelly_device_model = "shellyplus1pm",  // defsult device
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
     *  Based on the supplied model fix the device details into an object
     */
    public function get_device_details_from_model(): ? object
    {
      $shelly_device_details = new stdClass;

      $shelly_device_model = $this->shelly_device_model;

      switch ( $shelly_device_model )
      {
        case "shellyplus1pm":
          $shelly_device_details->channels   = (int)   1;
          $shelly_device_details->switch     = (bool)  true;
          $shelly_device_details->powermeter = (bool)  true;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   2;
          $shelly_device_details->status_call_method_name = "get_shellyplus1pm_status_over_lan";

          break;

        case "shellyplus1-voltmeter":
          $shelly_device_details->channels   = (int)   1;
          $shelly_device_details->switch     = (bool)  true;
          $shelly_device_details->powermeter = (bool)  false;
          $shelly_device_details->voltmeter  = (bool)  true;
          $shelly_device_details->gen        = (int)   2;
          break;

        case "shellypro4pm":
          $shelly_device_details->channels   = (int)   4;
          $shelly_device_details->switch     = (bool)  true;
          $shelly_device_details->powermeter = (bool)  true;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   2;
          break;

        case "shellyem":
          $shelly_device_details->channels   = (int)   2;
          $shelly_device_details->switch     = (bool)  true;
          $shelly_device_details->powermeter = (bool)  true;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   1;
          break; 

        case "shellypro3em":
          $shelly_device_details->channels   = (int)   3;
          $shelly_device_details->switch     = (bool)  false;
          $shelly_device_details->powermeter = (bool)  true;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   2;
          break;

        default:
          $shelly_device_details->channels   = (int)   0;
          $shelly_device_details->switch     = (bool)  false;
          $shelly_device_details->powermeter = (bool)  false;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   0;
      }

      $this->shelly_device_details = $shelly_device_details;
      
      return $shelly_device_details;
    }


    /**
     * 
     */
    public function get_shelly_device_data() : ? object
    {
      $shelly_device_data = new stdClass;

      $shelly_device_data->shelly_device_details      = $this->shelly_device_details;

      // based on the device model get the name of the function to be called to get device status
      $status_call_method_name = (string) $this->shelly_device_details->status_call_method_name;

      // make the status call using method name in variable as below. Note the trick of just adding () at end of variable
      // to make a function call contained inside of a variable.
      $data = $this->$status_call_method_name( $shelly_device_data );

      return $data;
    }




    /**
     *  @param object:$shelly_device_data
     *  Function takes in an object as parameter.
     *  An API call over local LAN is made to get the device data.
     *  The curlresponse is parsed and device data is extracted and added onto passed in object as properties
     *  This way, the shelly device data object is formed so that its data as properties can be accessed in straightforward manner
     */
    public function get_shellyplus1pm_status_over_lan( $shelly_device_data ): ? object
    {
      if ( $this->shelly_device_details->gen === 2 )
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

      if ( ! empty( $curlResponse ) )
      {
        // build the shelly device object from valid data obtained
        $shelly_device_data->switch_0_input_state_bool  = (bool) $curlResponse->{"input:0"}->state;
        $shelly_device_data->switch_0_output_state_bool = (bool) $curlResponse->{"switch:0"}->output;
        $shelly_device_data->switch_0_power_w           = (int)     round( $curlResponse->{"switch:0"}->apower,         0 );
        $shelly_device_data->switch_0_power_kw          = (float)   round( $curlResponse->{"switch:0"}->apower * 0.001, 3 );
        $shelly_device_data->switch_0_energy_counter    = (int)     round( $curlResponse->{"switch:0"}->aenergy->total, 0 );
        $shelly_device_data->switch_0_voltage           = (int)     round( $curlResponse->{"switch:0"}->voltage,         0 );
        $shelly_device_data->switch_0_current           = (float)   round( $curlResponse->{"switch:0"}->current,         1 );
        $shelly_device_data->timestamp                  = (int)            $curlResponse->{"switch:0"}->aenergy->minute_ts;
        $shelly_device_data->static_ip                  = (string)         $curlResponse->wifi->sta_ip;

        if ( $shelly_device_data->switch_0_output_state_bool === true )
        {
            $shelly_device_data->shelly_grid_switch_status_string = "ON";
        }
        elseif ( $shelly_device_data->switch_0_output_state_bool === false )
        {
          $shelly_device_data->shelly_grid_switch_status_string = "OFF";
        }

        return $shelly_device_data;
      }
      else
      {
        // device is offline or not connected or refused to respond
        $shelly_device_data->shelly_grid_switch_status_string = "OFFLINE";
        $shelly_device_data->switch_0_voltage           = (int)   0;
        $shelly_device_data->switch_0_power_kw          = (float) 0;

        return $shelly_device_data;
      }
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
          error_log("Curl GET failed from Shelly Device" . print_r($curl_response,true));
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