#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Finalizza import DB: indici, PK, FK, schema_migrations, dati mancanti.
 */
require_once __DIR__ . '/corehost_client.php';

$dumpPath = $argv[1] ?? 'c:\\Users\\carva\\Downloads\\u427445037_coresuitebusin.sql';
$dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$client = new CoreHostClient();

function run_sql(CoreHostClient $client, string $dbId, string $sql): bool
{
    $sql = trim($sql);
    if ($sql === '') {
        return true;
    }
    $res = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => $sql]);
    if ($res['status'] >= 400 || ($res['body']['success'] ?? true) === false) {
        $msg = (string) ($res['body']['message'] ?? $res['raw']);
        foreach ([
            'already exists', 'Duplicate', 'Multiple primary key',
            'Duplicate key name', "Can't DROP", 'check that column/key exists',
            'already has a primary key', 'Duplicate foreign key',
        ] as $ok) {
            if (stripos($msg, $ok) !== false) {
                return true;
            }
        }
        fwrite(STDERR, 'FAIL: ' . substr($sql, 0, 100) . '... => ' . $msg . "\n");
        return false;
    }
    return true;
}

echo "=== Finalize DB import ===\n";

// schema_migrations
run_sql($client, $dbId, 'CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  migration VARCHAR(255) NOT NULL UNIQUE,
  executed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

if (!is_file($dumpPath)) {
    fwrite(STDERR, "Dump non trovato\n");
    exit(1);
}

$content = file_get_contents($dumpPath);
if ($content === false) {
    exit(1);
}
$content = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', $content);

// Estrai ALTER TABLE e INSERT schema_migrations
preg_match_all('/ALTER TABLE `[^`]+`[\s\S]*?;/', $content, $alters);
preg_match_all('/INSERT INTO `schema_migrations`[\s\S]*?;/', $content, $schemaInserts);

echo 'alter statements=' . count($alters[0]) . "\n";
$ok = 0;
$fail = 0;
foreach ($alters[0] as $sql) {
    if (run_sql($client, $dbId, $sql)) {
        $ok++;
    } else {
        $fail++;
    }
}
echo "ALTER ok={$ok} fail={$fail}\n";

foreach ($schemaInserts[0] as $sql) {
    run_sql($client, $dbId, $sql);
}

// Re-import INSERT per tabelle vuote critiche
$emptyCheck = ['login_audit', 'brt_logs', 'email_templates', 'pickup_customer_sessions'];
foreach ($emptyCheck as $table) {
    $r = $client->request('POST', '/databases/' . $dbId . '/query', [
        'sql' => "SELECT COUNT(*) AS c FROM `{$table}`",
    ]);
    $count = (int) ($r['body']['data']['rows'][0]['c'] ?? 0);
    if ($count > 0) {
        echo "{$table}: già popolata ({$count})\n";
        continue;
    }
    if (!preg_match_all('/INSERT INTO `' . preg_quote($table, '/') . '`[\s\S]*?;/', $content, $inserts)) {
        continue;
    }
    echo "{$table}: import " . count($inserts[0]) . " insert...\n";
    foreach ($inserts[0] as $sql) {
        if (!run_sql($client, $dbId, $sql)) {
            echo "  warn insert fallito\n";
        }
    }
}

// Verifica
$checks = [
    'SELECT COUNT(*) AS c FROM users',
    'SELECT COUNT(*) AS c FROM clienti',
    'SELECT COUNT(*) AS c FROM login_audit',
    'SELECT COUNT(*) AS c FROM schema_migrations',
    "SHOW INDEX FROM users WHERE Key_name = 'PRIMARY'",
];
foreach ($checks as $sql) {
    $r = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => $sql]);
    echo $sql . ' => ' . json_encode($r['body']['data']['rows'] ?? []) . "\n";
}

echo "done\n";
