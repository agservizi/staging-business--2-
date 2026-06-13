<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();
foreach ([
    "/databases/{$id}",
    "/databases/{$id}/users",
    "/databases/{$id}/credentials",
    "/db-users?databaseId={$id}",
] as $path) {
    try {
        $r = $c->request('GET', $path);
        echo "{$path} HTTP {$r['status']}\n";
        echo substr(json_encode($r['body'], JSON_UNESCAPED_UNICODE), 0, 1200) . "\n\n";
    } catch (Throwable $e) {
        echo "{$path} ERR {$e->getMessage()}\n\n";
    }
}
