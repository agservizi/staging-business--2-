#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Ripara DB dopo import parziale: deduplica righe, PK, re-import INSERT falliti.
 * Usage: php tools/corehost_db_repair.php [dump.sql]
 */
require_once __DIR__ . '/corehost_client.php';

$dumpPath = $argv[1] ?? 'c:\\Users\\carva\\Downloads\\u427445037_coresuitebusin.sql';
$dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$client = new CoreHostClient();

function run_sql(CoreHostClient $client, string $dbId, string $sql, bool $quiet = false): bool
{
    $sql = trim($sql);
    if ($sql === '') {
        return true;
    }
    $res = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => $sql]);
    if ($res['status'] >= 400 || ($res['body']['success'] ?? true) === false) {
        $msg = (string) ($res['body']['message'] ?? $res['raw']);
        foreach ([
            'already exists', 'Duplicate entry', 'Multiple primary key',
            'Duplicate key name', "Can't DROP", 'check that column/key exists',
            'already has a primary key', 'Duplicate foreign key', 'Duplicate column',
        ] as $ok) {
            if (stripos($msg, $ok) !== false) {
                return true;
            }
        }
        if (!$quiet) {
            fwrite(STDERR, 'FAIL: ' . substr($sql, 0, 120) . '... => ' . $msg . "\n");
        }
        return false;
    }
    return true;
}

function table_count(CoreHostClient $client, string $dbId, string $table): int
{
    $r = $client->request('POST', '/databases/' . $dbId . '/query', [
        'sql' => "SELECT COUNT(*) AS c FROM `{$table}`",
    ]);
    return (int) ($r['body']['data']['rows'][0]['c'] ?? 0);
}

echo "=== Repair DB import ===\n";

$dedupeTables = ['users', 'pickup_customers', 'pickup_couriers'];
foreach ($dedupeTables as $table) {
    $before = table_count($client, $dbId, $table);
    $tmp = $table . '_dedup_' . substr(md5($table), 0, 6);
    echo "{$table}: before={$before} dedupe...\n";
    run_sql($client, $dbId, "DROP TABLE IF EXISTS `{$tmp}`");
    run_sql($client, $dbId, "CREATE TABLE `{$tmp}` LIKE `{$table}`");
    run_sql($client, $dbId, "INSERT INTO `{$tmp}` SELECT * FROM `{$table}` GROUP BY id");
    run_sql($client, $dbId, "DROP TABLE `{$table}`");
    run_sql($client, $dbId, "RENAME TABLE `{$tmp}` TO `{$table}`");
    $after = table_count($client, $dbId, $table);
    echo "{$table}: after={$after}\n";
}

echo "\n>>> Primary keys\n";
$pkSql = [
    'ALTER TABLE users ADD PRIMARY KEY (id)',
    'ALTER TABLE users ADD UNIQUE KEY username (username)',
    'ALTER TABLE users ADD UNIQUE KEY email (email)',
    'ALTER TABLE users MODIFY id int unsigned NOT NULL AUTO_INCREMENT',
    'ALTER TABLE pickup_customers ADD PRIMARY KEY (id)',
    'ALTER TABLE pickup_customers MODIFY id int unsigned NOT NULL AUTO_INCREMENT',
    'ALTER TABLE pickup_couriers ADD PRIMARY KEY (id)',
    'ALTER TABLE pickup_couriers MODIFY id int unsigned NOT NULL AUTO_INCREMENT',
];
foreach ($pkSql as $sql) {
    $ok = run_sql($client, $dbId, $sql);
    echo ($ok ? 'OK' : 'FAIL') . ': ' . substr($sql, 0, 80) . "\n";
}

if (!is_file($dumpPath)) {
    fwrite(STDERR, "Dump non trovato: {$dumpPath}\n");
    exit(1);
}

$content = file_get_contents($dumpPath);
if ($content === false) {
    exit(1);
}
$content = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', $content);

echo "\n>>> Re-import INSERT (one statement per request)\n";
$reimportTables = ['login_audit', 'email_templates', 'pickup_customer_sessions'];
foreach ($reimportTables as $table) {
    $count = table_count($client, $dbId, $table);
    if ($count > 0) {
        echo "{$table}: skip ({$count} rows)\n";
        continue;
    }
    if (!preg_match_all('/INSERT INTO `' . preg_quote($table, '/') . '`[\s\S]*?;/', $content, $inserts)) {
        echo "{$table}: no INSERT in dump\n";
        continue;
    }
    $ok = 0;
    $fail = 0;
    foreach ($inserts[0] as $i => $sql) {
        if (run_sql($client, $dbId, $sql, true)) {
            $ok++;
        } else {
            $fail++;
            fwrite(STDERR, "  insert #{$i} failed for {$table}\n");
        }
    }
    $after = table_count($client, $dbId, $table);
    echo "{$table}: inserts ok={$ok} fail={$fail} rows={$after}\n";
}

echo "\n>>> ALTER TABLE from dump\n";
preg_match_all('/ALTER TABLE `[^`]+`[\s\S]*?;/', $content, $alters);
$ok = 0;
$fail = 0;
foreach ($alters[0] as $sql) {
    if (run_sql($client, $dbId, $sql, true)) {
        $ok++;
    } else {
        $fail++;
    }
}
echo "ALTER ok={$ok} fail={$fail}\n";

echo "\n>>> Verifica finale\n";
$checks = [
    'SELECT COUNT(*) AS c FROM users',
    'SELECT COUNT(*) AS c FROM clienti',
    'SELECT COUNT(*) AS c FROM pratiche',
    'SELECT COUNT(*) AS c FROM login_audit',
    'SELECT COUNT(*) AS c FROM email_templates',
    'SELECT COUNT(*) AS c FROM pickup_customer_sessions',
    'SELECT COUNT(*) AS c FROM schema_migrations',
    "SHOW INDEX FROM users WHERE Key_name = 'PRIMARY'",
];
foreach ($checks as $sql) {
    $r = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => $sql]);
    echo $sql . ' => ' . json_encode($r['body']['data']['rows'] ?? $r['body']['message'] ?? []) . "\n";
}

echo "done\n";
