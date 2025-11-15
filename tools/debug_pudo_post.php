<?php
require __DIR__ . '/../includes/helpers.php';

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtHttpClient;

$config = new BrtConfig();
$client = new BrtHttpClient($config->getRestBaseUrl(), [], $config->getCaBundlePath());

$payload = [
    'account' => [
        'userID' => $config->getAccountUserId(),
        'password' => $config->getAccountPassword(),
    ],
    'routingData' => [
        'zipCode' => '80053',
        'countryAbbreviationISOAlpha2' => 'IT',
        'network' => $config->getDefaultNetwork(),
    ],
];

$response = $client->request('POST', '/shipments/pudo', null, $payload);

var_dump($response);
