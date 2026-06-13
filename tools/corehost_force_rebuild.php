<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$id = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$wid = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$c->request('PATCH', '/node-apps/' . $id, [
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
    'buildCmd' => 'date > .corehost-rebuild',
    'installCmd' => 'composer install --no-dev --no-interaction',
]);
$c->request('PATCH', '/websites/' . $wid, [
    'phpVersion' => null,
    'type' => 'REVERSE_PROXY',
    'port' => 10008,
]);
$c->request('POST', '/node-apps/' . $id . '/deploy');

for ($i = 1; $i <= 24; $i++) {
    sleep(10);
    $d = $c->request('GET', '/node-apps/' . $id)['body']['data'] ?? [];
    $dep = $d['deployments'][0] ?? [];
    $logs = (string)($dep['logs'] ?? '');
    $tag = str_contains($logs, 'Reusing image') ? 'CACHE' : 'BUILD';
    echo "[{$i}] " . ($dep['status'] ?? '?') . " {$tag}\n";
    if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
        echo substr($logs, -800) . "\n";
        break;
    }
}

try {
    $c->request('POST', '/websites/' . $wid . '/restart');
} catch (Throwable $e) {
}
sleep(10);

$ch = curl_init('https://business.coresuite.it/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = (string) curl_exec($ch);
echo 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . ' ' . substr(strip_tags($body), 0, 100) . "\n";
curl_close($ch);
