<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();

$queries = [
    "SELECT COUNT(*) AS c FROM users",
    "SELECT id, COUNT(*) AS n FROM users GROUP BY id HAVING n > 1",
    "SELECT username, COUNT(*) AS n FROM users GROUP BY username HAVING n > 1",
    "SELECT COUNT(*) AS c FROM pickup_customers",
    "SELECT id, COUNT(*) AS n FROM pickup_customers GROUP BY id HAVING n > 1",
    "SELECT COUNT(*) AS c FROM pickup_couriers",
    "SELECT id, COUNT(*) AS n FROM pickup_couriers GROUP BY id HAVING n > 1",
    "SELECT COUNT(*) AS c FROM login_audit",
    "SELECT COUNT(*) AS c FROM email_templates",
    "SELECT COUNT(*) AS c FROM schema_migrations",
    "SHOW INDEX FROM users",
    "SHOW INDEX FROM clienti",
];
foreach ($queries as $sql) {
    echo ">>> {$sql}\n";
    $r = $c->request('POST', '/databases/' . $id . '/query', ['sql' => $sql]);
    $data = $r['body']['data']['rows'] ?? $r['body']['message'] ?? $r['body'];
    echo json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
}
