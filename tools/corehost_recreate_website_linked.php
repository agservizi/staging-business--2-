<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$oldWid = (string) env('COREHOST_WEBSITE_ID', 'cmqbyvouc00ge101chhl9i5bn');
$domain = (string) env('COREHOST_SITE_DOMAIN', 'business.coresuite.it');

$app = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);

echo ">>> Delete broken website {$oldWid}\n";
try {
    $c->request('DELETE', '/websites/' . $oldWid);
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}

$payloads = [
    [
        'domain' => $domain,
        'type' => 'REVERSE_PROXY',
        'port' => $port,
        'nodeAppId' => $appId,
        'forceHttps' => true,
    ],
    [
        'domain' => $domain,
        'type' => 'REVERSE_PROXY',
        'port' => $port,
        'appId' => $appId,
        'forceHttps' => true,
    ],
];

$newId = '';
foreach ($payloads as $i => $payload) {
    echo ">>> POST websites attempt {$i}\n";
    $r = $c->request('POST', '/websites', $payload);
    echo 'HTTP ' . $r['status'] . ' ';
    if ($r['status'] >= 400) {
        echo substr($r['raw'], 0, 200) . "\n";
        continue;
    }
    $newId = (string) ($r['body']['data']['id'] ?? '');
    $na = $r['body']['data']['nodeApp']['id'] ?? null;
    echo "id={$newId} nodeApp=" . ($na ?: 'null') . ' proxy=' . json_encode($r['body']['data']['proxyConfig'] ?? null) . "\n";
    if ($newId !== '') {
        break;
    }
}

if ($newId === '') {
    exit(1);
}

$c->request('PATCH', '/node-apps/' . $appId, ['startCmd' => 'php -S 0.0.0.0:80 -t .']);
try {
    $c->request('POST', '/websites/' . $newId . '/ssl');
} catch (Throwable $e) {
    try {
        $c->request('POST', '/ssl', ['domain' => $domain, 'websiteId' => $newId]);
    } catch (Throwable $e2) {
    }
}

$c->request('POST', '/node-apps/' . $appId . '/restart');
try {
    $c->request('POST', '/websites/' . $newId . '/restart');
} catch (Throwable $e) {
}
sleep(15);

$envPath = dirname(__DIR__) . '/.env';
if (is_file($envPath)) {
    $env = file_get_contents($envPath);
    if ($env !== false) {
        $env = preg_replace('/^COREHOST_WEBSITE_ID=.*$/m', 'COREHOST_WEBSITE_ID=' . $newId, $env) ?? $env;
        file_put_contents($envPath, $env);
    }
}
echo "COREHOST_WEBSITE_ID={$newId}\n";

$ch = curl_init('https://' . $domain . '/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25]);
$body = (string) curl_exec($ch);
echo 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . substr(strip_tags($body), 0, 120) . "\n";
curl_close($ch);
