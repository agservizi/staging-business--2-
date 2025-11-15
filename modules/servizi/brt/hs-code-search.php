<?php
declare(strict_types=1);

define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';

$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

use App\Services\Customs\HsCodeLookupException;
use App\Services\Customs\HsCodeLookupService;
use Throwable;

use function mb_strlen;
use function trim;

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo non consentito.'
    ], JSON_THROW_ON_ERROR);
    exit;
}

$query = trim((string) ($_GET['q'] ?? $_GET['query'] ?? ''));
$limit = (int) ($_GET['limit'] ?? 10);

if (mb_strlen($query) < 3) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Inserisci almeno 3 caratteri per cercare un codice HS.',
    ], JSON_THROW_ON_ERROR);
    exit;
}

if ($limit <= 0) {
    $limit = 10;
} elseif ($limit > 25) {
    $limit = 25;
}

try {
    $service = new HsCodeLookupService();
    $results = $service->search($query, $limit);

    $responseResults = array_map(static function (array $item): array {
        return [
            'code' => $item['code'] ?? '',
            'description' => $item['description'] ?? '',
            'descriptions' => $item['descriptions'] ?? [],
            'breadcrumbs' => $item['breadcrumbs'] ?? [],
            'taric' => $item['taric'] ?? null,
        ];
    }, $results);

    echo json_encode([
        'success' => true,
        'query' => $query,
        'count' => count($responseResults),
        'results' => $responseResults,
    ], JSON_THROW_ON_ERROR);
} catch (HsCodeLookupException $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore inatteso durante la ricerca del codice HS: ' . $exception->getMessage(),
    ], JSON_THROW_ON_ERROR);
}
