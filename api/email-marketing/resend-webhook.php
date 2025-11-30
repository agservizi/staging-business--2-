<?php
declare(strict_types=1);

use App\Services\EmailMarketing\EventRecorder;
use PDO;
use Throwable;

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito']);
    exit;
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload non disponibile']);
    exit;
}

$config = get_email_marketing_config($pdo);
$secret = trim((string) ($config['webhook_secret'] ?? ''));
if ($secret === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Webhook non configurato']);
    exit;
}

$signatureHeader = $_SERVER['HTTP_RESEND_SIGNATURE'] ?? '';
if (!verify_resend_signature($signatureHeader, $rawBody, $secret)) {
    http_response_code(400);
    echo json_encode(['error' => 'Firma non valida']);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload non valido']);
    exit;
}

$eventType = (string) ($payload['type'] ?? '');
$eventData = $payload['data'] ?? [];
if (!is_array($eventData)) {
    $eventData = [];
}

$tags = $eventData['tags'] ?? [];
if (!is_array($tags)) {
    $tags = [];
}

$recipientId = isset($tags['recipient_id']) ? (int) $tags['recipient_id'] : 0;
$campaignId = isset($tags['campaign_id']) ? (int) $tags['campaign_id'] : 0;

$email = extract_event_email($eventData);
if ($recipientId <= 0 && $campaignId > 0 && $email !== '') {
    $recipientId = lookup_recipient_by_email($pdo, $campaignId, $email);
}

if ($recipientId <= 0 || $campaignId <= 0) {
    http_response_code(202);
    echo json_encode(['status' => 'ignored']);
    exit;
}

$occurredAt = (string) ($payload['created_at'] ?? ($eventData['timestamp'] ?? ''));
$context = build_event_context($eventType, $eventData, $email);

$recorder = new EventRecorder($pdo);

try {
    $recorder->apply($campaignId, $recipientId, $eventType, $context, $occurredAt !== '' ? $occurredAt : null);
} catch (Throwable $exception) {
    error_log('Resend webhook failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore interno']);
    exit;
}

http_response_code(200);
echo json_encode(['status' => 'ok']);

function verify_resend_signature(string $header, string $payload, string $secret): bool
{
    if ($header === '') {
        return false;
    }

    $parts = array_map('trim', explode(',', $header));
    $timestamp = null;
    $signature = null;

    foreach ($parts as $part) {
        if (strpos($part, '=') === false) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $part, 2));
        if ($key === 't') {
            $timestamp = $value;
        }
        if ($key === 'v1') {
            $signature = $value;
        }
    }

    if ($timestamp === null || $signature === null) {
        return false;
    }

    if (!ctype_digit($timestamp)) {
        return false;
    }

    $timestampInt = (int) $timestamp;
    if (abs(time() - $timestampInt) > 300) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);

    return hash_equals($expected, strtolower($signature));
}

/**
 * @param array<string, mixed> $eventData
 */
function extract_event_email(array $eventData): string
{
    $candidate = $eventData['email'] ?? null;
    if (is_string($candidate) && $candidate !== '') {
        return strtolower(trim($candidate));
    }

    $toField = $eventData['to'] ?? null;
    if (is_array($toField) && $toField !== []) {
        $first = reset($toField);
        if (is_string($first) && $first !== '') {
            return strtolower(trim($first));
        }
    }

    if (is_string($toField) && $toField !== '') {
        return strtolower(trim($toField));
    }

    return '';
}

function lookup_recipient_by_email(PDO $pdo, int $campaignId, string $email): int
{
    $stmt = $pdo->prepare('SELECT id FROM email_campaign_recipients WHERE campaign_id = :campaign_id AND email = :email LIMIT 1');
    $stmt->execute([
        ':campaign_id' => $campaignId,
        ':email' => $email,
    ]);

    return (int) $stmt->fetchColumn();
}

/**
 * @param array<string, mixed> $eventData
 * @return array<string, string>
 */
function build_event_context(string $eventType, array $eventData, string $email): array
{
    $context = [
        'provider_type' => $eventType,
    ];

    if ($email !== '') {
        $context['recipient'] = $email;
    }

    $ip = $eventData['ip'] ?? ($eventData['ip_address'] ?? null);
    if (is_string($ip) && $ip !== '') {
        $context['ip'] = $ip;
    }

    $userAgent = $eventData['user_agent'] ?? null;
    if (is_string($userAgent) && $userAgent !== '') {
        $context['user_agent'] = $userAgent;
    }

    $reason = $eventData['reason'] ?? ($eventData['diagnostic'] ?? null);
    if (is_array($eventData['bounce'] ?? null)) {
        $bounce = $eventData['bounce'];
        $reason = $bounce['detail'] ?? ($bounce['reason'] ?? $reason);
    }
    if (is_string($reason) && $reason !== '') {
        $context['reason'] = $reason;
    }

    $link = $eventData['link'] ?? ($eventData['url'] ?? null);
    if (is_string($link) && $link !== '') {
        $context['link'] = $link;
    }

    return $context;
}
