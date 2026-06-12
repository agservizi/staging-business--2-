<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

$token = trim((string) ($_GET['token'] ?? ''));
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

if ($token === '' || !in_array($action, ['confirm', 'reschedule'], true)) {
    http_response_code(400);
    echo 'Link non valido.';
    exit;
}

$parts = explode('.', $token, 2);
if (count($parts) !== 2) {
    http_response_code(400);
    echo 'Token non valido.';
    exit;
}

$appointmentId = (int) $parts[0];
$signature = $parts[1];
$secret = env('APP_KEY', env('CAF_PATRONATO_ENCRYPTION_KEY', 'coresuite'));
$expected = hash_hmac('sha256', (string) $appointmentId . ':' . $action, (string) $secret);

if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo 'Token scaduto o non autorizzato.';
    exit;
}

$stmt = $pdo->prepare('SELECT sa.*, c.email FROM servizi_appuntamenti sa LEFT JOIN clienti c ON c.id = sa.cliente_id WHERE sa.id = :id LIMIT 1');
$stmt->execute([':id' => $appointmentId]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$appointment) {
    http_response_code(404);
    echo 'Appuntamento non trovato.';
    exit;
}

if ($action === 'confirm') {
    $update = $pdo->prepare("UPDATE servizi_appuntamenti SET stato = 'Confermato', updated_at = NOW() WHERE id = :id");
    $update->execute([':id' => $appointmentId]);
    $message = 'Appuntamento confermato. Ti aspettiamo il ' . date('d/m/Y H:i', strtotime((string) $appointment['data_inizio']));
} else {
    $rescheduleUrl = base_url('modules/servizi/appuntamenti/view.php?id=' . $appointmentId);
    $message = 'Per riprogrammare contatta la sede o visita: ' . $rescheduleUrl;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appuntamento Coresuite</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm mx-auto" style="max-width: 520px;">
        <div class="card-body p-4">
            <h1 class="h4 mb-3">Gestione appuntamento</h1>
            <p class="mb-0"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        </div>
    </div>
</div>
</body>
</html>
