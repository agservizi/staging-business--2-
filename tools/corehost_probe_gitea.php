<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
foreach ([
    'GET /gitea/repos',
    'GET /gitea',
    'GET /integrations',
    'POST /gitea/repos {"name":"staging-business--2-","mirrorUrl":"https://github.com/agservizi/staging-business--2-.git"}',
] as $item) {
    [$method, $rest] = explode(' ', $item, 2);
    $path = $rest;
    $body = null;
    if (str_contains($rest, ' {')) {
        [$path, $json] = explode(' ', $rest, 2);
        $body = json_decode($json, true);
    }
    try {
        $r = $c->request($method, $path, $body);
        echo "{$method} {$path} -> {$r['status']}\n";
        echo substr($r['raw'], 0, 500) . "\n\n";
    } catch (Throwable $e) {
        echo "{$method} {$path} -> ERR {$e->getMessage()}\n\n";
    }
}
