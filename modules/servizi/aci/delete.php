<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin');

$praticaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = aci_get_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

$attachments = aci_get_attachments($pdo, $praticaId);

$pdo->prepare('DELETE FROM servizi_aci_allegati WHERE pratica_id = :pratica_id')->execute([':pratica_id' => $praticaId]);
$pdo->prepare('DELETE FROM servizi_aci_pratiche WHERE id = :id')->execute([':id' => $praticaId]);

aci_delete_attachment_files($attachments);

add_flash('success', 'Pratica eliminata correttamente.');
header('Location: index.php');
exit;
