<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();
$r = $c->request('POST', "/databases/{$id}/query", [
    'sql' => "CREATE TABLE IF NOT EXISTS _probe_import (id INT PRIMARY KEY);\nDROP TABLE IF EXISTS _probe_import;",
]);
echo json_encode($r['body'], JSON_PRETTY_PRINT) . "\n";
