<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = 'cmqbe9lbd07dm6ht4wonxnkk1';
$websiteId = 'cmqbddt4v078v6ht4hm8posiz';
$c = new CoreHostClient();
$tries = [
    ['PATCH', "/node-apps/{$appId}", ['websiteId' => $websiteId]],
    ['PATCH', '/websites/' . $websiteId, ['nodeAppId' => $appId]],
    ['POST', '/websites/' . $websiteId . '/link-app', ['nodeAppId' => $appId]],
];
foreach ($tries as [$method, $path, $body]) {
    $r = $c->request($method, $path, $body);
    echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 250) . "\n";
}
