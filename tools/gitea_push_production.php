#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');

$token = trim((string) env('COREHOST_API_TOKEN', ''));
if ($token === '') {
    fwrite(STDERR, "COREHOST_API_TOKEN mancante\n");
    exit(1);
}

$base = 'https://git.coresuite.it';
$owner = 'Carmine';
$repo = (string) env('COREHOST_GITEA_REPO', 'coresuite-business');
$projectRoot = dirname(__DIR__);

function gitea(string $base, string $token, string $method, string $path, ?array $body = null): array
{
    $ch = curl_init($base . $path);
    $ca = realpath(__DIR__ . '/../certs/cacert.pem');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: token ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
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
    return ['status' => $status, 'raw' => (string) $raw, 'err' => $err];
}

echo "=== Gitea mirror setup ===\n";

$user = gitea($base, $token, 'GET', '/api/v1/user');
echo 'auth user HTTP ' . $user['status'];
if ($user['err'] !== '') {
    echo ' err=' . $user['err'];
}
echo "\n";
if ($user['status'] !== 200) {
    echo "Token CoreHost non valido su Gitea. Provo push via git...\n";
} else {
    $data = json_decode($user['raw'], true);
    echo 'logged as: ' . ($data['login'] ?? '?') . "\n";
}

$check = gitea($base, $token, 'GET', "/api/v1/repos/{$owner}/{$repo}");
echo "repo check HTTP {$check['status']}\n";
if ($check['status'] === 404) {
    $create = gitea($base, $token, 'POST', '/api/v1/user/repos', [
        'name' => $repo,
        'private' => true,
        'auto_init' => false,
    ]);
    echo "create repo HTTP {$create['status']}\n";
    if ($create['status'] >= 300) {
        echo substr($create['raw'], 0, 300) . "\n";
    }
}

$pushUrl = 'https://oauth2:' . rawurlencode($token) . '@git.coresuite.it/' . $owner . '/' . $repo . '.git';
$cmds = [
    'git -C ' . escapeshellarg($projectRoot) . ' fetch origin production',
    'git -C ' . escapeshellarg($projectRoot) . ' push ' . escapeshellarg($pushUrl) . ' FETCH_HEAD:refs/heads/production --force',
];
foreach ($cmds as $cmd) {
    echo 'run: git push production to gitea' . "\n";
    passthru($cmd, $code);
    if ($code !== 0) {
        echo "git failed exit={$code}\n";
        exit($code);
    }
}

echo "Gitea mirror OK\n";
