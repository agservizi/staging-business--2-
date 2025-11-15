<?php
declare(strict_types=1);

define('CORESUITE_BRT_BOOTSTRAP', true);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../modules/servizi/brt/functions.php';

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtHttpClient;

$config = new BrtConfig();
$client = new BrtHttpClient($config->getRestBaseUrl(), [], $config->getCaBundlePath());

$path = $argv[1] ?? '/shipments/pudo';
$query = [];
parse_str($argv[2] ?? '', $query);

$result = $client->request('GET', $path, $query);
var_dump($result);
