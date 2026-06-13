<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');
$token = trim((string) env('COREHOST_API_TOKEN', ''));
$ch = curl_init('https://git.coresuite.it/api/v1/user');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Authorization: token ' . $token, 'Accept: application/json'],
]);
$raw = curl_exec($ch);
echo curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo substr((string) $raw, 0, 400) . "\n";
