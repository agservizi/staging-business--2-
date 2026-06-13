<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$c = new CoreHostClient();

// Trigger mirror sync from GitHub via giteaManaged webhook
echo ">>> Sync mirror from GitHub production\n";
$c->request('PATCH', "/node-apps/{$id}", [
    'repository' => 'git@github.com:agservizi/staging-business--2-.git',
    'branch' => 'production',
    'nodeVersion' => '8.4',
]);
$c->request('POST', "/node-apps/{$id}/deploy");
sleep(30);

$c->request('PATCH', "/node-apps/{$id}", [
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
    'nodeVersion' => '8.4',
]);
$c->request('POST', "/node-apps/{$id}/deploy");
echo "redeploy production queued\n";

for ($i = 1; $i <= 40; $i++) {
    sleep(15);
    $a = $c->request('GET', "/node-apps/{$id}");
    $d = $a['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?') . ' commit=' . substr((string)($dep['commitMessage']??''),0,50) . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo "SUCCESS\n";
        exit(0);
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        $logs = (string)($dep['logs'] ?? '');
        if (!str_contains($logs, 'Initial commit') && str_contains($logs, 'Deploy completed')) {
            echo "likely ok\n";
        }
        echo substr($logs, -1200) . "\n";
        if ($i < 40 && (str_contains($logs, 'RUNNING') || str_contains($logs, 'BUILDING'))) {
            continue;
        }
        break;
    }
}
exit(1);
