<?php

declare(strict_types=1);

defined( 'MyConst' ) or die( 'No script kiddies please!' );


/**
 *
 */

/**
 * The test  class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @author     Madhu Avasarala
 */

 

 require __DIR__ . '/vendor/autoload.php';
 
 use PhpMqtt\Client\ConnectionSettings;
 use PhpMqtt\Client\Examples\Shared\SimpleLogger;
 use PhpMqtt\Client\Exceptions\MqttClientException;
 use PhpMqtt\Client\MqttClient;
 use Psr\Log\LogLevel;

class my_mqtt_test {

    // configuration variable
    public  $config;

    public $message;

    public function __construct()
    {
        $this->config = $this->get_config();
    }



    public  function get_config()
    {
        $config = include( __DIR__."/transindus_eco_config.php");

        // return the array of the account holder directly as there is only one account in the file
        return $config;
    }

    public  function init()
    {
        $this->config = $this->get_config();
    }

    /**
     *  @param string:$topic_param is a string containing the topic. Something like 'shellypro3em-deviceid'
     */
    public function mqtt_subscribe_with_qos_0( $topic_param )
    {
        $mqtt_broker_host       = $this->config['accounts'][0]['mqtt_broker_host'];
        $mqtt_broker_tls_port   = $this->config['accounts'][0]['mqtt_broker_tls_port'];
        $authorization_username = $this->config['accounts'][0]['authorization_username'];
        $authorization_password = $this->config['accounts'][0]['authorization_password'];

        // Create an instance of a PSR-3 compliant logger. For this example, we will also use the logger to log exceptions.
        $logger = new SimpleLogger(LogLevel::INFO);

        try {
            // Create a new instance of an MQTT client and configure it to use the shared broker host and port.
            $client = new MqttClient($mqtt_broker_host, $mqtt_broker_tls_port, 'mysolarApp', MqttClient::MQTT_3_1, null, $logger);

            // Create and configure the connection settings as required.
            $connectionSettings = (new ConnectionSettings)
            ->setUseTls(true)                   // Use TLS to encrypt the communications
            ->setTlsSelfSignedAllowed(false)     //  No self-signed certifciates
            ->setTlsVerifyPeer(true)            // Do require the certificate to match the host
            ->setConnectTimeout(10)             // timeout for establishing socket
            ->setSocketTimeout(10)              // If no data is read or sent for the given amount of seconds, the socket will be closed.
            ->setResendTimeout(10)              // number of seconds the client will wait before sending a duplicate
            ->setKeepAliveInterval(10)          
            ->setTlsVerifyPeerName(true)
            ->setUsername($authorization_username)
            ->setPassword($authorization_password);
        
            // Connect to the broker using TLS with username and password authentication as defined above
            $client->connect( $connectionSettings, true );
        
            // Subscribe to the topic 'foo/bar/baz' using QoS 0.
            $client->subscribe( $topic_param, function (string $topic, string $message, bool $retained) use ($logger, $client) {
                
                // After receiving the first message on the subscribed topic, we want the client to stop listening for messages.
                $client->interrupt();

                $this->message = $message;

            }, MqttClient::QOS_AT_MOST_ONCE);

            
            // Since subscribing requires to wait for messages, we need to start the client loop which takes care of receiving,
            // parsing and delivering messages to the registered callbacks. The loop will run indefinitely, until a message
            // is received, which will interrupt the loop.
            $client->loop(true);
        
            // Gracefully terminate the connection to the broker.
            $client->disconnect();
        } catch (MqttClientException $e) {
            // MqttClientException is the base exception of all exceptions in the library. Catching it will catch all MQTT related exceptions.
            $logger->error('Subscribing to a topic using QoS 0 failed. An exception occurred.', ['exception' => $e]);
        }
    }


}



$test = new my_mqtt_test();

$topic = "shellypro3em-" . $test->config['accounts'][0]['shelly_device_id_acin_3p'] . '/status/emdata:0';

$test->mqtt_subscribe_with_qos_0( $topic );

$shellypro3em_acin_3p_emdata_obj = json_decode($test->message);
print_r($shellypro3em_acin_3p_emdata_obj);