<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$appId = 'cmqbek0kf07gt6ht4dhwubi16';
$websiteId = 'cmqbddt4v078v6ht4hm8posiz';
$paths = [
    ['POST', "/node-apps/{$appId}/terminal", ['command' => 'ls -la']],
    ['POST', "/node-apps/{$appId}/exec", ['command' => 'pwd']],
    ['POST', "/websites/{$websiteId}/terminal", ['command' => 'ls']],
    ['POST', "/node-apps/{$appId}/run", ['command' => 'git clone']],
    ['GET', "/node-apps/{$appId}/logs"],
    ['POST', '/node-apps', [
        'name' => 'coresuite-business-test',
        'runtime' => 'PHP',
        'repository' => '',
        'branch' => 'production',
    ]],
];
foreach ($paths as $item) {
    [$method, $path] = $item;
    $body = $item[2] ?? null;
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 160) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
