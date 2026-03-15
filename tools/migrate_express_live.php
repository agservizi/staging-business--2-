#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db_connect.php';

$dumpPath = __DIR__ . '/../express-native-export.sql';
$backupDir = __DIR__ . '/../storage/backups/express';
$timestamp = date('Ymd_His');
$backupPath = $backupDir . '/express_live_before_import_' . $timestamp . '.sql';

$sharedTables = ['clienti', 'entrate_uscite'];
$expressTables = [
    'servizi_express_vendita_righe',
    'servizi_express_iccid_stock',
    'servizi_express_richieste',
    'servizi_express_vendite',
    'servizi_express_offerte',
    'servizi_express_prodotti',
    'servizi_express_operatori',
];
$importTables = array_merge($sharedTables, array_reverse($expressTables));

if (!is_file($dumpPath)) {
    fwrite(STDERR, "Dump non trovato: {$dumpPath}\n");
    exit(1);
}

if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Impossibile creare la directory backup: {$backupDir}\n");
    exit(1);
}

$dumpSql = file_get_contents($dumpPath);
if ($dumpSql === false) {
    fwrite(STDERR, "Impossibile leggere il dump: {$dumpPath}\n");
    exit(1);
}

backupExpressTables($pdo, array_reverse($expressTables), $backupPath);

try {
    $pdo->beginTransaction();

    createTemporaryImportTables($pdo, $importTables);
    loadDumpDataIntoTemporaryTables($pdo, $dumpSql, $importTables);

    $customerMap = syncCustomers($pdo);
    $movementMap = syncMovements($pdo, $customerMap);

    clearExpressTables($pdo, $expressTables);

    $importedCounts = [];
    $importedCounts['operatori'] = importSimpleTable($pdo, 'servizi_express_operatori');
    $importedCounts['prodotti'] = importSimpleTable($pdo, 'servizi_express_prodotti');
    $importedCounts['offerte'] = importSimpleTable($pdo, 'servizi_express_offerte');
    $importedCounts['vendite'] = importSales($pdo, $customerMap, $movementMap);
    $importedCounts['iccid_stock'] = importSimpleTable($pdo, 'servizi_express_iccid_stock');
    $importedCounts['vendita_righe'] = importSimpleTable($pdo, 'servizi_express_vendita_righe');
    $importedCounts['richieste'] = importRequests($pdo, $customerMap);

    $importedCounts['clienti_merge'] = count($customerMap);
    $importedCounts['movimenti_merge'] = count($movementMap);

    $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (NULL, :modulo, :azione, :dettagli, NOW())');
    $logStmt->execute([
        ':modulo' => 'Servizi/Express',
        ':azione' => 'Import dataset Express live',
        ':dettagli' => json_encode([
            'backup' => basename($backupPath),
            'counts' => $importedCounts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $pdo->commit();

    echo 'Migrazione Express completata.' . PHP_EOL;
    echo 'Backup: ' . $backupPath . PHP_EOL;
    foreach ($importedCounts as $label => $count) {
        echo $label . ': ' . $count . PHP_EOL;
    }
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Errore migrazione Express: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

function backupExpressTables(PDO $pdo, array $tables, string $backupPath): void
{
    $sql = [];
    $sql[] = '-- Backup tabelle Express prima della migrazione';
    $sql[] = '-- Generato il ' . date('Y-m-d H:i:s');
    $sql[] = 'SET FOREIGN_KEY_CHECKS=0;';

    foreach ($tables as $table) {
        $rows = $pdo->query("SELECT * FROM `{$table}` ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $sql[] = 'DELETE FROM `' . $table . '`;';

        if ($rows === []) {
            continue;
        }

        $columns = array_keys($rows[0]);
        $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
        $valuesSql = [];

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $values[] = quoteSqlValue($pdo, $row[$column]);
            }
            $valuesSql[] = '(' . implode(', ', $values) . ')';
        }

        $sql[] = 'INSERT INTO `' . $table . '` (' . implode(', ', $quotedColumns) . ') VALUES';
        $sql[] = implode(',' . PHP_EOL, $valuesSql) . ';';
    }

    $sql[] = 'SET FOREIGN_KEY_CHECKS=1;';

    file_put_contents($backupPath, implode(PHP_EOL . PHP_EOL, $sql) . PHP_EOL);
}

function quoteSqlValue(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    return $pdo->quote((string) $value);
}

function createTemporaryImportTables(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS `tmp_import_{$table}`");
        $pdo->exec("CREATE TEMPORARY TABLE `tmp_import_{$table}` LIKE `{$table}`");
    }
}

function loadDumpDataIntoTemporaryTables(PDO $pdo, string $dumpSql, array $tables): void
{
    foreach ($tables as $table) {
        if (!preg_match_all('/INSERT INTO `' . preg_quote($table, '/') . '` VALUES .*?;/s', $dumpSql, $matches)) {
            continue;
        }

        foreach ($matches[0] as $statement) {
            $temporaryStatement = str_replace(
                'INSERT INTO `' . $table . '`',
                'INSERT INTO `tmp_import_' . $table . '`',
                $statement
            );
            $pdo->exec($temporaryStatement);
        }
    }
}

function syncCustomers(PDO $pdo): array
{
    $rows = $pdo->query('SELECT * FROM `tmp_import_clienti` ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $selectStmt = $pdo->prepare('SELECT id FROM clienti WHERE note = :note LIMIT 1');
    $insertStmt = $pdo->prepare('INSERT INTO clienti (ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note, morosita_flag, morosita_score, morosita_note, morosita_aggiornato_il, morosita_fonte, created_at, updated_at) VALUES (:ragione_sociale, :nome, :cognome, :cf_piva, :email, :telefono, :indirizzo, :note, :morosita_flag, :morosita_score, :morosita_note, :morosita_aggiornato_il, :morosita_fonte, :created_at, :updated_at)');
    $updateStmt = $pdo->prepare('UPDATE clienti SET ragione_sociale = :ragione_sociale, nome = :nome, cognome = :cognome, cf_piva = :cf_piva, email = :email, telefono = :telefono, indirizzo = :indirizzo, morosita_flag = :morosita_flag, morosita_score = :morosita_score, morosita_note = :morosita_note, morosita_aggiornato_il = :morosita_aggiornato_il, morosita_fonte = :morosita_fonte, updated_at = :updated_at WHERE id = :id');

    $map = [];

    foreach ($rows as $row) {
        $payload = [
            ':ragione_sociale' => $row['ragione_sociale'],
            ':nome' => $row['nome'],
            ':cognome' => $row['cognome'],
            ':cf_piva' => $row['cf_piva'],
            ':email' => $row['email'],
            ':telefono' => $row['telefono'],
            ':indirizzo' => $row['indirizzo'],
            ':note' => $row['note'],
            ':morosita_flag' => $row['morosita_flag'],
            ':morosita_score' => $row['morosita_score'],
            ':morosita_note' => $row['morosita_note'],
            ':morosita_aggiornato_il' => $row['morosita_aggiornato_il'],
            ':morosita_fonte' => $row['morosita_fonte'],
            ':created_at' => $row['created_at'],
            ':updated_at' => $row['updated_at'],
        ];

        $selectStmt->execute([':note' => $row['note']]);
        $existingId = $selectStmt->fetchColumn();

        if ($existingId !== false) {
            $updateStmt->execute($payload + [':id' => (int) $existingId]);
            $map[(int) $row['id']] = (int) $existingId;
            continue;
        }

        $insertStmt->execute($payload);
        $map[(int) $row['id']] = (int) $pdo->lastInsertId();
    }

    return $map;
}

function syncMovements(PDO $pdo, array $customerMap): array
{
    $rows = $pdo->query('SELECT * FROM `tmp_import_entrate_uscite` ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $columns = $rows === [] ? [] : array_keys($rows[0]);
    $insertColumns = array_values(array_filter($columns, static fn(string $column): bool => $column !== 'id'));

    if ($insertColumns === []) {
        return [];
    }

    $selectStmt = $pdo->prepare('SELECT id FROM entrate_uscite WHERE note = :note LIMIT 1');
    $insertSql = 'INSERT INTO entrate_uscite (' . implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $insertColumns)) . ') VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $insertColumns)) . ')';
    $updateAssignments = implode(', ', array_map(static fn(string $column): string => '`' . $column . '` = :' . $column, $insertColumns));
    $updateSql = 'UPDATE entrate_uscite SET ' . $updateAssignments . ' WHERE id = :id';

    $insertStmt = $pdo->prepare($insertSql);
    $updateStmt = $pdo->prepare($updateSql);

    $map = [];

    foreach ($rows as $row) {
        $row['cliente_id'] = $row['cliente_id'] !== null ? ($customerMap[(int) $row['cliente_id']] ?? null) : null;
        $payload = [];
        foreach ($insertColumns as $column) {
            $payload[':' . $column] = $row[$column];
        }

        $selectStmt->execute([':note' => $row['note']]);
        $existingId = $selectStmt->fetchColumn();

        if ($existingId !== false) {
            $updateStmt->execute($payload + [':id' => (int) $existingId]);
            $map[(int) $row['id']] = (int) $existingId;
            continue;
        }

        $insertStmt->execute($payload);
        $map[(int) $row['id']] = (int) $pdo->lastInsertId();
    }

    return $map;
}

function clearExpressTables(PDO $pdo, array $tables): void
{
    foreach ($tables as $table) {
        $pdo->exec('DELETE FROM `' . $table . '`');
    }
}

function importSimpleTable(PDO $pdo, string $table): int
{
    $rows = $pdo->query('SELECT * FROM `tmp_import_' . $table . '` ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return 0;
    }

    $columns = array_keys($rows[0]);
    $sql = 'INSERT INTO `' . $table . '` (' . implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns)) . ') VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ')';
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        $payload = [];
        foreach ($columns as $column) {
            $payload[':' . $column] = $row[$column];
        }
        $stmt->execute($payload);
    }

    return count($rows);
}

function importSales(PDO $pdo, array $customerMap, array $movementMap): int
{
    $rows = $pdo->query('SELECT * FROM `tmp_import_servizi_express_vendite` ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return 0;
    }

    $columns = array_keys($rows[0]);
    $sql = 'INSERT INTO `servizi_express_vendite` (' . implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns)) . ') VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ')';
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        $row['cliente_id'] = $customerMap[(int) $row['cliente_id']] ?? null;
        $row['entrata_uscita_id'] = $row['entrata_uscita_id'] !== null ? ($movementMap[(int) $row['entrata_uscita_id']] ?? null) : null;

        $payload = [];
        foreach ($columns as $column) {
            $payload[':' . $column] = $row[$column];
        }
        $stmt->execute($payload);
    }

    return count($rows);
}

function importRequests(PDO $pdo, array $customerMap): int
{
    $rows = $pdo->query('SELECT * FROM `tmp_import_servizi_express_richieste` ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    if ($rows === []) {
        return 0;
    }

    $columns = array_keys($rows[0]);
    $sql = 'INSERT INTO `servizi_express_richieste` (' . implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns)) . ') VALUES (' . implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)) . ')';
    $stmt = $pdo->prepare($sql);

    foreach ($rows as $row) {
        $row['cliente_id'] = $customerMap[(int) $row['cliente_id']] ?? null;
        $payload = [];
        foreach ($columns as $column) {
            $payload[':' . $column] = $row[$column];
        }
        $stmt->execute($payload);
    }

    return count($rows);
}