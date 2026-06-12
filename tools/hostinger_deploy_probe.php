<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');

$paths = [
    '/api/hosting/v1/websites/business.coresuite.it',
    '/api/hosting/v1/websites/demobusiness.coresuite.it',
    '/api/hosting/v1/websites/pickup.coresuite.it',
    '/api/hosting/v1/git',
    '/api/hosting/v1/git/business.coresuite.it',
    '/api/hosting/v1/git/demobusiness.coresuite.it',
];

foreach ($paths as $path) {
    $ch = curl_init($base . $path);
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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo $path . ' => ' . $status . PHP_EOL;
    if ($status < 500) {
        echo substr((string) $raw, 0, 800) . PHP_EOL;
    }
    echo PHP_EOL;
}
