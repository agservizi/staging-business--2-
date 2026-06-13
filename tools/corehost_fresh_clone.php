<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');

// cleanup failed apps
foreach ($c->request('GET', '/node-apps')['body']['data'] ?? [] as $app) {
    if (($app['name'] ?? '') === 'coresuite-business' || str_starts_with((string)($app['name'] ?? ''), 'coresuite-business')) {
        echo 'delete ' . $app['id'] . ' status=' . ($app['status'] ?? '?') . "\n";
        try {
            $c->request('DELETE', '/node-apps/' . $app['id']);
        } catch (Throwable $e) {
        }
    }
}

echo ">>> Fresh app new name/path\n";
$r = $c->request('POST', '/node-apps', [
    'name' => 'coresuite-business-v2',
    'runtime' => 'PHP',
    'repository' => 'https://github.com/agservizi/staging-business--2-.git',
    'branch' => 'production',
    'domain' => 'business.coresuite.it',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'healthPath' => '/',
    'autoDeploy' => true,
    'nodeVersion' => '8.4',
]);
$appId = (string) ($r['body']['data']['id'] ?? '');
echo 'id=' . $appId . "\n";
if ($appId === '') {
    exit(1);
}

$c->request('POST', '/node-apps/' . $appId . '/deploy');
for ($i = 1; $i <= 20; $i++) {
    sleep(10);
    $d = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $logs = (string)($dep['logs'] ?? '');
    echo "[{$i}] " . ($dep['status'] ?? '?') . ' ' . (str_contains($logs, 'Cloning') ? 'CLONE' : 'UPDATE') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS') {
        echo substr($logs, -800) . "\n";
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr($logs, -500) . "\n";
        if ($i === 20) {
            exit(1);
        }
    }
}
