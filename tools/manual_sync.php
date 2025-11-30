<?php
require_once __DIR__ . '/../includes/db_connect.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    echo "Specificare un ID valido\n";
    exit(1);
}

$stmt = $pdo->prepare('SELECT sa.*, c.email AS cliente_email, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.ragione_sociale AS cliente_ragione_sociale FROM servizi_appuntamenti sa LEFT JOIN clienti c ON c.id = sa.cliente_id WHERE sa.id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$appointment = $stmt->fetch();

if (!$appointment) {
    echo "Appuntamento non trovato\n";
    exit(1);
}

$service = new \App\Services\GoogleCalendarService();
if (!$service->isEnabled()) {
    echo "Servizio Google Calendar non abilitato\n";
    exit(1);
}

try {
    $result = $service->syncAppointment($appointment);
    var_export($result);
} catch (Throwable $exception) {
    echo 'Errore: ' . $exception->getMessage() . "\n";
    exit(1);
}
