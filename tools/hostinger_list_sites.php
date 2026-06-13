<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');

$ch = curl_init($base . '/api/hosting/v1/websites');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
]);
$raw = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo 'HTTP ' . $status . PHP_EOL;
$data = json_decode((string) $raw, true);
foreach (($data['data'] ?? []) as $website) {
    printf(
        "%-40s %s\n",
        (string) ($website['domain'] ?? ''),
        (string) ($website['root_directory'] ?? '')
    );
}
