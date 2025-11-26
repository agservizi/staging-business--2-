<?php
use App\Services\AI\ThinkingAdvisor;

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Validazione webhook secret
$webhookSecret = (string) env('CORESUITE_WEBHOOK_SECRET', '');
$providedSecret = trim((string) ($_SERVER['HTTP_X_CORESUITE_WEBHOOK_SECRET'] ?? ($_GET['secret'] ?? '')));

if ($webhookSecret !== '' && $providedSecret !== $webhookSecret) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Webhook secret non valido.'], JSON_THROW_ON_ERROR);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

// Validazione input per webhook vendita SIM da Express
$requiredFields = ['customer_email', 'iccid', 'product', 'amount', 'sale_date'];
foreach ($requiredFields as $field) {
    if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Campo obbligatorio mancante: {$field}."], JSON_THROW_ON_ERROR);
        exit;
    }
}

$customerEmail = trim((string) $payload['customer_email']);
$customerName = trim((string) ($payload['customer_name'] ?? ''));
$customerPhone = trim((string) ($payload['customer_phone'] ?? ''));
$iccid = trim((string) $payload['iccid']);
$product = trim((string) $payload['product']);
$amount = (float) $payload['amount'];
$saleDate = trim((string) $payload['sale_date']);
$paymentMethod = trim((string) ($payload['payment_method'] ?? 'Contanti'));
$notes = trim((string) ($payload['notes'] ?? ''));

// Validazione email
if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Email cliente non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

// Validazione data
try {
    $saleDateObj = new DateTimeImmutable($saleDate);
} catch (Exception $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Data vendita non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

// Cerca cliente per email
$stmtCliente = $pdo->prepare('SELECT id, nome, cognome FROM clienti WHERE email = ?');
$stmtCliente->execute([$customerEmail]);
$cliente = $stmtCliente->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    // Cliente non trovato, crealo
    $nomeParts = explode(' ', $customerName, 2);
    $nome = $nomeParts[0] ?? '';
    $cognome = $nomeParts[1] ?? '';

    try {
        $stmtInsertCliente = $pdo->prepare('INSERT INTO clienti (nome, cognome, email, telefono, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())');
        $stmtInsertCliente->execute([$nome, $cognome, $customerEmail, $customerPhone]);
        $clienteId = (int) $pdo->lastInsertId();
    } catch (PDOException $exception) {
        error_log('Errore creazione cliente da webhook: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Errore interno durante la creazione del cliente.'], JSON_THROW_ON_ERROR);
        exit;
    }
} else {
    $clienteId = (int) $cliente['id'];
}

// Creazione descrizione
$descrizione = "Vendita SIM da Express - ICCID {$iccid} - Prodotto: {$product}";
if ($notes !== '') {
    $descrizione .= " - Note: {$notes}";
}

// Inserimento entrata
try {
    $stmt = $pdo->prepare('INSERT INTO entrate_uscite (
        cliente_id, tipo_movimento, descrizione, importo, metodo, data_pagamento, stato, created_at, updated_at
    ) VALUES (?, "Entrata", ?, ?, ?, ?, "Pagato", NOW(), NOW())');
    $stmt->execute([
        $clienteId,
        $descrizione,
        $amount,
        $paymentMethod,
        $saleDateObj->format('Y-m-d H:i:s')
    ]);
    $entrataId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Errore creazione entrata da webhook: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno durante la creazione dell\'entrata.'], JSON_THROW_ON_ERROR);
    exit;
}

echo json_encode([
    'success' => true,
    'id' => $entrataId,
    'message' => 'Entrata per vendita SIM da Express registrata con successo.'
], JSON_THROW_ON_ERROR);
?>