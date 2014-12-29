<?php
include(dirname(__FILE__).'/Ios_Push_Notification.php');

$deviceTokens = array();
$message  = 'This is a test!';
$push = new Ios_Push_Notification($deviceTokens, $message);
$results = $push->pushNotification();
print_r($results);
