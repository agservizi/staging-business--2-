#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Analizza un dump SQL (es. Business.session.sql) senza importarlo.
 * Confronta tabelle e schema_migrations con le migrazioni del progetto.
 *
 * Uso: php tools/analyze_db_dump.php [percorso_dump.sql]
 */

$root = dirname(__DIR__);
$dumpPath = $argv[1] ?? ($root . DIRECTORY_SEPARATOR . 'Business.session.sql');

if (!is_file($dumpPath)) {
    fwrite(STDERR, "File dump non trovato: {$dumpPath}\n");
    exit(1);
}

$size = filesize($dumpPath);
if ($size === false || $size === 0) {
    fwrite(STDERR, "ERRORE: il dump è vuoto (0 byte). Esporta di nuovo da phpMyAdmin e salva in Business.session.sql\n");
    exit(2);
}

echo "=== Analisi dump database ===\n";
echo 'File: ' . $dumpPath . "\n";
echo 'Dimensione: ' . number_format((float) $size) . " byte\n\n";

$handle = fopen($dumpPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "Impossibile aprire il dump.\n");
    exit(1);
}

$tables = [];
$executedMigrations = [];
$buffer = '';
$chunkSize = 1024 * 512;

while (!feof($handle)) {
    $chunk = fread($handle, $chunkSize);
    if ($chunk === false) {
        break;
    }
    $buffer .= $chunk;

    if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-zA-Z0-9_]+)`?/i', $buffer, $matches)) {
        foreach ($matches[1] as $table) {
            $tables[strtolower($table)] = true;
        }
    }

    if (preg_match_all("/INSERT\s+INTO\s+`?schema_migrations`?\s*[^;]*VALUES\s*([^;]+);/is", $buffer, $insertMatches)) {
        foreach ($insertMatches[1] as $valuesBlock) {
            if (preg_match_all("/'([^']+\.sql)'/i", $valuesBlock, $migrationMatches)) {
                foreach ($migrationMatches[1] as $migration) {
                    $executedMigrations[$migration] = true;
                }
            }
        }
    }

    if (strlen($buffer) > 200000) {
        $buffer = substr($buffer, -100000);
    }
}
fclose($handle);

$migrationDir = $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';
$allMigrations = glob($migrationDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
sort($allMigrations);

$pendingMigrations = [];
foreach ($allMigrations as $file) {
    $name = basename($file);
    if (!isset($executedMigrations[$name])) {
        $pendingMigrations[] = $name;
    }
}

$wowTables = [
    'pratiche' => 'CAF pratiche unificate',
    'pratiche_documenti' => 'Documenti CAF',
    'pratiche_stati' => 'Storico stati pratiche',
    'fedelta_movimenti' => 'Fedeltà automatica',
    'ticket' => 'Auto-ticket CAF / notifiche',
    'pickup_packages' => 'Pickup live board',
    'pickup_customer_reports' => 'Segnalazioni pickup',
    'brt_shipments' => 'Spedizioni BRT',
    'servizi_visure' => 'Visure + webhook loyalty',
    'servizi_appuntamenti' => 'Appuntamenti con link conferma',
    'schema_migrations' => 'Registro migrazioni',
];

echo 'Tabelle trovate nel dump: ' . count($tables) . "\n";
echo 'Migrazioni registrate nel dump: ' . count($executedMigrations) . "\n";
echo 'Migrazioni pendenti: ' . count($pendingMigrations) . "\n\n";

echo "--- Tabelle WOW ---\n";
$missingWow = [];
foreach ($wowTables as $table => $label) {
    $ok = isset($tables[$table]);
    echo ($ok ? '[OK] ' : '[MANCA] ') . $table . ' — ' . $label . "\n";
    if (!$ok) {
        $missingWow[] = $table;
    }
}

if ($pendingMigrations) {
    echo "\n--- Migrazioni da applicare ---\n";
    foreach ($pendingMigrations as $migration) {
        echo '  - ' . $migration . "\n";
    }
} else {
    echo "\nTutte le migrazioni del progetto risultano già nel dump.\n";
}

if ($missingWow || $pendingMigrations) {
    echo "\nAzione consigliata: php tools/db_setup.php --migrate\n";
    echo "Oppure importa il dump e poi: php tools/migrate.php\n";
    exit(3);
}

echo "\nDump allineato alle funzioni WOW.\n";
exit(0);
