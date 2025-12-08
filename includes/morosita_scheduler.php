<?php
declare(strict_types=1);

use App\Services\Morosita\MorositaService;

if (!function_exists('maybe_refresh_morosita')) {
    function maybe_refresh_morosita(PDO $pdo): void
    {
        static $alreadyRun = false;
        if ($alreadyRun) {
            return;
        }
        $alreadyRun = true;

        if (!function_exists('env')) {
            require_once __DIR__ . '/env.php';
        }

        if (!filter_var(env('MOROSITA_REFRESH_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        if (PHP_SAPI === 'cli' && !filter_var(env('MOROSITA_REFRESH_ALLOW_CLI', false), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $intervalMinutes = (int) env('MOROSITA_REFRESH_EVERY_MINUTES', 360);
        if ($intervalMinutes < 30) {
            $intervalMinutes = 30;
        }

        $staleDays = (int) env('MOROSITA_REFRESH_STALE_DAYS', 30);
        if ($staleDays < 1) {
            $staleDays = 1;
        }

        $lastRun = fetch_morosita_last_run($pdo);
        $now = new DateTimeImmutable('now');
        if ($lastRun instanceof DateTimeInterface) {
            $nextAllowed = $lastRun->add(new DateInterval('PT' . $intervalMinutes . 'M'));
            if ($nextAllowed > $now) {
                return;
            }
        }

        try {
            $service = new MorositaService($pdo);
            $result = $service->refreshStale($staleDays);
            if (($result['processed'] ?? 0) > 0) {
                store_morosita_last_run($pdo, $now);
            }
        } catch (Throwable $exception) {
            error_log('Morosità scheduler: errore durante il refresh - ' . $exception->getMessage());
        }
    }
}

if (!function_exists('fetch_morosita_last_run')) {
    function fetch_morosita_last_run(PDO $pdo): ?DateTimeImmutable
    {
        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :key LIMIT 1');
            $stmt->execute([':key' => 'morosita_last_refresh']);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null || $value === '') {
                return null;
            }

            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value);
            return $dt ?: null;
        } catch (Throwable $exception) {
            error_log('Morosità scheduler: impossibile leggere la configurazione - ' . $exception->getMessage());
            return null;
        }
    }
}

if (!function_exists('store_morosita_last_run')) {
    function store_morosita_last_run(PDO $pdo, DateTimeImmutable $when): void
    {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':key' => 'morosita_last_refresh',
                ':value' => $when->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            error_log('Morosità scheduler: impossibile salvare la configurazione - ' . $exception->getMessage());
        }
    }
}
