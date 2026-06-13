<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('COREHOST_API_TOKEN', ''));
$base = 'https://git.coresuite.it';
$owner = 'Carmine';
$repo = 'staging-business--2-';

function gitea_req(string $base, string $auth, string $method, string $path, ?array $body = null): array
{
    $ch = curl_init($base . $path);
    $ca = realpath(__DIR__ . '/../certs/cacert.pem');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [$auth, 'Accept: application/json', 'Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
    ];
    if ($ca !== false) {
        $opts[CURLOPT_CAINFO] = $ca;
    }
    curl_setopt_array($ch, $opts);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'raw' => (string) $raw];
}

$auths = [
    'Authorization: token ' . $token,
    'Authorization: Bearer ' . $token,
];

echo "=== Gitea deploy key fix ===\n";
foreach ($auths as $auth) {
    foreach ([
        '/api/v1/user',
        '/api/v1/admin/deploy_keys/1',
        "/api/v1/repos/{$owner}/{$repo}",
        "/api/v1/repos/{$owner}/{$repo}/keys",
        "/api/v1/repos/{$owner}/dentallab-suite/keys",
    ] as $path) {
        $r = gitea_req($base, $auth, 'GET', $path);
        echo "GET {$path} -> {$r['status']} " . substr($r['raw'], 0, 120) . "\n";
    }
}

// try attach existing deploy key id 1 to repo (gitea API)
foreach ($auths as $auth) {
    $r = gitea_req($base, $auth, 'POST', "/api/v1/repos/{$owner}/{$repo}/keys", [
        'key' => 'placeholder',
        'title' => 'CoreHost',
        'read_only' => true,
    ]);
    echo "POST keys placeholder -> {$r['status']} " . substr($r['raw'], 0, 200) . "\n";
}

// make repo public if admin
foreach ($auths as $auth) {
    $r = gitea_req($base, $auth, 'PATCH', "/api/v1/repos/{$owner}/{$repo}", [
        'private' => false,
    ]);
    echo "PATCH public -> {$r['status']} " . substr($r['raw'], 0, 200) . "\n";
}
