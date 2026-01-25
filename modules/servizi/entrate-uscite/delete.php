<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/notifications.php';

require_role('Admin', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: index.php');
	exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
	header('Location: index.php');
	exit;
}

$stmt = $pdo->prepare('SELECT allegato_path, tipo_movimento, descrizione FROM entrate_uscite WHERE id = :id');
$stmt->execute([':id' => $id]);
$pagamento = $stmt->fetch();

if (!$pagamento) {
	header('Location: index.php?notfound=1');
	exit;
}

$pdo->beginTransaction();

try {
	$deleteStmt = $pdo->prepare('DELETE FROM entrate_uscite WHERE id = :id');
	$deleteStmt->execute([':id' => $id]);

	if (!empty($pagamento['allegato_path'])) {
		$filePath = public_path($pagamento['allegato_path']);
		if (is_file($filePath)) {
			@unlink($filePath);
		}
	}

	$pdo->commit();
	add_flash('success', 'Movimento eliminato correttamente.');
	$actorRole = (string) ($_SESSION['role'] ?? '');
	$actorId = (int) ($_SESSION['user_id'] ?? 0);
	$notification = [
		'type' => 'warning',
		'title' => 'Movimento eliminato',
		'message' => sprintf('Eliminato movimento #%d (%s).', $id, $pagamento['tipo_movimento'] ?? 'N/D'),
		'metadata' => [
			'entity' => 'entrate_uscite',
			'id' => $id,
			'action' => 'delete',
			'descrizione' => $pagamento['descrizione'] ?? null,
		],
	];
	foreach (['Admin', 'Manager'] as $notifyRole) {
		create_notification($pdo, array_merge($notification, ['scope' => 'role', 'role' => $notifyRole]), $actorId, $actorRole);
	}
} catch (Throwable $e) {
	$pdo->rollBack();
	error_log('Errore eliminazione movimento ID ' . $id . ': ' . $e->getMessage());
	add_flash('danger', "Impossibile eliminare il movimento. Riprova più tardi.");
}

header('Location: index.php');
exit;
