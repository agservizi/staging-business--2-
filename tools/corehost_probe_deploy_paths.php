<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$paths = [
    'GET /node-apps/cmqbek0kf07gt6ht4dhwubi16',
    'POST /node-apps/cmqbek0kf07gt6ht4dhwubi16/gitea',
    'POST /node-apps/cmqbek0kf07gt6ht4dhwubi16/import-github',
    'POST /node-apps/cmqbek0kf07gt6ht4dhwubi16/sync-repository',
    'GET /integrations',
    'GET /system/health',
    'GET /system/dns',
    'POST /system/dns/test',
    'GET /websites/cmqbddt4v078v6ht4hm8posiz/files?path=/',
    'GET /sites/business-fb736e/files?path=/',
];
foreach ($paths as $item) {
    [$method, $rest] = explode(' ', $item, 2);
    $path = $rest;
    $body = null;
    if (str_contains($rest, ' ')) {
        [$path, $json] = explode(' ', $rest, 2);
        $body = json_decode($json, true);
    }
    if ($method === 'POST' && $body === null && str_contains($path, 'import-github')) {
        $body = ['url' => 'https://github.com/agservizi/staging-business--2-.git', 'branch' => 'production'];
    }
    if ($method === 'POST' && $body === null && str_contains($path, 'sync-repository')) {
        $body = ['repository' => 'https://github.com/agservizi/staging-business--2-.git'];
    }
    if ($method === 'POST' && $body === null && str_contains($path, '/gitea')) {
        $body = ['action' => 'setup', 'githubUrl' => 'https://github.com/agservizi/staging-business--2-.git'];
    }
    if ($method === 'POST' && str_contains($path, 'dns/test')) {
        $body = ['host' => 'github.com'];
    }
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 180) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
