<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) {
    http_response_code(400);
    exit('Parametro id non valido.');
}

$stmt = $pdo->prepare('SELECT report_date, file_path FROM daily_financial_reports WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$report = $stmt->fetch();

if (!$report) {
    http_response_code(404);
    exit('Report non trovato.');
}

$relativePath = (string) ($report['file_path'] ?? '');
if ($relativePath === '') {
    http_response_code(404);
    exit('Percorso del report non disponibile.');
}

$fullPath = public_path($relativePath);
$realFullPath = realpath($fullPath);
if ($realFullPath !== false) {
    $fullPath = $realFullPath;
}

$reportsRoot = public_path('backups/daily-reports');
$realReportsRoot = realpath($reportsRoot) ?: $reportsRoot;

$normalizedFull = str_replace(chr(92), '/', $fullPath);
$normalizedRoot = rtrim(str_replace(chr(92), '/', $realReportsRoot), '/');

if (!is_file($fullPath) || $normalizedRoot === '' || strncmp($normalizedFull, $normalizedRoot, strlen($normalizedRoot)) !== 0) {
    http_response_code(404);
    exit('File del report non trovato.');
}

$mode = filter_input(INPUT_GET, 'mode', FILTER_UNSAFE_RAW) ?: '';
$inline = strtolower((string) $mode) === 'inline';

$filesize = @filesize($fullPath);
$downloadName = 'report_finanziario_' . ($report['report_date'] ?? date('Y_m_d')) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $downloadName . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Transfer-Encoding: binary');
if ($filesize !== false) {
    header('Accept-Ranges: bytes');
}

if (function_exists('ob_get_level')) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

$streamFile = static function (string $path, int $start = 0, ?int $end = null): void {
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit('Impossibile aprire il file del report.');
    }

    if ($start > 0) {
        fseek($handle, $start);
    }

    $bytesToSend = $end !== null ? ($end - $start + 1) : null;
    $bufferSize = 8192;

    while (!feof($handle)) {
        if ($bytesToSend !== null && $bytesToSend <= 0) {
            break;
        }

        $chunkSize = $bytesToSend !== null ? min($bufferSize, $bytesToSend) : $bufferSize;
        $data = fread($handle, $chunkSize);
        if ($data === false) {
            break;
        }

        echo $data;
        flush();

        if ($bytesToSend !== null) {
            $bytesToSend -= strlen($data);
        }

        if (connection_status() !== CONNECTION_NORMAL) {
            break;
        }
    }

    fclose($handle);
};

if ($filesize !== false && !empty($_SERVER['HTTP_RANGE'])) {
    $rangeHeader = (string) $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=([0-9]*)-([0-9]*)/', $rangeHeader, $matches)) {
        $start = $matches[1] !== '' ? (int) $matches[1] : 0;
        $end = $matches[2] !== '' ? (int) $matches[2] : ($filesize - 1);
        if ($end >= $filesize) {
            $end = $filesize - 1;
        }
        if ($start > $end || $start >= $filesize) {
            header('Content-Range: bytes */' . $filesize);
            http_response_code(416);
            exit;
        }

        $length = ($end - $start) + 1;
        header('HTTP/1.1 206 Partial Content');
        header('Content-Length: ' . $length);
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $filesize);

        $streamFile($fullPath, $start, $end);
        exit;
    }
}

if ($filesize !== false) {
    header('Content-Length: ' . $filesize);
}

$streamFile($fullPath);

exit;
