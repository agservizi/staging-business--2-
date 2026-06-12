<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');

$candidates = [
    '/api/hosting/v1/websites',
    '/api/hosting/v1/orders',
    '/api/hosting/v1/accounts/list',
    '/api/hosting/v1/ftp',
    '/api/hosting/v1/ftp/accounts',
    '/api/hosting/v1/websites/pickup.coresuite.it/ftp',
];

foreach ($candidates as $path) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
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
    if ($status === 200) {
        echo substr((string) $raw, 0, 1200) . PHP_EOL;
    }
    echo PHP_EOL;
}

$dnsHosts = ['ftp.coresuite.it', 'coresuite.it', 'files.coresuite.it'];
foreach ($dnsHosts as $host) {
    $records = @dns_get_record($host, DNS_A);
    echo 'DNS A ' . $host . ': ';
    if (!$records) {
        echo 'n/a' . PHP_EOL;
        continue;
    }
    echo implode(', ', array_column($records, 'ip')) . PHP_EOL;
}
