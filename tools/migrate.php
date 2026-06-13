#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

$strict = in_array('--strict', $argv, true);

$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');

$executedStmt = $pdo->query('SELECT migration FROM schema_migrations ORDER BY migration');
$executed = $executedStmt ? $executedStmt->fetchAll(PDO::FETCH_COLUMN) : [];
$executed = array_map(static fn($name) => (string) $name, $executed);

$migrationDir = realpath(__DIR__ . '/../database/migrations');
if ($migrationDir === false) {
    fwrite(STDERR, "Directory migrazioni non trovata.\n");
    exit(1);
}

$files = glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql');
if (!$files) {
    echo "Nessuna migrazione da applicare.\n";
    exit(0);
}

sort($files);
$pending = array_filter($files, static fn($file) => !in_array(basename($file), $executed, true));
if (!$pending) {
    echo "Migrazioni già aggiornate.\n";
    exit(0);
}

foreach ($pending as $file) {
    $migrationName = basename($file);
    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, 'Impossibile leggere la migrazione ' . $migrationName . "\n");
        exit(1);
    }

    $statements = array_filter(array_map('trim', preg_split('/;\s*\r?\n/', $sql)));
    $skippedAsApplied = false;

    try {
        $pdo->beginTransaction();
        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }
            try {
                $pdo->exec($statement);
            } catch (Throwable $statementError) {
                if ($strict || !migration_error_is_benign($statementError)) {
                    throw $statementError;
                }
                $skippedAsApplied = true;
                fwrite(STDERR, 'Avviso ' . $migrationName . ': ' . $statementError->getMessage() . " (considerata già applicata)\n");
            }
        }

        $insert = $pdo->prepare('INSERT IGNORE INTO schema_migrations (migration, executed_at) VALUES (:migration, NOW())');
        $insert->execute([':migration' => $migrationName]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        if ($skippedAsApplied) {
            echo 'Migrazione già presente nel DB, registrata: ' . $migrationName . "\n";
        } else {
            echo 'Applicata migrazione ' . $migrationName . "\n";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'Errore migrazione ' . $migrationName . ': ' . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "Migrazioni completate.\n";

function migration_error_is_benign(Throwable $error): bool
{
    $message = $error->getMessage();

    if (preg_match('/\b(1050|1060|1061|1091|1826)\b/', $message)) {
        return true;
    }

    return (bool) preg_match(
        '/(already exists|Duplicate column|Duplicate key|Duplicate entry|check that column\/key exists)/i',
        $message
    );
}
