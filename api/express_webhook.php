<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

http_response_code(410);

echo json_encode([
    'success' => false,
    'error' => 'Webhook legacy dismesso. Il modulo Express e\' ora solo nativo e single-tenant.',
    'mode' => 'single-tenant-native',
], JSON_THROW_ON_ERROR);