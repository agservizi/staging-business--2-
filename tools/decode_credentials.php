<?php
require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');
$encoded = env('GOOGLE_CALENDAR_CREDENTIALS_JSON');
if (!$encoded) {
    echo "No credentials";
    exit;
}
$decoded = base64_decode($encoded, true);
if ($decoded === false) {
    echo "Base64 decode failed";
    exit;
}
echo $decoded;
