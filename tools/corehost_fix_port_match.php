<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbyvouc00ge101chhl9i5bn');

$app = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
$port = (int) ($app['port'] ?? 10008);

$cmds = [
    "php -S 0.0.0.0:{$port} -t .",
    'php -S 0.0.0.0:${PORT} -t .',
    'php -S 0.0.0.0:80 -t .',
];

foreach ($cmds as $startCmd) {
    echo ">>> startCmd={$startCmd}\n";
    $c->request('PATCH', '/node-apps/' . $appId, [
        'websiteId' => $websiteId,
        'startCmd' => $startCmd,
        'buildCmd' => 'echo ' . md5($startCmd),
    ]);
    $c->request('PATCH', '/websites/' . $websiteId, ['type' => 'REVERSE_PROXY', 'port' => $port]);
    $c->request('POST', '/node-apps/' . $appId . '/deploy');

    for ($i = 1; $i <= 15; $i++) {
        sleep(8);
        $d = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
        $dep = $d['deployments'][0] ?? [];
        if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
            $logs = (string)($dep['logs'] ?? '');
            if (preg_match('/Start: (.+)/', $logs, $m)) {
                echo 'Start: ' . trim($m[1]) . "\n";
            }
            break;
        }
    }

    try {
        $c->request('POST', '/websites/' . $websiteId . '/restart');
    } catch (Throwable $e) {
    }
    sleep(10);

    $ch = curl_init('https://business.coresuite.it/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP {$code} " . substr(strip_tags($body), 0, 100) . "\n\n";
    if ($code === 200) {
        exit(0);
    }
}
exit(1);
