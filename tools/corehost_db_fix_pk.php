<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();
$cmds = [
    'ALTER TABLE users ADD PRIMARY KEY (id)',
    'ALTER TABLE users ADD UNIQUE KEY username (username)',
    'ALTER TABLE users ADD UNIQUE KEY email (email)',
    'ALTER TABLE users MODIFY id int unsigned NOT NULL AUTO_INCREMENT',
    'ALTER TABLE pickup_customers ADD PRIMARY KEY (id)',
    'ALTER TABLE pickup_couriers ADD PRIMARY KEY (id)',
    'ALTER TABLE clienti ADD PRIMARY KEY (id)',
];
foreach ($cmds as $sql) {
    $r = $c->request('POST', '/databases/' . $id . '/query', ['sql' => $sql]);
    $msg = $r['body']['message'] ?? 'ok';
    echo "{$sql} => HTTP {$r['status']} {$msg}\n";
}
$r = $c->request('POST', '/databases/' . $id . '/query', ['sql' => "SHOW INDEX FROM users WHERE Key_name = 'PRIMARY'"]);
echo 'PK users: ' . json_encode($r['body']['data']['rows'] ?? []) . "\n";
