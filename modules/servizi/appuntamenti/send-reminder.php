<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';

use App\Services\AppointmentReminderService;
use Throwable;

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
    require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
$force = isset($_POST['force']) && $_POST['force'] === '1';

if ($id <= 0) {
    add_flash('warning', 'Appuntamento non valido.');
    header('Location: index.php');
    exit;
}

$logPath = __DIR__ . '/../../../backups/appointment_reminders.log';

try {
    $service = new AppointmentReminderService(
        $pdo,
        static function (string $to, string $subject, string $htmlBody): bool {
            return send_system_mail($to, $subject, $htmlBody);
        },
        $logPath
    );

    $result = $service->sendReminderNow($id, $force);
    add_flash('success', sprintf('Promemoria inviato al cliente (%s).', $result['email']));
} catch (Throwable $exception) {
    add_flash('warning', 'Invio promemoria non riuscito: ' . $exception->getMessage());
}

header('Location: view.php?id=' . $id);
exit;
