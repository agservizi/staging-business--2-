<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('COREHOST_API_TOKEN', ''));
$base = 'https://git.coresuite.it';
$owner = 'Carmine';
$repo = 'staging-business--2-';

function try_gitea(string $base, string $authHeader, string $method, string $path, ?array $body = null): void
{
    $ch = curl_init($base . $path);
    $ca = realpath(__DIR__ . '/../certs/cacert.pem');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [$authHeader, 'Accept: application/json', 'Content-Type: application/json'],
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
    $err = curl_error($ch);
    curl_close($ch);
    echo "{$method} {$path} [{$authHeader}] -> HTTP {$status}";
    if ($err !== '') {
        echo " curl_err={$err}";
    }
    echo "\n" . substr((string) $raw, 0, 200) . "\n\n";
}

echo "=== Gitea auth probe ===\n";
$auths = [
    'Authorization: token ' . $token,
    'Authorization: Bearer ' . $token,
];
foreach ($auths as $auth) {
    try_gitea($base, $auth, 'GET', '/api/v1/user');
    try_gitea($base, $auth, 'GET', "/api/v1/repos/{$owner}/{$repo}");
}

try_gitea($base, 'Authorization: token ' . $token, 'POST', '/api/v1/user/repos', [
    'name' => $repo,
    'private' => true,
    'auto_init' => false,
]);
