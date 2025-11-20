<?php
use App\Services\AI\ThinkingAdvisor;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!ai_assistant_enabled()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Assistente AI non attivo.'], JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$question = trim((string) ($payload['question'] ?? ''));
if ($question === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Scrivi una domanda per ottenere suggerimenti.'], JSON_THROW_ON_ERROR);
    exit;
}

$advisorInput = [
    'question' => $question,
    'period' => $payload['period'] ?? 'last30',
    'focus' => $payload['focus'] ?? '',
    'history' => $payload['history'] ?? [],
    'customStart' => $payload['customStart'] ?? null,
    'customEnd' => $payload['customEnd'] ?? null,
];

try {
    $advisor = new ThinkingAdvisor($pdo);
    $result = $advisor->generate($advisorInput);

    $period = $result['period'];
    $response = [
        'ok' => true,
        'answer' => $result['answer'],
        'thinking' => $result['thinking'],
        'contextLines' => $result['contextLines'],
        'history' => $result['history'],
        'snapshot' => $result['snapshot'],
        'period' => [
            'label' => (string) ($period['label'] ?? ''),
            'start' => isset($period['start']) && $period['start'] instanceof DateTimeInterface ? $period['start']->format(DateTimeInterface::ATOM) : null,
            'end' => isset($period['end']) && $period['end'] instanceof DateTimeInterface ? $period['end']->format(DateTimeInterface::ATOM) : null,
            'days' => (int) ($period['days'] ?? 0),
            'key' => (string) ($period['key'] ?? ''),
        ],
        'generatedAt' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
    ];

    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('AI advisor API failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Non riesco a generare consigli in questo momento. Riprova tra poco.',
    ], JSON_THROW_ON_ERROR);
}
