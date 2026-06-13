<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$app = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);
$host = 'corehost-t4vgas0xmg';

$targets = [
    "http://{$host}:{$port}",
    "http://{$host}:8080",
    "http://{$host}:80",
];

foreach ($targets as $target) {
    echo ">>> PATCH proxy target={$target}\n";
    $r = $c->request('PATCH', "/websites/{$websiteId}", [
        'type' => 'REVERSE_PROXY',
        'port' => $port,
        'nodeAppId' => $appId,
        'proxyConfig' => ['target' => $target],
    ]);
    echo 'PATCH HTTP ' . $r['status'] . ' proxy=' . json_encode($r['body']['data']['proxyConfig'] ?? null) . "\n";
    try {
        $c->request('POST', "/websites/{$websiteId}/restart");
    } catch (Throwable $e) {
        echo 'restart err: ' . $e->getMessage() . "\n";
    }
    sleep(8);
    $ch = curl_init('https://business.coresuite.it/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_CAINFO => realpath(__DIR__ . '/../certs/cacert.pem') ?: '',
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $snippet = substr(strip_tags($body), 0, 120);
    echo "probe HTTPS -> {$code} {$snippet}\n\n";
    if ($code === 200 && str_contains($body, 'login')) {
        echo "SUCCESS with {$target}\n";
        exit(0);
    }
}

echo "No target worked\n";
exit(1);
