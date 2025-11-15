<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtTrackingService;

if (!function_exists('maybe_refresh_brt_tracking')) {
    function maybe_refresh_brt_tracking(PDO $pdo): void
    {
        static $alreadyRun = false;
        if ($alreadyRun) {
            return;
        }
        $alreadyRun = true;

        if (PHP_SAPI === 'cli') {
            return;
        }

        try {
            $config = new BrtConfig();
        } catch (\Throwable $exception) {
            error_log('BRT tracking: configurazione non valida - ' . $exception->getMessage());
            return;
        }

        if (!$config->isTrackingSchedulerEnabled()) {
            return;
        }

        $statuses = $config->getTrackingStatuses();
        if ($statuses === []) {
            return;
        }

        $intervalMinutes = $config->getTrackingIntervalMinutes();
        $batchSize = $config->getTrackingBatchSize();
        $staleMinutes = $config->getTrackingStaleMinutes();
        $maxAgeDays = $config->getTrackingMaxAgeDays();

        $lockName = 'coresuite_brt_tracking_lock';
        $configKey = 'brt_tracking_last_run';

        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :key LIMIT 1');
            $stmt->execute([':key' => $configKey]);
            $lastRunValue = $stmt->fetchColumn();
            $lastRun = $lastRunValue ? new \DateTimeImmutable((string) $lastRunValue) : null;
        } catch (\Throwable $exception) {
            error_log('BRT tracking: lettura configurazione fallita - ' . $exception->getMessage());
            return;
        }

        $now = new \DateTimeImmutable('now');
        if ($lastRun && $lastRun->modify('+' . $intervalMinutes . ' minutes') > $now) {
            return;
        }

        $lockAcquired = false;
        try {
            $lockStmt = $pdo->prepare('SELECT GET_LOCK(:lock, 0)');
            $lockStmt->execute([':lock' => $lockName]);
            if ((int) $lockStmt->fetchColumn() !== 1) {
                return;
            }
            $lockAcquired = true;

            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :key LIMIT 1');
            $stmt->execute([':key' => $configKey]);
            $lastRunValue = $stmt->fetchColumn();
            $lastRun = $lastRunValue ? new \DateTimeImmutable((string) $lastRunValue) : null;
            if ($lastRun && $lastRun->modify('+' . $intervalMinutes . ' minutes') > $now) {
                return;
            }

            if (!defined('CORESUITE_BRT_BOOTSTRAP')) {
                define('CORESUITE_BRT_BOOTSTRAP', true);
            }
            require_once __DIR__ . '/../modules/servizi/brt/functions.php';
            try {
                ensure_brt_tables();
            } catch (\RuntimeException $exception) {
                error_log('BRT tracking: tabelle mancanti - ' . $exception->getMessage());
                brt_log_event('error', 'Scheduler tracking: tabelle mancanti', [
                    'error' => $exception->getMessage(),
                    'user' => 'scheduler',
                ]);
                return;
            }

            $staleBefore = $now->modify(sprintf('-%d minutes', $staleMinutes));
            $notOlderThan = $maxAgeDays > 0 ? $now->modify(sprintf('-%d days', $maxAgeDays)) : null;
            $shipments = brt_get_shipments_pending_tracking($statuses, $staleBefore, $notOlderThan, $batchSize);

            if ($shipments === []) {
                $update = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:key, :value)
                    ON DUPLICATE KEY UPDATE valore = VALUES(valore)');
                $update->execute([
                    ':key' => $configKey,
                    ':value' => $now->format('Y-m-d H:i:s'),
                ]);
                return;
            }

            try {
                $trackingService = new BrtTrackingService($config);
            } catch (\Throwable $exception) {
                error_log('BRT tracking: inizializzazione servizio fallita - ' . $exception->getMessage());
                brt_log_event('error', 'Scheduler tracking: inizializzazione servizio fallita', [
                    'error' => $exception->getMessage(),
                    'user' => 'scheduler',
                ]);
                return;
            }

            foreach ($shipments as $shipment) {
                $trackingId = (string) ($shipment['tracking_by_parcel_id'] ?? '');
                if ($trackingId === '') {
                    $trackingId = (string) ($shipment['parcel_id'] ?? '');
                }

                if ($trackingId === '') {
                    continue;
                }

                try {
                    $tracking = $trackingService->trackingByParcelId($trackingId);
                    brt_update_tracking((int) $shipment['id'], $tracking);
                } catch (BrtException $exception) {
                    error_log(sprintf('BRT tracking: aggiornamento parcelID %s fallito - %s', $trackingId, $exception->getMessage()));
                    brt_log_event('warning', 'Scheduler tracking: aggiornamento fallito', [
                        'tracking_id' => $trackingId,
                        'shipment_id' => $shipment['id'] ?? null,
                        'error' => $exception->getMessage(),
                        'user' => 'scheduler',
                    ]);
                } catch (\Throwable $exception) {
                    error_log(sprintf('BRT tracking: errore imprevisto per parcelID %s - %s', $trackingId, $exception->getMessage()));
                    brt_log_event('error', 'Scheduler tracking: errore inatteso', [
                        'tracking_id' => $trackingId,
                        'shipment_id' => $shipment['id'] ?? null,
                        'error' => $exception->getMessage(),
                        'user' => 'scheduler',
                    ]);
                }
            }

            $update = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE valore = VALUES(valore)');
            $update->execute([
                ':key' => $configKey,
                ':value' => (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $exception) {
            error_log('BRT tracking: esecuzione fallita - ' . $exception->getMessage());
            brt_log_event('error', 'Scheduler tracking: esecuzione fallita', [
                'error' => $exception->getMessage(),
                'user' => 'scheduler',
            ]);
        } finally {
            if ($lockAcquired) {
                try {
                    $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock)');
                    $releaseStmt->execute([':lock' => $lockName]);
                } catch (\Throwable $releaseException) {
                    error_log('BRT tracking: rilascio lock fallito - ' . $releaseException->getMessage());
                    brt_log_event('warning', 'Scheduler tracking: rilascio lock fallito', [
                        'error' => $releaseException->getMessage(),
                        'user' => 'scheduler',
                    ]);
                }
            }
        }
    }
}
