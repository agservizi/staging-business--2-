<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbz61e300k1101c2unued6n');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');

echo ">>> Setup gitea + deploy\n";
$c->request('PATCH', '/node-apps/' . $appId, [
    'repository' => 'git@github.com:agservizi/staging-business--2-.git',
    'branch' => 'production',
    'nodeVersion' => '8.4',
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'autoDeploy' => true,
]);
$c->request('POST', '/node-apps/' . $appId . '/deploy');
sleep(30);

$a = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
echo 'giteaManaged=' . (($a['giteaManaged'] ?? false) ? 'true' : 'false') . ' giteaRepo=' . ($a['giteaRepo'] ?? 'null') . "\n";

$c->request('PATCH', '/node-apps/' . $appId, [
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
]);
$c->request('POST', '/node-apps/' . $appId . '/deploy');

for ($i = 1; $i <= 24; $i++) {
    sleep(10);
    $d = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] app=" . ($d['status'] ?? '?') . ' deploy=' . ($dep['status'] ?? '?') . ' gitea=' . (($d['giteaManaged'] ?? false) ? 'Y' : 'N') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string)($dep['logs'] ?? ''), -800) . "\n";
        if ($i < 24) {
            continue;
        }
        exit(1);
    }
}

$c->request('PATCH', '/websites/' . $websiteId, ['type' => 'REVERSE_PROXY', 'port' => (int)($d['port'] ?? 10008)]);
try {
    $c->request('POST', '/websites/' . $websiteId . '/restart');
    $c->request('POST', '/ssl', ['domain' => 'business.coresuite.it', 'websiteId' => $websiteId]);
} catch (Throwable $e) {
}

$w = $c->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
echo 'nodeApp=' . ($w['nodeApp']['id'] ?? 'null') . "\n";
