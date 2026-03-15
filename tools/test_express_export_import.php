<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';

load_env(__DIR__ . '/../.env');

function split_sql_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($index = 0; $index < $length; $index++) {
        $char = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';
        $prev = $index > 0 ? $sql[$index - 1] : '';

        if ($inLineComment) {
            if ($char === "\n") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            if ($char === '*' && $next === '/') {
                $inBlockComment = false;
                $index++;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($sql[$index + 2]))) {
                $inLineComment = true;
                $index++;
                continue;
            }

            if ($char === '#') {
                $inLineComment = true;
                continue;
            }

            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $index++;
                continue;
            }
        }

        if ($char === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
            $inSingle = !$inSingle;
            $buffer .= $char;
            continue;
        }

        if ($char === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
            $inDouble = !$inDouble;
            $buffer .= $char;
            continue;
        }

        if ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
            $buffer .= $char;
            continue;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $tail = trim($buffer);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

function create_pdo(string $dsn, ?string $database = null): PDO
{
    $username = (string) env('DB_USERNAME');
    $password = (string) env('DB_PASSWORD');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    if ($database !== null && $database !== '') {
        $dsn .= ';dbname=' . $database;
    }

    return new PDO($dsn, $username, $password, $options);
}

$path = $argv[1] ?? null;
if (!is_string($path) || $path === '') {
    fwrite(STDERR, "Uso: php tools/test_express_export_import.php <file-sql> [--keep-db]\n");
    exit(1);
}

$keepDb = in_array('--keep-db', $argv, true);
$sqlPath = realpath($path);
if ($sqlPath === false || !is_file($sqlPath)) {
    fwrite(STDERR, "File SQL non trovato: {$path}\n");
    exit(1);
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;charset=%s',
    (string) env('DB_HOST'),
    (string) env('DB_PORT'),
    (string) env('DB_CHARSET', 'utf8mb4')
);

$databaseName = 'express_compat_pdo_' . date('Ymd_His');

try {
    $serverPdo = create_pdo($dsn);
    $serverPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $databaseName));
    $serverPdo->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $databaseName));

    $pdo = create_pdo($dsn, $databaseName);
    $sql = file_get_contents($sqlPath);
    if ($sql === false) {
        throw new RuntimeException('Impossibile leggere il file SQL.');
    }

    $statements = split_sql_statements($sql);
    $executed = 0;
    foreach ($statements as $statement) {
        $pdo->exec($statement);
        $executed++;
    }

    $tables = [
        'clienti',
        'servizi_express_operatori',
        'servizi_express_prodotti',
        'servizi_express_offerte',
        'servizi_express_vendite',
        'servizi_express_vendita_righe',
        'servizi_express_iccid_stock',
        'servizi_express_richieste',
    ];

    echo 'DB_TEST=' . $databaseName . PHP_EOL;
    echo 'STATEMENTS=' . $executed . PHP_EOL;

    foreach ($tables as $table) {
        $count = (int) $pdo->query(sprintf('SELECT COUNT(*) FROM `%s`', $table))->fetchColumn();
        echo $table . '=' . $count . PHP_EOL;
    }

    if (!$keepDb) {
        $serverPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $databaseName));
        echo 'DB_DROPPED=1' . PHP_EOL;
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'DB_TEST=' . $databaseName . PHP_EOL);
    fwrite(STDERR, 'ERROR=' . $exception->getMessage() . PHP_EOL);
    exit(1);
}