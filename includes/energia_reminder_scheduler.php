<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/servizi/energia/functions.php';

function maybe_send_energia_reminders(PDO $pdo): void
{
    static $alreadyHandled = false;
    if ($alreadyHandled) {
        return;
    }
    $alreadyHandled = true;

    $enabledRaw = env('ENERGIA_REMINDERS_ENABLED', 'true');
    $enabled = is_string($enabledRaw)
        ? filter_var($enabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
        : (bool) $enabledRaw;
    if ($enabled === false) {
        return;
    }

    try {
        $existsStmt = $pdo->query("SHOW TABLES LIKE 'energia_contratti'");
        if (!$existsStmt || !$existsStmt->fetchColumn()) {
            return;
        }
    } catch (Throwable $exception) {
        error_log('Energia reminder scheduler skipped: ' . $exception->getMessage());
        return;
    }

    $hoursRaw = env('ENERGIA_REMINDER_HOURS', '24');
    $workingHours = (int) $hoursRaw;
    if ($workingHours <= 0) {
        $workingHours = 24;
    }

    $now = new DateTimeImmutable('now');

    try {
        $stmt = $pdo->query('SELECT id, email_sent_at
            FROM energia_contratti
            WHERE email_sent_at IS NOT NULL
              AND reminder_sent_at IS NULL
            ORDER BY email_sent_at ASC');
    } catch (Throwable $exception) {
        error_log('Energia reminder scheduler query failed: ' . $exception->getMessage());
        return;
    }

        $contracts = [];
        if ($stmt) {
            $contracts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }
    if (!$contracts) {
        return;
    }

    $batchLimitRaw = env('ENERGIA_REMINDER_BATCH_LIMIT', '5');
    $batchLimit = (int) $batchLimitRaw;
    if ($batchLimit <= 0) {
        $batchLimit = 5;
    }

    $processed = 0;

    foreach ($contracts as $row) {
        if ($processed >= $batchLimit) {
            break;
        }

        $emailSentRaw = $row['email_sent_at'] ?? null;
        if (!$emailSentRaw) {
            continue;
        }

        try {
            $emailSentAt = new DateTimeImmutable($emailSentRaw);
        } catch (Throwable) {
            continue;
        }

        $dueAt = energia_calculate_reminder_due_at($emailSentAt, $workingHours);
        if ($now < $dueAt) {
            continue;
        }

        $contractId = (int) ($row['id'] ?? 0);
        if ($contractId <= 0) {
            continue;
        }

        try {
            $contract = energia_fetch_contract($pdo, $contractId);
            if (!$contract) {
                continue;
            }

            $sent = energia_send_contract_mail($pdo, $contract, true, 'scheduler');
            if ($sent) {
                $processed++;
            }
        } catch (Throwable $exception) {
            error_log('Energia reminder dispatch failed for contratto #' . $contractId . ': ' . $exception->getMessage());
        }
    }
}
