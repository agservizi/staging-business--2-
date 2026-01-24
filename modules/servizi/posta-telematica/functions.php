<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/helpers.php';

/**
 * @return array{dir:string,relative:string,allowed_mimes:array<string,string>,max_size:int}
 */
function posta_telematica_upload_config(): array
{
    return [
        'dir' => rtrim(project_root_path(), '/') . '/uploads/posta-telematica',
        'relative' => 'uploads/posta-telematica',
        'allowed_mimes' => [
            'application/pdf' => 'PDF',
            'image/jpeg' => 'JPEG',
            'image/png' => 'PNG',
            'application/msword' => 'DOC',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'DOCX',
            'application/vnd.ms-excel' => 'XLS',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'XLSX',
            'text/plain' => 'TXT',
        ],
        'max_size' => 10 * 1024 * 1024,
    ];
}

/**
 * @param array<string,mixed> $files
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_normalize_files(array $files): array
{
    $normalized = [];

    if (!isset($files['name'])) {
        return $normalized;
    }

    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $normalized[] = [
                'name' => $files['name'][$i] ?? '',
                'type' => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$i] ?? 0,
            ];
        }
        return $normalized;
    }

    $normalized[] = [
        'name' => $files['name'] ?? '',
        'type' => $files['type'] ?? '',
        'tmp_name' => $files['tmp_name'] ?? '',
        'error' => $files['error'] ?? UPLOAD_ERR_NO_FILE,
        'size' => $files['size'] ?? 0,
    ];

    return $normalized;
}

/**
 * @param array<string,mixed> $files
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_store_attachments(array $files): array
{
    $config = posta_telematica_upload_config();
    $dir = $config['dir'];
    $relativeBase = $config['relative'];
    $allowed = $config['allowed_mimes'];
    $maxSize = $config['max_size'];

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Impossibile creare la cartella degli allegati.');
    }

    $normalized = posta_telematica_normalize_files($files);
    $stored = [];

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

    foreach ($normalized as $entry) {
        $error = (int) ($entry['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Errore durante il caricamento allegati.');
        }

        $size = (int) ($entry['size'] ?? 0);
        if ($size <= 0 || $size > $maxSize) {
            throw new RuntimeException('Dimensione allegato non valida.');
        }

        $tmpName = (string) ($entry['tmp_name'] ?? '');
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new RuntimeException('File temporaneo allegato non trovato.');
        }

        $originalName = sanitize_filename((string) ($entry['name'] ?? 'allegato'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        $mime = '';
        if ($finfo) {
            $detected = finfo_file($finfo, $tmpName);
            if (is_string($detected)) {
                $mime = $detected;
            }
        }
        if ($mime === '') {
            $mime = (string) ($entry['type'] ?? 'application/octet-stream');
        }

        if (!array_key_exists($mime, $allowed)) {
            throw new RuntimeException('Tipo di allegato non consentito: ' . $mime);
        }

        $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        if ($extension !== '') {
            $storedName .= '.' . $extension;
        }

        $absolutePath = rtrim($dir, '/') . '/' . $storedName;
        if (!move_uploaded_file($tmpName, $absolutePath)) {
            throw new RuntimeException('Impossibile salvare l\'allegato.');
        }

        $relativePath = rtrim($relativeBase, '/') . '/' . $storedName;
        $stored[] = [
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'relative_path' => $relativePath,
            'absolute_path' => $absolutePath,
            'mime' => $mime,
            'size' => $size,
        ];
    }

    if ($finfo) {
        finfo_close($finfo);
    }

    return $stored;
}

/**
 * @param array<string,mixed> $data
 */
function posta_telematica_create_message(PDO $pdo, array $data): int
{
    $stmt = $pdo->prepare('INSERT INTO posta_telematica_messages (channel, recipient_email, subject, body, status, error_message, cliente_id, created_by, message_id_header, created_at, updated_at)
        VALUES (:channel, :recipient_email, :subject, :body, :status, :error_message, :cliente_id, :created_by, :message_id_header, NOW(), NOW())');

    $stmt->execute([
        ':channel' => $data['channel'],
        ':recipient_email' => $data['recipient_email'],
        ':subject' => $data['subject'],
        ':body' => $data['body'],
        ':status' => $data['status'],
        ':error_message' => $data['error_message'],
        ':cliente_id' => $data['cliente_id'],
        ':created_by' => $data['created_by'],
        ':message_id_header' => $data['message_id_header'] ?? null,
    ]);

    return (int) $pdo->lastInsertId();
}

function posta_telematica_update_message_status(PDO $pdo, int $messageId, string $status, ?string $errorMessage = null): void
{
    $stmt = $pdo->prepare('UPDATE posta_telematica_messages SET status = :status, error_message = :error_message, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':error_message' => $errorMessage,
        ':id' => $messageId,
    ]);
}

/**
 * @param array<int,array<string,mixed>> $attachments
 */
function posta_telematica_insert_attachments(PDO $pdo, int $messageId, array $attachments): void
{
    if (!$attachments) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO posta_telematica_attachments (message_id, file_name, file_path, file_size, mime_type, created_at)
        VALUES (:message_id, :file_name, :file_path, :file_size, :mime_type, NOW())');

    foreach ($attachments as $attachment) {
        $stmt->execute([
            ':message_id' => $messageId,
            ':file_name' => $attachment['original_name'],
            ':file_path' => $attachment['relative_path'],
            ':file_size' => $attachment['size'],
            ':mime_type' => $attachment['mime'],
        ]);
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_list_messages(PDO $pdo, array $filters = []): array
{
    $where = [];
    $params = [];

    if (!empty($filters['channel'])) {
        $where[] = 'm.channel = :channel';
        $params[':channel'] = $filters['channel'];
    }
    if (!empty($filters['status'])) {
        $where[] = 'm.status = :status';
        $params[':status'] = $filters['status'];
    }
    if (!empty($filters['cliente_id'])) {
        $where[] = 'm.cliente_id = :cliente_id';
        $params[':cliente_id'] = (int) $filters['cliente_id'];
    }
    if (!empty($filters['search'])) {
        $where[] = '(m.recipient_email LIKE :search OR m.subject LIKE :search)';
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    $sql = 'SELECT m.*, u.username AS created_by_name, c.nome, c.cognome, c.ragione_sociale
        FROM posta_telematica_messages m
        LEFT JOIN users u ON m.created_by = u.id
        LEFT JOIN clienti c ON m.cliente_id = c.id';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY m.created_at DESC LIMIT 200';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function posta_telematica_get_message(PDO $pdo, int $messageId): ?array
{
    $stmt = $pdo->prepare('SELECT m.*, u.username AS created_by_name, c.nome, c.cognome, c.ragione_sociale
        FROM posta_telematica_messages m
        LEFT JOIN users u ON m.created_by = u.id
        LEFT JOIN clienti c ON m.cliente_id = c.id
        WHERE m.id = :id LIMIT 1');
    $stmt->execute([':id' => $messageId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_get_attachments(PDO $pdo, int $messageId): array
{
    $stmt = $pdo->prepare('SELECT * FROM posta_telematica_attachments WHERE message_id = :message_id ORDER BY id ASC');
    $stmt->execute([':message_id' => $messageId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function posta_telematica_get_attachment(PDO $pdo, int $attachmentId): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, m.channel, m.subject
        FROM posta_telematica_attachments a
        INNER JOIN posta_telematica_messages m ON m.id = a.message_id
        WHERE a.id = :id LIMIT 1');
    $stmt->execute([':id' => $attachmentId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function posta_telematica_build_cliente_label(array $row): string
{
    $ragione = trim((string) ($row['ragione_sociale'] ?? ''));
    $persona = trim((string) ($row['cognome'] ?? '') . ' ' . (string) ($row['nome'] ?? ''));
    if ($ragione !== '' && $persona !== '') {
        return $ragione . ' · ' . $persona;
    }
    return $ragione !== '' ? $ragione : ($persona !== '' ? $persona : '—');
}

function posta_telematica_generate_message_id(string $fromAddress): string
{
    $domain = 'localhost';
    if (str_contains($fromAddress, '@')) {
        $domain = trim(substr($fromAddress, strpos($fromAddress, '@') + 1));
    }
    $token = bin2hex(random_bytes(16));
    return sprintf('<%s@%s>', $token, $domain !== '' ? $domain : 'localhost');
}

function posta_telematica_normalize_message_id(?string $messageId): ?string
{
    if ($messageId === null) {
        return null;
    }
    $value = trim($messageId);
    if ($value === '') {
        return null;
    }
    $value = trim($value, '<>');
    return $value !== '' ? $value : null;
}

function posta_telematica_extract_message_id_from_text(string $text): ?string
{
    if (preg_match('/<([^>]+@[^>]+)>/i', $text, $matches)) {
        return posta_telematica_normalize_message_id($matches[1] ?? null);
    }

    if (preg_match('/message-id\s*[:=]\s*<?([^\s>]+@[^\s>]+)>?/i', $text, $matches)) {
        return posta_telematica_normalize_message_id($matches[1] ?? null);
    }

    if (preg_match('/references\s*[:=].*?<([^>]+@[^>]+)>/i', $text, $matches)) {
        return posta_telematica_normalize_message_id($matches[1] ?? null);
    }

    if (preg_match('/in-reply-to\s*[:=].*?<([^>]+@[^>]+)>/i', $text, $matches)) {
        return posta_telematica_normalize_message_id($matches[1] ?? null);
    }

    return null;
}

function posta_telematica_detect_receipt_type(string $subject, string $from, string $body): ?string
{
    $subjectUpper = mb_strtoupper($subject);

    if (str_contains($subjectUpper, 'ACCETTAZIONE')) {
        return 'accettazione';
    }

    if (str_contains($subjectUpper, 'CONSEGNA')) {
        return 'consegna';
    }

    if (str_contains($subjectUpper, 'POSTA CERTIFICATA') || str_contains($subjectUpper, 'RICEVUTA')) {
        return 'invio';
    }

    $fromUpper = mb_strtoupper($from);
    if (str_contains($fromUpper, 'POSTACERT') || str_contains($fromUpper, 'PEC')) {
        if (str_contains(mb_strtoupper($body), 'RICEVUTA')) {
            return 'invio';
        }
    }

    return null;
}

function posta_telematica_build_invio_receipt_body(array $message): string
{
    $lines = [];
    $lines[] = 'Ricevuta di invio PEC';
    $lines[] = 'Data invio: ' . format_datetime_locale($message['created_at'] ?? null);
    $lines[] = 'Destinatario: ' . ($message['recipient_email'] ?? '');
    $lines[] = 'Oggetto: ' . ($message['subject'] ?? '');
    if (!empty($message['message_id_header'])) {
        $lines[] = 'Message-ID: ' . $message['message_id_header'];
    }
    $lines[] = '';
    $lines[] = 'Messaggio:';
    $lines[] = (string) ($message['body'] ?? '');
    return implode("\n", $lines);
}

function posta_telematica_update_receipt(PDO $pdo, string $messageIdHeader, string $type, ?string $receivedAt = null, ?string $body = null): void
{
    $normalized = posta_telematica_normalize_message_id($messageIdHeader);
    if ($normalized === null) {
        return;
    }

    $column = null;
    $bodyColumn = null;
    if ($type === 'invio') {
        $column = 'pec_receipt_invio_at';
        $bodyColumn = 'pec_receipt_invio_body';
    } elseif ($type === 'accettazione') {
        $column = 'pec_receipt_accettazione_at';
        $bodyColumn = 'pec_receipt_accettazione_body';
    } elseif ($type === 'consegna') {
        $column = 'pec_receipt_consegna_at';
        $bodyColumn = 'pec_receipt_consegna_body';
    }

    if ($column === null) {
        return;
    }

    $sql = "UPDATE posta_telematica_messages SET {$column} = COALESCE({$column}, :received_at)";
    $params = [
        ':received_at' => $receivedAt,
        ':message_id_header' => $normalized,
    ];

    if ($bodyColumn !== null && $body !== null && trim($body) !== '') {
        $sql .= ", {$bodyColumn} = COALESCE({$bodyColumn}, :body)";
        $params[':body'] = $body;
    }

    $sql .= " , updated_at = NOW() WHERE channel = 'pec' AND message_id_header = :message_id_header LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function posta_telematica_render_mail_template(string $title, string $content): string
{
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f5f6f8; padding: 24px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden; border: 1px solid #e5e7eb;">
        <div style="background: #1f2937; color: #ffffff; padding: 16px 24px;">
            <h1 style="margin: 0; font-size: 18px; letter-spacing: 0.02em;">Comunicazione</h1>
        </div>
        <div style="padding: 24px; color: #111827; line-height: 1.6;">
            {$content}
        </div>
        <div style="padding: 14px 24px; font-size: 12px; color: #6b7280; background: #f3f4f6;">
            &copy; {$year}. Messaggio generato automaticamente.
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * @return \IMAP\Connection|resource
 */
function posta_telematica_imap_connect()
{
    if (!function_exists('imap_open')) {
        throw new RuntimeException('Estensione IMAP non disponibile sul server.');
    }

    $host = trim((string) env('PEC_IMAP_HOST', ''));
    $port = (int) env('PEC_IMAP_PORT', 993);
    $username = trim((string) env('PEC_IMAP_USERNAME', ''));
    $password = (string) env('PEC_IMAP_PASSWORD', '');
    $encryption = strtolower(trim((string) env('PEC_IMAP_ENCRYPTION', 'ssl')));
    $folder = trim((string) env('PEC_IMAP_FOLDER', 'INBOX'));

    if ($host === '' || $username === '' || $password === '') {
        throw new RuntimeException('Configurazione PEC IMAP incompleta.');
    }

    $flags = '/imap';
    if ($encryption === 'ssl') {
        $flags .= '/ssl';
    } elseif ($encryption === 'tls') {
        $flags .= '/tls';
    }

    $mailbox = sprintf('{%s:%d%s}%s', $host, $port, $flags, $folder !== '' ? $folder : 'INBOX');
    $connection = @imap_open($mailbox, $username, $password, 0, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
    if ($connection === false) {
        $errors = imap_errors();
        $message = $errors ? implode(' | ', $errors) : 'Connessione IMAP non riuscita.';
        throw new RuntimeException($message);
    }

    return $connection;
}

/**
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_fetch_inbox(int $limit = 30): array
{
    /** @var \IMAP\Connection $connection */
    $connection = posta_telematica_imap_connect();
    $messages = [];

    $uids = imap_search($connection, 'ALL', SE_UID) ?: [];
    rsort($uids);
    $uids = array_slice($uids, 0, max(1, $limit));

    foreach ($uids as $uid) {
        $overview = imap_fetch_overview($connection, (string) (int) $uid, FT_UID);
        $row = $overview && isset($overview[0]) ? $overview[0] : null;
        if (!$row) {
            continue;
        }

        $subject = isset($row->subject) ? imap_utf8((string) $row->subject) : '';
        $from = isset($row->from) ? imap_utf8((string) $row->from) : '';
        $date = isset($row->date) ? (string) $row->date : '';

        $messages[] = [
            'uid' => (int) $uid,
            'subject' => $subject,
            'from' => $from,
            'date' => $date,
            'seen' => !empty($row->seen),
        ];
    }

    imap_close($connection);
    return $messages;
}

/**
 * @return array<string,mixed>
 */
function posta_telematica_fetch_message(int $uid): array
{
    /** @var \IMAP\Connection $connection */
    $connection = posta_telematica_imap_connect();

    $overview = imap_fetch_overview($connection, (string) (int) $uid, FT_UID);
    $row = $overview && isset($overview[0]) ? $overview[0] : null;
    if (!$row) {
        imap_close($connection);
        throw new RuntimeException('Messaggio non trovato.');
    }

    $subject = isset($row->subject) ? imap_utf8((string) $row->subject) : '';
    $from = isset($row->from) ? imap_utf8((string) $row->from) : '';
    $date = isset($row->date) ? (string) $row->date : '';
    $seen = !empty($row->seen);

    $body = imap_body($connection, (int) $uid, FT_UID | FT_PEEK);
    $body = $body !== false ? quoted_printable_decode((string) $body) : '';

    imap_close($connection);

    return [
        'uid' => $uid,
        'subject' => $subject,
        'from' => $from,
        'date' => $date,
        'seen' => $seen,
        'body' => $body,
    ];
}

function posta_telematica_cache_message(PDO $pdo, array $message): int
{
    $stmt = $pdo->prepare('INSERT INTO posta_telematica_pec_messages (uid, mailbox, message_id_header, sender, subject, received_at, seen, snippet, body, created_at, updated_at)
        VALUES (:uid, :mailbox, :message_id_header, :sender, :subject, :received_at, :seen, :snippet, :body, NOW(), NOW())
        ON DUPLICATE KEY UPDATE message_id_header = VALUES(message_id_header), sender = VALUES(sender), subject = VALUES(subject), received_at = VALUES(received_at), seen = VALUES(seen), snippet = VALUES(snippet), body = VALUES(body), updated_at = NOW()');

    $stmt->execute([
        ':uid' => (int) ($message['uid'] ?? 0),
        ':mailbox' => $message['mailbox'] ?? 'INBOX',
        ':message_id_header' => $message['message_id_header'] ?? null,
        ':sender' => $message['from'] ?? null,
        ':subject' => $message['subject'] ?? null,
        ':received_at' => $message['received_at'] ?? null,
        ':seen' => !empty($message['seen']) ? 1 : 0,
        ':snippet' => $message['snippet'] ?? null,
        ':body' => $message['body'] ?? null,
    ]);

    $id = (int) $pdo->lastInsertId();
    if ($id > 0) {
        return $id;
    }

    $fetch = $pdo->prepare('SELECT id FROM posta_telematica_pec_messages WHERE uid = :uid AND mailbox = :mailbox LIMIT 1');
    $fetch->execute([
        ':uid' => (int) ($message['uid'] ?? 0),
        ':mailbox' => $message['mailbox'] ?? 'INBOX',
    ]);

    return (int) ($fetch->fetchColumn() ?: 0);
}

function posta_telematica_cache_attachments(PDO $pdo, int $messageId, array $attachments): void
{
    if (!$attachments || $messageId <= 0) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO posta_telematica_pec_attachments (message_id, file_name, file_path, file_size, mime_type, created_at)
        VALUES (:message_id, :file_name, :file_path, :file_size, :mime_type, NOW())');

    foreach ($attachments as $attachment) {
        $stmt->execute([
            ':message_id' => $messageId,
            ':file_name' => $attachment['file_name'],
            ':file_path' => $attachment['file_path'],
            ':file_size' => $attachment['file_size'],
            ':mime_type' => $attachment['mime_type'],
        ]);
    }
}

/**
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_list_cached_messages(PDO $pdo, int $limit = 50, ?string $search = null): array
{
    $limit = max(1, min(200, $limit));
    $params = [];
    $where = '';

    if ($search !== null && trim($search) !== '') {
        $where = 'WHERE sender LIKE :search OR subject LIKE :search';
        $params[':search'] = '%' . trim($search) . '%';
    }

    $sql = 'SELECT * FROM posta_telematica_pec_messages ' . $where . ' ORDER BY received_at DESC, id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function posta_telematica_get_cached_message(PDO $pdo, int $messageId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM posta_telematica_pec_messages WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $messageId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_get_cached_attachments(PDO $pdo, int $messageId): array
{
    $stmt = $pdo->prepare('SELECT * FROM posta_telematica_pec_attachments WHERE message_id = :message_id ORDER BY id ASC');
    $stmt->execute([':message_id' => $messageId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<int,array<string,mixed>>
 */
function posta_telematica_find_receipts(PDO $pdo, string $messageIdHeader, int $limit = 10): array
{
    $normalized = posta_telematica_normalize_message_id($messageIdHeader);
    if ($normalized === null) {
        return [];
    }

    $needle = '%' . $normalized . '%';
    $limit = max(1, min(50, $limit));

    $stmt = $pdo->prepare('SELECT * FROM posta_telematica_pec_messages
        WHERE (subject LIKE :needle_subject OR body LIKE :needle_body)
        ORDER BY received_at DESC, id DESC
        LIMIT ' . $limit);
    $stmt->execute([
        ':needle_subject' => $needle,
        ':needle_body' => $needle,
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
