<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();
$paths = [
    ['GET', "/databases/{$id}"],
    ['PATCH', "/databases/{$id}", ['externalAccess' => true]],
    ['POST', "/databases/{$id}/import", null],
    ['POST', "/databases/{$id}/restore", null],
    ['POST', "/databases/{$id}/query", ['sql' => 'SELECT 1']],
    ['POST', "/databases/{$id}/exec", ['sql' => 'SELECT 1']],
    ['GET', "/databases/{$id}/backups"],
    ['POST', "/databases/{$id}/backups", null],
];
foreach ($paths as [$m, $p, $b]) {
    try {
        $r = $c->request($m, $p, $b);
        echo "{$m} {$p} -> {$r['status']} " . substr($r['raw'], 0, 180) . "\n";
    } catch (Throwable $e) {
        echo "{$m} {$p} ERR\n";
    }
}
