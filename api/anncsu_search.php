<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://coresuite.it',
    'https://business.coresuite.it',
];

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$q = trim($_GET['q'] ?? '');
$q = mb_substr($q, 0, 120);
$minLength = 2;
$limit = 15;
$dbPath = getenv('ANNCSU_DB_PATH') ?: __DIR__ . '/../storage/tmp/anncsu.sqlite';

$response = [
    'query' => $q,
    'results' => [],
    'source' => 'anncsu_sqlite',
];

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        echo '{"error":"Unable to encode response"}';
    }
};

if ($q === '' || mb_strlen($q) < $minLength) {
    $respond(200, $response);
    return;
}

if (!is_readable($dbPath)) {
    $response['error'] = 'Indice ANNCSU non disponibile. Contatta l\'admin.';
    $respond(503, $response);
    return;
}

try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $exception) {
    $response['error'] = 'Impossibile aprire il database ANNCSU.';
    $respond(503, $response);
    return;
}

$tokens = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
$tokens = array_slice(array_map(static function (string $token): string {
    $sanitized = preg_replace('/[^\p{L}\p{N}]/u', ' ', $token);
    return trim($sanitized);
}, $tokens), 0, 6);

if ($tokens === []) {
    $respond(200, $response);
    return;
}

$match = implode(' ', array_map(static function (string $token): string {
    return $token . '*';
}, $tokens));

$sqlFts = 'SELECT street, street_number, city, province, cap
    FROM anncsu_fts
    WHERE anncsu_fts MATCH :match
    LIMIT :limit';

$results = [];

try {
    $stmt = $pdo->prepare($sqlFts);
    $stmt->bindValue(':match', $match, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
} catch (Throwable $ftsError) {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $sqlLike = 'SELECT street, street_number, city, province, cap
        FROM anncsu
        WHERE street LIKE :like OR city LIKE :like OR cap LIKE :like
        ORDER BY city
        LIMIT :limit';
    try {
        $stmt = $pdo->prepare($sqlLike);
        $stmt->bindValue(':like', $like, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll();
        $response['warnings'][] = 'Ricerca FTS non disponibile, fallback LIKE.';
    } catch (Throwable $likeError) {
        $response['error'] = 'Ricerca indirizzi non disponibile al momento.';
        $respond(503, $response);
        return;
    }
}

$response['results'] = array_map(static function (array $row): array {
    return [
        'street' => (string) ($row['street'] ?? ''),
        'street_number' => (string) ($row['street_number'] ?? ''),
        'city' => (string) ($row['city'] ?? ''),
        'province' => (string) ($row['province'] ?? ''),
        'cap' => (string) ($row['cap'] ?? ''),
    ];
}, $results);

$respond(200, $response);