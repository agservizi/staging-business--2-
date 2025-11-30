<?php
declare(strict_types=1);

define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

use App\Services\Brt\BrtException;
use App\Services\Brt\BrtPudoService;
use Throwable;

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

$zip = trim((string)($_GET['zip'] ?? $_GET['zipCode'] ?? ''));
$city = trim((string)($_GET['city'] ?? ''));
$province = trim((string)($_GET['province'] ?? ''));
$country = trim((string)($_GET['country'] ?? ''));
$limit = trim((string)($_GET['limit'] ?? ''));
$latitude = trim((string)($_GET['latitude'] ?? $_GET['lat'] ?? ''));
$longitude = trim((string)($_GET['longitude'] ?? $_GET['lng'] ?? ''));

if ($zip === '' && $city === '' && ($latitude === '' || $longitude === '')) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Inserisci almeno CAP o città per cercare i PUDO.'
    ], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $service = new BrtPudoService();
    $criteria = [
        'zipCode' => $zip,
        'city' => $city,
        'province' => $province,
        'country' => $country,
        'limit' => $limit,
        'latitude' => $latitude,
        'longitude' => $longitude,
    ];

    $results = $service->search($criteria);

    echo json_encode([
        'success' => true,
        'pudos' => $results,
        'count' => count($results),
    ], JSON_THROW_ON_ERROR);
} catch (BrtException $exception) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
        'debug' => [
            'criteria' => array_filter($criteria, static fn ($value) => $value !== null && $value !== ''),
        ],
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore inatteso nella ricerca dei PUDO: ' . $exception->getMessage(),
        'debug' => [
            'criteria' => array_filter($criteria ?? [], static fn ($value) => $value !== null && $value !== ''),
        ],
    ], JSON_THROW_ON_ERROR);
}