<?php
require_once __DIR__ . '/../includes/env.php';
load_env(__DIR__ . '/../.env');
configure_timezone();

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Brt\BrtPudoService;
use App\Services\Brt\BrtException;

$zip = $argv[1] ?? '00100';
$city = $argv[2] ?? 'ROMA';
$province = $argv[3] ?? '';
$country = $argv[4] ?? 'IT';

$service = new BrtPudoService();

try {
    $results = $service->search([
        'zipCode' => $zip,
        'city' => $city,
        'province' => $province,
        'country' => $country,
    ]);

    echo "Trovati " . count($results) . " PUDO\n";
    foreach (array_slice($results, 0, 5) as $pudo) {
        $label = ($pudo['name'] ?? 'PUDO ' . $pudo['id']);
        $address = trim(($pudo['address'] ?? '') . ', ' . ($pudo['zipCode'] ?? '') . ' ' . ($pudo['city'] ?? ''));
        echo "- " . $pudo['id'] . ': ' . $label;
        if ($address !== '') {
            echo ' (' . $address . ')';
        }
        echo "\n";
    }
} catch (BrtException $exception) {
    fwrite(STDERR, 'Errore BRT: ' . $exception->getMessage() . "\n");
    exit(1);
}
