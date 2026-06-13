<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$list = $c->request('GET', '/node-apps');
foreach (($list['body']['data'] ?? []) as $a) {
    if (($a['name'] ?? '') !== 'lumina') {
        continue;
    }
    $id = $a['id'];
    echo "lumina redeploy test...\n";
    $d = $c->request('POST', "/node-apps/{$id}/deploy");
    echo "queued HTTP {$d['status']}\n";
    sleep(25);
    $x = $c->request('GET', "/node-apps/{$id}");
    $dep = $x['body']['data']['deployments'][0] ?? [];
    echo 'deploy=' . ($dep['status'] ?? '?') . "\n";
    echo substr((string) ($dep['logs'] ?? ''), 0, 600) . "\n";
    break;
}
