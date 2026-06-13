<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbzop1t00rk101c788vjnmd');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');

$app = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);
echo "app port={$port}\n";

$c->request('PATCH', '/node-apps/' . $appId, [
    'startCmd' => "php -S 0.0.0.0:{$port} -t .",
    'websiteId' => $websiteId,
]);
$c->request('PATCH', '/websites/' . $websiteId, [
    'type' => 'REVERSE_PROXY',
    'port' => $port,
    'proxyConfig' => null,
]);
$c->request('POST', '/node-apps/' . $appId . '/deploy');

for ($i = 1; $i <= 20; $i++) {
    sleep(8);
    $d = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] app={$d['status']} deploy={$dep['status']}\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        break;
    }
}

$c->request('POST', '/websites/' . $websiteId . '/restart');
sleep(12);

$ch = curl_init('https://business.coresuite.it/');
$ca = realpath(__DIR__ . '/../certs/cacert.pem');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => $ca ?: '',
]);
curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "probe HTTP {$code}\n";
exit($code === 200 ? 0 : 1);
