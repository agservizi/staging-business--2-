#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Setup database: analisi dump, import opzionale, migrazioni, verifica schema.
 *
 * Uso:
 *   php tools/db_setup.php --analyze
 *   php tools/db_setup.php --analyze --dump=Business.session.sql
 *   php tools/db_setup.php --migrate
 *   php tools/db_setup.php --import --dump=Business.session.sql --migrate
 *   php tools/db_setup.php --export-pending   (SQL per phpMyAdmin)
 */

$root = dirname(__DIR__);

try {
    $options = parse_cli_options($argv);

    if (isset($options['help'])) {
        print_help();
        exit(0);
    }

    if (!isset($options['analyze']) && !isset($options['import']) && !isset($options['migrate']) && !isset($options['export-pending'])) {
        echo "Nessuna azione. Uso: php tools/db_setup.php --help\n";
        exit(1);
    }

    $dumpPath = $options['dump'] ?? ($root . DIRECTORY_SEPARATOR . 'Business.session.sql');

    if (isset($options['analyze'])) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/analyze_db_dump.php') . ' ' . escapeshellarg($dumpPath);
        passthru($cmd, $code);
        exit($code);
    }

    if (isset($options['export-pending'])) {
        export_pending_sql($root);
        exit(0);
    }

    if (isset($options['import'])) {
        import_dump($root, $dumpPath);
    }

    if (isset($options['migrate'])) {
        run_migrations($root);
    }

    verify_schema($root);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRORE: ' . $e->getMessage() . "\n");
    exit(1);
}

function parse_cli_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
        } elseif ($arg === '--analyze') {
            $options['analyze'] = true;
        } elseif ($arg === '--migrate') {
            $options['migrate'] = true;
        } elseif ($arg === '--import') {
            $options['import'] = true;
        } elseif ($arg === '--export-pending') {
            $options['export-pending'] = true;
        } elseif (str_starts_with($arg, '--dump=')) {
            $options['dump'] = substr($arg, 7);
        }
    }
    return $options;
}

function print_help(): void
{
    echo <<<'HELP'
db_setup.php — gestione database Coresuite Business

  --analyze              Analizza Business.session.sql (o --dump=path)
  --import --migrate     Importa dump poi applica migrazioni pendenti
  --migrate              Applica solo migrazioni pendenti (richiede .env DB)
  --export-pending       Genera database/pending_migrations_bundle.sql per phpMyAdmin
  --dump=percorso.sql    Dump da usare (default: Business.session.sql)

HELP;
}

function export_pending_sql(string $root): void
{
    $reconcileScript = $root . '/tools/build_reconcile_sql.php';
    if (!is_file($reconcileScript)) {
        throw new RuntimeException('Script build_reconcile_sql.php non trovato.');
    }

    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($reconcileScript), $code);
    if ($code !== 0) {
        throw new RuntimeException('Generazione reconcile_schema_migrations.sql fallita.');
    }

    echo "\nUsa database/reconcile_schema_migrations.sql in phpMyAdmin (NON pending_migrations_bundle.sql).\n";
    echo "Poi: php tools/migrate.php sul server.\n";
}

function import_dump(string $root, string $dumpPath): void
{
    if (!is_file($dumpPath)) {
        throw new RuntimeException("Dump non trovato: {$dumpPath}");
    }
    if (filesize($dumpPath) === 0) {
        throw new RuntimeException('Dump vuoto. Esporta di nuovo da phpMyAdmin (formato SQL, dati inclusi).');
    }

    require_once $root . '/includes/db_connect.php';

    echo "Import dump in corso (può richiedere diversi minuti)...\n";

    $handle = fopen($dumpPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Impossibile aprire il dump.');
    }

    global $pdo;
    $statement = '';
    $executed = 0;

    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        $statement .= $line;
        if (!str_ends_with(rtrim($line), ';')) {
            continue;
        }

        $sql = trim($statement);
        $statement = '';
        if ($sql === '') {
            continue;
        }

        try {
            $pdo->exec($sql);
            $executed++;
            if ($executed % 200 === 0) {
                echo "  ... {$executed} statement eseguiti\n";
            }
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'already exists') !== false || stripos($msg, 'Duplicate') !== false) {
                continue;
            }
            fclose($handle);
            throw new RuntimeException("Errore import (statement #{$executed}): {$msg}");
        }
    }

    fclose($handle);
    echo "Import completato ({$executed} statement).\n";
}

function run_migrations(string $root): void
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/migrate.php');
    passthru($cmd, $code);
    if ($code !== 0) {
        throw new RuntimeException('Migrazioni fallite (exit ' . $code . ').');
    }
}

function verify_schema(string $root): void
{
    require_once $root . '/includes/env.php';
    load_env($root . '/.env');

    $required = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    foreach ($required as $key) {
        if (trim((string) env($key, '')) === '') {
            echo "Schema check saltato: {$key} non configurato in .env\n";
            return;
        }
    }

    require_once $root . '/includes/db_connect.php';
    global $pdo;

    $checks = [
        'pratiche',
        'pratiche_documenti',
        'fedelta_movimenti',
        'schema_migrations',
    ];

    echo "\n=== Verifica schema live ===\n";
    foreach ($checks as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $exists = $stmt && $stmt->fetchColumn() !== false;
        echo ($exists ? '[OK] ' : '[MANCA] ') . $table . "\n";
    }

    $stmt = $pdo->query('SELECT COUNT(*) FROM schema_migrations');
    $count = $stmt ? (int) $stmt->fetchColumn() : 0;
    echo "Migrazioni registrate: {$count}\n";
}
