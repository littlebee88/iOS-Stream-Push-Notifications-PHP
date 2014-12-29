<?php
include(dirname(__FILE__).'/Ios_Push_Notification.php');

$db = new Ios_Push_Notification('localhost', 'root', 'root', 'db');

$insertData = array(
	'title' => 'Inserted title',
	'body' => 'Inserted body'
);

$results = $db->insert('posts', $insertData);
print_r($results);
