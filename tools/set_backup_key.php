<?php
declare(strict_types=1);

$envPath = __DIR__ . '/../.env';
if (!is_file($envPath)) {
    fwrite(STDERR, ".env non trovato\n");
    exit(1);
}

$key = bin2hex(random_bytes(32));
$content = file_get_contents($envPath);
if ($content === false) {
    fwrite(STDERR, "Impossibile leggere .env\n");
    exit(1);
}

$updated = preg_replace(
    '/^BACKUP_ENCRYPTION_KEY=.*$/m',
    'BACKUP_ENCRYPTION_KEY="' . $key . '"',
    $content
);

if ($updated === null) {
    fwrite(STDERR, "Errore durante la sostituzione della chiave\n");
    exit(1);
}

if ($updated === $content && !str_contains($content, 'BACKUP_ENCRYPTION_KEY=')) {
    $updated .= PHP_EOL . 'BACKUP_ENCRYPTION_KEY="' . $key . '"' . PHP_EOL;
}

if (file_put_contents($envPath, $updated) === false) {
    fwrite(STDERR, "Impossibile scrivere .env\n");
    exit(1);
}

echo $key, PHP_EOL;
