<?php
declare(strict_types=1);

use App\Services\Automata\AutomataService;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user_can('Admin', 'Operatore', 'Manager')) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato.']);
    exit;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$service = new AutomataService();

try {
    switch ($action) {
        case 'health':
            echo json_encode([
                'enabled' => $service->isEnabled(),
                'base_url' => env('AUTOMATA_BASE_URL', 'https://automa.coresuite.it'),
            ]);
            break;

        case 'caf_checklist':
            $type = trim((string) ($_GET['tipo'] ?? $_POST['tipo'] ?? 'CAF'));
            $servizio = trim((string) ($_GET['servizio'] ?? $_POST['servizio'] ?? ''));
            $docs = $_POST['documenti'] ?? [];
            if (!is_array($docs)) {
                $docs = [];
            }
            echo json_encode(['items' => $service->suggestCafDocumentChecklist($type, $servizio, $docs)]);
            break;

        case 'hs_suggest':
            $description = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
            $country = trim((string) ($_GET['country'] ?? $_POST['country'] ?? 'IT'));
            echo json_encode(['items' => $service->suggestHsCodes($description, $country)]);
            break;

        case 'completion_estimate':
            $samples = $_POST['samples'] ?? [];
            if (!is_array($samples)) {
                $samples = [];
            }
            $status = trim((string) ($_POST['stato'] ?? ''));
            $type = trim((string) ($_POST['tipo'] ?? 'CAF'));
            echo json_encode($service->estimatePracticeCompletion(array_map('intval', $samples), $status, $type));
            break;

        case 'assist':
            require_valid_csrf();
            $task = trim((string) ($_POST['task'] ?? ''));
            $context = $_POST['context'] ?? [];
            if (!is_array($context)) {
                $context = [];
            }
            if ($task === '') {
                throw new RuntimeException('Task mancante.');
            }
            echo json_encode(['result' => $service->assist($task, $context)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Azione Automata non valida.']);
    }
} catch (Throwable $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
}
