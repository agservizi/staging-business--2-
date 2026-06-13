#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Genera database/reconcile_schema_migrations.sql per phpMyAdmin.
 * Segna come "già applicate" le migrazioni il cui schema è già presente nel DB.
 */

$root = dirname(__DIR__);
$migrationDir = $root . '/database/migrations';
$outPath = $root . '/database/reconcile_schema_migrations.sql';

$files = glob($migrationDir . '/*.sql') ?: [];
sort($files);

$header = <<<'SQL'
-- =============================================================================
-- RECONCILE schema_migrations (phpMyAdmin)
-- NON importare pending_migrations_bundle.sql su un DB già in produzione!
--
-- 1) Esegui QUESTO file in phpMyAdmin (seleziona il database business)
-- 2) Poi esegui solo le migrazioni ancora mancanti con:
--      php tools/migrate.php   (terminale Hostinger, consigliato)
--    oppure importa database/only_pending_migrations.sql (generato dopo il passo 1
--    se hai le credenziali in .env: php tools/build_reconcile_sql.php --pending-only)
-- =============================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


SQL;

$body = '';
$noProbe = [];

foreach ($files as $file) {
    $name = basename($file);
    $sql = (string) file_get_contents($file);
    $probe = detect_probe($sql);

    if ($probe === null) {
        $noProbe[] = $name;
        continue;
    }

    $escapedName = str_replace("'", "''", $name);

    if ($probe['type'] === 'table') {
        $table = str_replace("'", "''", $probe['table']);
        $body .= "-- {$name} (tabella {$probe['table']})\n";
        $body .= "INSERT IGNORE INTO schema_migrations (migration, executed_at)\n";
        $body .= "SELECT '{$escapedName}', NOW()\n";
        $body .= "FROM information_schema.TABLES\n";
        $body .= "WHERE TABLE_SCHEMA = DATABASE()\n";
        $body .= "  AND TABLE_NAME = '{$table}'\n";
        $body .= "LIMIT 1;\n\n";
        continue;
    }

    $table = str_replace("'", "''", $probe['table']);
    $column = str_replace("'", "''", $probe['column']);
    $body .= "-- {$name} (colonna {$probe['table']}.{$probe['column']})\n";
    $body .= "INSERT IGNORE INTO schema_migrations (migration, executed_at)\n";
    $body .= "SELECT '{$escapedName}', NOW()\n";
    $body .= "FROM information_schema.COLUMNS\n";
    $body .= "WHERE TABLE_SCHEMA = DATABASE()\n";
    $body .= "  AND TABLE_NAME = '{$table}'\n";
    $body .= "  AND COLUMN_NAME = '{$column}'\n";
    $body .= "LIMIT 1;\n\n";
}

$footer = "-- Migrazioni senza probe automatico (verifica con php tools/migrate.php):\n";
foreach ($noProbe as $name) {
    $footer .= "--   - {$name}\n";
}
$footer .= "\nSELECT migration, executed_at FROM schema_migrations ORDER BY migration;\n";

file_put_contents($outPath, $header . $body . $footer);
echo "Creato: {$outPath}\n";
echo 'Migrazioni con probe: ' . (count($files) - count($noProbe)) . '/' . count($files) . "\n";
if ($noProbe) {
    echo "Senza probe automatico: " . count($noProbe) . " (usa php tools/migrate.php)\n";
}

function detect_probe(string $sql): ?array
{
    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $sql, $match)) {
        return ['type' => 'table', 'table' => $match[1]];
    }

    if (preg_match(
        '/ALTER\s+TABLE\s+`?([a-zA-Z0-9_]+)`?[\s\S]*?ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i',
        $sql,
        $match
    )) {
        return ['type' => 'column', 'table' => $match[1], 'column' => $match[2]];
    }

    if (preg_match('/CREATE\s+(?:OR\s+REPLACE\s+)?VIEW\s+`?([a-zA-Z0-9_]+)`?/i', $sql, $match)) {
        return ['type' => 'table', 'table' => $match[1]];
    }

    return null;
}
