#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Importa un dump SQL in MySQL CoreHost via API POST /databases/{id}/query
 * Usage: php tools/corehost_import_sql.php [path/to/dump.sql]
 */

require_once __DIR__ . '/corehost_client.php';
require_once __DIR__ . '/corehost_sql_split.php';

$dumpPath = $argv[1] ?? '';
if ($dumpPath === '') {
    fwrite(STDERR, "Usage: php tools/corehost_import_sql.php <dump.sql>\n");
    exit(1);
}
if (!is_file($dumpPath)) {
    fwrite(STDERR, "File non trovato: {$dumpPath}\n");
    exit(1);
}

$dbId = (string) env('COREHOST_DB_ID', 'cmqbdh0iw079g6ht49c20gmw3');
$client = new CoreHostClient();
$batchSize = 1;
$maxBatchChars = 200000;

function should_skip_statement(string $sql): bool
{
    $trimmed = trim($sql);
    if ($trimmed === '') {
        return true;
    }
    if (preg_match('/^(SET|START TRANSACTION|COMMIT|USE)\b/i', $trimmed)) {
        return true;
    }
    if (stripos($trimmed, 'DEFINER=') !== false && stripos($trimmed, 'CREATE') !== false) {
        return true;
    }
    return false;
}

function is_benign_error(string $msg): bool
{
    foreach ([
        'already exists',
        'Duplicate',
        'duplicate key',
    ] as $needle) {
        if (stripos($msg, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function run_batch(CoreHostClient $client, string $dbId, string $sql, int $batchNo): void
{
    $sql = corehost_encode_sql_for_api($sql);
    $res = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => $sql]);
    if ($res['status'] >= 400) {
        $msg = (string) ($res['body']['message'] ?? $res['raw']);
        throw new RuntimeException("Batch #{$batchNo} HTTP {$res['status']}: {$msg}");
    }
    if (($res['body']['success'] ?? true) === false) {
        $msg = (string) ($res['body']['message'] ?? json_encode($res['body']));
        throw new RuntimeException("Batch #{$batchNo} failed: {$msg}");
    }
}

echo "=== Import SQL CoreHost ===\n";
echo 'file=' . $dumpPath . ' size=' . filesize($dumpPath) . "\n";
echo 'databaseId=' . $dbId . "\n\n";

$raw = file_get_contents($dumpPath);
if ($raw === false) {
    fwrite(STDERR, "Impossibile leggere dump\n");
    exit(1);
}
$raw = str_replace('utf8mb4_uca1400_ai_ci', 'utf8mb4_unicode_ci', $raw);

$statements = [];
foreach (corehost_split_sql($raw) as $sql) {
    if (should_skip_statement($sql)) {
        continue;
    }
    $statements[] = $sql;
}

echo 'statements=' . count($statements) . "\n";

$batch = [];
$batchChars = 0;
$batchNo = 0;
$executed = 0;
$errors = 0;

foreach ($statements as $sql) {
    $batch[] = $sql;
    $batchChars += strlen($sql);
    if (count($batch) < $batchSize && $batchChars < $maxBatchChars) {
        continue;
    }

    $batchNo++;
    $payload = implode("\n", $batch);
    try {
        run_batch($client, $dbId, $payload, $batchNo);
        $executed += count($batch);
        echo "[batch {$batchNo}] ok ({$executed}/" . count($statements) . ")\n";
    } catch (Throwable $e) {
        // fallback: statement per statement
        foreach ($batch as $one) {
            try {
                run_batch($client, $dbId, $one, $batchNo);
                $executed++;
            } catch (Throwable $inner) {
                if (is_benign_error($inner->getMessage())) {
                    $executed++;
                    continue;
                }
                $errors++;
                fwrite(STDERR, 'ERR: ' . substr($one, 0, 120) . '... => ' . $inner->getMessage() . "\n");
            }
        }
    }

    $batch = [];
    $batchChars = 0;
}

if ($batch !== []) {
    $batchNo++;
    $payload = implode("\n", $batch);
    try {
        run_batch($client, $dbId, $payload, $batchNo);
        $executed += count($batch);
    } catch (Throwable $e) {
        foreach ($batch as $one) {
            try {
                run_batch($client, $dbId, $one, $batchNo);
                $executed++;
            } catch (Throwable $inner) {
                if (!is_benign_error($inner->getMessage())) {
                    $errors++;
                    fwrite(STDERR, 'ERR: ' . $inner->getMessage() . "\n");
                } else {
                    $executed++;
                }
            }
        }
    }
    echo "[batch {$batchNo}] done\n";
}

echo "\n>>> Verifica tabelle\n";
$check = $client->request('POST', '/databases/' . $dbId . '/query', ['sql' => 'SHOW TABLES']);
$rows = $check['body']['data']['rows'] ?? [];
$tableCount = is_array($rows) ? count($rows) : 0;
echo "tables={$tableCount} executed={$executed} errors={$errors}\n";

if ($tableCount > 0) {
    $sample = $client->request('POST', '/databases/' . $dbId . '/query', [
        'sql' => 'SELECT COUNT(*) AS c FROM schema_migrations',
    ]);
    $count = $sample['body']['data']['rows'][0]['c'] ?? $sample['body']['data']['rows'][0][0] ?? '?';
    echo "schema_migrations rows={$count}\n";
}

exit($errors > 0 ? 1 : 0);
