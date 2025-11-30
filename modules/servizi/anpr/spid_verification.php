<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    add_flash('warning', 'Metodo non consentito.');
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$praticaId = (int) ($_POST['pratica_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = anpr_fetch_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

try {
    if ($action === 'reset') {
        anpr_set_spid_status($pdo, $praticaId, null);
        anpr_log_action($pdo, 'SPID annullato', 'Annullata verifica SPID per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
        add_flash('success', 'Verifica SPID annullata.');
    } else {
        $operatoreId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        if (!$operatoreId) {
            throw new RuntimeException('Operatore non autenticato.');
        }
        anpr_set_spid_status($pdo, $praticaId, $operatoreId);
        anpr_log_action($pdo, 'SPID verificato', 'Registrata verifica SPID per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
        add_flash('success', 'Verifica SPID registrata con successo.');
    }
} catch (Throwable $exception) {
    error_log('ANPR SPID update failed: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile aggiornare la verifica SPID.');
}

header('Location: view_request.php?id=' . $praticaId);
exit;
