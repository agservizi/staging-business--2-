<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbyvouc00ge101chhl9i5bn');

$app = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
echo 'app.websiteId=' . ($app['websiteId'] ?? 'null') . "\n";

$tries = [
    ['PATCH', '/node-apps/' . $appId, ['websiteId' => $websiteId]],
    ['PATCH', '/websites/' . $websiteId, ['port' => (int)($app['port'] ?? 10008), 'type' => 'REVERSE_PROXY']],
];

foreach ($tries as [$method, $path, $body]) {
    $r = $c->request($method, $path, $body);
    echo "{$method} {$path} -> {$r['status']}\n";
}

$app2 = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
$w = $c->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
echo 'after app.websiteId=' . ($app2['websiteId'] ?? 'null') . "\n";
echo 'website.nodeApp=' . (($w['nodeApp']['id'] ?? null) ?: 'null') . "\n";
echo 'website.proxy=' . json_encode($w['proxyConfig'] ?? null) . "\n";

$c->request('POST', '/node-apps/' . $appId . '/restart');
try { $c->request('POST', '/websites/' . $websiteId . '/restart'); } catch (Throwable $e) {}
sleep(12);

$ch = curl_init('https://business.coresuite.it/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = (string) curl_exec($ch);
echo 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . substr(strip_tags($body), 0, 100) . "\n";
curl_close($ch);
