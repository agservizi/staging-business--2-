<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'errors' => ['Metodo non consentito.'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_valid_csrf();
} catch (Throwable $exception) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'errors' => ['Token CSRF non valido.'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    exit;
}

$data = [
    'ragione_sociale' => trim((string) ($_POST['ragione_sociale'] ?? '')),
    'nome' => trim((string) ($_POST['nome'] ?? '')),
    'cognome' => trim((string) ($_POST['cognome'] ?? '')),
    'cf_piva' => trim((string) ($_POST['cf_piva'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'telefono' => trim((string) ($_POST['telefono'] ?? '')),
    'indirizzo' => trim((string) ($_POST['indirizzo'] ?? '')),
    'note' => trim((string) ($_POST['note'] ?? '')),
];

$errors = [];

if ($data['nome'] === '' || $data['cognome'] === '') {
    $errors[] = 'Nome e cognome sono obbligatori.';
}

if ($data['ragione_sociale'] !== '' && mb_strlen($data['ragione_sociale']) > 160) {
    $errors[] = 'La ragione sociale non può superare i 160 caratteri.';
}

if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email non valida.';
}

if ($data['cf_piva'] !== '' && !preg_match('/^[A-Z0-9]{11,16}$/i', $data['cf_piva'])) {
    $errors[] = 'Codice fiscale o partita IVA non ha un formato valido.';
}

if ($data['telefono'] !== '' && !preg_match('/^[0-9+()\\s-]{6,}$/', $data['telefono'])) {
    $errors[] = 'Numero di telefono non valido.';
}

if (mb_strlen($data['note']) > 2000) {
    $errors[] = 'Le note non possono superare i 2000 caratteri.';
}

try {
    if (!$errors && $data['email'] !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clienti WHERE email = :email');
        $stmt->execute([':email' => $data['email']]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'Esiste già un cliente con questa email.';
        }
    }

    if (!$errors && $data['cf_piva'] !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clienti WHERE cf_piva = :cf_piva');
        $stmt->execute([':cf_piva' => $data['cf_piva']]);
        if ((int) $stmt->fetchColumn() > 0) {
            $errors[] = 'Esiste già un cliente con questo codice fiscale / partita IVA.';
        }
    }

    if ($errors) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO clienti (
        ragione_sociale,
        nome,
        cognome,
        cf_piva,
        email,
        telefono,
        indirizzo,
        note,
        created_at,
        updated_at
    ) VALUES (
        :ragione_sociale,
        :nome,
        :cognome,
        :cf_piva,
        :email,
        :telefono,
        :indirizzo,
        :note,
        NOW(),
        NOW()
    )');

    $stmt->execute([
        ':ragione_sociale' => $data['ragione_sociale'] !== '' ? $data['ragione_sociale'] : '',
        ':nome' => $data['nome'],
        ':cognome' => $data['cognome'],
        ':cf_piva' => $data['cf_piva'] !== '' ? $data['cf_piva'] : null,
        ':email' => $data['email'] !== '' ? $data['email'] : null,
        ':telefono' => $data['telefono'] !== '' ? $data['telefono'] : null,
        ':indirizzo' => $data['indirizzo'] !== '' ? $data['indirizzo'] : null,
        ':note' => $data['note'] !== '' ? $data['note'] : null,
    ]);

    $clientId = (int) $pdo->lastInsertId();

    $label = $data['ragione_sociale'] !== ''
        ? $data['ragione_sociale']
        : trim($data['cognome'] . ' ' . $data['nome']);
    if ($label === '') {
        $label = 'Cliente #' . $clientId;
    }

    try {
        $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':modulo' => 'Clienti',
            ':azione' => 'Creazione cliente rapida',
            ':dettagli' => sprintf('%s (#%d)', $label, $clientId),
        ]);
    } catch (Throwable $loggingException) {
        error_log('Client quick add log failed: ' . $loggingException->getMessage());
    }

    echo json_encode([
        'success' => true,
        'id' => $clientId,
        'label' => $label,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Client quick add failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'errors' => ['Impossibile creare il cliente in questo momento.'],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
