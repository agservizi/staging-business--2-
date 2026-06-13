<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$appId = 'cmqbek0kf07gt6ht4dhwubi16';
$c = new CoreHostClient();
$paths = [
    ['POST', "/node-apps/{$appId}/gitea/deploy-key", null],
    ['POST', "/node-apps/{$appId}/gitea/setup", ['repository' => 'git@github.com:agservizi/staging-business--2-.git']],
    ['POST', "/node-apps/{$appId}/gitea/sync", null],
    ['PATCH', "/node-apps/{$appId}", ['repository' => 'git@github.com:agservizi/staging-business--2-.git', 'branch' => 'production']],
    ['GET', "/node-apps/{$appId}"],
];
foreach ($paths as [$method, $path, $body]) {
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 220) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
