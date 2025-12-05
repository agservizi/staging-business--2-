<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';

use App\Services\Opportunities\OpportunityService;
use JsonException;
use RuntimeException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !current_user_can('Collaboratore')) {
    http_response_code(403);
    echo json_encode(['error' => 'Non sei autorizzato ad accedere alle bozze.']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$opportunityService = new OpportunityService($pdo);

$enforceCsrf = static function (): void {
    $originalMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($originalMethod === 'POST') {
        require_valid_csrf();
        return;
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    require_valid_csrf();
    $_SERVER['REQUEST_METHOD'] = $originalMethod;
};

try {
    if ($method === 'GET') {
        $draft = $opportunityService->getCollaboratorDraft($userId);
        echo json_encode([
            'draft' => $draft,
            'status' => $draft === null ? 'empty' : 'available',
        ], JSON_THROW_ON_ERROR);
        return;
    }

    if ($method === 'POST') {
        $enforceCsrf();
        $rawBody = file_get_contents('php://input') ?: '';
        $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        if (!isset($payload['data']) && isset($payload['payload'])) {
            $payload['data'] = $payload['payload'];
        }
        if (!is_array($payload['data'] ?? null)) {
            http_response_code(422);
            echo json_encode(['error' => 'Payload mancante.'], JSON_THROW_ON_ERROR);
            return;
        }

        $saved = $opportunityService->saveCollaboratorDraft($userId, $payload['data']);
        echo json_encode([
            'status' => 'saved',
            'draft' => $saved,
        ], JSON_THROW_ON_ERROR);
        return;
    }

    if ($method === 'DELETE') {
        $enforceCsrf();
        $opportunityService->deleteCollaboratorDraft($userId);
        echo json_encode(['status' => 'deleted'], JSON_THROW_ON_ERROR);
        return;
    }

    header('Allow: GET, POST, DELETE');
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato JSON non valido.']);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Opportunity draft API failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore inatteso nel salvataggio della bozza.']);
}
