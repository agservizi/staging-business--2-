<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$wid = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');

foreach (['', 'null', 'none'] as $pv) {
    $body = ['type' => 'REVERSE_PROXY', 'port' => 10008];
    if ($pv === 'null') {
        // skip key
    } else {
        $body['phpVersion'] = $pv;
    }
    $r = $c->request('PATCH', '/websites/' . $wid, $body);
    $d = $r['body']['data'] ?? [];
    echo "phpVersion sent=" . ($pv === 'null' ? '(omit)' : $pv) . " HTTP {$r['status']} got=" . json_encode($d['phpVersion'] ?? null) . " proxy=" . json_encode($d['proxyConfig'] ?? null) . "\n";
}

// Recreate website container via delete+patch trick: change domain alias then back?
echo "\n>>> PATCH app website link + startCmd 80\n";
$c->request('PATCH', '/node-apps/' . $appId, [
    'websiteId' => $wid,
    'startCmd' => 'php -S 0.0.0.0:80 -t .',
]);

echo ">>> website restart x2\n";
for ($i = 0; $i < 2; $i++) {
    try {
        $c->request('POST', '/websites/' . $wid . '/restart');
    } catch (Throwable $e) {
    }
    sleep(10);
}

$w = $c->request('GET', '/websites/' . $wid)['body']['data'] ?? [];
echo 'final phpVersion=' . json_encode($w['phpVersion'] ?? null) . ' proxy=' . json_encode($w['proxyConfig'] ?? null) . "\n";

$ch = curl_init('https://business.coresuite.it/');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
$body = (string) curl_exec($ch);
echo 'HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
curl_close($ch);
