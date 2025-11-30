<?php
require __DIR__ . '/../includes/helpers.php';

use App\Services\Brt\BrtHttpClient;

$client = new BrtHttpClient('https://api.brt.it/rest/v2', [], __DIR__ . '/../certs/cacert.pem');

$response = $client->request('GET', '/shipments/pudo', [
    'zipCode' => '80053',
    'country' => 'IT',
]);

var_dump($response);
