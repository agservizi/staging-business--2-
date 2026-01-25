<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/notifications.php';
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
$actorRole = (string) ($_SESSION['role'] ?? '');
$actorId = (int) ($_SESSION['user_id'] ?? 0);
$notification = [
    'type' => 'warning',
    'title' => 'Pratica ACI eliminata',
    'message' => sprintf('Eliminata pratica ACI #%d (%s).', $praticaId, $pratica['tipo_pratica'] ?? 'N/D'),
    'metadata' => [
        'entity' => 'servizi_aci_pratiche',
        'id' => $praticaId,
        'action' => 'delete',
    ],
];
foreach (['Admin', 'Manager'] as $notifyRole) {
    create_notification($pdo, array_merge($notification, ['scope' => 'role', 'role' => $notifyRole]), $actorId, $actorRole);
}
header('Location: index.php');
exit;
