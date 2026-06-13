#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Ripristina tabelle vuote o dati mancanti dal dump con parser SQL corretto.
 */
require_once __DIR__ . '/corehost_client.php';
require_once __DIR__ . '/corehost_sql_split.php';

$dumpPath = $argv[1] ?? 'c:\\Users\\carva\\Downloads\\u427445037_coresuitebusin.sql';
$dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$client = new CoreHostClient();

function run_sql(CoreHostClient $client, string $dbId, string $sql, bool $encode = true): array
{
    $payload = $encode ? corehost_encode_sql_for_api($sql) : $sql;
    $res = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => $payload]);
    $ok = $res['status'] < 400 && ($res['body']['success'] ?? true) !== false;
    $msg = (string) ($res['body']['message'] ?? '');
    return ['ok' => $ok, 'msg' => $msg, 'status' => $res['status']];
}

function table_count(CoreHostClient $client, string $dbId, string $table): int
{
    $r = $client->request('POST', '/databases/' . $dbId . '/query', [
        'sql' => "SELECT COUNT(*) AS c FROM `{$table}`",
    ]);
    return (int) ($r['body']['data']['rows'][0]['c'] ?? 0);
}

if (!is_file($dumpPath)) {
    fwrite(STDERR, "Dump non trovato\n");
    exit(1);
}

$content = file_get_contents($dumpPath);
if ($content === false) {
    exit(1);
}
$content = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', $content);

$all = corehost_split_sql($content);
echo 'total statements=' . count($all) . "\n";

$restoreTables = [
    'users',
    'pickup_customers',
    'pickup_couriers',
    'login_audit',
    'email_templates',
    'pickup_customer_sessions',
    'energia_email_history',
    'pickup_customer_activity_logs',
    'remember_tokens',
];

foreach ($restoreTables as $table) {
    $before = table_count($client, $dbId, $table);
    $inserts = corehost_filter_statements($all, $table, 'INSERT');
    echo "\n{$table}: before={$before} inserts_in_dump=" . count($inserts) . "\n";

    if ($before > 0 && !in_array($table, ['login_audit'], true)) {
        echo "  skip (già popolata)\n";
        continue;
    }

    foreach ($inserts as $i => $sql) {
        $r = run_sql($client, $dbId, $sql);
        if (!$r['ok']) {
            if (stripos($r['msg'], 'Duplicate') !== false) {
                echo "  insert #{$i}: duplicate (ok)\n";
                continue;
            }
            fwrite(STDERR, "  insert #{$i} FAIL: {$r['msg']}\n");
            fwrite(STDERR, '  preview: ' . substr($sql, 0, 200) . "...\n");
        } else {
            echo "  insert #{$i}: ok\n";
        }
    }

    $after = table_count($client, $dbId, $table);
    echo "  after={$after}\n";
}

echo "\n>>> Verifica\n";
foreach (['users', 'clienti', 'login_audit', 'email_templates', 'pickup_customer_sessions'] as $t) {
    echo "{$t}=" . table_count($client, $dbId, $t) . "\n";
}

echo "done\n";
