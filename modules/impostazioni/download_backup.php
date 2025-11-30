<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('Admin', 'Manager');

$filename = $_GET['file'] ?? '';
if ($filename === '' || basename($filename) !== $filename) {
    http_response_code(400);
    exit('Parametro file non valido.');
}

$backupDir = realpath(__DIR__ . '/../../backups');
if ($backupDir === false) {
    http_response_code(404);
    exit('Cartella backup non trovata.');
}

$requestedPath = realpath($backupDir . DIRECTORY_SEPARATOR . $filename);
if ($requestedPath === false || strncmp($requestedPath, $backupDir, strlen($backupDir)) !== 0 || !is_file($requestedPath)) {
    http_response_code(404);
    exit('File di backup non trovato.');
}

$filesize = filesize($requestedPath);
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . $filename . '"');
if ($filesize !== false) {
    header('Content-Length: ' . $filesize);
}
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

$handle = fopen($requestedPath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Impossibile aprire il file di backup.');
}

while (!feof($handle)) {
    echo fread($handle, 8192);
    flush();
}

fclose($handle);
exit;
