<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$c = new CoreHostClient();
$c->request('PATCH', "/node-apps/{$id}", [
    'nodeVersion' => '8.4',
    'branch' => 'main',
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
]);
$c->request('POST', "/node-apps/{$id}/deploy");
echo "deploy main + php8.4 queued\n";
for ($i = 1; $i <= 36; $i++) {
    sleep(10);
    $a = $c->request('GET', "/node-apps/{$id}");
    $d = $a['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo "RUNNING preview=https://panel.coresuite.it/preview/" . ($d['previewSlug'] ?? '') . "\n";
        exit(0);
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string) ($dep['logs'] ?? ''), -1500) . "\n";
        exit(1);
    }
}
