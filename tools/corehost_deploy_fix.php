<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$c = new CoreHostClient();

echo ">>> Fix PHP version + sync GitHub production\n";
$patches = [
    ['nodeVersion' => '8.4', 'branch' => 'production', 'repository' => 'git@github.com:agservizi/staging-business--2-.git'],
    ['nodeVersion' => '8.4', 'branch' => 'production', 'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git'],
];
foreach ($patches as $i => $patch) {
    echo "\n=== Patch set " . ($i + 1) . " ===\n";
    $r = $c->request('PATCH', "/node-apps/{$id}", $patch);
    echo 'PATCH HTTP ' . $r['status'] . ' nodeVersion=' . ($r['body']['data']['nodeVersion'] ?? '?') . "\n";

    $c->request('POST', "/node-apps/{$id}/deploy");
    echo "deploy queued\n";
    for ($n = 1; $n <= 24; $n++) {
        sleep(10);
        $a = $c->request('GET', "/node-apps/{$id}");
        $d = $a['body']['data'] ?? [];
        $dep = $d['deployments'][0] ?? [];
        $msg = (string) ($dep['commitMessage'] ?? '');
        echo "[{$n}] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?') . ' commit=' . substr($msg, 0, 40) . "\n";
        if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
            echo "SUCCESS\n";
            exit(0);
        }
        if (($dep['status'] ?? '') === 'FAILED') {
            echo substr((string) ($dep['logs'] ?? ''), -1200) . "\n";
            break;
        }
    }
}
exit(1);
