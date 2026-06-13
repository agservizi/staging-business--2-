<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$appId = (string) env('COREHOST_APP_ID', 'cmqbzop1t00rk101c788vjnmd');
$expected = substr((string) ($argv[1] ?? 'f09dad2'), 0, 7);
$c = new CoreHostClient();

echo ">>> PATCH GitHub production (force gitea sync)\n";
$c->request('PATCH', "/node-apps/{$appId}", [
    'repository' => 'https://github.com/agservizi/staging-business--2-.git',
    'branch' => 'production',
]);
sleep(20);

echo ">>> deploy\n";
$c->request('POST', "/node-apps/{$appId}/deploy");

for ($i = 1; $i <= 36; $i++) {
    sleep(10);
    $d = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $sha = substr((string) ($dep['commitSha'] ?? ''), 0, 7);
    $sync = (string) ($d['giteaLastSyncAt'] ?? '');
    echo "[{$i}] deploy={$dep['status']} commit={$sha} sync={$sync}\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && $sha === $expected) {
        echo "OK expected commit\n";
        $c->request('PATCH', "/node-apps/{$appId}", [
            'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
            'branch' => 'production',
        ]);
        exit(0);
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string) ($dep['logs'] ?? ''), -600) . "\n";
        exit(1);
    }
}
exit(1);
