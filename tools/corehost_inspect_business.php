<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');
$app = $c->request('GET', "/node-apps/{$appId}")['body']['data'] ?? [];
$dep = $app['deployments'][0] ?? [];
echo "startCmd=" . ($app['startCmd'] ?? '?') . "\n";
echo "port=" . ($app['port'] ?? '?') . "\n";
echo "commit=" . substr((string)($dep['commitSha'] ?? ''), 0, 7) . "\n";
$logs = (string)($dep['logs'] ?? '');
echo "deploy_tail:\n" . substr($logs, -2000) . "\n\n";
$w = $c->request('GET', "/websites/{$websiteId}")['body']['data'] ?? [];
echo "website.type=" . ($w['type'] ?? '?') . " port=" . ($w['port'] ?? '?') . "\n";
echo "proxyConfig=" . json_encode($w['proxyConfig'] ?? null) . "\n";
echo "nodeAppId=" . ($w['nodeAppId'] ?? ($w['nodeApp']['id'] ?? 'null')) . "\n";
