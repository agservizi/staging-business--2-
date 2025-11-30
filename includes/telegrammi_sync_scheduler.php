<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;
use App\Services\ServiziWeb\UfficioPostaleClient;

if (!function_exists('maybe_sync_telegrammi')) {
    function maybe_sync_telegrammi(PDO $pdo): void
    {
        static $alreadyRun = false;
        if ($alreadyRun) {
            return;
        }
        $alreadyRun = true;

        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!function_exists('env')) {
            require_once __DIR__ . '/env.php';
        }

        if (!filter_var(env('TELEGRAMMI_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        $token = env('UFFICIO_POSTALE_TOKEN') ?: env('UFFICIO_POSTALE_SANDBOX_TOKEN');
        if (trim((string) $token) === '') {
            return;
        }

        $intervalMinutes = (int) env('TELEGRAMMI_SYNC_INTERVAL', 15);
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        } elseif ($intervalMinutes > 180) {
            $intervalMinutes = 180;
        }

    $configKey = 'telegrammi_sync_last_run';
    $lockName = 'coresuite_telegrammi_sync_lock';
    $rootPath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    $logPath = $rootPath . '/backups/telegrammi_sync.log';

        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => $configKey]);
            $lastRunValue = $stmt->fetchColumn();
            $lastRunTime = $lastRunValue ? new DateTimeImmutable((string) $lastRunValue) : null;
        } catch (Throwable $exception) {
            error_log('Sincronizzazione telegrammi: lettura configurazione fallita - ' . $exception->getMessage());
            return;
        }

        $now = new DateTimeImmutable('now');
        if ($lastRunTime && $lastRunTime->modify('+' . $intervalMinutes . ' minutes') > $now) {
            return;
        }

        try {
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock, 0)');
            $lockStmt->execute([':lock' => $lockName]);
            if ((int) $lockStmt->fetchColumn() !== 1) {
                return;
            }
        } catch (Throwable $exception) {
            error_log('Sincronizzazione telegrammi: ottenimento lock fallito - ' . $exception->getMessage());
            return;
        }

        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => $configKey]);
            $lastRunValue = $stmt->fetchColumn();
            $lastRunTime = $lastRunValue ? new DateTimeImmutable((string) $lastRunValue) : null;
            if ($lastRunTime && $lastRunTime->modify('+' . $intervalMinutes . ' minutes') > $now) {
                return;
            }

            $client = new UfficioPostaleClient();
            $service = new TelegrammiService($pdo);

            $response = $client->listTelegram();
            $payload = $response['data'] ?? [];

            $records = [];
            try {
                $records = $service->persistFromApi($payload, null, null, null);
            } catch (RuntimeException $runtimeException) {
                if ($runtimeException->getMessage() !== 'Risposta API telegrammi vuota o non valida.') {
                    throw $runtimeException;
                }
            }
            $count = count($records);

            $executedAt = new DateTimeImmutable('now');
            $update = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                ON DUPLICATE KEY UPDATE valore = VALUES(valore)');
            $update->execute([
                ':chiave' => $configKey,
                ':valore' => $executedAt->format('Y-m-d H:i:s'),
            ]);

            if ($count > 0) {
                $line = sprintf('%s | sincronizzati=%d', $executedAt->format('Y-m-d H:i:s'), $count);
                $logDir = dirname($logPath);
                if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                    error_log('Sincronizzazione telegrammi: impossibile creare la cartella log ' . $logDir);
                } else {
                    file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }
        } catch (Throwable $exception) {
            error_log('Sincronizzazione telegrammi: esecuzione fallita - ' . $exception->getMessage());
        } finally {
            try {
                $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock)');
                $releaseStmt->execute([':lock' => $lockName]);
            } catch (Throwable $releaseException) {
                error_log('Sincronizzazione telegrammi: rilascio lock fallito - ' . $releaseException->getMessage());
            }
        }
    }
}
