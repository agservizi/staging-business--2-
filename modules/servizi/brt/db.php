<?php
declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
    http_response_code(403);
    exit('Accesso non autorizzato.');
}

require_once __DIR__ . '/../../../bootstrap/autoload.php';
require_once __DIR__ . '/../../../includes/env.php';

load_env(__DIR__ . '/../../../.env');
configure_timezone();

function brt_db(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    static $localPdo = null;

    if ($localPdo instanceof PDO) {
        return $localPdo;
    }

    $config = [
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'database' => env('DB_DATABASE', 'coresuite'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ];

    $persistent = env('DB_PERSISTENT');
    if ($persistent !== null && $persistent !== '') {
        $config['persistent'] = filter_var($persistent, FILTER_VALIDATE_BOOL);
    }

    $localPdo = ConnectionFactory::make($config);

    $timezonePreference = env('DB_TIMEZONE');
    if ($timezonePreference === null || $timezonePreference === '') {
        $timezonePreference = env('APP_TIMEZONE', 'Europe/Rome');
    }

    if ($timezonePreference !== null && $timezonePreference !== '') {
        $resolvedTimezone = ConnectionFactory::resolveMysqlTimezone((string) $timezonePreference);
        if ($resolvedTimezone !== null) {
            try {
                $localPdo->exec("SET time_zone = '" . $resolvedTimezone . "'");
            } catch (Throwable $exception) {
                error_log('Impossibile impostare il fuso orario MySQL: ' . $exception->getMessage());
            }
        }
    }

    return $localPdo;
}
