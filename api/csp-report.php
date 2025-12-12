<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$rawBody = file_get_contents('php://input') ?: '';
if ($rawBody === '') {
    http_response_code(204);
    exit;
}

try {
    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON'], JSON_THROW_ON_ERROR);
    exit;
}

$report = [];
if (isset($payload['csp-report']) && is_array($payload['csp-report'])) {
    $report = $payload['csp-report'];
} elseif (isset($payload['report']) && is_array($payload['report'])) {
    $report = $payload['report'];
} elseif (isset($payload['body']) && is_array($payload['body'])) {
    $report = $payload['body'];
}

if ($report === []) {
    http_response_code(400);
    echo json_encode(['error' => 'No report payload'], JSON_THROW_ON_ERROR);
    exit;
}

$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}

$logFile = $logDir . '/csp-report.log';
$entry = [
    'timestamp' => gmdate('c'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
    'report' => [
        'document-uri' => $report['document-uri'] ?? null,
        'referrer' => $report['referrer'] ?? null,
        'violated-directive' => $report['violated-directive'] ?? null,
        'effective-directive' => $report['effective-directive'] ?? null,
        'original-policy' => $report['original-policy'] ?? null,
        'blocked-uri' => $report['blocked-uri'] ?? null,
        'line-number' => $report['line-number'] ?? null,
        'column-number' => $report['column-number'] ?? null,
        'source-file' => $report['source-file'] ?? null,
        'status-code' => $report['status-code'] ?? null,
        'sample' => $report['sample'] ?? null,
    ],
];

try {
    $jsonLine = json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    @file_put_contents($logFile, $jsonLine . PHP_EOL, FILE_APPEND | LOCK_EX);
} catch (Throwable $exception) {
    error_log('CSP report log failed: ' . $exception->getMessage());
}

http_response_code(204);
