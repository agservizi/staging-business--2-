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
    $client = null;
    $likeSliceLength = max(3, min(8, strlen($normalized)));
    $likePattern = '%' . substr($normalized, 0, $likeSliceLength) . '%';

    $lookupStmt = $pdo->prepare(
        "SELECT id, nome, cognome, ragione_sociale, cf_piva, email, telefono
         FROM clienti
         WHERE cf_piva IS NOT NULL AND cf_piva <> ''
           AND UPPER(cf_piva) LIKE :pattern
         LIMIT 100"
    );
    $lookupStmt->execute([':pattern' => $likePattern]);
    $candidates = $lookupStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if (!$candidates) {
        $fallbackStmt = $pdo->query(
            "SELECT id, nome, cognome, ragione_sociale, cf_piva, email, telefono
             FROM clienti
             WHERE cf_piva IS NOT NULL AND cf_piva <> ''
             ORDER BY updated_at DESC
             LIMIT 200"
        );
        $candidates = $fallbackStmt ? $fallbackStmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    foreach ($candidates as $candidate) {
        $candidateCfRaw = (string) ($candidate['cf_piva'] ?? '');
        $candidateCf = strtoupper(trim($candidateCfRaw));
        $candidateNormalized = preg_replace('/[^A-Z0-9]/', '', $candidateCf ?: '');
        if ($candidateNormalized === '') {
            continue;
        }
        if ($candidateNormalized === $normalized || strpos($candidateNormalized, $normalized) !== false || strpos($normalized, $candidateNormalized) !== false) {
            $candidate['_matched_cf'] = $candidateCfRaw;
            $client = $candidate;
            break;
        }
    }

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

    $cfValue = strtoupper(trim((string) ($client['_matched_cf'] ?? $client['cf_piva'] ?? '')));

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
