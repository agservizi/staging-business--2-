<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo non consentito.',
    ]);
    exit;
}

try {
    require_valid_csrf();
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Token CSRF non valido.',
    ]);
    exit;
}

$country = strtoupper(trim((string) ($_POST['country'] ?? '')));
$vatNumber = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['vat'] ?? '')));

if (!preg_match('/^[A-Z]{2}$/', $country)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Specificare un paese IVA valido (codice ISO a 2 lettere).',
    ]);
    exit;
}

if ($vatNumber === '' || !preg_match('/^[A-Z0-9]{8,15}$/', $vatNumber)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Inserisci una partita IVA valida (8-15 caratteri).',
    ]);
    exit;
}

if ($country === 'IT' && !preg_match('/^[0-9]{11}$/', $vatNumber)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Per l\'Italia la partita IVA deve contenere 11 cifre.',
    ]);
    exit;
}

if (!extension_loaded('soap')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Il server non supporta la verifica VIES: estensione SOAP mancante.',
    ]);
    exit;
}

try {
    $client = new SoapClient('https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl', [
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_MEMORY,
        'connection_timeout' => 10,
        'user_agent' => 'CoresuiteBusiness/1.0 VIES Client',
    ]);

    $result = $client->checkVat([
        'countryCode' => $country,
        'vatNumber' => $vatNumber,
    ]);

    if (empty($result->valid)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'La partita IVA indicata non risulta valida nel registro VIES.',
        ]);
        exit;
    }

    $name = trim((string) ($result->name ?? ''));
    $addressRaw = str_replace("\r", '', trim((string) ($result->address ?? '')));
    $addressParts = preg_split('/\n+/', $addressRaw) ?: [];
    $addressLines = array_values(array_filter(array_map('trim', $addressParts)));

    $cityLine = '';
    $cap = '';
    $city = '';
    $province = '';

    if (!empty($addressLines)) {
        $last = end($addressLines);
        if ($last !== false && $last !== null) {
            $cityLine = (string) $last;
        }
    }

    $streetLines = $addressLines;
    if ($cityLine !== '' && !empty($streetLines)) {
        array_pop($streetLines);
    }
    $street = trim(implode(', ', $streetLines));

    $normalizeCase = static function (string $value): string {
        $value = preg_replace('/\s+/', ' ', trim($value));
        if ($value === '') {
            return '';
        }
        $upper = mb_strtoupper($value, 'UTF-8');
        if ($upper === $value) {
            return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        return $value;
    };

    if ($cityLine !== '') {
        $cityLineClean = preg_replace('/\s+/', ' ', trim($cityLine));
        if (preg_match('/^(\d{3,10})\s+(.+?)\s+([A-Z]{2})$/u', $cityLineClean, $matches)) {
            $cap = $matches[1];
            $city = $matches[2];
            $province = strtoupper($matches[3]);
        } elseif (preg_match('/^(\d{3,10})\s+(.+)$/u', $cityLineClean, $matches)) {
            $cap = $matches[1];
            $city = $matches[2];
        } elseif (preg_match('/^(.+?)\s+([A-Z]{2})$/u', $cityLineClean, $matches)) {
            $city = $matches[1];
            $province = strtoupper($matches[2]);
        } else {
            $city = $cityLineClean;
        }
    }

    $data = [
        'name' => $normalizeCase($name),
        'address' => $street !== '' ? $normalizeCase($street) : '',
        'cap' => $cap,
        'city' => $city !== '' ? $normalizeCase($city) : '',
        'provincia' => $province,
        'rawAddress' => $addressLines ? implode(' | ', $addressLines) : $addressRaw,
    ];

    $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
        VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
    $logStmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':modulo' => 'Impostazioni',
        ':azione' => 'Consultazione VIES',
        ':dettagli' => json_encode([
            'vat_country' => $country,
            'vat_number' => $vatNumber,
            'name' => $data['name'],
        ], JSON_UNESCAPED_UNICODE),
    ]);

    echo json_encode([
        'success' => true,
        'data' => $data,
    ]);
    exit;
} catch (SoapFault $fault) {
    error_log('VIES lookup failed: ' . $fault->getMessage());
    $faultString = (string) ($fault->faultstring ?? '');
    if (str_contains($faultString, 'INVALID_INPUT')) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => 'Dati partita IVA non accettati dal servizio VIES.',
        ]);
    } else {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'message' => 'Servizio VIES temporaneamente non disponibile. Riprova piu\' tardi.',
        ]);
    }
    exit;
} catch (Throwable $e) {
    error_log('Unexpected VIES error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore interno durante la verifica VIES.',
    ]);
    exit;
}