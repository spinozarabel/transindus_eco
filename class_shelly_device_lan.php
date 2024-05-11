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

        case "shellyplus1-v":
          // shellyplu1 with addon
          $shelly_device_details->channels   = (int)   1;
          $shelly_device_details->switch     = (bool)  true;
          $shelly_device_details->powermeter = (bool)  false;
          $shelly_device_details->voltmeter  = (bool)  true;
          $shelly_device_details->gen        = (int)   2;
          $shelly_device_details->status_call_method_name = "get_shellyplus1_status_over_lan";
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
          $shelly_device_details->status_call_method_name = "get_shellyem_status_over_lan";
          break; 

        case "shellypro3em":
          $shelly_device_details->channels   = (int)   3;
          $shelly_device_details->switch     = (bool)  false;
          $shelly_device_details->powermeter = (bool)  true;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   2;
          $shelly_device_details->status_call_method_name = "get_shellypro3em_status_over_lan";
          break;

        case "shellypro4pm":
          $shelly_device_details->channels   = (int)   4;
          $shelly_device_details->switch     = (bool)  true;
          $shelly_device_details->powermeter = (bool)  true;
          $shelly_device_details->voltmeter  = (bool)  false;
          $shelly_device_details->gen        = (int)   2;
          $shelly_device_details->status_call_method_name = "get_shelly4pm_status_over_lan";
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
    public function get_shellyem_status_over_lan( $shelly_device_data ): ? object
    {
      
      // assumes gen1
      $protocol_method = "/status";

      // parameters for query string
      $params   = [];

      $headers  = [];

      $endpoint = $this->shelly_device_static_ip . $protocol_method;

      // already json decoded into object or null
      $curlResponse   = $this->getCurl($endpoint, $headers, $params);

      // check to make sure that it exists. If null API call was fruitless
      if (  empty(      $curlResponse ) || 
            empty(      $curlResponse->emeters[0]->total ) 
          )
      { // Shelly Load EM did not respond over LAN
        $this->verbose ? error_log( "LogApi: Shelly EM Load Energy API call failed - See below for response" ): false;
        $this->verbose ? error_log( print_r($curlResponse , true) ): false;

        return $shelly_device_data;   // return passed in object without dynamic addition of API data
      }

      $emeters = array();
      
      $emeters[0] = new stdClass;
      $emeters[1] = new stdClass;

      $shelly_device_data->emeters = $emeters;

      // if we get here it means we have valid data from API call over LNA
      {
        // build the shelly device object from valid data obtained
        $shelly_device_data->emeters[0]->total = (int) round( $curlResponse->emeters[0]->total, 0 );  // channel 0 total energy WH counter
        $shelly_device_data->emeters[1]->total = (int) round( $curlResponse->emeters[1]->total, 0 );  // channel 1 total energy WH counter

        // AC voltage as measured by channel 0 of the Shelly EM
        $shelly_device_data->emeters[0]->voltage  = (int)   round( $curlResponse->emeters[0]->voltage,        0 );

        // power as measured by Shelly EM on channel 0 and channel 1
        $shelly_device_data->emeters[0]->power_kw = (float) round( $curlResponse->emeters[0]->power * 0.001,  3 );
        $shelly_device_data->emeters[1]->power_kw = (float) round( $curlResponse->emeters[1]->power * 0.001,  3 );

        $shelly_device_data->timestamp           = (int)          $curlResponse->unixtime;
        $shelly_device_data->static_ip           = (string)       $curlResponse->wifi_sta->ip;

        // update the property of the relay output. This is usually used to control a contactor
        $shelly_device_data->output              = (bool)         $curlResponse->relays[0]->ison;

        return $shelly_device_data;
      }
    }




    /**
     *  @param object:$shelly_device_data
     *  Function takes in an object as parameter.
     *  An API call over local LAN is made to get the device data.
     *  The curlresponse is parsed and device data is extracted and added onto passed in object as properties
     *  If no curlresponse, then the passed in object is returned unmodified except for switch state being OFFLINE
     *  This way, the shelly device data object is formed so that its data as properties can be accessed in straightforward manner
     */
    public function get_shellyplus1_status_over_lan( $shelly_device_data ): ? object
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
        $shelly_device_data->input_0_state_bool         = (bool)  $curlResponse->{"input:0"}->state;      // digital input state
        $shelly_device_data->switch_0_output_state_bool = (bool)  $curlResponse->{"switch:0"}->output;    // switch output state

        // check to see if addon 'input:100' exists in response. If so get it
        if ( property_exists($curlResponse, "input:100" ) )
        {
          $shelly_device_data->voltmeter_percent        = (float) $curlResponse->{"input:100"}->percent;  // percentage of full-scale of 10V
        }
        else
        {
          $shelly_device_data->voltmeter_percent = null;
        }

        $shelly_device_data->timestamp                  = (int)            $curlResponse->sys->unixtime;
        $shelly_device_data->static_ip                  = (string)         $curlResponse->wifi->sta_ip;

        return $shelly_device_data;
      }
      else
      {
        // device is offline or not connected or refused to respond
        $shelly_device_data->switch_0_output_state_string = "OFFLINE";
        return $shelly_device_data;
      }
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

      $switch     = array();
      $switch[0]  = new stdClass;

      if ( ! empty( $curlResponse ) )
      {
        // build the shelly device object from valid data obtained
        $shelly_device_data->switch[0]->input_state_bool  = (bool) $curlResponse->{"input:0"}->state;
        $shelly_device_data->switch[0]->output_state_bool = (bool) $curlResponse->{"switch:0"}->output;
        $shelly_device_data->switch[0]->power             = (int)     round( $curlResponse->{"switch:0"}->apower,         0 );
        $shelly_device_data->switch[0]->power_kw          = (float)   round( $curlResponse->{"switch:0"}->apower * 0.001, 3 );
        $shelly_device_data->switch[0]->energy            = (int)     round( $curlResponse->{"switch:0"}->aenergy->total, 0 );
        $shelly_device_data->switch[0]->voltage           = (int)     round( $curlResponse->{"switch:0"}->voltage,         0 );
        $shelly_device_data->switch[0]->current           = (float)   round( $curlResponse->{"switch:0"}->current,         1 );
        $shelly_device_data->timestamp                    = (int)            $curlResponse->{"switch:0"}->aenergy->minute_ts;
        $shelly_device_data->static_ip                    = (string)         $curlResponse->wifi->sta_ip;

        if ( $shelly_device_data->switch[0]->output_state_bool === true )
        {
            $shelly_device_data->switch[0]->output_state_string = "ON";
        }
        elseif ( $shelly_device_data->switch[0]->output_state_bool === false )
        {
          $shelly_device_data->switch[0]->output_state_string = "OFF";
        }

        return $shelly_device_data;
      }
      else
      {
        // device is offline or not connected or refused to respond
        $shelly_device_data->switch[0]->output_state_string = "OFFLINE";
        $shelly_device_data->switch[0]->voltage             = (int)   0;
        $shelly_device_data->switch[0]->power               = (int)   0;
        $shelly_device_data->switch[0]->power_kw            = (float) 0;
        
        return $shelly_device_data;
      }
    }



    /**
     * 
     */
    public function get_shelly4pm_status_over_lan( $shelly_device_data ): object
    {
     
      $protocol_method = "/rpc/Switch.GetStatus";

      $headers  = [];

      $switch = array();
      
      $switch[0] = new stdClass;
      $switch[1] = new stdClass;
      $switch[2] = new stdClass;
      $switch[3] = new stdClass;
       
      for ($channel = 0; $channel <= 3; $channel++) 
      {
        // parameters for query string
        $params     = array
        (
            "id"          => $channel                  //         0/1/2/3
        );

        $endpoint = $this->shelly_device_static_ip . $protocol_method;

        // already json decoded into object or null
        $curlResponse   = $this->getCurl($endpoint, $headers, $params);

        if ( ! empty( $curlResponse ) )
        {
          $shelly_device_data->switch[$channel]->input_state_bool  = (bool) $curlResponse->{"input:"  . $channel}->state;
          $shelly_device_data->switch[0]->output_state_bool        = (bool) $curlResponse->{"switch:" . $channel}->output;
          $shelly_device_data->switch[0]->power                    = (int)     round( $curlResponse->{"switch:" . $channel}->apower,         0 );
          $shelly_device_data->switch[0]->power_kw                 = (float)   round( $curlResponse->{"switch:" . $channel}->apower * 0.001, 3 );
          $shelly_device_data->switch[0]->energy                   = (int)     round( $curlResponse->{"switch:" . $channel}->aenergy->total, 0 );
          $shelly_device_data->switch[0]->voltage                  = (int)     round( $curlResponse->{"switch:" . $channel}->voltage,        0 );
          $shelly_device_data->switch[0]->current                  = (float)   round( $curlResponse->{"switch:" . $channel}->current,        1 );
        }
      }
      
      // get timestamp outside of channel loop
      $shelly_device_data->timestamp                    = (int)            $curlResponse->{"switch:0"}->aenergy->minute_ts;
      $shelly_device_data->static_ip                    = (string)         $curlResponse->wifi->sta_ip;
      

      return  $shelly_device_data;
    }
  


     /**
     *  @return bool returns true if actual state is same as desired state, returns false otherwise or if api call failed
     *  @param object:$shelly_device_data is the shelly device object that has all the details
     *  @param int:$channel is the channel to switch. For shellyplus1pm it is 0
     *  @param string:$desired_state can be either 'on' or 'off' to turn on the switch to ON or OFF respectively
     *  
     *  
     */
    public function turn_on_off_shelly_x_plus_pm_switch_over_lan( string $desired_state, int $channel = 0 ) :  bool
    {
        // parse the desired state so end result is 1 or 0
        if ( strtolower($desired_state)     === "true"  || strtolower($desired_state) === "on")
        {
            $desired_switch_state = "true";
        }
        elseif ( strtolower($desired_state) === "false" || strtolower($desired_state) === "off" )
        {
            $desired_switch_state = "false";
        }

        // form the variable name holders as below that themselves depend on variable $channel
        $channel_holder_boolvar_string    = "switch_" . strval( $channel ) . "_output_state_bool";    // switch_0_output_state_bool
        $channel_holder_stringvar_string  = "switch_" . strval( $channel ) . "_output_state_string";  // switch_0_output_state_string

        //-------------------------------------Get the cutrrent state of switch ----------->
        $shelly_device_data = $this->get_shelly_device_data();

        // Get the present switch state in a string. Possible values are: "ON"/"OFF"/"OFFLINE"
        $present_switch_state_string = (string) $shelly_device_data->$channel_holder_stringvar_string;

        if ( $present_switch_state_string !== "OFFLINE" )   // we did get a valid response
        {
          // this is the present boolean state of the switch
          $initial_switch_state_bool = (bool) $shelly_device_data->$channel_holder_boolvar_string ;

          // if the existing switch state is same as desired, no need to do anything, we just exit with message
          if (  ( $initial_switch_state_bool === true  &&   strtolower( $desired_switch_state) === "true"  ) || 
                ( $initial_switch_state_bool === false &&   strtolower( $desired_switch_state) === "false" )      )
          {
            // esisting state is same as desired final state so return
            error_log( "LogSw: No Action required - Initial Switch State: $initial_switch_state_bool, Desired State: $desired_state" );
            return true;
          }
        }
        else
        {
          // we didn't get a valid response for the initial state but we can continue and try switching
          error_log( "LogSw: shelly switch initial status:  $present_switch_state_string, Try to switch anyway...");
        }

        //------------ do the switch ----------------------------------------------------->
        // parameters for query string
        $params     = array
        (
            "id"          => $channel               ,   // 0/1/2/3
            'on'          => $desired_switch_state  ,   // "true" / "false" values
        );
        
        $headers  = [];

        $endpoint = $this->shelly_device_static_ip . "/rpc/Switch.Set";

        // issue the switch.set command. The response will consist of was_on:true/false
        $curlResponse   = $this->getCurl($endpoint, $headers, $params);

        If ( empty( $curlResponse ) )
        {
          error_log( "LogSw: Danger-we didn't get a valid response for switch turn on/off" );
          return false;
        }

        $previous_state_was_on = $curlResponse->was_on;

        //-------------------- Verify that the desired switch action took place----------------->
        $shelly_device_data = $this->get_shelly_device_data();

        $final_switch_state_string = $shelly_device_data->$channel_holder_stringvar_string;

        if ( $final_switch_state_string !== "OFFLINE" )
        {
          // Get shelly switch state now
          $final_switch_state_bool = (bool) $shelly_device_data->$channel_holder_boolvar_string;

          if (  ( $final_switch_state_bool === true  &&  ( strtolower( $desired_switch_state) === "true"  ) ) || 
                ( $final_switch_state_bool === false &&  ( strtolower( $desired_switch_state) === "false" ) )
              )
          {
            // Final state is same as desired final state so return success
            return true;
          }
          else
          {
            error_log( "LogSw: Danger-ACIN Switch to desired state Failed - Desired State: $desired_state, Final State: $final_switch_state_bool" );
            error_log( "previous_state_was_on: $previous_state_was_on as indicated by was_on property returned" );
            return false;
          }
        }
        else
        {
          error_log( "LogSw: Danger-we didn't get a valid response for switch turn on/off" );
          return false;
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