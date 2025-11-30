<?php
declare(strict_types=1);


const ENERGIA_MODULE_LOG = 'Servizi/Energia';
const ENERGIA_MAX_UPLOAD_SIZE = 15_728_640; // 15 MB per allegato

function energia_notification_recipient(): string
{
    $recipient = env('ENERGIA_NOTIFICATION_EMAIL', 'energia@newprojectmobile.it');
    $trimmed = is_string($recipient) ? trim($recipient) : '';
    return $trimmed !== '' ? $trimmed : 'energia@newprojectmobile.it';
}

function energia_fetch_contracts(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT ec.*, u.username AS created_by_username,
        (SELECT COUNT(*) FROM energia_contratti_allegati ea WHERE ea.contratto_id = ec.id) AS attachments_count,
        (SELECT COUNT(*) FROM energia_contratti_allegati ea WHERE ea.contratto_id = ec.id AND ea.file_path LIKE \'%_extra_%\') AS extra_attachments_count
        FROM energia_contratti ec
        LEFT JOIN users u ON ec.created_by = u.id
        ORDER BY ec.created_at DESC');

    return $stmt->fetchAll() ?: [];
}

function energia_fetch_contract(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ec.*, u.username AS created_by_username
        FROM energia_contratti ec
        LEFT JOIN users u ON ec.created_by = u.id
        WHERE ec.id = :id');
    $stmt->execute([':id' => $id]);

    $contract = $stmt->fetch();
    if (!$contract) {
        return null;
    }

    $attachmentsStmt = $pdo->prepare('SELECT id, file_name, file_path, mime_type, file_size, created_at,
        CASE WHEN file_path LIKE \'%_extra_%\' THEN 1 ELSE 0 END AS is_extra
        FROM energia_contratti_allegati
        WHERE contratto_id = :id
        ORDER BY id');
    $attachmentsStmt->execute([':id' => $id]);
    $contract['attachments'] = $attachmentsStmt->fetchAll() ?: [];

    try {
        $historyStmt = $pdo->prepare('SELECT h.id, h.event_type, h.send_channel, h.recipient, h.subject, h.status,
                h.error_message, h.sent_at, h.created_at, h.sent_by, u.username AS sent_by_username
            FROM energia_email_history h
            LEFT JOIN users u ON h.sent_by = u.id
            WHERE h.contratto_id = :id
            ORDER BY h.sent_at DESC, h.id DESC');
        $historyStmt->execute([':id' => $id]);
        $contract['email_history'] = $historyStmt->fetchAll() ?: [];
    } catch (Throwable $exception) {
        error_log('Energia email history fetch failed: ' . $exception->getMessage());
        $contract['email_history'] = [];
    }

    return $contract;
}

function energia_send_contract_mail(PDO $pdo, array $contract, bool $isReminder = false, string $channel = 'manual'): bool
{
    $contractId = (int) ($contract['id'] ?? 0);
    if ($contractId <= 0) {
        return false;
    }

    if (!isset($contract['attachments'])) {
        $reloaded = energia_fetch_contract($pdo, $contractId);
        if ($reloaded === null) {
            return false;
        }
        $contract = $reloaded;
    }

    $recipient = energia_notification_recipient();
    $nominativo = (string) ($contract['nominativo'] ?? 'N/D');
    $fornitura = (string) ($contract['fornitura'] ?? '');
    $operazione = (string) ($contract['operazione'] ?? '');
    $contractCode = trim((string) ($contract['contract_code'] ?? ''));
    if ($contractCode === '') {
        $contractCode = energia_build_contract_code($contractId, (string) ($contract['created_at'] ?? ''));
    }

    $subjectBase = 'Contratto energia ' . $contractCode . ' - ' . $nominativo;
    if ($fornitura !== '') {
        $subjectBase .= ' (' . $fornitura . ')';
    }

    $subject = $isReminder ? 'Reminder - ' . $subjectBase : $subjectBase;
    $eventType = $isReminder ? 'reminder' : 'initial';
    $channel = trim($channel) !== '' ? trim($channel) : 'manual';

    $detailsRows = [
        'Codice contratto' => $contractCode,
        'Nominativo' => $nominativo,
        'Codice fiscale' => (string) ($contract['codice_fiscale'] ?? 'N/D'),
        'Email referente' => (string) ($contract['email'] ?? 'N/D'),
        'Telefono' => (string) ($contract['telefono'] ?? 'N/D'),
        'Fornitura' => $fornitura !== '' ? $fornitura : 'N/D',
        'Operazione richiesta' => $operazione !== '' ? $operazione : 'N/D',
        'Note' => (string) ($contract['note'] ?? 'Nessuna nota'),
        'Creato il' => format_datetime_locale((string) ($contract['created_at'] ?? '')) ?: 'N/D',
        'Creato da' => (string) ($contract['created_by_username'] ?? 'Sistema'),
    ];

    $rowsHtml = '';
    foreach ($detailsRows as $label => $value) {
        $rowsHtml .= '<tr><th align="left" style="padding:6px 12px;background:#f8f9fc;width:180px;">' . energia_escape($label) . '</th>';
        $rowsHtml .= '<td style="padding:6px 12px;">' . energia_escape($value) . '</td></tr>';
    }

    $attachmentsHtml = '<p style="margin:16px 0 8px;">Allegati caricati:</p>';
    if (!empty($contract['attachments'])) {
        $attachmentsHtml .= '<ul style="padding-left:20px;margin:0;">';
        foreach ($contract['attachments'] as $attachment) {
            $url = base_url((string) $attachment['file_path']);
            $size = energia_format_bytes((int) $attachment['file_size']);
            $attachmentsHtml .= '<li style="margin-bottom:4px;"><a href="' . energia_escape($url) . '" style="color:#12468f;text-decoration:none;">' . energia_escape((string) $attachment['file_name']) . '</a>'; 
            $attachmentsHtml .= ' <span style="color:#6c757d;">(' . energia_escape($size) . ')</span></li>';
        }
        $attachmentsHtml .= '</ul>';
    } else {
        $attachmentsHtml .= '<p style="margin:0;color:#6c757d;">Nessun allegato fornito.</p>';
    }

    $intro = $isReminder
        ? '<p style="margin:0 0 12px;">Promemoria: è presente una richiesta di gestione contratto energia da prendere in carico.</p>'
        : '<p style="margin:0 0 12px;">È stato caricato un nuovo contratto energia da gestire.</p>';

    $content = $intro . '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">' . $rowsHtml . '</table>' . $attachmentsHtml;

    $htmlBody = render_mail_template('Contratto energia', $content);
    $sent = send_system_mail($recipient, $subject, $htmlBody);

    if (!$sent) {
        energia_record_email_history($pdo, $contractId, $eventType, 'failed', $subject, $recipient, $channel, 'Invio email non riuscito');
        return false;
    }

    $assignedCode = energia_assign_contract_code($pdo, $contract);
    if ($assignedCode !== null) {
        $contract['contract_code'] = $assignedCode;
    }

    if ($isReminder) {
        $stmt = $pdo->prepare('UPDATE energia_contratti
            SET reminder_sent_at = NOW(), last_reminder_subject = :subject, stato = :stato, updated_at = NOW()
            WHERE id = :id');
        $stmt->execute([
            ':subject' => $subject,
            ':stato' => 'Reminder inviato',
            ':id' => $contractId,
        ]);
        energia_log_action($pdo, 'Reminder inviato', 'Reminder email per contratto #' . $contractId);
    } else {
        $stmt = $pdo->prepare('UPDATE energia_contratti
            SET email_sent_at = NOW(), stato = :stato, last_reminder_subject = NULL, updated_at = NOW()
            WHERE id = :id');
        $stmt->execute([
            ':stato' => 'Inviato',
            ':id' => $contractId,
        ]);
        energia_log_action($pdo, 'Email inviata', 'Email inviata per contratto #' . $contractId);
    }

    energia_record_email_history($pdo, $contractId, $eventType, 'sent', $subject, $recipient, $channel, null);

    return true;
}

function energia_record_email_history(PDO $pdo, int $contractId, string $eventType, string $status, string $subject, string $recipient, string $channel, ?string $errorMessage = null): void
{
    try {
        $sentBy = null;
        if (isset($_SESSION['user_id'])) {
            $candidate = (int) $_SESSION['user_id'];
            if ($candidate > 0) {
                $sentBy = $candidate;
            }
        }

        $stmt = $pdo->prepare('INSERT INTO energia_email_history
            (contratto_id, event_type, send_channel, recipient, subject, status, error_message, sent_by, sent_at, created_at)
            VALUES (:contratto_id, :event_type, :send_channel, :recipient, :subject, :status, :error_message, :sent_by, NOW(), NOW())');
        $stmt->execute([
            ':contratto_id' => $contractId,
            ':event_type' => $eventType,
            ':send_channel' => $channel,
            ':recipient' => $recipient,
            ':subject' => $subject,
            ':status' => $status,
            ':error_message' => $errorMessage,
            ':sent_by' => $sentBy,
        ]);
    } catch (Throwable $exception) {
        error_log('Energia email history log failed: ' . $exception->getMessage());
    }
}

function energia_log_action(PDO $pdo, string $action, string $details): void
{
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $stmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $stmt->execute([
            ':user_id' => $userId ?: null,
            ':modulo' => ENERGIA_MODULE_LOG,
            ':azione' => $action,
            ':dettagli' => $details,
        ]);
    } catch (Throwable) {
        // Ignora errori di logging per non interrompere il flusso principale.
    }
}

function energia_escape(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function energia_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $power);

    return sprintf('%s %s', number_format($value, $power === 0 ? 0 : 2, ',', '.'), $units[$power]);
}

function energia_allowed_mime_types(): array
{
    return [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
    ];
}

function energia_normalize_uploads(?array $files): array
{
    if (!$files || !is_array($files)) {
        return [];
    }

    $normalized = [];
    if (is_array($files['name'] ?? null)) {
        foreach ($files['name'] as $index => $name) {
            $error = $files['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $normalized[] = [
                'name' => (string) $name,
                'type' => (string) ($files['type'][$index] ?? ''),
                'tmp_name' => (string) ($files['tmp_name'][$index] ?? ''),
                'error' => (int) $error,
                'size' => (int) ($files['size'][$index] ?? 0),
            ];
        }

        return $normalized;
    }

    $error = (int) ($files['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return [];
    }

    $normalized[] = [
        'name' => (string) ($files['name'] ?? ''),
        'type' => (string) ($files['type'] ?? ''),
        'tmp_name' => (string) ($files['tmp_name'] ?? ''),
        'error' => $error,
        'size' => (int) ($files['size'] ?? 0),
    ];

    return $normalized;
}

function energia_detect_mime(string $filePath): string
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($filePath);
            return $detected ?: 'application/octet-stream';
        }
        return 'application/octet-stream';
    }

    $mime = finfo_file($finfo, $filePath) ?: 'application/octet-stream';
    finfo_close($finfo);

    return $mime;
}

function energia_calculate_reminder_due_at(DateTimeImmutable $sentAt, int $workingHours): DateTimeImmutable
{
    $hours = max(0, $workingHours);
    $current = $sentAt;
    if ($hours === 0) {
        return $current;
    }

    while ($hours > 0) {
        $current = $current->modify('+1 hour');
        if ((int) $current->format('N') >= 6) {
            continue;
        }

        $hours--;
    }

    return $current;
}

function energia_build_contract_code(int $contractId, ?string $createdAt): string
{
    if ($contractId <= 0) {
        return 'ENE-' . date('Y') . '-00000';
    }

    $year = date('Y');
    if ($createdAt) {
        try {
            $date = new DateTimeImmutable($createdAt);
            $year = $date->format('Y');
        } catch (Throwable) {
            $year = date('Y');
        }
    }

    return sprintf('ENE-%s-%05d', $year, $contractId);
}

function energia_assign_contract_code(PDO $pdo, array $contract): ?string
{
    $existing = trim((string) ($contract['contract_code'] ?? ''));
    if ($existing !== '') {
        return $existing;
    }

    $contractId = (int) ($contract['id'] ?? 0);
    if ($contractId <= 0) {
        return null;
    }

    $code = energia_build_contract_code($contractId, (string) ($contract['created_at'] ?? ''));

    try {
        $stmt = $pdo->prepare('UPDATE energia_contratti
            SET contract_code = :code
            WHERE id = :id AND (contract_code IS NULL OR contract_code = \'\')');
        $stmt->execute([
            ':code' => $code,
            ':id' => $contractId,
        ]);
    } catch (Throwable $exception) {
        error_log('Energia contract code assignment failed: ' . $exception->getMessage());
        return null;
    }

    return $code;
}
