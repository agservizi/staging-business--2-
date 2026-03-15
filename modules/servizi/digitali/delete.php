<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . digitali_module_url('index'));
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    add_flash('warning', 'Movimento non trovato.');
    header('Location: ' . digitali_module_url('index'));
    exit;
}

$movementStmt = $pdo->prepare('SELECT cliente_id FROM fedelta_movimenti WHERE id = :id');
$movementStmt->execute([':id' => $id]);
$clienteId = (int) $movementStmt->fetchColumn();

if ($clienteId <= 0) {
    add_flash('warning', 'Movimento non trovato.');
    header('Location: ' . digitali_module_url('index'));
    exit;
}

try {
    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM fedelta_movimenti WHERE id = :id');
    $deleteStmt->execute([':id' => $id]);

    recalculate_loyalty_balances($pdo, $clienteId);

    $pdo->commit();

    add_flash('success', 'Movimento fedeltà eliminato.');
    header('Location: ' . digitali_module_url('index', ['deleted' => 1]));
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Delete loyalty movement failed: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile eliminare il movimento. Riprova.');
    header('Location: ' . digitali_module_url('index', ['error' => 1]));
}
exit;
