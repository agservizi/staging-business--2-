<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$attempts = [
    ['website' => ['phpVersion' => null, 'type' => 'REVERSE_PROXY', 'port' => 10008], 'app' => null],
    ['website' => ['phpVersion' => null, 'type' => 'REVERSE_PROXY', 'port' => 10008, 'indexFile' => null], 'app' => ['startCmd' => 'php -S 0.0.0.0:80 -t .']],
    ['website' => null, 'app' => ['giteaManaged' => false, 'repository' => 'git@github.com:agservizi/staging-business--2-.git', 'branch' => 'production', 'startCmd' => 'php -S 0.0.0.0:80 -t .']],
];

foreach ($attempts as $n => $attempt) {
    echo "=== attempt {$n} ===\n";
    if ($attempt['app']) {
        $r = $c->request('PATCH', "/node-apps/{$appId}", $attempt['app']);
        echo 'app PATCH ' . $r['status'] . ' start=' . ($r['body']['data']['startCmd'] ?? '?') . "\n";
    }
    if ($attempt['website']) {
        $r = $c->request('PATCH', "/websites/{$websiteId}", $attempt['website']);
        echo 'web PATCH ' . $r['status'] . ' proxy=' . json_encode($r['body']['data']['proxyConfig'] ?? null) . "\n";
    }
    $c->request('POST', "/node-apps/{$appId}/deploy");
    for ($i = 0; $i < 12; $i++) {
        sleep(10);
        $d = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
        $dep = $d['deployments'][0] ?? [];
        if (($dep['status'] ?? '') === 'SUCCESS' && ($d['status'] ?? '') === 'RUNNING') {
            echo 'deploy OK commit=' . substr((string)($dep['commitSha'] ?? ''), 0, 7) . "\n";
            break;
        }
    }
    try { $c->request('POST', "/websites/{$websiteId}/restart"); } catch (Throwable $e) {}
    sleep(8);
    $ch = curl_init('https://business.coresuite.it/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_NOBODY => true, CURLOPT_SSL_VERIFYPEER => true]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP {$code}\n\n";
    if ($code === 200) {
        exit(0);
    }
}
exit(1);
