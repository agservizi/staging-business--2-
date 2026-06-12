<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');

$ch = curl_init($base . '/api/hosting/v1/websites');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ],
]);
$raw = curl_exec($ch);
curl_close($ch);

$data = json_decode((string) $raw, true);
$sites = is_array($data['data'] ?? null) ? $data['data'] : [];

foreach ($sites as $site) {
    if (!is_array($site)) {
        continue;
    }
    printf(
        "%s | %s | %s\n",
        (string) ($site['domain'] ?? ''),
        (string) ($site['root_directory'] ?? ''),
        (string) ($site['username'] ?? '')
    );
}
