<?php
declare(strict_types=1);

define('CORESUITE_BRT_BOOTSTRAP', true);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../modules/servizi/brt/functions.php';

use App\Services\Brt\BrtPudoService;

$service = new BrtPudoService();

try {
    $results = $service->search([
        'zip' => '80053',
        'country' => 'IT',
    ]);
    var_dump($results);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Errore: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
