<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$attachmentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($attachmentId <= 0) {
    add_flash('warning', 'Allegato non valido.');
    header('Location: ' . visure_cr_module_url('index'));
    exit;
}

$attachment = visure_cr_get_attachment($pdo, $attachmentId);
if (!$attachment) {
    add_flash('warning', 'Allegato non trovato.');
    header('Location: ' . visure_cr_module_url('index'));
    exit;
}

$relativePath = (string) ($attachment['file_path'] ?? '');
if ($relativePath === '') {
    add_flash('warning', 'Percorso allegato non valido.');
    header('Location: ' . visure_cr_module_url('index'));
    exit;
}

$absolutePath = rtrim(project_root_path(), '/') . '/' . ltrim($relativePath, '/');
$realPath = realpath($absolutePath);
$uploadRoot = realpath(rtrim(project_root_path(), '/') . '/' . VISURE_CR_UPLOAD_DIR);

if ($realPath === false || $uploadRoot === false || strpos($realPath, $uploadRoot) !== 0 || !is_file($realPath)) {
    add_flash('warning', 'File allegato non disponibile.');
    header('Location: ' . visure_cr_module_url('index'));
    exit;
}

$filename = (string) ($attachment['file_name'] ?? 'allegato');
$mimeType = (string) ($attachment['mime_type'] ?? 'application/octet-stream');

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($realPath));
readfile($realPath);
exit;
