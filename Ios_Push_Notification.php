<?php
/**
 * Ios_Push_Notification Class
 *
 * @category  Request Driver
 * @package   Ios_Push_Notification
 * @author    Stephanie Schmidt <littlebeehigbee@gmail.com>
 * @copyright Copyright (c) 2014
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @version   1.0
 **/

include(dirname(__FILE__).'/Request_Driver_Stream.php');

/**
 * Class IosPushNotification
 */
class Ios_Push_Notification
{
	/**
     * An array of device tokens to send the push notifications to.
     *
     * @var array
     */
    public $deviceTokens;

	/**
     * Push Message as a string
     *
     * @var string
     */
    public $message;

	/**
     * Badge you would like displayed on the push notification
     *
     * @var string
     */
    public $badge;

    /**
     * Sound you would like to use with the push notification
     *
    * @var string
    */
    public $sound;

	/**
     * The push notification response
     *
     * @var
     */
    public $response;

    /**
     * The payload that will be constructed from the $message, $badge, and $sound
     *
     * @var array
     */
    protected $payload;

    /**
     * Any additional payload you would like to include in the push notification for your mobile app
     *
     * @var string
     */
    protected $addtlPayload;

    /**
     * The $badgeCallback parameter allows you call for a $badge count on each individual device token in the queue of $devices.
     *
     * The badge call back consists of an array of two arguments, the class to call and the class method to call,
     * and the device token is used as the argument for the method call.
     *
     * @var string
     */
    protected $badgeCallback;

    /**
     * @param $deviceTokens
     * @param $message
     * @param $badge
     * @param $sound
     * @param null $badgeCallback
     */
    function __construct($deviceTokens, $message, $badge=null, $sound=null, $addtlPayload=null, $badgeCallback=null)
    {
        $this->deviceTokens = $deviceTokens;
        $this->message = $message;
        $this->badge = $badge;
        $this->sound = $sound;
        $this->addtlPayload = $addtlPayload;
        $this->badgeCallback = $badgeCallback;
    }

    /**
     * @return array
     * @throws Exception
     */
    function pushNotification()
    {
        //set up data array to be pushed to APNS
        //$message = 'Message from '. $this->sender  .': '.$this->message;
        $responses = array();
        foreach($this->deviceTokens as $deviceToken) {

            //callback to get te badge if needed
            if(is_array($this->badgeCallback) && !empty($this->badgeCallback))
            {
                $class = $this->badgeCallback[0];
                $method = $this->badgeCallback[1];
                $object = new $class;
                $result = call_user_func_array(array($object, $method), array($deviceToken));
                if($result && is_int((int)$result)){
                    $this->badge = $result;
                }
            }
            $payload['aps'] = array(
                'alert' => $this->message,
                'badge' => $this->badge,
                'sound' => $this->sound,
            );
            if (!is_null($this->addtlPayload)) {
                $payload['app'] = $this->addtlPayload;
            }

            $payload = json_encode($payload);

            //load correct request driver and send request properties
            $driver = new Request_Driver_Stream($deviceToken, $payload);
            $response = $driver->execute();

            if ($response) {
                $responses['true'][] = $deviceToken;
            } else {
                $responses['false'][] = $deviceToken;
            }
        }
        if(empty($responses['false'])){
            //no pushes failed
            $this->response = true;
        } else {
            //something went wrong
            $this->response = false;
            throw new Exception('Send failed for these push token(s): '.implode(', ',$responses['false']));
        }
        return $this->response;
    }
}