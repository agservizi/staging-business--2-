<?php
declare(strict_types=1);
require_once __DIR__ . '/corehost_client.php';
require_once __DIR__ . '/corehost_sql_split.php';

$id = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$c = new CoreHostClient();
$dump = file_get_contents('c:/Users/carva/Downloads/u427445037_coresuitebusin.sql');
$dump = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', (string) $dump);
$stmts = corehost_split_sql($dump);

$insertTables = [];
foreach ($stmts as $sql) {
    if (preg_match('/INSERT INTO `([^`]+)`/i', $sql, $m)) {
        $insertTables[$m[1]] = ($insertTables[$m[1]] ?? 0) + 1;
    }
}

$r = $c->request('POST', '/databases/' . $id . '/query', ['sql' => 'SHOW TABLES']);
$rows = $r['body']['data']['rows'] ?? [];
$empty = [];
foreach ($rows as $row) {
    $t = array_values($row)[0] ?? '';
    if ($t === '' || str_starts_with($t, '_')) {
        continue;
    }
    $cr = $c->request('POST', '/databases/' . $id . '/query', ['sql' => "SELECT COUNT(*) AS c FROM `{$t}`"]);
    $count = (int) ($cr['body']['data']['rows'][0]['c'] ?? 0);
    if ($count === 0 && isset($insertTables[$t])) {
        $empty[] = $t;
    }
}
echo 'tables_with_dump_inserts_but_empty=' . count($empty) . PHP_EOL;
foreach ($empty as $t) {
    echo "  {$t} (inserts_in_dump={$insertTables[$t]})\n";
}
