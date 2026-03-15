<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/express_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$expressPortalService = new ExpressPortalService();
$businessCustomer = $expressPortalService->resolveBusinessCustomer((array) $customer);

if ($businessCustomer === null) {
    header('Location: dashboard.php?error=' . urlencode('Area Express non disponibile per questo account cliente.'));
    exit;
}

$portalExpressContext = [
    'portalCustomer' => $customer,
    'businessCustomer' => $businessCustomer,
    'service' => $expressPortalService,
];
