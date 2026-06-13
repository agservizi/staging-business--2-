<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
$paths = [
    'GET /auth/me',
    'POST /auth/gitea-token',
    'GET /users/me/gitea',
    'POST /users/me/gitea/repos',
    'POST /gitea/repositories',
    'POST /repositories/mirror',
];
foreach ($paths as $item) {
    [$method, $path] = explode(' ', $item, 2);
    $body = null;
    if ($path === '/auth/gitea-token' || $path === '/users/me/gitea/repos' || $path === '/repositories/mirror') {
        $body = [
            'url' => 'https://github.com/agservizi/staging-business--2-.git',
            'name' => 'staging-business--2-',
            'branch' => 'production',
            'nodeAppId' => 'cmqbek0kf07gt6ht4dhwubi16',
        ];
    }
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']} " . substr($r['raw'], 0, 200) . "\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n";
    }
}
