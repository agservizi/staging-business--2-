<?php
declare(strict_types=1);
/**
 * Recovery: ricrea website + node-app con collegamento corretto.
 */
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$oldAppId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');

// Backup env vars
$old = $c->request('GET', '/node-apps/' . $oldAppId)['body']['data'] ?? [];
$envBackup = $old['envVars'] ?? [];
echo 'backup env count=' . count($envBackup) . "\n";

// Remove orphan websites for domain
$list = $c->request('GET', '/websites');
foreach (($list['body']['data'] ?? []) as $w) {
    if (($w['domain'] ?? '') === $domain) {
        echo 'delete orphan website ' . $w['id'] . "\n";
        $c->request('DELETE', '/websites/' . $w['id']);
    }
}

echo ">>> Create website\n";
$port = (int) ($old['port'] ?? 10008);
$wRes = $c->request('POST', '/websites', [
    'domain' => $domain,
    'type' => 'REVERSE_PROXY',
    'port' => $port,
    'forceHttps' => true,
    'notes' => 'Coresuite Business WOW',
]);
$websiteId = (string) ($wRes['body']['data']['id'] ?? '');
echo "websiteId={$websiteId}\n";
if ($websiteId === '') {
    exit(1);
}

echo ">>> Delete old app {$oldAppId}\n";
$c->request('DELETE', '/node-apps/' . $oldAppId);

echo ">>> Create new app\n";
$payloads = [
    [
        'name' => 'coresuite-business',
        'runtime' => 'PHP',
        'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
        'branch' => 'production',
        'websiteId' => $websiteId,
        'installCmd' => 'composer install --no-dev --no-interaction',
        'startCmd' => 'php -S 0.0.0.0:80 -t .',
        'healthPath' => '/',
        'autoDeploy' => true,
        'nodeVersion' => '8.4',
        'memoryLimit' => '512m',
    ],
    [
        'name' => 'coresuite-business',
        'runtime' => 'PHP',
        'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
        'branch' => 'production',
        'domain' => $domain,
        'installCmd' => 'composer install --no-dev --no-interaction',
        'startCmd' => 'php -S 0.0.0.0:80 -t .',
        'healthPath' => '/',
        'autoDeploy' => true,
        'nodeVersion' => '8.4',
    ],
];

$newAppId = '';
foreach ($payloads as $i => $payload) {
    $r = $c->request('POST', '/node-apps', $payload);
    echo "create#{$i} HTTP {$r['status']} ";
    if ($r['status'] < 300) {
        $newAppId = (string) ($r['body']['data']['id'] ?? '');
        echo "id={$newAppId} websiteId=" . ($r['body']['data']['websiteId'] ?? 'null') . "\n";
        break;
    }
    echo substr($r['raw'], 0, 180) . "\n";
}

if ($newAppId === '') {
    exit(1);
}

echo ">>> Restore env vars\n";
foreach ($envBackup as $ev) {
    if (!is_array($ev)) {
        continue;
    }
    $key = (string) ($ev['key'] ?? '');
    $value = (string) ($ev['value'] ?? '');
    if ($key === '') {
        continue;
    }
    $c->request('POST', '/env-vars', [
        'nodeAppId' => $newAppId,
        'key' => $key,
        'value' => $value,
        'isSecret' => (bool) ($ev['isSecret'] ?? false),
    ]);
}

try {
    $c->request('PATCH', '/databases/' . $dbId, ['nodeAppId' => $newAppId, 'websiteId' => $websiteId]);
} catch (Throwable $e) {
}

$c->request('POST', '/node-apps/' . $newAppId . '/deploy');
for ($i = 1; $i <= 20; $i++) {
    sleep(10);
    $d = $c->request('GET', '/node-apps/' . $newAppId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] " . ($dep['status'] ?? '?') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        break;
    }
}

try {
    $c->request('POST', '/ssl', ['domain' => $domain, 'websiteId' => $websiteId]);
} catch (Throwable $e) {
}

$w = $c->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
echo 'website nodeApp=' . ($w['nodeApp']['id'] ?? 'null') . ' proxy=' . json_encode($w['proxyConfig'] ?? null) . "\n";

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $txt = file_get_contents($envPath);
    if ($txt !== false) {
        $txt = preg_replace('/^COREHOST_APP_ID=.*$/m', 'COREHOST_APP_ID=' . $newAppId, $txt) ?? $txt;
        $txt = preg_replace('/^COREHOST_WEBSITE_ID=.*$/m', 'COREHOST_WEBSITE_ID=' . $websiteId, $txt) ?? $txt;
        file_put_contents($envPath, $txt);
    }
}
echo "COREHOST_APP_ID={$newAppId}\nCOREHOST_WEBSITE_ID={$websiteId}\n";

sleep(15);
$ch = curl_init('https://' . $domain . '/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
$body = (string) curl_exec($ch);
echo 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . substr(strip_tags($body), 0, 120) . "\n";
curl_close($ch);
