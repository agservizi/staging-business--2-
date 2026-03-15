<?php
declare(strict_types=1);

// CLI script: php tools/anncsu_import.php /path/to/anncsu.csv
// Expected headers: street, street_number, city, province, cap (case-insensitive). Delimiter auto-detected between comma/semicolon.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Use from CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/env.php';

$inputPath = $argv[1] ?? null;
$append = in_array('--append', $argv, true);
if (!$inputPath || !is_readable($inputPath)) {
    fwrite(STDERR, "Provide a readable CSV/GeoCSV file path.\n");
    exit(1);
}

$dbPath = getenv('ANNCSU_DB_PATH') ?: (__DIR__ . '/../storage/tmp/anncsu.sqlite');
$dir = dirname($dbPath);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create directory: {$dir}\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA journal_mode=WAL;');
$pdo->exec('PRAGMA synchronous=NORMAL;');

$pdo->exec('CREATE TABLE IF NOT EXISTS anncsu (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    street TEXT NOT NULL,
    street_number TEXT,
    city TEXT NOT NULL,
    province TEXT,
    cap TEXT
);');
$pdo->exec('CREATE VIRTUAL TABLE IF NOT EXISTS anncsu_fts USING fts5(
    street, street_number, city, province, cap,
    content="anncsu", content_rowid="id"
);');

if (!$append) {
    $pdo->exec('DELETE FROM anncsu;');
}
$pdo->exec('DELETE FROM anncsu_fts;');

$firstLineRaw = '';
$tmpHandle = fopen($inputPath, 'r');
if ($tmpHandle !== false) {
    $firstLineRaw = (string) fgets($tmpHandle);
    fclose($tmpHandle);
}
$delimiter = (strpos($firstLineRaw, ';') !== false) ? ';' : ',';

$csv = new SplFileObject($inputPath, 'r');
$csv->setCsvControl($delimiter, '"', '\\');
$csv->setFlags(SplFileObject::READ_CSV);
$csv->rewind();

$headers = $csv->fgetcsv();
if (!is_array($headers)) {
    fwrite(STDERR, "Cannot read header row.\n");
    exit(1);
}
$headers = array_map(static fn($h) => strtolower(trim((string) $h)), $headers);

$lookup = static function(array $haystack, array $needles) {
    foreach ($needles as $needle) {
        $pos = array_search(strtolower($needle), $haystack, true);
        if ($pos !== false) {
            return $pos;
        }
    }
    return false;
};

// opzionale: mappa comuni per arricchire city/province/cap a partire da CODICE_ISTAT
$comuniIndex = [];
$comuniPath = __DIR__ . '/../data/comuni.json';
if (is_readable($comuniPath)) {
    try {
        $content = file_get_contents($comuniPath);
        $comuni = json_decode((string) $content, true, flags: JSON_THROW_ON_ERROR);
        if (is_array($comuni)) {
            foreach ($comuni as $comune) {
                $istat = str_pad((string) ($comune['istat'] ?? $comune['codice'] ?? ''), 6, '0', STR_PAD_LEFT);
                if ($istat === '') {
                    continue;
                }
                $capValue = $comune['cap'] ?? '';
                $cap = is_array($capValue) ? (string) ($capValue[0] ?? '') : (string) $capValue;
                $comuniIndex[$istat] = [
                    'city' => (string) ($comune['nome'] ?? ''),
                    'province' => (string) ($comune['sigla'] ?? ''),
                    'cap' => $cap,
                ];
            }
        }
    } catch (Throwable $e) {
        fwrite(STDERR, "Warning: cannot parse comuni.json ({$e->getMessage()}).\n");
    }
}

$index = [
    'street' => $lookup($headers, ['street', 'odonimo']),
    'street_number' => $lookup($headers, ['street_number', 'civico']),
    'esponente' => $lookup($headers, ['esponente']),
    'city' => $lookup($headers, ['city']),
    'province' => $lookup($headers, ['province', 'provincia']),
    'cap' => $lookup($headers, ['cap']),
    'istat' => $lookup($headers, ['codice_istat', 'istat']),
];

if ($index['street'] === false) {
    fwrite(STDERR, "Headers must include at least street (or ODONIMO).\n");
    exit(1);
}

$insert = $pdo->prepare('INSERT INTO anncsu (street, street_number, city, province, cap) VALUES (:street, :street_number, :city, :province, :cap)');

$pdo->beginTransaction();
$rows = 0;
foreach ($csv as $row) {
    if (!is_array($row) || $row === [null]) {
        continue;
    }
    $street = trim((string) ($row[$index['street']] ?? ''));
    $streetNumber = trim((string) ($row[$index['street_number']] ?? ''));
    $esponente = $index['esponente'] !== false ? trim((string) ($row[$index['esponente']] ?? '')) : '';
    $city = $index['city'] !== false ? trim((string) ($row[$index['city']] ?? '')) : '';
    $province = $index['province'] !== false ? trim((string) ($row[$index['province']] ?? '')) : '';
    $cap = $index['cap'] !== false ? trim((string) ($row[$index['cap']] ?? '')) : '';

    if ($street === '') {
        continue;
    }

    if ($streetNumber !== '' && $esponente !== '') {
        $streetNumber = $streetNumber . $esponente;
    }

    if ($city === '' && $index['istat'] !== false) {
        $istat = str_pad(trim((string) ($row[$index['istat']] ?? '')), 6, '0', STR_PAD_LEFT);
        if ($istat !== '' && isset($comuniIndex[$istat])) {
            $city = $comuniIndex[$istat]['city'] ?? '';
            $province = $province ?: ($comuniIndex[$istat]['province'] ?? '');
            $cap = $cap ?: ($comuniIndex[$istat]['cap'] ?? '');
        }
    }

    if ($city === '') {
        continue;
    }

    $insert->execute([
        ':street' => $street,
        ':street_number' => $streetNumber,
        ':city' => $city,
        ':province' => $province,
        ':cap' => $cap,
    ]);
    ++$rows;
    if (($rows % 5000) === 0) {
        fwrite(STDOUT, "Imported {$rows} rows...\n");
    }
}
$pdo->commit();

$pdo->exec('INSERT INTO anncsu_fts(rowid, street, street_number, city, province, cap)
    SELECT id, street, street_number, city, province, cap FROM anncsu;');

fwrite(STDOUT, "Import completed. Rows: {$rows}. DB: {$dbPath}\n");
