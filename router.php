<?php
declare(strict_types=1);

function clean_url_scopes(): array
{
    return [
        '/',
        '/forgot_password',
        '/reset_password',
        '/logout',
        '/mfa-setup',
        '/mfa-verify',
        '/dashboard',
        '/modules/servizi/express',
        '/modules/clienti',
        '/modules/ticket',
        '/modules/documenti',
        '/modules/report',
        '/modules/impostazioni',
        '/modules/email-marketing',
        '/modules/opportunities',
        '/modules/iliad',
        '/modules/servizi/entrate-uscite',
        '/modules/servizi/appuntamenti',
        '/modules/servizi/energia',
        '/modules/servizi/aci',
        '/modules/servizi/fedelta',
        '/modules/servizi/brt',
        '/modules/servizi/curriculum',
        '/modules/servizi/posta-telematica',
        '/modules/servizi/telegrammi',
        '/modules/servizi/logistici',
        '/modules/servizi/visure-cr',
        '/modules/servizi/cie',
        '/modules/servizi/digitali',
        '/modules/servizi/anpr',
        '/modules/servizi/caf-patronato',
        '/modules/servizi/ricariche',
    ];
}

function clean_url_scope_match(string $requestPath): bool
{
    foreach (clean_url_scopes() as $scope) {
        $normalizedScope = '/' . trim($scope, '/');
        if ($requestPath === $normalizedScope || str_starts_with($requestPath, $normalizedScope . '/')) {
            return true;
        }
    }

    return false;
}

$requestPath = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

if ($requestPath === false || $requestPath === '') {
    $requestPath = '/';
}

$documentRoot = __DIR__;
$absolutePath = realpath($documentRoot . $requestPath);
$isCleanUrlScope = clean_url_scope_match($requestPath);

if (
    $isCleanUrlScope
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
} elseif ($isCleanUrlScope) {
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
