<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo 'Parametro prenotazione mancante.';
    exit;
}

$booking = cie_fetch_booking($pdo, $id);
if ($booking === null) {
    http_response_code(404);
    echo 'Prenotazione non trovata.';
    exit;
}

$url = cie_build_portal_url($booking);
cie_log_action($pdo, 'Apertura portale', 'Apertura portale ministeriale per prenotazione #' . $id);

header('Location: ' . $url);
exit;
