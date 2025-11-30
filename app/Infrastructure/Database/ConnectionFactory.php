<?php
declare(strict_types=1);

namespace App\Infrastructure\Database;

use DateTime;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;

final class ConnectionFactory
{
    /**
     * Create a configured PDO connection for MySQL.
     *
     * @param array{host?:string,port?:int|string,database?:string,username?:string,password?:string,charset?:string,collation?:string,options?:array<int,int|string>,attributes?:array<int,mixed>,persistent?:bool} $config
     */
    public static function make(array $config): PDO
    {
        $host = self::stringValue($config, 'host', null, false);
        $database = self::stringValue($config, 'database', null, false);
        $username = self::stringValue($config, 'username', null, false);
        $password = self::stringValue($config, 'password', null, false);
        $charset = self::stringValue($config, 'charset', 'utf8mb4', false);
        $port = (int) self::stringValue($config, 'port', '3306', false);

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $database,
            $charset
        );

        $options = self::mergeOptions($config);

        try {
            $pdo = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $exception) {
            $detail = $exception->getMessage();
            if ($detail === '' && $exception->getCode() !== 0) {
                $detail = (string) $exception->getCode();
            }
            throw new RuntimeException(
                $detail !== '' ? 'Database connection failed: ' . $detail : 'Database connection failed',
                0,
                $exception
            );
        }

        $collation = $config['collation'] ?? null;
        if (is_string($collation) && $collation !== '') {
            $charsetForSet = self::sanitizeIdentifier($charset);
            $collationForSet = self::sanitizeIdentifier($collation);

            if ($charsetForSet !== '' && $collationForSet !== '') {
                $pdo->exec(sprintf("SET NAMES '%s' COLLATE '%s'", $charsetForSet, $collationForSet));
            }
        }

        if (!empty($config['attributes']) && is_array($config['attributes'])) {
            foreach ($config['attributes'] as $attribute => $value) {
                $pdo->setAttribute($attribute, $value);
            }
        }

        return $pdo;
    }

    public static function resolveMysqlTimezone(?string $timezone): ?string
    {
        if ($timezone === null) {
            return null;
        }

        $trimmed = trim($timezone);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/^[+-]\d{2}:\d{2}$/', $trimmed) === 1) {
            return $trimmed;
        }

        try {
            $tz = new DateTimeZone($trimmed);
            $now = new DateTime('now', $tz);
            return $now->format('P');
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $config
     * @return array<int,mixed>
     */
    private static function mergeOptions(array $config): array
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if (isset($config['options']) && is_array($config['options'])) {
            $options = $config['options'] + $options;
        }

        if (array_key_exists('persistent', $config)) {
            $options[PDO::ATTR_PERSISTENT] = (bool) $config['persistent'];
        }

        return $options;
    }

    private static function stringValue(array $config, string $key, ?string $default = null, bool $allowEmpty = true): string
    {
        $value = $config[$key] ?? $default;

        if ($value === null) {
            throw new RuntimeException(sprintf('Missing database configuration key: %s', $key));
        }

        $stringValue = (string) $value;

        if ($stringValue === '' && !$allowEmpty) {
            throw new RuntimeException(sprintf('Missing database configuration key: %s', $key));
        }

        return $stringValue;
    }

    private static function sanitizeIdentifier(string $value): string
    {
        $sanitized = preg_replace('/[^a-zA-Z0-9_]+/', '', $value);

        return $sanitized ?? '';
    }
}
