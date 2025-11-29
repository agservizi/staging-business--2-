<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/ticket_functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Support');

$ticketId = (int) ($_GET['id'] ?? 0);
if ($ticketId <= 0) {
    header('Location: index.php');
    exit;
}

$ticket = ticket_find($pdo, $ticketId);
if (!$ticket) {
    header('Location: index.php?ticket_not_found=1');
    exit;
}

$messages = ticket_messages($pdo, $ticketId);
$statusOptions = ticket_status_options();
$priorityOptions = ticket_priority_options();
$agents = ticket_assignments($pdo);
$csrfToken = csrf_token();
$pageTitle = 'Ticket #' . sanitize_output((string) ($ticket['codice'] ?? $ticket['id']));

$tags = [];
if (!empty($ticket['tags'])) {
    $decoded = json_decode((string) $ticket['tags'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $tags = array_filter(array_map('trim', $decoded));
    }
}

$creatorLabel = trim((string) (($ticket['creator_lastname'] ?? '') . ' ' . ($ticket['creator_name'] ?? '')));
if ($creatorLabel === '') {
    $creatorLabel = (string) ($ticket['creator_username'] ?? 'Sistema');
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100" data-ticket-id="<?php echo (int) $ticketId; ?>" data-ticket-csrf="<?php echo sanitize_output($csrfToken); ?>" data-ticket-base="/modules/ticket">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <a class="btn btn-outline-secondary btn-sm mb-2" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Tutti i ticket</a>
                <h1 class="h3 mb-1">Ticket #<?php echo sanitize_output($ticket['codice'] ?? $ticket['id']); ?></h1>
                <p class="text-muted mb-0">Creato il <?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $ticket['created_at']))); ?> · Ultimo aggiornamento <?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $ticket['updated_at']))); ?></p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <button class="btn btn-soft-secondary" type="button" data-ticket-action="copy-link">
                    <i class="fa-solid fa-link me-2"></i>Copia link
                </button>
                <button class="btn btn-danger" type="button" data-ticket-action="archive">
                    <i class="fa-solid fa-box-archive me-2"></i>Archivia
                </button>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-4">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Stato e SLA</h2>
                        <span class="badge <?php echo ticket_status_badge((string) $ticket['status']); ?> text-uppercase"><?php echo sanitize_output($ticket['status']); ?></span>
                    </div>
                    <div class="card-body">
                        <form id="ticket-status-form" class="row g-3" data-ticket-form="status">
                            <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>">
                            <div class="col-12">
                                <label class="form-label" for="status">Stato</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach ($statusOptions as $value => $label): ?>
                                        <option value="<?php echo sanitize_output($value); ?>" <?php echo $ticket['status'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="priority">Priorità</label>
                                <select class="form-select" id="priority" name="priority">
                                    <?php foreach ($priorityOptions as $value => $label): ?>
                                        <option value="<?php echo sanitize_output($value); ?>" <?php echo $ticket['priority'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="assigned_to">Assegnato a</label>
                                <select class="form-select" id="assigned_to" name="assigned_to">
                                    <option value="">Team ticket</option>
                                    <?php foreach ($agents as $agent): ?>
                                        <?php $label = trim(($agent['cognome'] ?? '') . ' ' . ($agent['nome'] ?? '') . ' · ' . ($agent['username'] ?? '')); ?>
                                        <option value="<?php echo (int) $agent['id']; ?>" <?php echo (int) ($ticket['assigned_to'] ?? 0) === (int) $agent['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="sla_due_at">Scadenza SLA</label>
                                <input type="datetime-local" class="form-control" id="sla_due_at" name="sla_due_at" value="<?php echo $ticket['sla_due_at'] ? sanitize_output(date('Y-m-d\TH:i', strtotime((string) $ticket['sla_due_at']))) : ''; ?>">
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary w-100" type="submit">Aggiorna</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Cliente</h2>
                    </div>
                    <div class="card-body">
                        <p class="fw-semibold mb-1"><?php echo sanitize_output($ticket['customer_name'] ?? $ticket['company_name'] ?? 'Cliente non specificato'); ?></p>
                        <?php if (!empty($ticket['customer_email'])): ?>
                            <p class="mb-1"><i class="fa-solid fa-envelope me-2 text-warning"></i><a href="mailto:<?php echo sanitize_output($ticket['customer_email']); ?>" class="link-light"><?php echo sanitize_output($ticket['customer_email']); ?></a></p>
                        <?php endif; ?>
                        <?php if (!empty($ticket['customer_phone'])): ?>
                            <p class="mb-0"><i class="fa-solid fa-phone me-2 text-warning"></i><a href="tel:<?php echo sanitize_output($ticket['customer_phone']); ?>" class="link-light"><?php echo sanitize_output($ticket['customer_phone']); ?></a></p>
                        <?php endif; ?>
                        <hr>
                        <p class="text-muted small mb-0">Creato da <?php echo sanitize_output($creatorLabel); ?></p>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Tag</h2>
                        <button class="btn btn-sm btn-soft-secondary" type="button" data-ticket-action="edit-tags">
                            <i class="fa-solid fa-pen"></i>
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 <?php echo $tags ? '' : 'd-none'; ?>" id="ticket-tags">
                            <?php foreach ($tags as $tag): ?>
                                <span class="badge bg-dark text-uppercase"><?php echo sanitize_output($tag); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-muted mb-0 <?php echo $tags ? 'd-none' : ''; ?>" id="ticket-tags-empty">Nessun tag impostato.</p>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-8">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Conversazione</h2>
                    </div>
                    <div class="card-body" id="ticket-thread">
                        <?php if (!$messages): ?>
                            <p class="text-muted mb-0">Nessun messaggio registrato. Aggiungi una nota per iniziare la conversazione.</p>
                        <?php endif; ?>
                        <?php foreach ($messages as $message): ?>
                            <?php
                                $isInternal = !empty($message['is_internal']);
                                $messageVisibility = $isInternal ? 'Nota interna' : 'Cliente';
                                $attachments = json_decode((string) ($message['attachments'] ?? '[]'), true);
                                $attachments = is_array($attachments) ? $attachments : [];
                            ?>
                            <article class="border rounded-3 p-3 mb-3" data-ticket-message-id="<?php echo (int) $message['id']; ?>">
                                <header class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?php echo sanitize_output($message['author_name'] ?? 'Operatore'); ?></strong>
                                        <span class="badge <?php echo $isInternal ? 'bg-secondary' : 'bg-primary'; ?> ms-2"><?php echo $isInternal ? 'Interno' : 'Cliente'; ?></span>
                                        <span class="text-muted small ms-2"><?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $message['created_at']))); ?></span>
                                    </div>
                                    <div class="text-end small text-uppercase text-muted">
                                        Stato: <?php echo sanitize_output($message['status_snapshot']); ?>
                                    </div>
                                </header>
                                <p class="mb-2"><?php echo nl2br(sanitize_output($message['body'])); ?></p>
                                <?php if ($attachments): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($attachments as $attachment): ?>
                                            <a class="btn btn-outline-warning btn-sm" href="/<?php echo sanitize_output(ltrim((string) $attachment, '/')); ?>" target="_blank">
                                                <i class="fa-solid fa-paperclip me-1"></i>Allegato
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card ag-card" id="ticket-reply-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Aggiungi aggiornamento</h2>
                    </div>
                    <div class="card-body">
                        <form id="ticket-message-form" enctype="multipart/form-data" data-ticket-form="message">
                            <input type="hidden" name="ticket_id" value="<?php echo (int) $ticketId; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="message-body">Messaggio</label>
                                <textarea class="form-control" id="message-body" name="body" rows="5" required placeholder="Scrivi un aggiornamento per il cliente o una nota interna..."></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="message-attachments">Allegati</label>
                                <input class="form-control" id="message-attachments" name="attachments[]" type="file" multiple>
                                <small class="text-muted">Max 10MB per file, formati supportati: pdf, docx, immagini, zip.</small>
                            </div>
                            <div class="row g-3 mb-3">
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="message-internal" name="is_internal">
                                        <label class="form-check-label" for="message-internal">Nota interna</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="message-notify-client" name="notify_client" checked>
                                        <label class="form-check-label" for="message-notify-client">Notifica cliente</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="message-notify-admin" name="notify_admin" checked>
                                        <label class="form-check-label" for="message-notify-admin">Notifica team</label>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script src="<?php echo asset('assets/js/ticket.js'); ?>" defer></script>
