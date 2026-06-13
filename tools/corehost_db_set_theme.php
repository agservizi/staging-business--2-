<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
require_once __DIR__ . '/corehost_sql_split.php';

$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();

$sql = "UPDATE configurazioni SET valore = 'navy' WHERE chiave = 'ui_theme'";
$r = $c->request('POST', '/databases/' . $id . '/query', ['sql' => $sql]);
echo 'UPDATE ui_theme: HTTP ' . $r['status'] . ' ' . ($r['body']['message'] ?? 'ok') . PHP_EOL;

$check = $c->request('POST', '/databases/' . $id . '/query', [
    'sql' => "SELECT chiave, valore FROM configurazioni WHERE chiave = 'ui_theme'",
]);
echo json_encode($check['body']['data']['rows'] ?? []) . PHP_EOL;
