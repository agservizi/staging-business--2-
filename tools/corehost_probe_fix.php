<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';

$c = new CoreHostClient();
$appId = (string) env('COREHOST_APP_ID', 'cmqbf9y2q07q66ht4vgas0xmg');
$websiteId = (string) env('COREHOST_WEBSITE_ID', 'cmqbddt4v078v6ht4hm8posiz');

$paths = [
    ['GET', '/auth/me', null],
    ['POST', '/auth/gitea-token', null],
    ['GET', '/users/me/gitea', null],
    ['POST', "/node-apps/{$appId}/gitea/sync", null],
    ['POST', "/node-apps/{$appId}/import-github", ['url' => 'https://github.com/agservizi/staging-business--2-.git', 'branch' => 'production']],
    ['POST', "/node-apps/{$appId}/sync-repository", ['repository' => 'https://github.com/agservizi/staging-business--2-.git', 'branch' => 'production']],
    ['POST', '/repositories/mirror', ['url' => 'https://github.com/agservizi/staging-business--2-.git', 'name' => 'coresuite-business', 'branch' => 'production', 'nodeAppId' => $appId]],
    ['POST', "/websites/{$websiteId}/provision", null],
    ['POST', "/websites/{$websiteId}/sync", null],
    ['PATCH', "/websites/{$websiteId}", ['type' => 'REVERSE_PROXY', 'port' => 10008, 'nodeAppId' => $appId]],
];

foreach ($paths as [$method, $path, $body]) {
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 220) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
