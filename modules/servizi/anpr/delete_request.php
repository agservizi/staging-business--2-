<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    add_flash('warning', 'Metodo non consentito.');
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$praticaId = (int) ($_POST['id'] ?? 0);
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = anpr_fetch_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata o già rimossa.');
    header('Location: index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    if (!empty($pratica['certificato_path'])) {
        anpr_delete_certificate($pratica['certificato_path']);
    }

    if (!empty($pratica['delega_path'])) {
        anpr_delete_delega($pratica['delega_path']);
    }

    if (!empty($pratica['documento_path'])) {
        anpr_delete_documento($pratica['documento_path']);
    }

    $stmt = $pdo->prepare('DELETE FROM anpr_pratiche WHERE id = :id');
    $stmt->execute([':id' => $praticaId]);

    $pdo->commit();

    anpr_log_action($pdo, 'Pratica eliminata', 'Eliminata pratica ' . ($pratica['pratica_code'] ?? 'ID ' . $praticaId));
    add_flash('success', 'Pratica eliminata con successo.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ANPR delete failed: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile eliminare la pratica.');
}

header('Location: index.php');
exit;
