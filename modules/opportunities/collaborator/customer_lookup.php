<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore', 'Admin', 'Manager');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.']);
    exit;
}

try {
    require_valid_csrf();
} catch (Throwable $exception) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Token di sicurezza non valido.']);
    exit;
}

$taxCode = strtoupper(trim((string) ($_POST['tax_code'] ?? '')));
if ($taxCode === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Inserisci un codice fiscale da cercare.']);
    exit;
}

$customer = $opportunityService->findCustomerByTaxCode($taxCode);
if (!$customer) {
    echo json_encode(['success' => false, 'message' => 'Nessun cliente trovato con questo codice fiscale.']);
    exit;
}

echo json_encode([
    'success' => true,
    'customer' => $customer,
]);
