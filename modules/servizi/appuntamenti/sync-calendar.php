<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

use App\Services\GoogleCalendarService;

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    add_flash('warning', 'Appuntamento non valido.');
    header('Location: index.php');
    exit;
}

$calendarService = new GoogleCalendarService();
if (!$calendarService->isEnabled()) {
    add_flash('warning', 'Integrazione Google Calendar disabilitata.');
    header('Location: view.php?id=' . $id);
    exit;
}

$statusConfig = get_appointment_status_config($pdo);
$confirmationStatus = trim((string) $statusConfig['confirmation']);
if ($confirmationStatus === '') {
    add_flash('warning', 'Configura uno stato "confermato" nelle impostazioni prima di sincronizzare.');
    header('Location: view.php?id=' . $id);
    exit;
}

$stmt = $pdo->prepare('SELECT sa.*, c.email AS cliente_email, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale FROM servizi_appuntamenti sa LEFT JOIN clienti c ON c.id = sa.cliente_id WHERE sa.id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    add_flash('warning', 'Appuntamento non trovato.');
    header('Location: index.php');
    exit;
}

if (strcasecmp(trim((string) ($appointment['stato'] ?? '')), $confirmationStatus) !== 0) {
    add_flash('warning', 'Sincronizza l\'appuntamento solo dopo averlo impostato come "' . $confirmationStatus . '".');
    header('Location: view.php?id=' . $id);
    exit;
}

try {
    $syncResult = $calendarService->syncAppointment($appointment);

    $update = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_id = :event_id, google_event_synced_at = :synced_at, google_event_sync_error = NULL WHERE id = :id');
    $update->execute([
        ':event_id' => $syncResult['eventId'],
        ':synced_at' => $syncResult['syncedAt']->format('Y-m-d H:i:s'),
        ':id' => $id,
    ]);

    add_flash('success', 'Appuntamento sincronizzato su Google Calendar.');
} catch (Throwable $exception) {
    $errorMessage = substr($exception->getMessage(), 0, 240);
    $errorUpdate = $pdo->prepare('UPDATE servizi_appuntamenti SET google_event_sync_error = :error WHERE id = :id');
    $errorUpdate->execute([
        ':error' => $errorMessage,
        ':id' => $id,
    ]);

    add_flash('warning', 'Sincronizzazione Google Calendar non riuscita: ' . $errorMessage);
}

header('Location: view.php?id=' . $id);
exit;
