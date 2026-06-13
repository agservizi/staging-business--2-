#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('HOSTINGER_API_TOKEN', ''));
$base = rtrim((string) env('HOSTINGER_API_BASE_URI', 'https://developers.hostinger.com'), '/');
$user = (string) env('HOSTINGER_SSH_USER', 'u427445037');

if ($token === '') {
    fwrite(STDERR, "HOSTINGER_API_TOKEN mancante\n");
    exit(1);
}

function get_json(string $base, string $token, string $path): array
{
    $ch = curl_init($base . $path);
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

    return ['status' => $status, 'body' => json_decode((string) $raw, true), 'raw' => (string) $raw];
}

echo "=== Hostinger API Status ===\n\n";

$res = get_json($base, $token, '/api/hosting/v1/websites?domain=business.coresuite.it');
echo "business.coresuite.it HTTP {$res['status']}\n";
foreach (($res['body']['data'] ?? []) as $site) {
    echo '  root: ' . ($site['root_directory'] ?? '?') . "\n";
}

$res = get_json($base, $token, '/api/hosting/v1/accounts/' . rawurlencode($user) . '/databases');
echo "\nDatabase HTTP {$res['status']}\n";
foreach (($res['body']['data'] ?? $res['body'] ?? []) as $db) {
    if (!is_array($db)) {
        continue;
    }
    echo '  - ' . ($db['name'] ?? $db['database'] ?? json_encode($db)) . "\n";
}

echo "\nDeploy consigliato:\n";
echo "  hPanel → business.coresuite.it → Git → branch production → Deploy\n";
echo "  Oppure: aggiungi chiave SSH da deploy/SSH-SETUP.txt e usa GitHub Actions\n";
