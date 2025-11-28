<?php
declare(strict_types=1);

use Throwable;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Nuovo ticket';
$csrfToken = csrf_token();

$statusOptions = ['Aperto', 'In corso', 'Chiuso'];
$allowedAttachmentExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'doc', 'docx', 'xlsx', 'zip'];
$maxUploadSize = 10 * 1024 * 1024; // 10 MB

$clients = $pdo->query('SELECT id, nome, cognome, email FROM clienti ORDER BY cognome, nome')->fetchAll();
$clientIndex = [];
foreach ($clients as $client) {
    $clientIndex[(int) $client['id']] = $client;
}

function store_ticket_attachment(array $upload): string
{
    $targetDir = public_path('assets/uploads/ticket');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Impossibile preparare la cartella degli allegati.');
    }

    $fileName = 'ticket_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . $upload['original'];
    $destination = $targetDir . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
        throw new RuntimeException('Impossibile salvare l\'allegato del ticket appena creato.');
    }

    return 'assets/uploads/ticket/' . $fileName;
}

$data = [
    'cliente_id' => '',
    'titolo' => '',
    'descrizione' => '',
    'stato' => 'Aperto',
    'initial_message' => '',
    'notify_email' => '',
    'notify_now' => '1',
];

$errors = [];
$pendingUpload = null;
$selectedClient = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    foreach ($data as $field => $_) {
        if ($field === 'notify_now') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if ($data['cliente_id'] !== '') {
        $clientId = (int) $data['cliente_id'];
        if ($clientId <= 0 || !isset($clientIndex[$clientId])) {
            $errors[] = 'Seleziona un cliente valido oppure lascia "Ticket interno".';
        } else {
            $selectedClient = $clientIndex[$clientId];
        }
    }

    if ($data['titolo'] === '') {
        $errors[] = 'Inserisci un titolo per il ticket.';
    } elseif (mb_strlen($data['titolo']) > 180) {
        $errors[] = 'Il titolo non può superare 180 caratteri.';
    }

    if ($data['descrizione'] === '') {
        $errors[] = 'La descrizione è obbligatoria.';
    } elseif (mb_strlen($data['descrizione']) > 5000) {
        $errors[] = 'La descrizione non può superare 5000 caratteri.';
    }

    if (!in_array($data['stato'], $statusOptions, true)) {
        $errors[] = 'Lo stato selezionato non è valido.';
    }

    if ($data['notify_email'] !== '' && !filter_var($data['notify_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'indirizzo email inserito per la notifica non è valido.';
    }

    $initialMessage = $data['initial_message'] !== '' ? $data['initial_message'] : $data['descrizione'];
    if ($initialMessage !== '' && mb_strlen($initialMessage) > 5000) {
        $errors[] = 'Il messaggio iniziale non può superare 5000 caratteri.';
    }

    if (!empty($_FILES['initial_attachment']['name'])) {
        $file = $_FILES['initial_attachment'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Errore durante il caricamento dell\'allegato iniziale.';
        } elseif ($file['size'] > $maxUploadSize) {
            $errors[] = 'L\'allegato iniziale non può superare 10 MB.';
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedAttachmentExtensions, true)) {
                $errors[] = 'Formato allegato non supportato (consentiti: PDF, immagini, Office, ZIP).';
            } else {
                $pendingUpload = [
                    'tmp_name' => $file['tmp_name'],
                    'original' => sanitize_filename($file['name']),
                ];
            }
        }
    }

    $recipientEmail = '';
    if ($data['notify_now'] === '1') {
        $candidate = $data['notify_email'] !== '' ? $data['notify_email'] : (string) ($selectedClient['email'] ?? '');
        if ($candidate === '') {
            $errors[] = 'Per inviare subito una notifica serve un indirizzo email valido.';
        } elseif (!filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'L\'indirizzo email di notifica non è valido.';
        } else {
            $recipientEmail = $candidate;
        }
    } elseif ($data['notify_email'] !== '' && filter_var($data['notify_email'], FILTER_VALIDATE_EMAIL)) {
        $recipientEmail = $data['notify_email'];
    }

    if (!$errors) {
        $attachmentPath = null;
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO ticket (cliente_id, titolo, descrizione, stato, created_by) VALUES (:cliente_id, :titolo, :descrizione, :stato, :created_by)');
            $stmt->execute([
                ':cliente_id' => $selectedClient['id'] ?? null,
                ':titolo' => $data['titolo'],
                ':descrizione' => $data['descrizione'],
                ':stato' => $data['stato'],
                ':created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);

            $ticketId = (int) $pdo->lastInsertId();

            if ($pendingUpload !== null) {
                $attachmentPath = store_ticket_attachment($pendingUpload);
            }

            if ($initialMessage !== '' || $attachmentPath !== null) {
                $msgStmt = $pdo->prepare('INSERT INTO ticket_messaggi (ticket_id, utente_id, messaggio, allegato_path) VALUES (:ticket_id, :utente_id, :messaggio, :allegato_path)');
                $msgStmt->execute([
                    ':ticket_id' => $ticketId,
                    ':utente_id' => (int) ($_SESSION['user_id'] ?? 0),
                    ':messaggio' => $initialMessage,
                    ':allegato_path' => $attachmentPath,
                ]);
            }

            $pdo->commit();

            $notificationSent = false;
            if ($recipientEmail !== '') {
                $ticketLink = base_url('modules/ticket/view.php?id=' . $ticketId);
                $emailBody = '<p>Ciao,</p>'
                    . '<p>Abbiamo aperto il ticket <strong>#' . $ticketId . ' - ' . sanitize_output($data['titolo']) . '</strong>.</p>'
                    . '<p>' . nl2br(sanitize_output($initialMessage)) . '</p>'
                    . '<p>Segui lo stato dal <a href="' . $ticketLink . '">portale assistenza</a>.</p>';
                $notificationSent = send_system_mail($recipientEmail, 'Nuovo ticket #' . $ticketId, render_mail_template('Ticket aperto', $emailBody));
            }

            $flashMessage = 'Ticket #' . $ticketId . ' creato correttamente.';
            if ($notificationSent) {
                $flashMessage .= ' Notifica inviata a ' . sanitize_output($recipientEmail) . '.';
            }
            add_flash('success', $flashMessage);
            header('Location: view.php?id=' . $ticketId . '&created=1');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($attachmentPath !== null) {
                $absolute = public_path($attachmentPath);
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }
            error_log('Ticket create failed: ' . $exception->getMessage());
            $errors[] = 'Si è verificato un errore durante il salvataggio del ticket. Riprova più tardi.';
        }
    }
}

if ($selectedClient === null && $data['cliente_id'] !== '') {
    $clientId = (int) $data['cliente_id'];
    if ($clientId > 0 && isset($clientIndex[$clientId])) {
        $selectedClient = $clientIndex[$clientId];
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex flex-wrap gap-3 justify-content-between align-items-start">
            <div>
                <h1 class="h3 mb-1">Nuovo ticket di assistenza</h1>
                <p class="text-muted mb-0">Raccogli tutte le informazioni utili e avvia subito il thread operativo.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Tutti i ticket</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-warning mb-4">
                <p class="fw-semibold mb-2">Correggi i seguenti errori:</p>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="row g-4" novalidate>
            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <div class="col-12 col-xxl-8">
                <div class="d-flex flex-column gap-4">
                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <p class="text-muted text-uppercase small mb-1">Richiedente e canale</p>
                                <h2 class="h5 mb-0">Informazioni di contatto</h2>
                            </div>
                            <span class="badge bg-warning text-white">Step 1</span>
                        </div>
                        <div class="card-body">
                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label" for="cliente_id">Cliente (opzionale)</label>
                                    <select class="form-select" id="cliente_id" name="cliente_id">
                                        <option value="">Ticket interno</option>
                                        <?php foreach ($clients as $client): ?>
                                            <option value="<?php echo (int) $client['id']; ?>" <?php echo ((int) $data['cliente_id'] === (int) $client['id']) ? 'selected' : ''; ?>>
                                                <?php echo sanitize_output(trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')) ?: 'Cliente senza nome'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="stato">Stato iniziale</label>
                                    <select class="form-select" id="stato" name="stato">
                                        <?php foreach ($statusOptions as $status): ?>
                                            <option value="<?php echo $status; ?>" <?php echo $data['stato'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="notify_email">Email per notifica</label>
                                    <input class="form-control" id="notify_email" name="notify_email" type="email" placeholder="es. cliente@azienda.it" value="<?php echo sanitize_output($data['notify_email']); ?>">
                                    <small class="text-muted">Se vuoto useremo l'email del cliente (se presente).</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Invia subito notifica</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="notify_now" name="notify_now" value="1" <?php echo $data['notify_now'] === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notify_now">Invia email di apertura al cliente</label>
                                    </div>
                                    <small class="text-muted">Disattiva per inviare manualmente in un secondo momento.</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0">
                            <p class="text-muted text-uppercase small mb-1">Dettagli ticket</p>
                            <h2 class="h5 mb-0">Descrizione e contesto</h2>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="titolo">Titolo <span class="text-warning">*</span></label>
                                <input class="form-control" id="titolo" name="titolo" maxlength="180" value="<?php echo sanitize_output($data['titolo']); ?>" required>
                            </div>
                            <div class="mb-0">
                                <label class="form-label" for="descrizione">Descrizione <span class="text-warning">*</span></label>
                                <textarea class="form-control" id="descrizione" name="descrizione" rows="6" maxlength="5000" placeholder="Descrivi la richiesta, includendo riferimenti utili." required><?php echo sanitize_output($data['descrizione']); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-3">
                            <div>
                                <p class="text-muted text-uppercase small mb-1">Conversazione</p>
                                <h2 class="h5 mb-0">Messaggio iniziale e allegati</h2>
                            </div>
                            <span class="badge bg-secondary-subtle text-body">PDF · Immagini · ZIP</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="initial_message">Messaggio al cliente</label>
                                <textarea class="form-control" id="initial_message" name="initial_message" rows="4" maxlength="5000" placeholder="Lascia vuoto per riutilizzare la descrizione."><?php echo sanitize_output($data['initial_message']); ?></textarea>
                            </div>
                            <div class="mb-4">
                                <label class="form-label" for="initial_attachment">Allegato iniziale (opzionale)</label>
                                <input class="form-control" id="initial_attachment" name="initial_attachment" type="file" accept=".pdf,.png,.jpg,.jpeg,.gif,.doc,.docx,.xlsx,.zip">
                                <small class="text-muted">Max 10 MB. Verrà aggiunto alla conversazione.</small>
                            </div>
                            <div class="d-flex flex-wrap justify-content-end gap-2">
                                <a class="btn btn-outline-secondary" href="index.php">Annulla</a>
                                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Apri ticket</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xxl-4">
                <div class="d-flex flex-column gap-4">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <p class="text-muted text-uppercase small mb-2">Cliente selezionato</p>
                            <?php if ($selectedClient): ?>
                                <div class="mb-1 fw-semibold"><?php echo sanitize_output(trim(($selectedClient['cognome'] ?? '') . ' ' . ($selectedClient['nome'] ?? '')) ?: 'Cliente'); ?></div>
                                <?php if (!empty($selectedClient['email'])): ?>
                                    <div class="small text-muted">Email: <?php echo sanitize_output($selectedClient['email']); ?></div>
                                <?php else: ?>
                                    <div class="small text-muted">Nessuna email registrata</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <p class="small text-muted mb-0">Seleziona un cliente per visualizzare recapiti e velocizzare le notifiche.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-body">
                            <p class="text-muted text-uppercase small mb-2">Checklist apertura</p>
                            <ul class="list-unstyled small mb-0">
                                <li class="d-flex align-items-start gap-2 mb-2">
                                    <i class="fa-solid fa-circle-check text-success mt-1"></i>
                                    Indica sempre canale, urgenza e riferimenti ordine.
                                </li>
                                <li class="d-flex align-items-start gap-2 mb-2">
                                    <i class="fa-solid fa-circle-check text-success mt-1"></i>
                                    Allegati leggibili aiutano il team a rispondere più rapidamente.
                                </li>
                                <li class="d-flex align-items-start gap-2">
                                    <i class="fa-solid fa-circle-check text-success mt-1"></i>
                                    Usa il messaggio iniziale per riassumere le azioni già svolte.
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-body">
                            <p class="text-muted text-uppercase small mb-2">Supporto rapido</p>
                            <p class="small mb-1">Per escalation urgenti contatta:</p>
                            <div class="small">
                                <div class="fw-semibold">Helpdesk</div>
                                <div><a class="link-warning" href="mailto:support@coresuite.it">support@coresuite.it</a></div>
                                <div><a class="link-warning" href="tel:+390812345600">081 234 5600</a></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
