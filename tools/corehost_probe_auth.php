<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$c = new CoreHostClient();
foreach (['/auth/me', '/auth/tokens', '/settings', '/profile'] as $p) {
    try {
        $r = $c->request('GET', $p);
        echo "{$p} HTTP {$r['status']}\n";
        echo substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 600) . "\n\n";
    } catch (Throwable $e) {
        echo "{$p} ERR {$e->getMessage()}\n\n";
    }
}
