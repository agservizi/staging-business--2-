<?php
use App\Infrastructure\Database\ConnectionFactory;

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/env.php';

load_env(__DIR__ . '/../.env');
configure_timezone();

$database = [
    'host' => env('DB_HOST'),
    'port' => env('DB_PORT'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
];

$debug = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);

$missing = [];
foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
    if ($database[$key] === null || $database[$key] === '') {
        $missing[] = 'DB_' . strtoupper($key);
    }
}

if ($missing) {
    $message = 'Configurazione database non valida. Contatta l\'amministratore.';
    error_log('Missing database configuration keys: ' . implode(', ', $missing));
    if ($debug) {
        $envPath = realpath(__DIR__ . '/../.env') ?: '.env';
        $details = [
            'file' => $envPath,
            'missing' => $missing,
            'values' => array_map(static fn($value) => $value === null ? 'null' : ($value === '' ? '[vuoto]' : '[impostato]'), $database),
        ];
        $message .= '<br><pre>' . htmlspecialchars(print_r($details, true), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    http_response_code(500);
    echo $message;
    exit;
}

$database['persistent'] = filter_var(env('DB_PERSISTENT', false), FILTER_VALIDATE_BOOL);

try {
    $pdo = ConnectionFactory::make($database);
} catch (Throwable $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    $message = 'Errore di connessione al database. Contatta l\'amministratore.';
    if ($debug) {
        $message .= '<br><pre>' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
    }
    echo $message;
    exit;
}

$dbTimezonePreference = env('DB_TIMEZONE');
if ($dbTimezonePreference === null || $dbTimezonePreference === '') {
    $dbTimezonePreference = env('APP_TIMEZONE', 'Europe/Rome');
}

if ($dbTimezonePreference !== null && $dbTimezonePreference !== '') {
    $resolvedMysqlTimezone = ConnectionFactory::resolveMysqlTimezone($dbTimezonePreference);
    if ($resolvedMysqlTimezone !== null) {
        try {
            $pdo->exec("SET time_zone = '" . $resolvedMysqlTimezone . "'");
        } catch (Throwable $tzException) {
            error_log('Impossibile impostare il fuso orario MySQL: ' . $tzException->getMessage());
        }
    }
}

require_once __DIR__ . '/appointment_scheduler.php';
maybe_dispatch_appointment_reminders($pdo);
require_once __DIR__ . '/daily_report_scheduler.php';
maybe_generate_daily_financial_reports($pdo);
require_once __DIR__ . '/energia_reminder_scheduler.php';
maybe_send_energia_reminders($pdo);
require_once __DIR__ . '/telegrammi_sync_scheduler.php';
maybe_sync_telegrammi($pdo);
require_once __DIR__ . '/brt_tracking_scheduler.php';
maybe_refresh_brt_tracking($pdo);
