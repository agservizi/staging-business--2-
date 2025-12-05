<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

use App\Services\Opportunities\OpportunityUploadStorage;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !current_user_can('Collaboratore')) {
    http_response_code(403);
    echo json_encode(['error' => 'Non sei autorizzato a gestire gli allegati.']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

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
    if ($method === 'POST') {
        $enforceCsrf();
        if (!isset($_FILES['file'])) {
            http_response_code(422);
            echo json_encode(['error' => 'File mancante.'], JSON_THROW_ON_ERROR);
            return;
        }
        $upload = OpportunityUploadStorage::store($_FILES['file'], $userId);
        echo json_encode([
            'status' => 'stored',
            'upload' => $upload,
        ], JSON_THROW_ON_ERROR);
        return;
    }

    if ($method === 'GET') {
        $uploads = OpportunityUploadStorage::listTokens($userId);
        echo json_encode(['uploads' => $uploads], JSON_THROW_ON_ERROR);
        return;
    }

    if ($method === 'DELETE') {
        $enforceCsrf();
        $token = trim((string) ($_GET['token'] ?? ''));
        if ($token === '') {
            $rawBody = file_get_contents('php://input') ?: '';
            if ($rawBody !== '') {
                try {
                    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($payload['token'])) {
                        $token = trim((string) $payload['token']);
                    }
                } catch (JsonException) {
                    $token = '';
                }
            }
        }
        if ($token === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Token mancante.'], JSON_THROW_ON_ERROR);
            return;
        }
        $deleted = OpportunityUploadStorage::deleteToken($userId, $token);
        echo json_encode([
            'status' => $deleted ? 'deleted' : 'missing',
            'token' => $token,
        ], JSON_THROW_ON_ERROR);
        return;
    }

    header('Allow: GET, POST, DELETE');
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato JSON non valido.']);
} catch (Throwable $exception) {
    error_log('Opportunity upload API failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore durante la gestione degli allegati.']);
}
