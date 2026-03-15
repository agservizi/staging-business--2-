<?php
declare(strict_types=1);

$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($requestPath === false || $requestPath === '') {
    $requestPath = '/';
}

$documentRoot = __DIR__;
$absolutePath = realpath($documentRoot . $requestPath);
$isExpressPath = str_starts_with($requestPath, '/modules/servizi/express');

if (
    $isExpressPath
    && in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)
    && $requestPath !== '/index.php'
    && str_ends_with($requestPath, '.php')
) {
    $cleanPath = preg_replace('/\.php$/', '', $requestPath) ?: $requestPath;
    if ($cleanPath !== $requestPath) {
        $queryString = $_SERVER['QUERY_STRING'] ?? '';
        if ($queryString !== '') {
            $cleanPath .= '?' . $queryString;
        }
        header('Location: ' . $cleanPath, true, 301);
        return true;
    }
}

if ($absolutePath !== false && str_starts_with($absolutePath, $documentRoot) && is_file($absolutePath)) {
    return false;
}

if ($absolutePath !== false && str_starts_with($absolutePath, $documentRoot) && is_dir($absolutePath)) {
    $indexFile = $absolutePath . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($indexFile)) {
        require $indexFile;
        return true;
    }
}

$trimmedPath = trim($requestPath, '/');
$candidates = [];

if ($trimmedPath === '') {
    $candidates[] = $documentRoot . '/index.php';
} elseif ($isExpressPath) {
    $candidates[] = $documentRoot . '/' . $trimmedPath . '.php';
    $candidates[] = $documentRoot . '/' . $trimmedPath . '/index.php';
}

foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        return true;
    }
}

http_response_code(404);
echo '404 Not Found';
