<?php


/**
 * Class Request_Driver_Stream
 */
class RequestDriverStream
{
    //connection settings for sending stream
    /**
     * @var
     */
    public $gateway;
    /**
     * @var
     */
    public $ssl;
    /**
     * @var
     */
    public $certificate = 'path/to/your/certificate';
    /**
     * @var
     */
    public $passPhrase = 'YourPassPhrase';
    /**
     * @var
     */
    public $feedback;

    /**
     * @var
     */
    public $connection;
	/**
     * @var
     */
    private $deviceToken;
	/**
     * @var
     */
    private $payload;
    /**
     * @var bool
     */
    private $testing;

    /**
     * @param $deviceToken
     * @param $payload
     * @param bool $testing
     */
    public function __construct($deviceToken, $payload, $testing=true)
    {
                //ios specific settings for request connection
        if ($testing) {
            $this->gateway = 'gateway.sandbox.push.apple.com:2195';
            $this->feedback = 'ssl://feedback.sandbox.push.apple.com:2196';
            $this->ssl = 'ssl://gateway.sandbox.push.apple.com:2195';
        } else {
            $this->gateway = 'gateway.push.apple.com:2195';
            $this->feedback = 'ssl://feedback.push.apple.com:2196';
            $this->ssl = 'ssl://gateway.push.apple.com:2195';
        }
        $this->deviceToken = $deviceToken;
        $this->payload = $payload;
        $this->testing = $testing;
    }

    /**
     * @return bool
     */
    function execute()
    {
        if (!$this->connect()) {
            return false;
        }

        if (!$this->sendStream($this->deviceToken, $this->payload)) {
            return false;
        }

        $this->checkFeedback();
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    function connect()
    {
        $ctx = stream_context_create();
        stream_context_set_option($ctx, 'ssl', 'local_cert', $this->certificate);
        stream_context_set_option($ctx, 'ssl', 'passphrase', $this->passPhrase);
        //stream_context_set_option($ctx, 'ssl', 'verify_peer', true);

        $this->connection = stream_socket_client($this->ssl, $error,
            $errorString, 60, STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT, $ctx);
        if ($this->connection == false) {
            throw new Exception("Failed to connect {$error} {$errorString}");
        }

        return true;
    }

    /**
     * @param $token
     * @param $payload
     * @return bool
     * @throws Exception
     */
    function sendStream($token, $payload)
    {
        $fp = false;
        if (isset($this->connection)) {
            $fp = $this->connection;
        }

        if (!$fp) {
            throw new Exception('error', "A connected socket to APNS wasn't available.");
        }

        // Enhanced notification format: ("recommended for most providers")
        // 1: 1. 4: Identifier. 4: Expiry. 2: Token length. 32: Device Token. 2: Payload length. 34: Payload
        $expiry = time() + 120; // 2 minute validity hard coded!
        //$msg = chr(0).pack('n', 32).pack('H*', $deviceId).pack('n', strlen($payload)).$payload;
        if (!$msg = chr(0) . pack('n', 32) . pack('H*', $token) . pack('n', strlen($payload)) . $payload) {
            throw new Exception('error', "Could not build binary message." . E_USER_ERROR);
        }

        $fwrite = fwrite($fp, $msg);
        if (!$fwrite) {
            throw new Exception("Failed writing to stream." . E_USER_ERROR);
        } else {
            // "Provider Communication with Apple Push Notification Service"
            // http://developer.apple.com/library/ios/#documentation/NetworkingInternet/Conceptual/RemoteNotificationsPG/CommunicatingWIthAPS/CommunicatingWIthAPS.html#//apple_ref/doc/uid/TP40008194-CH101-SW1
            // "If you send a notification and APNs finds the notification malformed or otherwise unintelligible, it
            // returns an error-response packet prior to disconnecting. (If there is no error, APNs doesn't return
            // anything.)"
            //
            // This complicates the read if it blocks.
            // The timeout (if using a stream_select) is dependent on network latency.
            // default socket timeout is 60 seconds
            // Without a read, we leave a false positive on this push's success.
            // The next write attempt will fail correctly since the socket will be closed.
            //
            // This can be done if we start batching the write

            // Read response from server if any. Or if the socket was closed.
            // [Byte: data.] 1: 8. 1: status. 4: Identifier.
            $tv_sec = 1;
            $tv_usec = null; // Timeout. 1 million micro seconds = 1 second
            $r = array($fp);
            $we = null; // Temporaries. "Only variables can be passed as reference."
            $numChanged = stream_select($r, $we, $we, $tv_sec, $tv_usec);
            if (false === $numChanged) {
                throw new Exception("Failed selecting stream to read." . E_USER_ERROR);
            } else if ($numChanged > 1) {
                $command = ord(fread($fp, 1));
                $status = ord(fread($fp, 1));
                $identifier = implode('', unpack("N", fread($fp, 4)));
                $statusDesc = array(
                    0 => 'No errors encountered',
                    1 => 'Processing error',
                    2 => 'Missing device token',
                    3 => 'Missing topic',
                    4 => 'Missing payload',
                    5 => 'Invalid token size',
                    6 => 'Invalid topic size',
                    7 => 'Invalid payload size',
                    8 => 'Invalid token',
                    255 => 'None (unknown)',
                );
                //throw new Exception("APNS responded with command($command) status($status) pid($identifier)." . E_USER_NOTICE);

                if ($status > 0) {
                    $desc = isset($statusDesc[$status]) ? $statusDesc[$status] : 'Unknown';
                    throw new Exception("APNS responded with error for pid($identifier). status($status: $desc)" . E_USER_ERROR);
                    // The socket has also been closed. Cause reopening in the loop outside.
                }
            }
        }
        return true;
    }

    /**
     * @return bool
     * @throws Exception
     */
    function checkFeedback()
    {
        $ctx = stream_context_create();
        stream_context_set_option($ctx, 'ssl', 'local_cert', $this->ssl);
        stream_context_set_option($ctx, 'ssl', 'passphrase', $this->passPhrase);
        stream_context_set_option($ctx, 'ssl', 'verify_peer', true);
        $fb = stream_socket_client($this->feedback, $error, $errorString, 60, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fb) {
            throw new Exception("Failed to retrieve feedback {$error} {$errorString}");
        }
        while ($devcon = fread($fb, 38)) {
            $arr = unpack("H*", $devcon);
            $rawhex = trim(implode("", $arr));
            $token = substr($rawhex, 12, 64);
            if (!empty($token)) {
                throw new Exception("Bad Device Token: {$token}.");
            }
        }
        @fclose($fb);
        return true;
    }
}

