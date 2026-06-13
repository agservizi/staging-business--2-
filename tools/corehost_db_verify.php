<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();
$queries = [
    'SHOW TABLES',
    "SELECT COUNT(*) AS c FROM users",
    "SELECT COUNT(*) AS c FROM clienti",
    "SELECT COUNT(*) AS c FROM pratiche",
    "SELECT COUNT(*) AS c FROM login_audit",
    "SHOW CREATE TABLE users",
    "SHOW TABLES LIKE 'schema_migrations'",
];
foreach ($queries as $sql) {
    echo ">>> {$sql}\n";
    $r = $c->request('POST', '/databases/' . $id . '/query', ['sql' => $sql]);
    echo json_encode($r['body']['data'] ?? $r['body'], JSON_UNESCAPED_UNICODE) . "\n\n";
}
