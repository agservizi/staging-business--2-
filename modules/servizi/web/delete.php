<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$projectId = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($projectId <= 0) {
    add_flash('warning', 'Progetto non valido.');
    header('Location: index.php');
    exit;
}

$project = servizi_web_fetch_project($pdo, $projectId);
if (!$project) {
    add_flash('warning', 'Il progetto è già stato rimosso.');
    header('Location: index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $delete = $pdo->prepare('DELETE FROM servizi_web_progetti WHERE id = :id');
    $delete->execute([':id' => $projectId]);

    if (!empty($project['allegato_path'])) {
        servizi_web_delete_attachment($project['allegato_path']);
    }
    servizi_web_cleanup_project_storage($projectId);

    servizi_web_log_action($pdo, 'delete', 'Eliminato progetto ' . ($project['codice'] ?? ('ID ' . $projectId)));

    $pdo->commit();

    add_flash('success', 'Progetto digitale eliminato correttamente.');
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Servizi web delete failed: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile eliminare il progetto selezionato.');
}

header('Location: index.php');
exit;
