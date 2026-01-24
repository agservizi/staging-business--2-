<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$limit = isset($_GET['limit']) ? max(5, min(100, (int) $_GET['limit'])) : 30;

try {
    /** @var \IMAP\Connection $connection */
    $connection = posta_telematica_imap_connect();
    $uids = imap_search($connection, 'ALL', SE_UID) ?: [];
    rsort($uids);
    $uids = array_slice($uids, 0, $limit);

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

        $headers = imap_fetchheader($connection, (int) $uid, FT_UID);
        $receiptType = posta_telematica_detect_receipt_type($subject, $from, $bodyRaw);
        if ($receiptType) {
            $originalMessageId = posta_telematica_extract_message_id_from_text((string) $headers . "\n" . $bodyRaw);
            if ($originalMessageId) {
                posta_telematica_update_receipt($pdo, $originalMessageId, $receiptType, $receivedAt, $bodyRaw);
            }
        }

        if ($messageId <= 0) {
            continue;
        }
    }

    imap_close($connection);
    add_flash('success', 'Inbox PEC sincronizzata.');
} catch (Throwable $exception) {
    add_flash('danger', 'Sync PEC fallita: ' . $exception->getMessage());
}

header('Location: inbox.php');
exit;
