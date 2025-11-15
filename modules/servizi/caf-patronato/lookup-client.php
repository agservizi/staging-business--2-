<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

use PDO;
use Throwable;

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'found' => false,
        'error' => 'Metodo non supportato',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$rawCf = '';
if (isset($_GET['cf'])) {
    $rawCf = (string) $_GET['cf'];
} elseif (isset($_GET['codice_fiscale'])) {
    $rawCf = (string) $_GET['codice_fiscale'];
}

$normalized = strtoupper(trim($rawCf));
$normalized = preg_replace('/[^A-Z0-9]/', '', $normalized ?? '');

if ($normalized === '' || strlen($normalized) < 11) {
    echo json_encode([
        'found' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $stmt = $pdo->prepare(
        "SELECT id, nome, cognome, ragione_sociale, cf_piva, email, telefono
         FROM clienti
         WHERE cf_piva IS NOT NULL
           AND REPLACE(REPLACE(REPLACE(REPLACE(UPPER(cf_piva), ' ', ''), '-', ''), '.', ''), '/', '') = :cf
         LIMIT 1"
    );
    $stmt->execute([':cf' => $normalized]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        echo json_encode([
            'found' => false,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $clientId = (int) ($client['id'] ?? 0);
    $company = trim((string) ($client['ragione_sociale'] ?? ''));
    $surname = trim((string) ($client['cognome'] ?? ''));
    $name = trim((string) ($client['nome'] ?? ''));
    $person = trim($surname . ($surname && $name ? ' ' : '') . $name);

    $displayName = $company !== '' && $person !== ''
        ? $company . ' - ' . $person
        : ($company !== '' ? $company : ($person !== '' ? $person : 'Cliente #' . $clientId));

    $nominativoSuggestion = $company !== '' ? $company : $person;
    if ($nominativoSuggestion === '') {
        $nominativoSuggestion = $displayName;
    }

    $cfValue = strtoupper(trim((string) ($client['cf_piva'] ?? '')));

    echo json_encode([
        'found' => true,
        'client' => [
            'id' => $clientId,
            'nome' => $name,
            'cognome' => $surname,
            'ragione_sociale' => $company,
            'email' => trim((string) ($client['email'] ?? '')),
            'telefono' => trim((string) ($client['telefono'] ?? '')),
            'cf' => $cfValue,
            'display_name' => $displayName,
            'nominativo_suggestion' => $nominativoSuggestion,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('[CAF Patronato] lookup-client failure: ' . $exception->getMessage());
    echo json_encode([
        'found' => false,
        'error' => 'Impossibile cercare il cliente in questo momento.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
