<?php
try {
    $client = new SoapClient('https://ec.europa.eu/taxation_customs/dds2/taric/services/goods?wsdl', [
        'trace' => true,
        'exceptions' => true,
    ]);
    var_dump($client->__getFunctions());
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
}
