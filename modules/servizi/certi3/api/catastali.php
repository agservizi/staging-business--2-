<?php
require_once '../../../includes/db_connect.php';
require_once '../../../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

// Verifica CSRF
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF non valido']);
    exit;
}

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Utente non autenticato']);
    exit;
}

try {
    // Validazione dati
    $required_fields = ['categoria', 'tipo', 'codice_fiscale', 'nome', 'cognome', 'comune', 'provincia'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Campo obbligatorio mancante: $field");
        }
    }

    // Validazione codice fiscale
    if (!preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $_POST['codice_fiscale'])) {
        throw new Exception('Codice fiscale non valido');
    }

    // Prepara dati per il database
    $dati_richiesta = [
        'codice_fiscale' => strtoupper($_POST['codice_fiscale']),
        'nome' => trim($_POST['nome']),
        'cognome' => trim($_POST['cognome']),
        'comune' => trim($_POST['comune']),
        'provincia' => strtoupper(trim($_POST['provincia'])),
        'foglio' => !empty($_POST['foglio']) ? trim($_POST['foglio']) : null,
        'particella' => !empty($_POST['particella']) ? trim($_POST['particella']) : null,
        'note' => !empty($_POST['note']) ? trim($_POST['note']) : null,
        'urgente' => isset($_POST['urgente']) ? 1 : 0
    ];

    $data = [
        'user_id' => $_SESSION['user_id'],
        'categoria' => 'catastali',
        'tipo' => $_POST['tipo'],
        'dati_richiesta' => json_encode($dati_richiesta),
        'stato' => 'pending', // In attesa di approvazione admin
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Inserisci nel database
    $columns = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));

    $stmt = $pdo->prepare("INSERT INTO certificati_richieste ($columns) VALUES ($placeholders)");

    foreach ($data as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }

    $stmt->execute();
    $request_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Richiesta certificato catastale inviata con successo',
        'request_id' => $request_id
    ]);

} catch (Exception $e) {
    error_log("Errore richiesta certificato catastale: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>