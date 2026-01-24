<?php
declare(strict_types=1);

if (!function_exists('maybe_sync_posta_telematica_pec')) {
    function maybe_sync_posta_telematica_pec(PDO $pdo): void
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

        if (!filter_var(env('PEC_SYNC_ENABLED', true), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        if (!function_exists('imap_open')) {
            error_log('Sync PEC: estensione IMAP non disponibile.');
            return;
        }

        $intervalMinutes = (int) env('PEC_SYNC_INTERVAL_MINUTES', 30);
        if ($intervalMinutes < 5) {
            $intervalMinutes = 5;
        } elseif ($intervalMinutes > 240) {
            $intervalMinutes = 240;
        }

        $limit = (int) env('PEC_SYNC_LIMIT', 50);
        if ($limit < 5) {
            $limit = 5;
        } elseif ($limit > 200) {
            $limit = 200;
        }

        $configKey = 'posta_telematica_pec_sync_last_run';
        $lockName = 'coresuite_posta_telematica_pec_sync_lock';
        $rootPath = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
        $logPath = $rootPath . '/backups/posta_telematica_pec_sync.log';

        try {
            $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => $configKey]);
            $lastRunValue = $stmt->fetchColumn();
            $lastRunTime = $lastRunValue ? new DateTimeImmutable((string) $lastRunValue) : null;
        } catch (Throwable $exception) {
            error_log('Sync PEC: lettura configurazione fallita - ' . $exception->getMessage());
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
            error_log('Sync PEC: ottenimento lock fallito - ' . $exception->getMessage());
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

            require_once __DIR__ . '/../modules/servizi/posta-telematica/functions.php';

            /** @var \IMAP\Connection $connection */
            $connection = posta_telematica_imap_connect();
            $uids = imap_search($connection, 'ALL', SE_UID) ?: [];
            rsort($uids);
            $uids = array_slice($uids, 0, $limit);

            $synced = 0;
            foreach ($uids as $uid) {
                $overview = imap_fetch_overview($connection, (string) (int) $uid, FT_UID);
                $row = $overview && isset($overview[0]) ? $overview[0] : null;
                if (!$row) {
                    continue;
                }

                $subject = isset($row->subject) ? imap_utf8((string) $row->subject) : '';
                $from = isset($row->from) ? imap_utf8((string) $row->from) : '';
                $date = isset($row->date) ? (string) $row->date : '';
                $seen = !empty($row->seen);
                $messageIdHeader = isset($row->message_id) ? (string) $row->message_id : null;

                $bodyRaw = imap_body($connection, (int) $uid, FT_UID | FT_PEEK);
                $bodyRaw = $bodyRaw !== false ? quoted_printable_decode((string) $bodyRaw) : '';
                $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($bodyRaw))) ?: '';
                if (mb_strlen($snippet) > 200) {
                    $snippet = mb_substr($snippet, 0, 200) . '…';
                }

                $receivedAt = null;
                if ($date !== '') {
                    $timestamp = strtotime($date);
                    if ($timestamp !== false) {
                        $receivedAt = date('Y-m-d H:i:s', $timestamp);
                    }
                }

                $messageId = posta_telematica_cache_message($pdo, [
                    'uid' => $uid,
                    'mailbox' => 'INBOX',
                    'message_id_header' => $messageIdHeader,
                    'from' => $from,
                    'subject' => $subject,
                    'received_at' => $receivedAt,
                    'seen' => $seen,
                    'snippet' => $snippet,
                    'body' => $bodyRaw,
                ]);

                if ($messageId > 0) {
                    $synced++;
                }

                $headers = imap_fetchheader($connection, (int) $uid, FT_UID);
                $receiptType = posta_telematica_detect_receipt_type($subject, $from, $bodyRaw);
                if ($receiptType) {
                    $originalMessageId = posta_telematica_extract_message_id_from_text((string) $headers . "\n" . $bodyRaw);
                    if ($originalMessageId) {
                        posta_telematica_update_receipt($pdo, $originalMessageId, $receiptType, $receivedAt, $bodyRaw, $messageId > 0 ? $messageId : null);
                    }
                }
            }

            imap_close($connection);

            $executedAt = new DateTimeImmutable('now');
            $update = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                ON DUPLICATE KEY UPDATE valore = VALUES(valore)');
            $update->execute([
                ':chiave' => $configKey,
                ':valore' => $executedAt->format('Y-m-d H:i:s'),
            ]);

            if ($synced > 0) {
                $line = sprintf('%s | sincronizzati=%d', $executedAt->format('Y-m-d H:i:s'), $synced);
                $logDir = dirname($logPath);
                if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
                    error_log('Sync PEC: impossibile creare la cartella log ' . $logDir);
                } else {
                    file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            }
        } catch (Throwable $exception) {
            error_log('Sync PEC: esecuzione fallita - ' . $exception->getMessage());
        } finally {
            try {
                $releaseStmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock)');
                $releaseStmt->execute([':lock' => $lockName]);
            } catch (Throwable $releaseException) {
                error_log('Sync PEC: rilascio lock fallito - ' . $releaseException->getMessage());
            }
        }
    }
}
