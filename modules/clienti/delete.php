<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';

require_role('Admin', 'Manager', 'Operatore');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . clienti_module_url('index'));
    exit;
}

require_valid_csrf();

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    add_flash('danger', 'Richiesta non valida: impossibile individuare il cliente.');
    header('Location: ' . clienti_module_url('index'));
    exit;
}

$clientStmt = $pdo->prepare('SELECT ragione_sociale, nome, cognome FROM clienti WHERE id = :id');
$clientStmt->execute([':id' => $id]);
$client = $clientStmt->fetch();

if (!$client) {
    add_flash('danger', 'Il cliente selezionato non esiste più.');
    header('Location: ' . clienti_module_url('index'));
    exit;
}

$companyName = trim((string) ($client['ragione_sociale'] ?? ''));
$clientLabel = $companyName !== ''
    ? $companyName
    : trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''));
if ($clientLabel === '') {
    $clientLabel = 'Cliente';
}

if (!function_exists('client_module_table_exists')) {
    /**
     * Lightweight helper to detect optional tables before attempting cleanup queries.
     */
    function client_module_table_exists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1');
        $stmt->execute([':table' => $tableName]);
        $cache[$tableName] = (bool) $stmt->fetchColumn();
        return $cache[$tableName];
    }
}

try {
    $pdo->beginTransaction();
    $tables = [
        'entrate_uscite',
        'servizi_appuntamenti',
        'fedelta_movimenti',
        'curriculum_languages',
        'curriculum_skills',
        'curriculum_education',
        'curriculum_experiences',
        'curriculum',
        'spedizioni',
        'ticket',
        'documents',
    ];
    foreach ($tables as $table) {
        if (!client_module_table_exists($pdo, $table)) {
            continue;
        }
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE cliente_id = :id");
        $stmt->execute([':id' => $id]);
    }
    $deleteClient = $pdo->prepare('DELETE FROM clienti WHERE id = :id');
    $deleteClient->execute([':id' => $id]);

    if ($deleteClient->rowCount() === 0) {
        throw new RuntimeException('Cliente non trovato.');
    }

    $pdo->commit();
    $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
        VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
    $logStmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':modulo' => 'Clienti',
        ':azione' => 'Eliminazione cliente',
        ':dettagli' => sprintf('%s (#%d)', $clientLabel, $id),
    ]);

    add_flash('success', 'Cliente eliminato correttamente.');
    $actorRole = (string) ($_SESSION['role'] ?? '');
    $actorId = (int) ($_SESSION['user_id'] ?? 0);
    $notification = [
        'type' => 'warning',
        'title' => 'Cliente eliminato',
        'message' => sprintf('Eliminato cliente #%d (%s).', $id, $clientLabel),
        'metadata' => [
            'entity' => 'clienti',
            'id' => $id,
            'action' => 'delete',
        ],
    ];
    foreach (['Admin', 'Manager'] as $notifyRole) {
        create_notification($pdo, array_merge($notification, ['scope' => 'role', 'role' => $notifyRole]), $actorId, $actorRole);
    }
    header('Location: ' . clienti_module_url('index'));
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Client delete failed: ' . $e->getMessage());
    add_flash('danger', 'Impossibile eliminare il cliente. Riprova più tardi.');
    header('Location: ' . clienti_module_url('index'));
}
exit;
