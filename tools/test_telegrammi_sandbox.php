<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

use App\Services\ServiziWeb\UfficioPostaleClient;

$cliOptions = getopt('', ['base::', 'autoconfirm', 'addresses::', 'list', 'get:']);
$baseOverride = isset($cliOptions['base']) && is_string($cliOptions['base']) ? trim($cliOptions['base']) : null;
$autoConfirm = array_key_exists('autoconfirm', $cliOptions);
$addressesPath = isset($cliOptions['addresses']) && is_string($cliOptions['addresses']) ? trim($cliOptions['addresses']) : null;
$listMode = array_key_exists('list', $cliOptions);
$getId = isset($cliOptions['get']) && is_string($cliOptions['get']) ? trim($cliOptions['get']) : null;

$clientOptions = [];
$configuredCa = env('UFFICIO_POSTALE_CA_BUNDLE');
if (is_string($configuredCa) && $configuredCa !== '') {
    $clientOptions['ca_bundle'] = $configuredCa;
} else {
    $defaultCa = realpath(__DIR__ . '/../certs/cacert.pem');
    if ($defaultCa !== false) {
        $clientOptions['ca_bundle'] = $defaultCa;
    }
}

try {
    $client = $baseOverride !== null && $baseOverride !== ''
        ? new UfficioPostaleClient(null, $baseOverride, $clientOptions)
        : new UfficioPostaleClient(null, null, $clientOptions);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Token mancante o configurazione non valida: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

if ($listMode || ($getId !== null && $getId !== '')) {
    try {
        if ($getId !== null && $getId !== '') {
            $response = $client->getTelegram($getId);
            fwrite(STDOUT, 'Dettaglio telegramma ' . $getId . ':' . PHP_EOL);
        } else {
            $response = $client->listTelegram();
            fwrite(STDOUT, 'Elenco telegrammi:' . PHP_EOL);
        }

        fwrite(STDOUT, json_encode($response['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Errore durante il recupero: ' . $exception->getMessage() . PHP_EOL);
        if (method_exists($exception, 'getCode')) {
            fwrite(STDERR, 'Codice errore: ' . $exception->getCode() . PHP_EOL);
        }
        exit(1);
    }
}

$defaultMittente = [
    'ragione_sociale' => 'Coresuite Demo SRL',
    'dug' => 'VIA',
    'indirizzo' => 'di Test',
    'civico' => '123',
    'cap' => '00147',
    'comune' => 'Roma',
    'provincia' => 'RM',
    'nazione' => 'IT',
    'email' => 'noreply@example.com',
];

$defaultDestinatari = [[
    'nome' => 'Mario',
    'cognome' => 'Rossi',
    'dug' => 'PIAZZA',
    'indirizzo' => 'Fittizia',
    'civico' => '1',
    'cap' => '20121',
    'comune' => 'Milano',
    'provincia' => 'MI',
    'nazione' => 'IT',
    'email' => 'sandbox@example.com',
]];

$mittente = $defaultMittente;
$destinatari = $defaultDestinatari;

if ($addressesPath !== null && $addressesPath !== '') {
    if (!file_exists($addressesPath)) {
        fwrite(STDERR, 'File indirizzi non trovato: ' . $addressesPath . PHP_EOL);
        exit(1);
    }

    $decoded = json_decode((string) file_get_contents($addressesPath), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, 'Formato JSON non valido nel file indirizzi.' . PHP_EOL);
        exit(1);
    }

    if (isset($decoded['mittente']) && is_array($decoded['mittente'])) {
        $mittente = array_replace($mittente, $decoded['mittente']);
    }

    if (isset($decoded['destinatari']) && is_array($decoded['destinatari']) && $decoded['destinatari'] !== []) {
        $destinatari = $decoded['destinatari'];
    }
}

$now = new DateTimeImmutable('now', new DateTimeZone(env('APP_TIMEZONE', 'Europe/Rome')));
$documento = 'Telegramma di prova sandbox inviato il ' . $now->format('d/m/Y H:i') . "\n\n" . 'Questa è una richiesta di test automatica, ignorare.';

// Payload conforme allo schema Telegrammi (dug/indirizzo/civico/.. a livello radice).
$payload = [
    'prodotto' => 'telegramma',
    'mittente' => $mittente,
    'destinatari' => $destinatari,
    'documento' => $documento,
    'opzioni' => [
        'autoconfirm' => $autoConfirm,
        'mittente' => true,
    ],
];

fwrite(STDOUT, 'Invio richiesta al sandbox Ufficio Postale (endpoint: ' . ($baseOverride !== null && $baseOverride !== '' ? $baseOverride : env('UFFICIO_POSTALE_BASE_URI', 'default')) . ')...' . PHP_EOL);

try {
    $response = $client->createTelegram($payload);
    $status = $response['status'] ?? null;
    $data = $response['data'] ?? null;

    fwrite(STDOUT, 'HTTP Status: ' . ($status ?? 'n/d') . PHP_EOL);
    if (is_array($data)) {
        fwrite(STDOUT, 'Payload risposta:' . PHP_EOL);
        fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    } else {
        fwrite(STDOUT, 'Nessun payload JSON restituito.' . PHP_EOL);
        if (isset($response['raw'])) {
            fwrite(STDOUT, 'Body raw:' . PHP_EOL . $response['raw'] . PHP_EOL);
        }
    }

    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Errore durante l\'invio: ' . $exception->getMessage() . PHP_EOL);
    if (method_exists($exception, 'getCode')) {
        fwrite(STDERR, 'Codice errore: ' . $exception->getCode() . PHP_EOL);
    }
    exit(1);
}
