<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$brokenId = (string) env('COREHOST_APP_ID', 'cmqbz61e300k1101c2unued6n');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');

echo ">>> Delete broken app\n";
try {
    $c->request('DELETE', '/node-apps/' . $brokenId);
} catch (Throwable $e) {
}

echo ">>> Create app from GitHub HTTPS (no gitea)\n";
$r = $c->request('POST', '/node-apps', [
    'name' => 'coresuite-business',
    'runtime' => 'PHP',
    'repository' => 'https://github.com/agservizi/staging-business--2-.git',
    'branch' => 'production',
    'domain' => 'business.coresuite.it',
    'installCmd' => 'composer install --no-dev --no-interaction',
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'healthPath' => '/',
    'autoDeploy' => true,
    'nodeVersion' => '8.4',
    'memoryLimit' => '512m',
]);
$appId = (string) ($r['body']['data']['id'] ?? '');
echo 'HTTP ' . $r['status'] . ' id=' . $appId . ' gitea=' . (($r['body']['data']['giteaManaged'] ?? false) ? 'Y' : 'N') . "\n";
if ($appId === '') {
    echo $r['raw'] . "\n";
    exit(1);
}

// env vars from .env
$vars = [
    'APP_ENV' => 'production',
    'APP_URL' => 'https://business.coresuite.it',
    'DB_HOST' => 'corehost-db-6ht49c20gmw3',
    'DB_PORT' => '23307',
    'DB_DATABASE' => 'coresuite_business',
    'DB_USERNAME' => 'dbuser',
    'CAF_PATRONATO_ENCRYPTION_KEY' => (string) env('CAF_PATRONATO_ENCRYPTION_KEY', ''),
    'AUTOMATA_BASE_URL' => 'https://automa.coresuite.it',
];
$db = $c->request('GET', '/databases/cmqbdh0iw079g6ht49c20gmw3')['body']['data'] ?? [];
$u = $db['dbUsers'][0] ?? [];
if ($u) {
    $vars['DB_PASSWORD'] = (string) ($u['password'] ?? '');
}
foreach ($vars as $k => $v) {
    if ($v === '') {
        continue;
    }
    $c->request('POST', '/env-vars', [
        'nodeAppId' => $appId,
        'key' => $k,
        'value' => $v,
        'isSecret' => in_array($k, ['DB_PASSWORD', 'CAF_PATRONATO_ENCRYPTION_KEY'], true),
    ]);
}

$c->request('POST', '/node-apps/' . $appId . '/deploy');
for ($i = 1; $i <= 24; $i++) {
    sleep(10);
    $d = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] " . ($d['status'] ?? '?') . ' ' . ($dep['status'] ?? '?') . ' ' . substr((string)($dep['commitSha'] ?? ''), 0, 7) . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo substr((string)($dep['logs'] ?? ''), -600) . "\n";
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr((string)($dep['logs'] ?? ''), -600) . "\n";
    }
}

$c->request('PATCH', '/websites/' . $websiteId, ['type' => 'REVERSE_PROXY', 'port' => (int)($d['port'] ?? 10008)]);
try {
    $c->request('POST', '/websites/' . $websiteId . '/restart');
} catch (Throwable $e) {
}

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $txt = file_get_contents($envPath);
    if ($txt !== false) {
        $txt = preg_replace('/^COREHOST_APP_ID=.*$/m', 'COREHOST_APP_ID=' . $appId, $txt) ?? $txt;
        file_put_contents($envPath, $txt);
    }
}
echo "APP_ID={$appId}\n";
