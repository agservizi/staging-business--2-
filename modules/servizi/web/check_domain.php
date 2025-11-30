<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json; charset=utf-8');

if (!servizi_web_hostinger_is_configured()) {
    http_response_code(503);
    echo json_encode([
        'success' => false,
        'message' => 'Integrazione Hostinger non configurata. Aggiungi il token API nelle variabili ambiente.',
    ]);
    exit;
}

$domain = strtolower(trim((string) ($_GET['domain'] ?? $_POST['domain'] ?? '')));
if ($domain === '') {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Specifica un dominio da verificare.',
    ]);
    exit;
}

$result = servizi_web_hostinger_check_domain($domain);

if (isset($result['error']) && $result['error']) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'domain' => $domain,
        'message' => 'Verifica Hostinger fallita: ' . $result['error'],
    ]);
    exit;
}

$items = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];

if (!$items) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'domain' => $domain,
        'available' => null,
        'message' => 'Nessuna risposta disponibile. Verifica manualmente su Hostinger.',
    ]);
    exit;
}

$match = null;
foreach ($items as $item) {
    if (isset($item['domain']) && strtolower((string) $item['domain']) === $domain) {
        $match = $item;
        break;
    }
}

if ($match === null) {
    echo json_encode([
        'success' => true,
        'domain' => $domain,
        'available' => null,
        'message' => 'Dominio non presente nella risposta Hostinger.',
    ]);
    exit;
}

$available = null;
if (array_key_exists('is_available', $match)) {
    $available = (bool) $match['is_available'];
} elseif (array_key_exists('available', $match)) {
    $available = (bool) $match['available'];
}

$status = null;
if (isset($match['is_available'])) {
    $status = $match['is_available'] ? 'AVAILABLE' : 'UNAVAILABLE';
} elseif (isset($match['status'])) {
    $status = (string) $match['status'];
}

$response = [
    'success' => true,
    'domain' => $domain,
    'available' => $available,
    'status' => $status,
    'details' => $match,
];

echo json_encode($response);
