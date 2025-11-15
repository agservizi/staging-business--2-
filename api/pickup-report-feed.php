<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!current_user_can('Admin', 'Operatore', 'Manager')) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato.']);
    return;
}

$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$limit = 10;

$response = [
    'events' => [],
    'lastId' => $sinceId,
];

try {
    $sql = <<<SQL
SELECT r.id,
       r.customer_id,
       r.tracking_code,
       r.courier_name,
       r.recipient_name,
       r.created_at,
       r.notes,
       c.name AS customer_name,
       c.email AS customer_email,
       c.phone AS customer_phone
FROM pickup_customer_reports r
LEFT JOIN pickup_customers c ON c.id = r.customer_id
WHERE r.id > :since_id
ORDER BY r.id ASC
LIMIT :limit
SQL;
    $statement = $pdo->prepare($sql);
    $statement->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
    $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
    $statement->execute();

    $events = [];
    $maxId = $sinceId;

    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        $eventId = (int) ($row['id'] ?? 0);
        if ($eventId <= 0) {
            continue;
        }
        if ($eventId > $maxId) {
            $maxId = $eventId;
        }

        $customerLabel = build_customer_label($row);
        $trackingLabel = build_tracking_label($row);

        $message = 'Portale pickup: ' . $customerLabel . ' ha segnalato ' . $trackingLabel . '.';

        $recipient = sanitize_notification_value($row['recipient_name'] ?? null);
        if ($recipient !== '') {
            $message .= ' Destinatario: ' . $recipient . '.';
        }

        $events[] = [
            'id' => $eventId,
            'message' => $message,
            'severity' => 'info',
            'url' => base_url('modules/servizi/logistici/report.php?id=' . $eventId),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'trackingCode' => sanitize_notification_value($row['tracking_code'] ?? null),
            'customerName' => $customerLabel,
        ];
    }

    $response['events'] = $events;
    $response['lastId'] = $maxId;

    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Pickup report feed failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Impossibile recuperare le segnalazioni del portale.'], JSON_THROW_ON_ERROR);
    return;
}

function sanitize_notification_value($value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $normalized = preg_replace('/\s+/u', ' ', $text);
    if (!is_string($normalized)) {
        return $text;
    }
    return trim($normalized);
}

function build_customer_label(array $row): string
{
    $candidates = [
        $row['customer_name'] ?? '',
        $row['customer_email'] ?? '',
        $row['customer_phone'] ?? '',
    ];

    foreach ($candidates as $candidate) {
        $label = sanitize_notification_value($candidate);
        if ($label !== '') {
            return $label;
        }
    }

    $customerId = (int) ($row['customer_id'] ?? 0);
    return $customerId > 0 ? 'Cliente #' . $customerId : 'Cliente portale';
}

function build_tracking_label(array $row): string
{
    $tracking = sanitize_notification_value($row['tracking_code'] ?? null);
    if ($tracking === '') {
        return 'un nuovo pacco';
    }

    $courier = sanitize_notification_value($row['courier_name'] ?? null);
    if ($courier !== '') {
        return 'il pacco #' . $tracking . ' (' . $courier . ')';
    }

    return 'il pacco #' . $tracking;
}
