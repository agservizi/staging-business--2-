<?php
require_once __DIR__ . '/../includes/mailer.php';

$to = $argv[1] ?? 'developers@coresuite.it';
$result = send_system_mail($to, 'Test Resend', '<p>Test email</p>');
var_dump($result);
