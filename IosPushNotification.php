<?php


include(dirname(__FILE__).'/RequestDriverStream.php');

/**
 * Class IosPushNotification
 */
class IosPushNotification
{
	/**
     * @var array
     */
    public $deviceTokens;
	/**
     * @var
     */
    public $message;
	/**
     * @var null
     */
    public $badge;
	/**
     * @var
     */
    public $response;

    /**
     * @var array
     */
    protected $payload;

    /**
     * @var array
     */
    protected $addtl_payload;

    /**
     * @param $devices
     * @param $message
     * @param $certificate
     * @param $passPhrase
     * @param $badge
     * @param $sound
     * @param null $badgeCallback
     * @param bool $testing
     * @internal param Apps_Notification $notification
     */
    function __construct($devices, $message, $badge=null, $sound=null, $badgeCallback=null)
    {
        $this->deviceTokens = $devices;
        $this->message = $message;
        $this->badge = $badge;
        $this->sound = $sound;
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
            if (!is_null($this->addtl_payload)) {
                $payload['app'] = $this->addtl_payload;
            }

            $payload = json_encode($payload);

            //load correct request driver and send request properties
            $driver = new RequestDriverStream($deviceToken, $payload);
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