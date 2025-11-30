<?php
use App\Services\AI\ThinkingAdvisor;
use App\Services\CoresuiteExpressClient;

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

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

// Validazione input per vendita SIM
$requiredFields = ['cliente_id', 'sim_iccid', 'prodotto', 'importo', 'data_vendita'];
foreach ($requiredFields as $field) {
    if (!isset($payload[$field]) || trim((string) $payload[$field]) === '') {
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => "Campo obbligatorio mancante: {$field}."], JSON_THROW_ON_ERROR);
        exit;
    }
}

$clienteId = (int) $payload['cliente_id'];
$simIccid = trim((string) $payload['sim_iccid']);
$prodotto = trim((string) $payload['prodotto']);
$importo = (float) $payload['importo'];
$dataVendita = trim((string) $payload['data_vendita']);
$metodoPagamento = trim((string) ($payload['metodo_pagamento'] ?? 'Contanti'));
$note = trim((string) ($payload['note'] ?? ''));

// Validazione cliente
$stmtCliente = $pdo->prepare('SELECT id FROM clienti WHERE id = ?');
$stmtCliente->execute([$clienteId]);
if (!$stmtCliente->fetch()) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Cliente non trovato.'], JSON_THROW_ON_ERROR);
    exit;
}

// Validazione data
try {
    $dataVenditaObj = new DateTimeImmutable($dataVendita);
} catch (Exception $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Data vendita non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

// Creazione descrizione
$descrizione = "Vendita SIM ICCID {$simIccid} - Prodotto: {$prodotto}";
if ($note !== '') {
    $descrizione .= " - Note: {$note}";
}

// Inserimento entrata
try {
    $stmt = $pdo->prepare('INSERT INTO entrate_uscite (
        cliente_id, tipo_movimento, descrizione, importo, metodo, data_pagamento, stato, created_at, updated_at
    ) VALUES (?, "Entrata", ?, ?, ?, ?, "Pagato", NOW(), NOW())');
    $stmt->execute([
        $clienteId,
        $descrizione,
        $importo,
        $metodoPagamento,
        $dataVenditaObj->format('Y-m-d H:i:s')
    ]);
    $entrataId = (int) $pdo->lastInsertId();
} catch (PDOException $exception) {
    error_log('Errore creazione entrata vendita SIM: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore interno durante la creazione dell\'entrata.'], JSON_THROW_ON_ERROR);
    exit;
}

// Ottieni dati cliente per Express
$stmtClienteDati = $pdo->prepare('SELECT nome, cognome, email, telefono FROM clienti WHERE id = ?');
$stmtClienteDati->execute([$clienteId]);
$cliente = $stmtClienteDati->fetch(PDO::FETCH_ASSOC);
if (!$cliente) {
    // Cliente non trovato, ma entrata già creata - logga errore ma continua
    error_log("Cliente {$clienteId} non trovato per Express, entrata {$entrataId} creata localmente.");
} else {
    try {
        $expressClient = new CoresuiteExpressClient();
        
        // Cerca cliente in Express per email
        $expressCustomer = $expressClient->findCustomerByEmail($cliente['email']);
        if (!$expressCustomer) {
            // Crea cliente in Express
            $expressCustomer = $expressClient->createCustomer([
                'name' => trim($cliente['nome'] . ' ' . $cliente['cognome']),
                'email' => $cliente['email'],
                'phone' => $cliente['telefono'] ?? '',
            ]);
        }
        
        // Crea vendita SIM in Express
        $saleData = [
            'customer_id' => $expressCustomer['id'],
            'product' => $prodotto,
            'iccid' => $simIccid,
            'amount' => $importo,
            'sale_date' => $dataVenditaObj->format(DateTimeInterface::ATOM),
            'payment_method' => $metodoPagamento,
            'notes' => $note,
        ];
        $expressSale = $expressClient->createSimSale($saleData);
        
        // Log successo
        error_log("Vendita SIM creata in Express: " . json_encode($expressSale));
    } catch (Exception $e) {
        // Log errore ma non fallire la richiesta
        error_log('Errore creazione vendita in Express: ' . $e->getMessage());
    }
}

echo json_encode([
    'success' => true,
    'id' => $entrataId,
    'message' => 'Entrata per vendita SIM registrata con successo.'
], JSON_THROW_ON_ERROR);
?></content>
<parameter name="filePath">/Users/carminecavaliere/Downloads/staging-business/api/sim_sale.php