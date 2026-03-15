<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json; charset=utf-8');

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 3) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = 10;
try {
    $stmt = $pdo->prepare('SELECT DISTINCT recipient_email FROM posta_telematica_messages WHERE recipient_email LIKE :q ORDER BY recipient_email ASC LIMIT ' . $limit);
    $stmt->execute([':q' => $q . '%']);
    $emails = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $exception) {
    echo json_encode(['results' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['results' => $emails], JSON_UNESCAPED_UNICODE);
