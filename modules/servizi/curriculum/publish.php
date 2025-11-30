<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

use App\Services\Curriculum\CurriculumBuilderService;

require_role('Admin', 'Operatore', 'Manager');
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

$stmt = $pdo->prepare('SELECT id FROM curriculum WHERE id = :id');
$stmt->execute([':id' => $id]);
if (!$stmt->fetchColumn()) {
    add_flash('warning', 'Curriculum non trovato.');
    header('Location: index.php');
    exit;
}

try {
    $builder = new CurriculumBuilderService($pdo, realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../');
    $result = $builder->buildEuropass($id);

    $update = $pdo->prepare('UPDATE curriculum SET generated_file = :generated_file, last_generated_at = :generated_at, status = CASE WHEN status = "Archiviato" THEN status ELSE "Pubblicato" END WHERE id = :id');
    $update->execute([
        ':generated_file' => $result['relative_path'],
        ':generated_at' => $result['generated_at'],
        ':id' => $id,
    ]);

    add_flash('success', 'Curriculum generato correttamente.');
    header('Location: view.php?id=' . $id);
    exit;
} catch (Throwable $exception) {
    error_log('Curriculum publish failed: ' . $exception->getMessage());
    add_flash('warning', 'Impossibile generare il PDF Europass. Riprovare.');
    header('Location: view.php?id=' . $id);
    exit;
}
