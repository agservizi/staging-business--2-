<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');

$app = $c->request('GET', "/node-apps/{$appId}");
$port = (int) ($app['body']['data']['port'] ?? 0);
echo "app port={$port}\n";

$res = $c->request('PATCH', "/websites/{$websiteId}", [
    'type' => 'REVERSE_PROXY',
    'port' => $port,
]);
echo 'PATCH website HTTP ' . $res['status'] . ' port=' . ($res['body']['data']['port'] ?? '?') . "\n";

try {
    $r = $c->request('POST', "/websites/{$websiteId}/restart");
    echo 'restart HTTP ' . $r['status'] . "\n";
} catch (Throwable $e) {
    echo 'restart: ' . $e->getMessage() . "\n";
}

sleep(5);
$w = $c->request('GET', "/websites/{$websiteId}");
echo 'website status=' . ($w['body']['data']['status'] ?? '?') . ' port=' . ($w['body']['data']['port'] ?? '?') . "\n";
