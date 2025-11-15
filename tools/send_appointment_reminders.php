<?php
declare(strict_types=1);

use App\Services\AppointmentReminderService;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/mailer.php';

$options = function_exists('getopt') ? getopt('', ['grace::', 'dry-run', 'help']) : [];
if (isset($options['help'])) {
    echo "Invio automatico promemoria appuntamenti\n";
    echo "Uso: php tools/send_appointment_reminders.php [--grace=MINUTI] [--dry-run]\n";
    echo "  --grace    Finestra in minuti per intercettare i promemoria (default 30).\n";
    echo "  --dry-run  Non invia email, mostra soltanto cosa verrebbe spedito.\n";
    exit(0);
}

$graceMinutes = 30;
if (isset($options['grace'])) {
    $graceMinutes = (int) $options['grace'];
}
$graceMinutes = max(1, min($graceMinutes, 720));

$dryRun = isset($options['dry-run']);

$logFile = __DIR__ . '/../backups/appointment_reminders.log';

$service = new AppointmentReminderService(
    $pdo,
    static function (string $to, string $subject, string $htmlBody): bool {
        return send_system_mail($to, $subject, $htmlBody);
    },
    $logFile
);

$result = $service->dispatch($graceMinutes, $dryRun);

echo "Promemoria appuntamenti\n";
echo "======================\n";
printf("Appuntamenti trovati: %d\n", $result['total']);
printf("Promemoria inviati: %d\n", $result['sent']);
printf("Promemoria rimasti (dry-run o fuori finestra): %d\n", $result['skipped']);
printf("Errori: %d\n", $result['errors']);

if ($dryRun) {
    echo "Modalità dry-run attiva: nessuna email è stata inviata.\n";
}

if ($result['errors'] > 0) {
    exit(2);
}

exit(0);
