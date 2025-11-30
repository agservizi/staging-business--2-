<?php
declare(strict_types=1);

use App\Services\AppointmentReminderService;

if (!function_exists('maybe_dispatch_appointment_reminders')) {
    function maybe_dispatch_appointment_reminders(PDO $pdo): void
    {
        static $alreadyRun = false;
        if ($alreadyRun) {
            return;
        }
        $alreadyRun = true;

        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!filter_var(env('APPOINTMENT_REMINDERS_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $logPath = __DIR__ . '/../backups/appointment_reminders.log';
        $lockName = 'coresuite_reminder_lock';
        $configKey = 'appointment_reminders_last_run';
        $intervalMinutes = (int) env('APPOINTMENT_REMINDERS_INTERVAL', 10);
        if ($intervalMinutes < 1) {
            $intervalMinutes = 1;
        } elseif ($intervalMinutes > 120) {
            $intervalMinutes = 120;
        }

        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => $configKey]);
            $lastRunValue = $stmt->fetchColumn();
            $lastRunTime = $lastRunValue ? new DateTimeImmutable((string) $lastRunValue) : null;
        } catch (Throwable $exception) {
            error_log('Promemoria appuntamenti: lettura configurazione fallita - ' . $exception->getMessage());
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
            error_log('Promemoria appuntamenti: ottenimento lock fallito - ' . $exception->getMessage());
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

            require_once __DIR__ . '/mailer.php';

            $service = new AppointmentReminderService(
                $pdo,
                static function (string $to, string $subject, string $htmlBody): bool {
                    return send_system_mail($to, $subject, $htmlBody);
                },
                $logPath
            );

            $service->dispatch(30, false);

            $executedAt = new DateTimeImmutable('now');

            $update = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                ON DUPLICATE KEY UPDATE valore = VALUES(valore)');
            $update->execute([
                ':chiave' => $configKey,
                ':valore' => $executedAt->format('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $exception) {
            error_log('Promemoria appuntamenti: esecuzione fallita - ' . $exception->getMessage());
        } finally {
            try {
                $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock)');
                $releaseStmt->execute([':lock' => $lockName]);
            } catch (Throwable $releaseException) {
                error_log('Promemoria appuntamenti: rilascio lock fallito - ' . $releaseException->getMessage());
            }
        }
    }
}
