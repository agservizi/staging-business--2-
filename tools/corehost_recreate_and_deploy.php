<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$oldId = (string) env('COREHOST_APP_ID', 'cmqbek0kf07gt6ht4dhwubi16');

echo "Delete {$oldId}...\n";
$c->request('DELETE', "/node-apps/{$oldId}");

$payload = [
    'name' => 'coresuite-business',
    'runtime' => 'PHP',
    'repository' => 'git@github.com:agservizi/staging-business--2-.git',
    'branch' => 'production',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'startCmd' => 'php -S 0.0.0.0:8080 -t .',
    'healthPath' => '/',
    'autoDeploy' => true,
    'memoryLimit' => '512m',
];

echo "Create app...\n";
$r = $c->request('POST', '/node-apps', $payload);
echo 'POST HTTP ' . $r['status'] . "\n";
$newId = (string) ($r['body']['data']['id'] ?? '');
$data = $r['body']['data'] ?? [];
echo 'id=' . $newId . ' giteaManaged=' . (($data['giteaManaged'] ?? false) ? 'true' : 'false') . "\n";
echo 'giteaRepo=' . ($data['giteaRepo'] ?? 'null') . "\n";

if ($newId === '') {
    exit(1);
}

sleep(3);
$check = $c->request('GET', "/node-apps/{$newId}");
$d = $check['body']['data'] ?? [];
echo 'after GET giteaManaged=' . (($d['giteaManaged'] ?? false) ? 'true' : 'false') . "\n";
echo 'giteaDeployKeyId=' . ($d['giteaDeployKeyId'] ?? 'null') . "\n";

// try internal gitea repo
$c->request('PATCH', "/node-apps/{$newId}", [
    'repository' => 'ssh://git@gitea:22/Carmine/staging-business--2-.git',
    'branch' => 'production',
]);

echo "Deploy...\n";
$dep = $c->request('POST', "/node-apps/{$newId}/deploy");
echo 'deploy HTTP ' . $dep['status'] . "\n";

for ($i = 1; $i <= 18; $i++) {
    sleep(10);
    $a = $c->request('GET', "/node-apps/{$newId}");
    $ad = $a['body']['data'] ?? [];
    $last = $ad['deployments'][0] ?? [];
    echo "[{$i}] app=" . ($ad['status'] ?? '?') . ' deploy=' . ($last['status'] ?? '?') . "\n";
    if (($last['status'] ?? '') === 'SUCCESS' && ($ad['status'] ?? '') === 'RUNNING') {
        echo "NEW_APP_ID={$newId}\n";
        exit(0);
    }
    if (($last['status'] ?? '') === 'FAILED') {
        echo substr((string) ($last['logs'] ?? ''), -900) . "\n";
        echo "NEW_APP_ID={$newId}\n";
        exit(1);
    }
}
