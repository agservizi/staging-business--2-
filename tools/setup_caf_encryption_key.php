<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/env.php';

$envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
$keyName = 'CAF_PATRONATO_ENCRYPTION_KEY';

if (is_file($envPath)) {
    load_env($envPath);
    $existing = env($keyName);
    if (is_string($existing) && trim($existing) !== '') {
        fwrite(STDOUT, "Chiave già presente in .env\n");
        exit(0);
    }
} else {
    $template = <<<'ENV'
APP_DEBUG=false
APP_TIMEZONE=Europe/Rome

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci

AUTOMATA_BASE_URL=https://automa.coresuite.it
AUTOMATA_API_KEY=
AUTOMATA_MODEL=automata-default

ENV;
    file_put_contents($envPath, $template);
    load_env($envPath);
}

$hexKey = bin2hex(random_bytes(32));
$line = $keyName . '=' . $hexKey . PHP_EOL;

if (!file_put_contents($envPath, $line, FILE_APPEND | LOCK_EX)) {
    fwrite(STDERR, "Impossibile scrivere in .env\n");
    exit(1);
}

fwrite(STDOUT, "Chiave CAF_PATRONATO_ENCRYPTION_KEY generata e salvata in .env\n");
