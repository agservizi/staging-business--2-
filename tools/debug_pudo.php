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
$client = new BrtHttpClient($config->getRestBaseUrl(), $headers, $config->getCaBundlePath());

$payload = [
    'account' => [
        'userID' => $config->getAccountUserId(),
        'password' => $config->getAccountPassword(),
    ],
    'routingData' => [
        'zipCode' => '80053',
        'countryAbbreviationISOAlpha2' => 'IT',
        'senderCustomerCode' => $config->getSenderCustomerCode(),
        'departureDepot' => $config->getDepartureDepot(),
    ],
];

$response = $client->request('POST', '/shipments/pudo', null, $payload);

var_dump([
    'status' => $response['status'],
    'headers' => $response['headers'],
    'body' => $response['body'],
    'raw' => $response['raw'],
]);
