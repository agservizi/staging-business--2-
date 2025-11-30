<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

use App\Services\GoogleCalendarService;
use PDO;
use Throwable;

require_role('Admin', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php?error=1');
    exit;
}

try {
    $calendarService = new GoogleCalendarService();
    $appointment = null;
    if ($calendarService->isEnabled()) {
        $fetchStmt = $pdo->prepare('SELECT google_event_id FROM servizi_appuntamenti WHERE id = :id LIMIT 1');
        $fetchStmt->execute([':id' => $id]);
        $appointment = $fetchStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($appointment && !empty($appointment['google_event_id'])) {
            try {
                $calendarService->removeAppointmentEvent($appointment);
            } catch (Throwable $calendarException) {
                add_flash('warning', 'Rimozione evento da Google Calendar non riuscita: ' . $calendarException->getMessage());
            }
        }
    }

    $stmt = $pdo->prepare('DELETE FROM servizi_appuntamenti WHERE id = :id');
    $stmt->execute([':id' => $id]);
    header('Location: index.php?deleted=1');
} catch (PDOException $e) {
    error_log('Delete appointment failed: ' . $e->getMessage());
    header('Location: index.php?error=1');
}
exit;
