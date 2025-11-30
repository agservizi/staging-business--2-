<?php
declare(strict_types=1);

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    http_response_code(403);
    exit('Accesso non autorizzato.');
}

require_once __DIR__ . '/../../../includes/env.php';

load_env(__DIR__ . '/../../../.env');
configure_timezone();

function pickup_db(bool $reset = false): PDO
{
    static $pdo = null;

    if ($reset) {
        $pdo = null;
    }

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower((string) env('DB_CONNECTION', 'mysql'));

    if ($driver === 'sqlite') {
        $database = (string) env('DB_DATABASE', ':memory:');
        $dsn = $database === ':memory:' ? 'sqlite::memory:' : 'sqlite:' . $database;

        $pdo = new PDO($dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    $host = env('DB_HOST', '127.0.0.1');
    $port = env('DB_PORT', '3306');
    $database = env('DB_DATABASE', 'coresuite');
    $username = env('DB_USERNAME', 'root');
    $password = env('DB_PASSWORD', '');

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, $username, $password, $options);
    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('SET time_zone = "+00:00"');

    return $pdo;
}
