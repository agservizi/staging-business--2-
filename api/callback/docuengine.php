<?php
// Callback endpoint per DocuEngine
require_once '../../includes/db_connect.php';

header('Content-Type: application/json');

// Verifica che sia una richiesta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Ottieni il body della richiesta
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Log della richiesta per debug
error_log('DocuEngine callback: ' . $input);

// Verifica che ci sia un request_id
if (!isset($data['request_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing request_id']);
    exit;
}

$requestId = $data['request_id'];
$state = $data['state'] ?? 'unknown';
$results = $data['results'] ?? [];
$error = $data['error'] ?? null;

try {
    // Aggiorna la richiesta nel database
    $stmt = $pdo->prepare('
        UPDATE certificati_richieste
        SET stato = ?, documenti = ?, errore = ?, updated_at = NOW()
        WHERE request_id = ?
    ');

    $documentiJson = !empty($results) ? json_encode($results) : null;

    $stmt->execute([$state, $documentiJson, $error, $requestId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Request updated']);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found']);
    }

} catch (Exception $e) {
    error_log('Error updating request: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>