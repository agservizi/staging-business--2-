<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/db_connect.php';

use App\Services\Opportunities\OpportunityService;
use RuntimeException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!current_user_can('Admin') && !current_user_can('Manager')) {
    http_response_code(403);
    echo json_encode(['error' => 'Non autorizzato.']);
    exit;
}

try {
    $service = new OpportunityService($pdo);
    $row = $pdo->query(
        "SELECT o.id, o.code, o.created_at, o.status_code,
                u.nome AS collaborator_name, u.cognome AS collaborator_surname
         FROM opportunities o
         LEFT JOIN users u ON u.id = o.collaborator_id
         ORDER BY o.created_at DESC, o.id DESC
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($row === null) {
        echo json_encode(['status' => 'empty']);
        return;
    }

    echo json_encode([
        'status' => 'ok',
        'id' => (int) ($row['id'] ?? 0),
        'code' => (string) ($row['code'] ?? ''),
        'created_at' => $row['created_at'] ?? null,
        'status_code' => (string) ($row['status_code'] ?? ''),
        'collaborator_name' => trim(((string) ($row['collaborator_name'] ?? '')) . ' ' . ((string) ($row['collaborator_surname'] ?? ''))),
    ]);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Latest opportunity API failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore nel recupero opportunità.']);
}
