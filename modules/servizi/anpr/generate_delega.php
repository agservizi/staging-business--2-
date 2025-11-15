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
$action = (string) ($_POST['action'] ?? 'generate');

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

if (!anpr_can_generate_delega($pratica)) {
    add_flash('warning', 'Questa tipologia non supporta la generazione automatica della delega.');
    header('Location: view_request.php?id=' . $praticaId);
    exit;
}

try {
    anpr_auto_generate_delega($pdo, $praticaId, $pratica);
    $message = $action === 'generate' ? 'Delega generata automaticamente.' : 'Delega rigenerata automaticamente.';
    add_flash('success', $message);
} catch (RuntimeException $exception) {
    add_flash('warning', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('ANPR delega auto-generation error: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile generare la delega automatica.');
}

header('Location: view_request.php?id=' . $praticaId);
exit;
