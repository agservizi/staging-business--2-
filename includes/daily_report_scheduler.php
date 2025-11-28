<?php
declare(strict_types=1);

use App\Services\DailyFinancialReportService;

if (!function_exists('maybe_generate_daily_financial_reports')) {
    function maybe_generate_daily_financial_reports(PDO $pdo): void
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

        if (!filter_var(env('DAILY_REPORTS_ENABLED', true), FILTER_VALIDATE_BOOL)) {
            return;
        }

        $rootPath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $logPath = $rootPath . '/backups/daily_reports.log';
        $lockName = 'coresuite_daily_report_lock';
        $configKey = 'daily_reports_last_generated';

        $catchupDays = (int) env('DAILY_REPORTS_CATCHUP_DAYS', 3);
        if ($catchupDays < 1) {
            $catchupDays = 1;
        } elseif ($catchupDays > 31) {
            $catchupDays = 31;
        }

        $today = new DateTimeImmutable('today');
        $targetDate = $today->modify('-1 day');
        if ($targetDate < new DateTimeImmutable('2000-01-01')) {
            return;
        }

        $lastGenerated = fetch_last_generated_report_date($pdo, $configKey);
        $currentDate = $lastGenerated ? $lastGenerated->modify('+1 day') : $targetDate;

        if ($currentDate > $targetDate) {
            return;
        }

        $offsetDays = max(0, $catchupDays - 1);
        if ($offsetDays > 0) {
            $earliestAllowed = $targetDate->modify('-' . $offsetDays . ' days');
            if ($currentDate < $earliestAllowed) {
                $currentDate = $earliestAllowed;
            }
        }

        $mysqlLockAcquired = false;
        $fileLockHandle = null;
        $lockFilePath = $rootPath . '/backups/.daily-report.lock';

        try {
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
            $lockStmt->execute([':name' => $lockName]);
            $mysqlLockAcquired = ((int) $lockStmt->fetchColumn() === 1);
        } catch (Throwable $exception) {
            error_log('Report giornaliero: impossibile acquisire il lock MySQL - ' . $exception->getMessage());
            $mysqlLockAcquired = false;
        }

        if (!$mysqlLockAcquired) {
            if (!is_dir(dirname($lockFilePath)) && !mkdir(dirname($lockFilePath), 0775, true) && !is_dir(dirname($lockFilePath))) {
                error_log('Report giornaliero: impossibile creare la cartella per il file di lock.');
                return;
            }

            $fileLockHandle = @fopen($lockFilePath, 'c');
            if ($fileLockHandle === false) {
                error_log('Report giornaliero: impossibile aprire il file di lock: ' . $lockFilePath);
                return;
            }

            if (!flock($fileLockHandle, LOCK_EX | LOCK_NB)) {
                fclose($fileLockHandle);
                return;
            }
        }

        try {
            $service = new DailyFinancialReportService($pdo, $rootPath);
            $processedDate = null;

            while ($currentDate <= $targetDate) {
                $formatted = $currentDate->format('Y-m-d');

                try {
                    $result = $service->generateForDate($currentDate);
                    $processedDate = $currentDate;
                    $logLine = sprintf('%s | report=%s | saldo=%0.2f | pdf=on-demand',
                        (new DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
                        $formatted,
                        $result['saldo']
                    );
                    file_put_contents($logPath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
                } catch (Throwable $generationException) {
                    error_log('Report giornaliero: generazione fallita per ' . $formatted . ' - ' . $generationException->getMessage());
                    break;
                }

                $currentDate = $currentDate->modify('+1 day');
            }

            if ($processedDate instanceof DateTimeImmutable) {
                $stmt = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                    ON DUPLICATE KEY UPDATE valore = VALUES(valore)');
                $stmt->execute([
                    ':chiave' => $configKey,
                    ':valore' => $processedDate->format('Y-m-d'),
                ]);
            }
        } catch (Throwable $exception) {
            error_log('Report giornaliero: esecuzione fallita - ' . $exception->getMessage());
        } finally {
            if ($mysqlLockAcquired) {
                try {
                    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
                    $releaseStmt->execute([':name' => $lockName]);
                } catch (Throwable $releaseException) {
                    error_log('Report giornaliero: rilascio lock MySQL fallito - ' . $releaseException->getMessage());
                }
            }

            if (is_resource($fileLockHandle)) {
                flock($fileLockHandle, LOCK_UN);
                fclose($fileLockHandle);
            }
        }
    }

    /**
     * @return DateTimeImmutable|null
     */
    function fetch_last_generated_report_date(PDO $pdo, string $configKey): ?DateTimeImmutable
    {
        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => $configKey]);
            $value = $stmt->fetchColumn();
            if ($value) {
                return new DateTimeImmutable((string) $value);
            }
        } catch (Throwable $exception) {
            error_log('Report giornaliero: lettura configurazioni fallita - ' . $exception->getMessage());
        }

        try {
            $stmt = $pdo->query('SELECT MAX(report_date) FROM daily_financial_reports');
            $value = $stmt->fetchColumn();
            if ($value) {
                return new DateTimeImmutable((string) $value);
            }
        } catch (Throwable $exception) {
            error_log('Report giornaliero: lettura storico report fallita - ' . $exception->getMessage());
        }

        return null;
    }
}
