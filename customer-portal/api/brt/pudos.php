<?php
declare(strict_types=1);

use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../includes/brt_service.php';

$customer = require_authentication();
$service = new PickupBrtService();

try {
    require_method('GET');

    $zip = isset($_GET['zip']) ? trim((string) $_GET['zip']) : '';
    $city = isset($_GET['city']) ? trim((string) $_GET['city']) : '';

    if ($zip === '' || $city === '') {
        throw new RuntimeException('Specifica CAP e città per cercare un punto di ritiro.');
    }

    $criteria = [
        'zip' => $zip,
        'city' => $city,
    ];

    $province = isset($_GET['province']) ? trim((string) $_GET['province']) : '';
    if ($province !== '') {
        $criteria['province'] = $province;
    }

    $country = isset($_GET['country']) ? trim((string) $_GET['country']) : '';
    if ($country !== '') {
        $criteria['country'] = $country;
    }

    if (isset($_GET['distance']) && $_GET['distance'] !== '') {
        $criteria['distance'] = trim((string) $_GET['distance']);
    } elseif (isset($_GET['max_distance']) && $_GET['max_distance'] !== '') {
        $criteria['distance'] = trim((string) $_GET['max_distance']);
    } elseif (isset($_GET['maxDistance']) && $_GET['maxDistance'] !== '') {
        $criteria['distance'] = trim((string) $_GET['maxDistance']);
    }

    if (isset($_GET['limit']) && $_GET['limit'] !== '') {
        $criteria['limit'] = (int) $_GET['limit'];
    }

    $results = $service->searchPudos($criteria);
    $count = count($results);
    $message = $count === 0
        ? 'Nessun punto di ritiro trovato per i criteri indicati.'
        : sprintf('Trovati %d punti di ritiro BRT.', $count);

    api_success([
        'pudos' => $results,
        'count' => $count,
    ], $message);
} catch (RuntimeException $exception) {
    api_error($exception->getMessage(), 400);
} catch (Throwable $exception) {
    portal_error_log('BRT PUDO API error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'] ?? null,
    ]);
    api_error('Impossibile completare la ricerca dei punti di ritiro al momento.', 500);
}
