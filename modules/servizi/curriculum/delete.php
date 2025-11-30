<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore');
require_valid_csrf();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    add_flash('warning', 'Curriculum non valido.');
    header('Location: index.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT generated_file FROM curriculum WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $curriculum = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$curriculum) {
        $pdo->rollBack();
        add_flash('warning', 'Curriculum non trovato.');
        header('Location: index.php');
        exit;
    }

    $delete = $pdo->prepare('DELETE FROM curriculum WHERE id = :id');
    $delete->execute([':id' => $id]);

    $pdo->commit();

    if (!empty($curriculum['generated_file'])) {
        $filePath = public_path($curriculum['generated_file']);
        if (is_file($filePath)) {
            @unlink($filePath);
        }
    }

    add_flash('success', 'Curriculum eliminato correttamente.');
    header('Location: index.php');
    exit;
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Curriculum delete failed: ' . $exception->getMessage());
    add_flash('warning', 'Errore durante l\'eliminazione del curriculum.');
    header('Location: index.php');
    exit;
}
