<?php
if (!function_exists('load_env')) {
    function load_env(string $path): void
    {
        static $loaded = [];
        if (isset($loaded[$path])) {
            return;
        }
        if (!is_file($path)) {
            $loaded[$path] = false;
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (isset($trimmed[0]) && ($trimmed[0] === '#' || $trimmed[0] === ';')) {
                continue;
            }
            if (strncmp($trimmed, "\xEF\xBB\xBF", 3) === 0) {
                $trimmed = substr($trimmed, 3);
            }
            if ($trimmed === '') {
                continue;
            }
            $delimiterPosition = strpos($trimmed, '=');
            if ($delimiterPosition === false) {
                continue;
            }
            $name = trim(substr($trimmed, 0, $delimiterPosition));
            if (strncmp($name, "\xEF\xBB\xBF", 3) === 0) {
                $name = substr($name, 3);
            }
            $value = trim(substr($trimmed, $delimiterPosition + 1));

            if ($value !== '') {
                if (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            $value = str_replace(['\\n', '\\r'], ["\n", "\r"], $value);

            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            if (!isset($_SERVER[$name])) {
                $_SERVER[$name] = $value;
            }
        }
        $loaded[$path] = true;
    }
}

if (!function_exists('env')) {
    function env(string $key, $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return $default;
            }
        }
        return $value;
    }
}

if (!function_exists('configure_timezone')) {
    function configure_timezone(): void
    {
        $timezone = env('APP_TIMEZONE', 'Europe/Rome');
        if ($timezone === null || $timezone === '') {
            return;
        }

        if (@date_default_timezone_set($timezone) === false) {
            error_log('Timezone non valida configurata: ' . $timezone);
            date_default_timezone_set('Europe/Rome');
        }
    }
}
