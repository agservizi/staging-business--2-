<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';

use App\Services\Morosita\MorositaService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !current_user_can('Admin', 'Manager')) {
    http_response_code(403);
    echo json_encode(['error' => 'Non sei autorizzato ad eseguire la verifica.']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if ($method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.']);
    exit;
}

require_valid_csrf();

try {
    $rawBody = file_get_contents('php://input') ?: '';
    $payload = $rawBody !== '' ? json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR) : [];
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON non valido.']);
    exit;
}

$taxCode = isset($payload['tax_code']) ? strtoupper(trim((string) $payload['tax_code'])) : '';
if ($taxCode === '') {
    http_response_code(422);
    echo json_encode(['error' => 'Codice fiscale/partita IVA mancante.']);
    exit;
}

$requestedScore = isset($payload['score']) ? strtolower(trim((string) $payload['score'])) : null;
$allowedScores = ['ok', 'attenzione', 'bloccato'];
if ($requestedScore !== null && !in_array($requestedScore, $allowedScores, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Valore score non valido.']);
    exit;
}

$note = isset($payload['note']) ? trim((string) $payload['note']) : null;
$source = isset($payload['fonte']) ? trim((string) $payload['fonte']) : '';
$manualMetrics = null;
if (isset($payload['metrics']) && is_array($payload['metrics'])) {
    $manualMetrics = [
        'pending_count' => $payload['metrics']['pending_count'] ?? null,
        'pending_amount' => $payload['metrics']['pending_amount'] ?? null,
        'overdue_count' => $payload['metrics']['overdue_count'] ?? null,
        'overdue_amount' => $payload['metrics']['overdue_amount'] ?? null,
        'max_overdue_days' => $payload['metrics']['max_overdue_days'] ?? null,
    ];
}

try {
    $service = new MorositaService($pdo);
    $result = $service->evaluateAndPersistByTaxCode(
        $taxCode,
        $userId,
        $requestedScore !== null ? 'override-manuale' : ($source !== '' ? $source : 'verifica-manuale'),
        $requestedScore,
        $note,
        $manualMetrics
    );

    echo json_encode([
        'status' => 'ok',
        'customer_id' => $result['customer_id'],
        'score' => $result['score'],
        'flag' => $result['flag'],
        'fonte' => $result['fonte'],
        'note' => $result['note'],
        'metrics' => $result['metrics'],
        'updated_at' => date('c'),
    ]);
} catch (Throwable $exception) {
    error_log('Morosita check failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore durante la verifica morosita.']);
}
