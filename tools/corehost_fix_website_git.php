<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$app = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);

echo ">>> PATCH website like shop (no gitRepo)\n";
$r = $c->request('PATCH', "/websites/{$websiteId}", [
    'type' => 'REVERSE_PROXY',
    'port' => $port,
    'gitRepo' => null,
    'gitBranch' => null,
    'buildCmd' => null,
    'phpVersion' => null,
]);
echo 'HTTP ' . $r['status'] . "\n";
echo 'gitRepo=' . json_encode($r['body']['data']['gitRepo'] ?? null) . "\n";
echo 'proxyConfig=' . json_encode($r['body']['data']['proxyConfig'] ?? null) . "\n";

echo ">>> PATCH app startCmd port 80 (internal)\n";
$r2 = $c->request('PATCH', "/node-apps/{$appId}", [
    'websiteId' => $websiteId,
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'repository' => 'ssh://git@gitea:22/Carmine/coresuite-business.git',
    'branch' => 'production',
]);
echo 'startCmd=' . ($r2['body']['data']['startCmd'] ?? '?') . "\n";

$c->request('POST', "/node-apps/{$appId}/deploy");
for ($i = 1; $i <= 24; $i++) {
    sleep(10);
    $d = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    echo "[{$i}] " . ($dep['status'] ?? '?') . ' ' . ($d['status'] ?? '?') . "\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo substr((string)($dep['logs'] ?? ''), -800) . "\n";
        break;
    }
}

try { $c->request('POST', "/websites/{$websiteId}/restart"); } catch (Throwable $e) {}
sleep(10);

$w = $c->request('GET', "/websites/{$websiteId}")['body']['data'] ?? [];
echo 'final gitRepo=' . json_encode($w['gitRepo'] ?? null) . ' proxy=' . json_encode($w['proxyConfig'] ?? null) . "\n";

$ch = curl_init('https://business.coresuite.it/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_CAINFO => realpath(__DIR__ . '/../certs/cacert.pem') ?: '']);
$body = (string) curl_exec($ch);
echo 'HTTPS ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . substr(strip_tags($body), 0, 120) . "\n";
curl_close($ch);
