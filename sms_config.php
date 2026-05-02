<?php
// sms_config.php

define('SMS_ENABLED', true);

// Change this to your real local SMS gateway URL
// Example:
// http://127.0.0.1:3000/send-sms
// http://192.168.1.10:3000/send-sms

define('SMS_GATEWAY_URL', 'http://192.168.1.10:3000/send-sms');

// Put your real token here if your local gateway uses one
define('SMS_GATEWAY_TOKEN', 'CHANGE_THIS_TOKEN');

define('SMS_SENDER_NAME', 'RJL Fitness');