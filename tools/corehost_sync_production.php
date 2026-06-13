#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Allinea Gitea (mirror CoreHost) con GitHub production e redeploya.
 * Usage: php tools/corehost_sync_production.php
 */

require_once __DIR__ . '/corehost_client.php';

$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$branch = (string) env('COREHOST_GIT_BRANCH', 'production');
$client = new CoreHostClient();

echo "=== Sync production GitHub -> Gitea -> CoreHost ===\n\n";

// 1) Push branch production su Gitea (repo usato dall'app)
$pushScript = __DIR__ . '/gitea_push_production.php';
if (is_file($pushScript)) {
    passthru('php ' . escapeshellarg($pushScript), $code);
    if ($code !== 0) {
        fwrite(STDERR, "Push Gitea fallito (exit {$code})\n");
    }
}

// 2) Trigger sync mirror gestito da CoreHost (se disponibile)
foreach (['/node-apps/' . $appId . '/gitea/sync'] as $path) {
    try {
        $r = $client->request('POST', $path);
        echo "POST {$path} HTTP {$r['status']}\n";
    } catch (Throwable $e) {
        echo "POST {$path} skip: {$e->getMessage()}\n";
    }
}

// 3) Allinea website al pattern shop (proxy auto, no git sul sito)
$app = $client->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);

echo "\n>>> PATCH website (no gitRepo, port={$port})\n";
$client->request('PATCH', '/websites/' . $websiteId, [
    'type' => 'REVERSE_PROXY',
    'port' => $port,
    'gitRepo' => null,
    'gitBranch' => null,
    'buildCmd' => null,
]);

echo ">>> PATCH app (PHP su porta interna 80, come proxy CoreHost)\n";
$client->request('PATCH', '/node-apps/' . $appId, [
    'websiteId' => $websiteId,
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => $branch,
    'nodeVersion' => '8.4',
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'healthPath' => '/',
]);

echo ">>> Deploy\n";
$client->request('POST', '/node-apps/' . $appId . '/deploy');

for ($i = 1; $i <= 30; $i++) {
    sleep(10);
    $d = $client->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $sha = substr((string)($dep['commitSha'] ?? ''), 0, 7);
    echo "[{$i}] app=" . ($d['status'] ?? '?') . " deploy=" . ($dep['status'] ?? '?') . " commit={$sha}\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string)($dep['logs'] ?? ''), -1200) . "\n";
        exit(1);
    }
}

try {
    $client->request('POST', '/websites/' . $websiteId . '/restart');
} catch (Throwable $e) {
    echo 'website restart: ' . $e->getMessage() . "\n";
}

$w = $client->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
echo "\nwebsite proxy=" . json_encode($w['proxyConfig'] ?? null) . "\n";
echo "live: https://business.coresuite.it/\n";
