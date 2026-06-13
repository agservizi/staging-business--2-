<?php
declare(strict_types=1);
/**
 * Ricrea website business.coresuite.it come REVERSE_PROXY (stile shop).
 */
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');
$dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');

$app = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);

echo ">>> Create website REVERSE_PROXY port={$port}\n";
$create = $c->request('POST', '/websites', [
    'domain' => $domain,
    'type' => 'REVERSE_PROXY',
    'port' => $port,
    'forceHttps' => true,
    'notes' => 'Coresuite Business - reverse proxy verso node app',
]);
echo 'POST /websites HTTP ' . $create['status'] . "\n";
$websiteId = (string) ($create['body']['data']['id'] ?? '');
if ($websiteId === '') {
    echo substr($create['raw'], 0, 400) . "\n";
    exit(1);
}
echo "new websiteId={$websiteId}\n";
echo 'proxy=' . json_encode($create['body']['data']['proxyConfig'] ?? null) . "\n";

echo ">>> Link app to website\n";
$c->request('PATCH', '/node-apps/' . $appId, [
    'websiteId' => $websiteId,
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
]);

try {
    $c->request('PATCH', '/databases/' . $dbId, ['websiteId' => $websiteId]);
} catch (Throwable $e) {
    echo 'db link: ' . $e->getMessage() . "\n";
}

echo ">>> SSL\n";
try {
    $ssl = $c->request('POST', '/ssl', ['domain' => $domain, 'websiteId' => $websiteId]);
    echo 'SSL HTTP ' . $ssl['status'] . "\n";
} catch (Throwable $e) {
    echo 'SSL: ' . $e->getMessage() . "\n";
}

$c->request('POST', '/node-apps/' . $appId . '/deploy');
for ($i = 1; $i <= 18; $i++) {
    sleep(10);
    $d = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] deploy=" . ($dep['status'] ?? '?') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        break;
    }
}

try {
    $c->request('POST', '/websites/' . $websiteId . '/restart');
} catch (Throwable $e) {
}

sleep(15);
$w = $c->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
echo 'website phpVersion=' . json_encode($w['phpVersion'] ?? null) . ' proxy=' . json_encode($w['proxyConfig'] ?? null) . "\n";
echo "UPDATE .env COREHOST_WEBSITE_ID={$websiteId}\n";

$ch = curl_init('https://' . $domain . '/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "HTTPS {$code} " . substr(strip_tags($body), 0, 120) . "\n";

// Persist new website id locally
$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $env = file_get_contents($envPath);
    if ($env !== false) {
        $env = preg_replace('/^COREHOST_WEBSITE_ID=.*$/m', 'COREHOST_WEBSITE_ID=' . $websiteId, $env) ?? $env;
        file_put_contents($envPath, $env);
    }
}

exit($code === 200 ? 0 : 1);
