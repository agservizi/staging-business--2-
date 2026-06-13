<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = 'cmqbe9lbd07dm6ht4wonxnkk1';
$c = new CoreHostClient();
$paths = [
    ['POST', "/node-apps/{$appId}/gitea/sync", null],
    ['POST', "/node-apps/{$appId}/gitea/setup", ['repository' => 'https://github.com/agservizi/staging-business--2-.git']],
    ['POST', "/node-apps/{$appId}/mirror", ['url' => 'https://github.com/agservizi/staging-business--2-.git']],
    ['GET', '/gitea/status'],
];
foreach ($paths as [$method, $path, $body]) {
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 250) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
