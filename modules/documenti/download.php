<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$versionId = isset($_GET['version']) ? (int) $_GET['version'] : 0;
if ($versionId <= 0) {
    http_response_code(400);
    echo 'Richiesta non valida.';
    exit;
}

$stmt = $pdo->prepare('SELECT dv.*, d.titolo FROM document_versions dv JOIN documents d ON d.id = dv.document_id WHERE dv.id = :id LIMIT 1');
$stmt->execute([':id' => $versionId]);
$version = $stmt->fetch();

if (!$version) {
    http_response_code(404);
    echo 'Documento non trovato.';
    exit;
}

$filePath = __DIR__ . '/../../' . $version['file_path'];
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(410);
    echo 'File non disponibile.';
    exit;
}

$mime = $version['mime_type'] ?: 'application/octet-stream';
$filename = $version['file_name'];

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . (string) filesize($filePath));
header('X-Content-Type-Options: nosniff');

readfile($filePath);
exit;
