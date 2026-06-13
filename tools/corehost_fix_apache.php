<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

echo ">>> PATCH app: Apache default + force rebuild hint\n";
$c->request('PATCH', "/node-apps/{$appId}", [
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
    'nodeVersion' => '8.4',
    'startCmd' => null,
    'installCmd' => 'composer install --no-dev --no-interaction',
    'healthPath' => '/',
]);

echo ">>> Deploy\n";
$c->request('POST', "/node-apps/{$appId}/deploy");

for ($i = 1; $i <= 30; $i++) {
    sleep(10);
    $a = $c->request('GET', "/node-apps/{$appId}");
    $d = $a['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $logs = (string)($dep['logs'] ?? '');
    $reuse = str_contains($logs, 'Reusing image');
    echo "[{$i}] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?')
        . ' start=' . ($d['startCmd'] ?? 'default')
        . ' port=' . ($d['port'] ?? '?')
        . ($reuse ? ' (cached)' : '')
        . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo substr($logs, -1500) . "\n";
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr($logs, -1500) . "\n";
        exit(1);
    }
}

$port = (int) ($d['port'] ?? 80);
$c->request('PATCH', "/websites/{$websiteId}", ['type' => 'REVERSE_PROXY', 'port' => $port]);
try {
    $c->request('POST', "/websites/{$websiteId}/restart");
} catch (Throwable $e) {
    echo 'website restart: ' . $e->getMessage() . "\n";
}
echo "done port={$port}\n";
