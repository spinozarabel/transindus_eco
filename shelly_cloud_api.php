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

    public function __construct(string $auth_key, string $server_uri, string $shelly_device_id)
    {
      $this->verbose  = self::VERBOSE;

      $this->auth_key		      = $auth_key;    // Auth key to access account
		  $this->server_uri	      = $server_uri;  // The server uri can be obtained 
                                              // on the same page where the authorization key is generated
      $this->shelly_device_id = $shelly_device_id;
    }       // end construct function



    /**
    * read status of Shelly Device using Shelly CLoud API
    *
    */
    public function get_shelly_device_status()
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
      $curlResponse   = $this->postCurl($endpoint, $headers, $params);
      error_log( "This is the response while querying for your Studer parameter" . print_r($curlResponse, true) );


      if ( $curlResponse->isok )
          {
              return $curlResponse;
          }
      else
          {
              if ($this->verbose)
              {
                  error_log( "This is the response while querying for your Studer parameter" . print_r($curlResponse, true) );
              }
              return null;
          }
    }


    /**
    * read multiple user values in one sibgle POST request
    *
    */
    public function get_user_values()
    {
      $uhash            = $this->uhash;
      $phash            = $this->phash;
      $baseurl          = $this->baseurl;
      $installation_id  = $this->installation_id;

      // the ones below are not set inside of this class but the function calling this as a public function outside the class
      $params             = $this->body;

      $headers =
      [
       "UHASH: $uhash",
       "PHASH: $phash"
      ];


      $endpoint = $baseurl . "/api/v1/installation/multi-info/" . $installation_id;

      $curlResponse   = $this->postCurl($endpoint, $headers, $params);

      // the curlResponse is already JSON decoded as object
      return $curlResponse;
    }


    protected function postCurl ($endpoint, $headers = [], $params = []) 
    {
      $postFields = json_encode($params);
      array_push($headers,
         'Content-Type: application/json', 
         'Content-Length: ' . strlen($postFields));

      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, $endpoint);
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
      // curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
      curl_setopt($ch, CURLOPT_TIMEOUT, 10);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
      curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

      $returnData = curl_exec($ch);
      $this->verbose ? error_log("curl reposne" . print_r($returnData,true)) : false;

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
       $this->verbose ? error_log("curl reposne" . print_r($returnData,true)) : false;
       curl_close($ch);
       if ($returnData != "")
       {
        return json_decode($returnData, false);     // returns object not array
       }
       return NULL;
    }
}
