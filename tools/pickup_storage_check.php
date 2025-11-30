<?php
declare(strict_types=1);

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    define('CORESUITE_PICKUP_BOOTSTRAP', true);
}

require_once __DIR__ . '/../modules/servizi/logistici/functions.php';

ensure_pickup_tables();

$arguments = $argv ?? [];
array_shift($arguments);

$graceDays = isset($arguments[0]) ? (int) $arguments[0] : PICKUP_DEFAULT_STORAGE_GRACE_DAYS;
$warningDays = isset($arguments[1]) ? (int) $arguments[1] : PICKUP_STORAGE_WARNING_BEFORE_DAYS;

try {
    $result = check_storage_expiration($graceDays, ['warning_days' => $warningDays]);

    $processed = (int) ($result['processed'] ?? 0);
    $warned = (int) ($result['warned'] ?? 0);
    $expired = (int) ($result['expired'] ?? 0);
    $window = (int) ($result['warning_days'] ?? $warningDays);

    echo "Verifica giacenza completata\n";
    echo "Pacchi processati: {$processed}\n";
    echo "Avvisi inviati: {$warned}\n";
    echo "Scadenze registrate: {$expired}\n";
    echo "Giorni grazia: {$graceDays} | Preavviso: {$window}\n";

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Errore verifica giacenza: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
