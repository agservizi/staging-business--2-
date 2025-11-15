<?php
require __DIR__ . '/../includes/helpers.php';

use App\Services\Brt\BrtHttpClient;

$client = new BrtHttpClient('https://api.brt.it/fermopoint/v1', [], __DIR__ . '/../certs/cacert.pem');

$response = $client->request('GET', '/pudos', [
    'zipCode' => '80053',
    'country' => 'IT',
]);

var_dump($response);
