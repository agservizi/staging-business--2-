<?php
if (!isset($customer)) {
    $customer = CustomerAuth::getAuthenticatedCustomer();
}

$pageTitle = $pageTitle ?? 'Pickup Portal';
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$csrfToken = htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8');
$customerName = htmlspecialchars($customer['name'] ?? $customer['email'] ?? 'Cliente', ENT_QUOTES, 'UTF-8');

$normalizePath = static function (?string $path, string $default) {
    if ($path === null) {
        return $default;
    }
    $trimmed = trim($path);
    if ($trimmed === '' || $trimmed === '/') {
        return '/';
    }
    return '/' . trim($trimmed, '/');
};

$envBasePath = env('PORTAL_BASE_PATH', null);
$portalBasePath = $envBasePath !== null ? $normalizePath($envBasePath, '/') : null;

if ($portalBasePath === null) {
    $urlPath = (string) parse_url((string) env('PORTAL_URL', ''), PHP_URL_PATH);
    $portalBasePath = $urlPath !== '' ? $normalizePath($urlPath, '/') : null;
}

if ($portalBasePath === null) {
    $portalDirFs = realpath(__DIR__ . '/..');
    $documentRootFs = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($portalDirFs !== false && $documentRootFs !== false && strpos($portalDirFs, $documentRootFs) === 0) {
        $relativePath = trim(str_replace('\\', '/', substr($portalDirFs, strlen($documentRootFs))), '/');
        $portalBasePath = $relativePath === '' ? '/' : '/' . $relativePath;
    }
}

if ($portalBasePath === null) {
    $portalBasePath = '/customer-portal';
}

$baseForAssets = rtrim($portalBasePath, '/');
$apiBaseUrl = ($baseForAssets === '' ? '' : $baseForAssets) . '/api/';
$staticBaseUrl = ($baseForAssets === '' ? '' : $baseForAssets) . '/assets/';
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?> · Pickup Portal</title>
    <meta name="description" content="Gestisci i tuoi ritiri con il Pickup Portal di Coresuite Business">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0b2f6b">
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Crect width='16' height='16' rx='3' ry='3' fill='%230b2f6b'/%3E%3Ctext x='8' y='11' font-family='Arial' font-size='7' font-weight='bold' text-anchor='middle' fill='white'%3ECP%3C/text%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer">
    <link href="assets/css/portal.css" rel="stylesheet">
</head>
<body class="portal-body">
<div id="portal-app" class="d-flex flex-grow-1">
<script>
window.portalConfig = {
    csrfToken: '<?= $csrfToken ?>',
    customerId: <?= (int) ($customer['id'] ?? 0) ?>,
    apiBaseUrl: '<?= $apiBaseUrl ?>',
    staticBaseUrl: '<?= $staticBaseUrl ?>',
    currentPage: '<?= $currentPage ?>'
};
</script>

<div id="global-alert-container" class="container-fluid py-3" style="display: none;">
    <div class="row">
        <div class="col-12">
            <div id="global-alert" class="alert alert-dismissible fade show" role="alert">
                <span id="global-alert-message"></span>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
            </div>
        </div>
    </div>
</div>