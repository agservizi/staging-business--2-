<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = 'cmqbe9lbd07dm6ht4wonxnkk1';
$c = new CoreHostClient();
$repos = [
    'git@github.com:agservizi/staging-business--2-.git',
    'ssh://git@git.coresuite.it:2222/Carmine/staging-business--2-.git',
    'ssh://git@git.coresuite.it:2222/agservizi/staging-business--2-.git',
];
foreach ($repos as $repo) {
    echo ">>> repo={$repo}\n";
    $p = $c->request('PATCH', "/node-apps/{$appId}", ['repository' => $repo, 'branch' => 'production']);
    echo "PATCH {$p['status']}\n";
    if ($p['status'] >= 300) {
        continue;
    }
    $d = $c->request('POST', "/node-apps/{$appId}/deploy");
    echo "deploy queued {$d['status']}\n";
    sleep(25);
    $a = $c->request('GET', "/node-apps/{$appId}");
    $last = $a['body']['data']['deployments'][0] ?? [];
    echo 'status=' . ($last['status'] ?? '?') . "\n";
    if (($last['status'] ?? '') === 'SUCCESS') {
        echo "SUCCESS\n";
        exit(0);
    }
    echo substr((string) ($last['logs'] ?? ''), -800) . "\n\n";
}
exit(1);
