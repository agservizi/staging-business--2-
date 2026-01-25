<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/global_search.php';

header('Content-Type: application/json; charset=utf-8');

$term = trim($_GET['q'] ?? '');
$term = mb_substr($term, 0, 120);
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 8;
$limit = max(1, min(25, $limit));
$types = isset($_GET['type']) ? (string) $_GET['type'] : '';

$rateKey = 'global_search_rate';
$rateWindow = 10;
$rateLimit = 20;
$now = time();
if (!isset($_SESSION[$rateKey]) || !is_array($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = [];
}
$_SESSION[$rateKey] = array_values(array_filter($_SESSION[$rateKey], static fn($ts) => is_int($ts) && ($now - $ts) <= $rateWindow));
if (count($_SESSION[$rateKey]) >= $rateLimit) {
    http_response_code(429);
    echo json_encode([
        'query' => $term,
        'items' => [],
        'groups' => new stdClass(),
        'error' => 'Troppe richieste. Riprova tra qualche secondo.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$_SESSION[$rateKey][] = $now;

$payload = global_search($pdo, $term, [
    'limit' => $limit,
    'types' => $types,
    'role' => $_SESSION['role'] ?? '',
    'userId' => (int) ($_SESSION['user_id'] ?? 0),
    'userEmail' => (string) ($_SESSION['email'] ?? ''),
]);

if (empty($payload['allowedTypes'])) {
    http_response_code(403);
    echo json_encode([
        'query' => $term,
        'items' => [],
        'groups' => new stdClass(),
        'error' => 'Ricerca non disponibile per il tuo ruolo.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$response = [
    'query' => $payload['query'] ?? $term,
    'items' => $payload['items'] ?? [],
    'groups' => $payload['groups'] ?? new stdClass(),
    'warnings' => $payload['warnings'] ?? [],
    'allowedTypes' => $payload['allowedTypes'] ?? [],
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
