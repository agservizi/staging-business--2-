<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Dettaglio ticket';
$csrfToken = csrf_token();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$ticketStmt = $pdo->prepare('SELECT t.*, c.nome, c.cognome, c.email FROM ticket t LEFT JOIN clienti c ON t.cliente_id = c.id WHERE t.id = :id');
$ticketStmt->execute([':id' => $id]);
$ticket = $ticketStmt->fetch();
if (!$ticket) {
    header('Location: index.php?notfound=1');
    exit;
}

$statusOptions = ['Aperto', 'In corso', 'Chiuso'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'message') {
        $message = trim($_POST['messaggio'] ?? '');
        if ($message === '') {
            $errors[] = 'Il messaggio non può essere vuoto.';
        }

        $attachmentPath = null;
        if (!empty($_FILES['allegato']['name'])) {
            $file = $_FILES['allegato'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'doc', 'docx', 'xlsx', 'zip'], true)) {
                    $errors[] = 'Formato allegato non supportato.';
                } else {
                    $fileName = sprintf('ticket_%s.%s', uniqid('', true), $ext);
                    $destination = __DIR__ . '/../../assets/uploads/ticket/' . $fileName;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        $errors[] = 'Errore caricamento allegato.';
                    } else {
                        $attachmentPath = 'assets/uploads/ticket/' . $fileName;
                    }
                }
            } else {
                $errors[] = 'Errore caricamento allegato.';
            }
        }

        if (!$errors) {
            $insert = $pdo->prepare('INSERT INTO ticket_messaggi (ticket_id, utente_id, messaggio, allegato_path) VALUES (:ticket_id, :utente_id, :messaggio, :allegato_path)');
            $insert->execute([
                ':ticket_id' => $id,
                ':utente_id' => $_SESSION['user_id'],
                ':messaggio' => $message,
                ':allegato_path' => $attachmentPath,
            ]);
            if (!empty($ticket['email'])) {
                $baseUrl = env('APP_URL', sprintf('%s://%s', isset($_SERVER['HTTPS']) ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost'));
                $ticketLink = rtrim($baseUrl, '/') . '/modules/ticket/view.php?id=' . $id;
                $mailContent = sprintf('<p>Ciao %s,</p><p>Abbiamo aggiunto una nuova risposta al ticket <strong>#%d - %s</strong>.</p><p>Messaggio:</p><blockquote>%s</blockquote><p>Puoi seguire l\'avanzamento <a href="%s">a questo link</a>.</p>',
                    sanitize_output(trim(($ticket['cognome'] ?? '') . ' ' . ($ticket['nome'] ?? '')) ?: 'cliente'),
                    $ticket['id'],
                    sanitize_output($ticket['titolo']),
                    nl2br(sanitize_output($message)),
                    $ticketLink
                );
                send_system_mail($ticket['email'], 'Aggiornamento ticket #' . $ticket['id'], render_mail_template('Aggiornamento ticket', $mailContent));
            }
            add_flash('success', 'Risposta inviata.');
            header('Location: view.php?id=' . $id . '#messaggi');
            exit;
        }
    } elseif ($action === 'status' && in_array($_POST['stato'] ?? '', $statusOptions, true)) {
        $update = $pdo->prepare('UPDATE ticket SET stato = :stato WHERE id = :id');
        $update->execute([':stato' => $_POST['stato'], ':id' => $id]);
        if (!empty($ticket['email'])) {
            $baseUrl = env('APP_URL', sprintf('%s://%s', isset($_SERVER['HTTPS']) ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost'));
            $ticketLink = rtrim($baseUrl, '/') . '/modules/ticket/view.php?id=' . $id;
            $mailContent = sprintf('<p>Ciao,</p><p>Il ticket <strong>#%d - %s</strong> è passato allo stato <strong>%s</strong>.</p><p>Consulta i dettagli <a href="%s">qui</a>.</p>',
                $ticket['id'],
                sanitize_output($ticket['titolo']),
                sanitize_output($_POST['stato']),
                $ticketLink
            );
            send_system_mail($ticket['email'], 'Aggiornamento stato ticket #' . $ticket['id'], render_mail_template('Aggiornamento stato', $mailContent));
        }
        add_flash('success', 'Stato aggiornato.');
        header('Location: view.php?id=' . $id . '&updated=1');
        exit;
    }
}

$msgStmt = $pdo->prepare('SELECT tm.*, u.username FROM ticket_messaggi tm LEFT JOIN users u ON tm.utente_id = u.id WHERE tm.ticket_id = :ticket_id ORDER BY tm.created_at ASC');
$msgStmt->execute([':ticket_id' => $id]);
$messages = $msgStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Tutti i ticket</a>
            <div class="toolbar-actions">
                <form method="post" class="stack-sm align-items-center">
                    <input type="hidden" name="action" value="status">
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <label class="form-label mb-0" for="stato">Stato</label>
                    <select class="form-select" name="stato" id="stato">
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $ticket['stato'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-warning text-dark" type="submit">Aggiorna</button>
                </form>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Informazioni ticket</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">ID</dt>
                            <dd class="col-sm-7">#<?php echo (int) $ticket['id']; ?></dd>
                            <dt class="col-sm-5">Titolo</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output($ticket['titolo']); ?></dd>
                            <dt class="col-sm-5">Stato</dt>
                            <dd class="col-sm-7"><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($ticket['stato']); ?></span></dd>
                            <dt class="col-sm-5">Cliente</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output(trim(($ticket['cognome'] ?? '') . ' ' . ($ticket['nome'] ?? '')) ?: 'Interno'); ?></dd>
                            <dt class="col-sm-5">Creato</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output(date('d/m/Y H:i', strtotime($ticket['created_at']))); ?></dd>
                            <dt class="col-sm-5">Descrizione</dt>
                            <dd class="col-sm-7"><?php echo nl2br(sanitize_output($ticket['descrizione'])); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-8" id="messaggi">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Conversazione</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($errors): ?>
                            <div class="alert alert-warning mb-3"><?php echo implode('<br>', array_map('sanitize_output', $errors)); ?></div>
                        <?php endif; ?>
                        <?php foreach ($messages as $message): ?>
                            <div class="border rounded-3 p-3 mb-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?php echo sanitize_output($message['username'] ?? 'Utente'); ?></strong>
                                        <span class="text-muted small ms-2"><?php echo sanitize_output(date('d/m/Y H:i', strtotime($message['created_at']))); ?></span>
                                    </div>
                                    <?php if ($message['allegato_path']): ?>
                                        <a class="btn btn-sm btn-outline-warning" href="../../<?php echo sanitize_output($message['allegato_path']); ?>" target="_blank">Allegato</a>
                                    <?php endif; ?>
                                </div>
                                <p class="mb-0"><?php echo nl2br(sanitize_output($message['messaggio'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$messages): ?>
                            <p class="text-muted mb-0">Nessun messaggio ancora presente.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Aggiungi risposta</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" novalidate>
                            <input type="hidden" name="action" value="message">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="messaggio">Messaggio</label>
                                <textarea class="form-control" id="messaggio" name="messaggio" rows="4" required></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="allegato">Allegato (opzionale)</label>
                                <input class="form-control" id="allegato" name="allegato" type="file">
                            </div>
                            <div class="d-flex justify-content-end">
                                <button class="btn btn-warning text-dark" type="submit">Invia risposta</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
