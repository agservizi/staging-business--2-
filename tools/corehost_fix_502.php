<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbz5nuq00ju101cegni8luy');
$appId = (string) env('COREHOST_APP_ID', 'cmqbzop1t00rk101c788vjnmd');
$c = new CoreHostClient();

echo ">>> Clear nginxRules\n";
$r = $c->request('PATCH', '/websites/' . $websiteId, ['nginxRules' => null]);
echo 'PATCH HTTP ' . $r['status'] . ' ' . substr($r['raw'], 0, 200) . PHP_EOL;

echo ">>> Restart website + app\n";
$c->request('POST', '/websites/' . $websiteId . '/restart');
$c->request('POST', '/node-apps/' . $appId . '/restart');

sleep(15);
$w = $c->request('GET', '/websites/' . $websiteId)['body']['data'] ?? [];
$a = $c->request('GET', '/node-apps/' . $appId)['body']['data'] ?? [];
echo 'website=' . ($w['status'] ?? '?') . ' app=' . ($a['status'] ?? '?') . PHP_EOL;
