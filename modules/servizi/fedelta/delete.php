<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    add_flash('warning', 'Movimento non trovato.');
    header('Location: index.php');
    exit;
}

$movementStmt = $pdo->prepare('SELECT cliente_id FROM fedelta_movimenti WHERE id = :id');
$movementStmt->execute([':id' => $id]);
$clienteId = (int) $movementStmt->fetchColumn();

if ($clienteId <= 0) {
    add_flash('warning', 'Movimento non trovato.');
    header('Location: index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM fedelta_movimenti WHERE id = :id');
    $deleteStmt->execute([':id' => $id]);

    recalculate_loyalty_balances($pdo, $clienteId);

    $pdo->commit();

    try {
        $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'] ?? null,
            ':modulo' => 'Programma Fedeltà',
            ':azione' => 'Eliminazione movimento',
            ':dettagli' => sprintf('Movimento #%d cancellato per cliente #%d', $id, $clienteId),
        ]);
    } catch (Throwable $logException) {
        error_log('Loyalty movement log failure (delete): ' . $logException->getMessage());
    }

    add_flash('success', 'Movimento fedeltà eliminato.');
    header('Location: index.php?deleted=1');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Delete loyalty movement failed: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile eliminare il movimento. Riprova.');
    header('Location: index.php?error=1');
}
exit;
