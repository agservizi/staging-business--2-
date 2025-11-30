<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Trova il percorso dell'autoloader condiviso, tenendo conto di installazioni su sottodomini.
 */
$bootstrapCandidates = [];

$envBootstrapPath = env('PORTAL_BOOTSTRAP_PATH');
if (is_string($envBootstrapPath) && $envBootstrapPath !== '') {
    $bootstrapCandidates[] = $envBootstrapPath;
}

$bootstrapCandidates[] = dirname(__DIR__, 3) . '/bootstrap/autoload.php';
$bootstrapCandidates[] = dirname(__DIR__, 2) . '/bootstrap/autoload.php';

$bootstrapPath = null;
foreach ($bootstrapCandidates as $candidate) {
    if (!is_string($candidate) || $candidate === '') {
        continue;
    }

    $normalized = str_replace(['\\', '//'], '/', $candidate);
    if (is_file($normalized)) {
        $bootstrapPath = $normalized;
        break;
    }
}

if ($bootstrapPath === null) {
    $checked = array_values(array_filter(array_map(static function ($path) {
        return is_string($path) ? $path : null;
    }, $bootstrapCandidates)));

    $message = 'Impossibile trovare bootstrap/autoload.php per il portale pickup.';
    if ($checked) {
        $message .= ' Percorsi verificati: ' . implode(', ', $checked);
    }

    portal_error_log($message);
    throw new RuntimeException($message);
}

portal_debug_log('Bootstrap autoloader resolved', [
    'path' => $bootstrapPath,
]);

require_once $bootstrapPath;

/**
 * Classe per la gestione della connessione database del portale cliente
 */
class PortalDatabase {
    private static ?PDO $connection = null;
    private static ?bool $sharedConnection = null;

    /**
     * Determina se il portale deve utilizzare le stesse credenziali del gestionale business
     */
    private static function useSharedConnection(): bool
    {
        if (self::$sharedConnection !== null) {
            return self::$sharedConnection;
        }

        $raw = env('PORTAL_USE_SHARED_DB', 'true');
        $flag = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($flag === null) {
            $normalized = strtolower((string) $raw);
            $flag = in_array($normalized, ['1', 'on', 'yes', 'true', 'shared'], true);
        }

        self::$sharedConnection = $flag ?? true;

        return self::$sharedConnection;
    }

    /**
     * Recupera una variabile d'ambiente con preferenza per i valori del portale.
     */
    private static function envValue(string $key, $default = null): ?string
    {
        $value = null;

        if (!self::useSharedConnection()) {
            $portalKey = 'PORTAL_' . $key;
            $value = env($portalKey);
        }

        if ($value === null || $value === '') {
            $value = env($key, $default);
        }

        if ($value === null || $value === '') {
            return $default === null ? null : (string) $default;
        }

        return is_string($value) ? trim($value) : (string) $value;
    }
    
    public static function getConnection(): PDO {
        if (self::$connection === null) {
            $host = self::envValue('DB_HOST', '127.0.0.1');
            $port = self::envValue('DB_PORT', '3306');
            $database = self::envValue('DB_DATABASE', 'coresuite');
            $username = self::envValue('DB_USERNAME', 'root');
            $password = self::envValue('DB_PASSWORD', '');
            $charset = self::envValue('DB_CHARSET', 'utf8mb4');
            $collation = self::envValue('DB_COLLATION', 'utf8mb4_unicode_ci');
            $persistent = filter_var(self::envValue('DB_PERSISTENT', 'false'), FILTER_VALIDATE_BOOL) ?? false;

            try {
                self::$connection = \App\Infrastructure\Database\ConnectionFactory::make([
                    'host' => $host,
                    'port' => $port,
                    'database' => $database,
                    'username' => $username,
                    'password' => $password,
                    'charset' => $charset,
                    'collation' => $collation,
                    'persistent' => $persistent,
                ]);
            } catch (Throwable $connectionException) {
                portal_error_log('Database connection failed: ' . $connectionException->getMessage());

                $debugMessage = 'Errore di connessione al database';
                if (filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL)) {
                    $debugMessage .= ': ' . $connectionException->getMessage();
                }

                throw new Exception($debugMessage, 0, $connectionException);
            }

            $timezonePreference = self::envValue('DB_TIMEZONE');
            if ($timezonePreference === null || $timezonePreference === '') {
                $timezonePreference = self::envValue('APP_TIMEZONE', '+00:00');
            }

            $mysqlTimezone = \App\Infrastructure\Database\ConnectionFactory::resolveMysqlTimezone($timezonePreference);
            if ($mysqlTimezone === null && $timezonePreference !== null && $timezonePreference !== '') {
                $mysqlTimezone = '+00:00';
            }
            if ($mysqlTimezone !== null) {
                try {
                    self::$connection->exec(sprintf("SET time_zone = '%s'", addslashes($mysqlTimezone)));
                } catch (PDOException $timezoneException) {
                    portal_error_log('Failed to apply configured MySQL timezone', [
                        'configured_timezone' => $timezonePreference,
                        'resolved_timezone' => $mysqlTimezone,
                        'error' => $timezoneException->getMessage(),
                    ]);
                }
            }

            portal_debug_log('Database connection established for portal', [
                'mode' => self::useSharedConnection() ? 'shared' : 'dedicated',
            ]);
        }
        
        return self::$connection;
    }
    
    public static function beginTransaction(): bool {
        return self::getConnection()->beginTransaction();
    }
    
    public static function commit(): bool {
        return self::getConnection()->commit();
    }
    
    public static function rollback(): bool {
        return self::getConnection()->rollback();
    }
    
    public static function lastInsertId(): string {
        return self::getConnection()->lastInsertId();
    }
    
    /**
     * Esegue una query preparata
     */
    public static function execute(string $sql, array $params = []): PDOStatement {
        try {
            $stmt = self::getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            portal_error_log('Database query failed: ' . $e->getMessage(), [
                'sql' => $sql,
                'params' => $params
            ]);
            throw new Exception('Errore durante l\'esecuzione della query');
        }
    }
    
    /**
     * Recupera un singolo record
     */
    public static function fetchOne(string $sql, array $params = []): ?array {
        $stmt = self::execute($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Recupera tutti i record
     */
    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::execute($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Recupera un singolo valore
     */
    public static function fetchValue(string $sql, array $params = []) {
        $stmt = self::execute($sql, $params);
        return $stmt->fetchColumn();
    }
    
    /**
     * Controlla se esiste almeno un record
     */
    public static function exists(string $sql, array $params = []): bool {
        $stmt = self::execute($sql, $params);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Conta i record
     */
    public static function count(string $sql, array $params = []): int {
        $result = self::fetchValue($sql, $params);
        return (int) $result;
    }
    
    /**
     * Inserisce un record e restituisce l'ID
     */
    public static function insert(string $table, array $data): int {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );
        
        self::execute($sql, $data);
        return (int) self::lastInsertId();
    }
    
    /**
     * Aggiorna record
     */
    public static function update(string $table, array $data, array $where): int {
        $setClause = implode(', ', array_map(fn($col) => $col . ' = :' . $col, array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn($col) => $col . ' = :where_' . $col, array_keys($where)));
        
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, $setClause, $whereClause);
        
        $params = $data;
        foreach ($where as $key => $value) {
            $params['where_' . $key] = $value;
        }
        
        $stmt = self::execute($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Elimina record
     */
    public static function delete(string $table, array $where): int {
        $whereClause = implode(' AND ', array_map(fn($col) => $col . ' = :' . $col, array_keys($where)));
        $sql = sprintf('DELETE FROM %s WHERE %s', $table, $whereClause);
        
        $stmt = self::execute($sql, $where);
        return $stmt->rowCount();
    }

}

/**
 * Funzioni helper per l'accesso rapido al database
 */
function portal_db(): PDO {
    return PortalDatabase::getConnection();
}

function portal_query(string $sql, array $params = []): PDOStatement {
    return PortalDatabase::execute($sql, $params);
}

function portal_fetch_one(string $sql, array $params = []): ?array {
    return PortalDatabase::fetchOne($sql, $params);
}

function portal_fetch_all(string $sql, array $params = []): array {
    return PortalDatabase::fetchAll($sql, $params);
}

function portal_fetch_value(string $sql, array $params = []) {
    return PortalDatabase::fetchValue($sql, $params);
}

function portal_exists(string $sql, array $params = []): bool {
    return PortalDatabase::exists($sql, $params);
}

function portal_count(string $sql, array $params = []): int {
    return PortalDatabase::count($sql, $params);
}

function portal_insert(string $table, array $data): int {
    return PortalDatabase::insert($table, $data);
}

function portal_update(string $table, array $data, array $where): int {
    return PortalDatabase::update($table, $data, $where);
}

function portal_delete(string $table, array $where): int {
    return PortalDatabase::delete($table, $where);
}