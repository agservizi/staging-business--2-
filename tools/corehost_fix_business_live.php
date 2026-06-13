<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$app = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);

echo ">>> PATCH app startCmd uses PORT ({$port})\n";
$c->request('PATCH', "/node-apps/{$appId}", [
    'websiteId' => $websiteId,
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
    'nodeVersion' => '8.4',
    'startCmd' => 'php -S 0.0.0.0:${PORT} -t .',
    'buildCmd' => 'echo corehost-rebuild-' . date('YmdHis'),
    'healthPath' => '/',
]);

echo ">>> PATCH website: clear proxyConfig, match port\n";
$payloads = [
    ['type' => 'REVERSE_PROXY', 'port' => $port, 'proxyConfig' => null],
    ['type' => 'REVERSE_PROXY', 'port' => $port],
    ['port' => $port],
];
foreach ($payloads as $i => $body) {
    $r = $c->request('PATCH', "/websites/{$websiteId}", $body);
    $pc = $r['body']['data']['proxyConfig'] ?? 'missing';
    echo "payload#{$i} HTTP {$r['status']} proxyConfig=" . json_encode($pc) . "\n";
}

echo ">>> Deploy app (force rebuild)\n";
$c->request('POST', "/node-apps/{$appId}/deploy");

for ($i = 1; $i <= 30; $i++) {
    sleep(10);
    $a = $c->request('GET', "/node-apps/{$appId}");
    $d = $a['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $logs = (string)($dep['logs'] ?? '');
    echo "[{$i}] deploy=" . ($dep['status'] ?? '?') . ' start=' . ($d['startCmd'] ?? '?')
        . (str_contains($logs, 'Reusing image') ? ' cached' : ' rebuilt')
        . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo substr($logs, -1200) . "\n";
        break;
    }
    if (($dep['status'] ?? '') === 'FAILED') {
        echo substr($logs, -1200) . "\n";
        exit(1);
    }
}

echo ">>> Restart website\n";
try {
    $c->request('POST', "/websites/{$websiteId}/restart");
} catch (Throwable $e) {
    echo $e->getMessage() . "\n";
}
sleep(10);

$w = $c->request('GET', "/websites/{$websiteId}")['body']['data'] ?? [];
echo 'final proxyConfig=' . json_encode($w['proxyConfig'] ?? null) . " port=" . ($w['port'] ?? '?') . "\n";

$ch = curl_init('https://business.coresuite.it/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_CAINFO => realpath(__DIR__ . '/../certs/cacert.pem') ?: '',
]);
$body = (string) curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
echo "probe HTTPS {$code} " . substr(strip_tags($body), 0, 100) . "\n";
exit($code === 200 ? 0 : 1);
