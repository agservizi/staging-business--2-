<?php
use App\Services\AI\CustomAdvisor;
use InvalidArgumentException;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

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

$conversationId = (int) ($payload['conversation_id'] ?? 0);
$rating = (int) ($payload['rating'] ?? 0);

if ($conversationId <= 0 || $rating < 1 || $rating > 5) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'ID conversazione e rating (1-5) richiesti.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $advisor = new CustomAdvisor($pdo);
    $success = $advisor->giveFeedback($conversationId, $rating);

    if ($success) {
        echo json_encode(['ok' => true, 'message' => 'Feedback registrato. Grazie per il tuo input!'], JSON_THROW_ON_ERROR);
    } else {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Conversazione non trovata.'], JSON_THROW_ON_ERROR);
    }
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('AI feedback API failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Errore nel registrare il feedback.',
    ], JSON_THROW_ON_ERROR);
}
?>