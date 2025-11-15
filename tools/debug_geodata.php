<?php
require __DIR__ . '/../includes/helpers.php';

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtHttpClient;

$config = new BrtConfig();
$headers = [];
$apiKey = $config->getApiKey();
if ($apiKey) {
    $headers[] = 'X-Api-Key: ' . $apiKey;
}
$client = new BrtHttpClient($config->getOrmBaseUrl(), $headers, $config->getCaBundlePath());

$response = $client->request('GET', '/pudos', [
    'cap' => '80053',
    'provincia' => 'NA',
]);

var_dump($response);
