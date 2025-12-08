<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$opportunityId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($opportunityId <= 0 || $collaboratorId <= 0) {
    add_flash('warning', 'Opportunity non trovata.');
    header('Location: list.php');
    exit;
}

$opportunity = $opportunityService->findById($opportunityId);
if ($opportunity === null || (int) ($opportunity['collaborator_id'] ?? 0) !== $collaboratorId) {
    add_flash('warning', 'Non hai accesso a questa opportunity.');
    header('Location: list.php');
    exit;
}

$files = $opportunityService->listFiles($opportunityId);

$customerName = trim(sprintf('%s %s', (string) ($opportunity['customer_first_name'] ?? ''), (string) ($opportunity['customer_last_name'] ?? '')));
$statusColor = $opportunity['status_color'] ?? '';
$badgeClass = 'badge bg-secondary';
$colorToBootstrap = [
    'warning' => 'badge bg-warning text-dark',
    'info' => 'badge bg-info text-dark',
    'primary' => 'badge bg-primary',
    'danger' => 'badge bg-danger',
    'success' => 'badge bg-success',
];
if ($statusColor && isset($colorToBootstrap[$statusColor])) {
    $badgeClass = $colorToBootstrap[$statusColor];
}

$noteUrl = asset('modules/opportunities/collaborator/notes.php?id=' . $opportunityId);
$reminderUrl = asset('modules/opportunities/collaborator/reminder.php?id=' . $opportunityId);
$ticketUrl = asset('modules/opportunities/collaborator/ticket.php?id=' . $opportunityId);
$listUrl = asset('modules/opportunities/collaborator/list.php');
$canEdit = ($opportunity['status_code'] ?? '') === 'in_verifica';
$metadata = [];
if (!empty($opportunity['metadata'])) {
    $decodedMeta = json_decode((string) $opportunity['metadata'], true);
    if (is_array($decodedMeta)) {
        $metadata = $decodedMeta;
    }
}
$telefoniaContractType = strtolower((string) ($metadata['telefonia_contract_type'] ?? 'migrazione'));
$isTelefoniaMigration = ($opportunity['category'] ?? '') === 'telefonia' && $telefoniaContractType === 'migrazione';
$stripeIbanValidated = (($opportunity['payment_method'] ?? '') === 'iban') && !empty($metadata['payment_iban_stripe_pm_id'] ?? '');
$stripeIbanBank = (string) ($metadata['payment_iban_stripe_bank_code'] ?? '');
$stripeIbanLast4 = (string) ($metadata['payment_iban_stripe_last4'] ?? '');

$statusTimeline = [];
$createdAt = $opportunity['created_at'] ?? null;
if ($createdAt) {
    $statusTimeline[] = [
        'label' => 'Creata',
        'timestamp' => $createdAt,
    ];
}
$lastStatusChange = $opportunity['last_status_change'] ?? null;
if ($lastStatusChange && $lastStatusChange !== $createdAt) {
    $statusTimeline[] = [
        'label' => 'Ultimo cambio stato',
        'timestamp' => $lastStatusChange,
    ];
}
if (($opportunity['updated_at'] ?? null) && $opportunity['updated_at'] !== $lastStatusChange && $opportunity['updated_at'] !== $createdAt) {
    $statusTimeline[] = [
        'label' => 'Ultima modifica',
        'timestamp' => $opportunity['updated_at'],
    ];
}

$ticketSummaries = [];
if (!empty($opportunity['code'])) {
    try {
        $tagPattern = '%"opportunity_code:' . $opportunity['code'] . '"%';
        $ticketStmt = $pdo->prepare(
            'SELECT id, codice, subject, status, updated_at, last_message_at
             FROM tickets
             WHERE created_by = :creator
               AND tags LIKE :tag
             ORDER BY updated_at DESC
             LIMIT 3'
        );
        $ticketStmt->execute([
            ':creator' => $collaboratorId,
            ':tag' => $tagPattern,
        ]);
        $ticketSummaries = $ticketStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $exception) {
        $ticketSummaries = [];
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity #<?php echo (int) $opportunity['id']; ?></p>
                <h1 class="h4 mb-1"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></h1>
                <p class="text-muted mb-0">Categoria <?php echo sanitize_output(strtoupper((string) ($opportunity['category'] ?? ''))); ?> · Stato <?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($listUrl); ?>">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna alla lista
                </a>
                <?php if ($canEdit): ?>
                    <a class="btn btn-outline-warning" href="<?php echo sanitize_output(asset('modules/opportunities/collaborator/create.php?edit_id=' . $opportunityId)); ?>">
                        <i class="fa-solid fa-pen me-2"></i>Modifica dati
                    </a>
                <?php endif; ?>
                <a class="btn btn-outline-success" href="<?php echo sanitize_output($noteUrl); ?>">
                    <i class="fa-solid fa-note-sticky me-2"></i>Aggiungi nota
                </a>
                <a class="btn btn-warning text-white" href="<?php echo sanitize_output($reminderUrl); ?>">
                    <i class="fa-solid fa-bell me-2"></i>Sollecito operativo
                </a>
                <a class="btn btn-primary" href="<?php echo sanitize_output($ticketUrl); ?>">
                    <i class="fa-solid fa-ticket me-2"></i>Apri ticket
                </a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Cliente</p>
                                <h2 class="h5 mb-0"><?php echo sanitize_output($customerName ?: '—'); ?></h2>
                                <p class="text-muted mb-0"><?php echo sanitize_output($opportunity['customer_tax_code'] ?? ''); ?></p>
                            </div>
                            <div class="text-end">
                                <p class="text-uppercase small text-muted mb-1">Stato</p>
                                <span class="<?php echo $badgeClass; ?>"><?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?></span>
                                <p class="text-muted small mb-0 mt-2">Ultimo aggiornamento <?php echo sanitize_output(format_datetime_locale($opportunity['last_status_change'] ?? $opportunity['updated_at'] ?? $opportunity['created_at'] ?? null)); ?></p>
                            </div>
                        </div>
                        <hr>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Telefono</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_phone'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Email</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_email'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Indirizzo</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_address'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Città</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_city'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">CAP</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_postal_code'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Documento</p>
                                <p class="mb-0"><?php echo sanitize_output(($opportunity['document_type'] ?? '') . ' ' . ($opportunity['document_number'] ?? '')); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Gestore e offerta</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Gestore</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['provider_label'] ?? ''); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Offerta</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['offer_label'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Categoria</p>
                                <p class="mb-0 text-uppercase"><?php echo sanitize_output($opportunity['category'] ?? ''); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Provvigione stimata</p>
                                <p class="mb-0"><?php echo $opportunity['commission_amount'] !== null ? sanitize_output(number_format((float) $opportunity['commission_amount'], 2, ',', '.')) . ' €' : '—'; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dettagli contrattuali</h2>
                        <div class="row g-3">
                            <?php if (($opportunity['category'] ?? '') === 'telefonia'): ?>
                                    <div class="col-md-6">
                                        <p class="text-uppercase small text-muted mb-1">Tipologia contratto</p>
                                        <p class="mb-0"><?php echo $telefoniaContractType === 'migrazione' ? 'Migrazione' : 'Nuova attivazione'; ?></p>
                                    </div>
                                    <?php if ($isTelefoniaMigration) : ?>
                                        <div class="col-md-6">
                                            <p class="text-uppercase small text-muted mb-1">Operatore attuale</p>
                                            <p class="mb-0"><?php echo sanitize_output($opportunity['telefonia_current_operator'] ?? '—'); ?></p>
                                        </div>
                                        <div class="col-md-6">
                                            <p class="text-uppercase small text-muted mb-1">Numero linea</p>
                                            <p class="mb-0"><?php echo sanitize_output($opportunity['telefonia_line_number'] ?? '—'); ?></p>
                                        </div>
                                        <?php if (!empty($metadata['migration_code'])) : ?>
                                            <div class="col-md-6">
                                                <p class="text-uppercase small text-muted mb-1">Codice migrazione</p>
                                                <p class="mb-0"><?php echo sanitize_output($metadata['migration_code']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                            <?php endif; ?>
                            <?php if (($opportunity['category'] ?? '') === 'luce'): ?>
                                <div class="col-md-6">
                                    <p class="text-uppercase small text-muted mb-1">Codice POD</p>
                                    <p class="mb-0"><?php echo sanitize_output($opportunity['luce_pod'] ?? '—'); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (($opportunity['category'] ?? '') === 'gas'): ?>
                                <div class="col-md-6">
                                    <p class="text-uppercase small text-muted mb-1">Codice PDR</p>
                                    <p class="mb-0"><?php echo sanitize_output($opportunity['gas_pdr'] ?? '—'); ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Metodo pagamento</p>
                                <p class="mb-0 text-capitalize"><?php echo sanitize_output($opportunity['payment_method'] ?? ''); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">IBAN</p>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <p class="mb-0"><?php echo sanitize_output($opportunity['payment_iban'] ?? '—'); ?></p>
                                    <?php if ($stripeIbanValidated): ?>
                                        <?php
                                            $stripeDetails = [];
                                            if ($stripeIbanBank !== '') {
                                                $stripeDetails[] = 'banca ' . sanitize_output($stripeIbanBank);
                                            }
                                            if ($stripeIbanLast4 !== '') {
                                                $stripeDetails[] = '***' . sanitize_output($stripeIbanLast4);
                                            }
                                        ?>
                                        <span class="badge bg-success-subtle text-success" title="IBAN validato tramite Stripe">
                                            Validato<?php echo $stripeDetails ? ' (' . implode(' · ', $stripeDetails) . ')' : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-12">
                                <p class="text-uppercase small text-muted mb-1">Note collaboratore</p>
                                <p class="mb-0"><?php echo nl2br(sanitize_output($opportunity['additional_notes'] ?? 'Nessuna nota.'), false); ?></p>
                            </div>
                            <?php if (!empty($opportunity['admin_notes'])): ?>
                                <div class="col-12">
                                    <p class="text-uppercase small text-muted mb-1">Note interne</p>
                                    <p class="mb-0 text-muted"><?php echo nl2br(sanitize_output($opportunity['admin_notes']), false); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h6 text-uppercase text-muted mb-0">Allegati</h2>
                            <span class="badge bg-secondary"><?php echo count($files); ?></span>
                        </div>
                        <?php if (!$files): ?>
                            <p class="text-muted mb-0">Nessun file caricato.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($files as $file): ?>
                                    <?php $fileUrl = !empty($file['file_path']) ? asset($file['file_path']) : '#'; ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <p class="mb-0 fw-semibold"><?php echo sanitize_output($file['original_name'] ?? $file['stored_name']); ?></p>
                                            <p class="text-muted small mb-0">
                                                Caricato <?php echo sanitize_output(format_datetime_locale($file['created_at'] ?? null)); ?>
                                            </p>
                                        </div>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo $fileUrl; ?>" target="_blank" rel="noreferrer">
                                            <i class="fa-solid fa-download me-2"></i>Scarica
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Azioni rapide</h2>
                        <div class="d-grid gap-2">
                            <a class="btn btn-outline-success" href="<?php echo sanitize_output($noteUrl); ?>">
                                <i class="fa-solid fa-note-sticky me-2"></i>Scrivi una nota
                            </a>
                            <a class="btn btn-outline-warning" href="<?php echo sanitize_output($reminderUrl); ?>">
                                <i class="fa-solid fa-bell me-2"></i>Invia sollecito
                            </a>
                        </div>
                        <p class="text-muted small mb-0 mt-3">Le note vengono condivise con il team interno e compaiono anche nella vista amministratore.</p>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Codici contratto</h2>
                        <div class="d-flex flex-column gap-2">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Codice contratto</p>
                                <p class="mb-0 fw-semibold"><?php echo sanitize_output($opportunity['contract_code'] ?? '—'); ?></p>
                            </div>
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Codice cliente</p>
                                <p class="mb-0 fw-semibold"><?php echo sanitize_output($opportunity['client_code'] ?? '—'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Referenti</h2>
                        <p class="mb-1">Collaboratore: <?php echo sanitize_output(trim(($opportunity['collaborator_name'] ?? '') . ' ' . ($opportunity['collaborator_surname'] ?? '')) ?: 'Non indicato'); ?></p>
                        <p class="mb-1">Manager assegnato: <?php echo sanitize_output(trim(($opportunity['manager_name'] ?? '') . ' ' . ($opportunity['manager_surname'] ?? '')) ?: 'In attesa'); ?></p>
                        <p class="text-muted small mb-0">Inviata il <?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?></p>
                    </div>
                </div>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h6 text-uppercase text-muted mb-0">Ticket collegati</h2>
                            <span class="badge bg-secondary"><?php echo count($ticketSummaries); ?></span>
                        </div>
                        <?php if (!$ticketSummaries): ?>
                            <p class="text-muted mb-0">Nessun ticket aperto su questa opportunity.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($ticketSummaries as $ticket): ?>
                                    <?php
                                        $statusBadge = ticket_status_badge((string) ($ticket['status'] ?? 'OPEN'));
                                        $ticketLink = asset('modules/opportunities/collaborator/ticket-view.php?id=' . (int) ($ticket['id'] ?? 0));
                                    ?>
                                    <div class="border rounded-3 p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <a class="fw-semibold" href="<?php echo sanitize_output($ticketLink); ?>">Ticket #<?php echo sanitize_output($ticket['codice'] ?? $ticket['id'] ?? ''); ?></a>
                                            <span class="badge <?php echo $statusBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['status'] ?? 'OPEN'); ?></span>
                                        </div>
                                        <div class="text-muted small mb-1"><?php echo sanitize_output($ticket['subject'] ?? ''); ?></div>
                                        <div class="text-muted small">Aggiornato il <?php echo sanitize_output(format_datetime_locale($ticket['last_message_at'] ?? $ticket['updated_at'] ?? null)); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted mb-3">Timeline stato</h2>
                                <?php if ($statusTimeline): ?>
                                    <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                                        <?php foreach ($statusTimeline as $event): ?>
                                            <li class="d-flex align-items-start gap-2">
                                                <span class="badge bg-light text-muted border flex-shrink-0"><i class="fa-solid fa-clock me-1"></i><?php echo sanitize_output($event['label']); ?></span>
                                                <div class="small text-muted"><?php echo sanitize_output(format_datetime_locale($event['timestamp'] ?? null)); ?></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Nessuna informazione di timeline disponibile.</p>
                                <?php endif; ?>
                        <div class="card shadow-sm">
                            <div class="card-body">
                                <h2 class="h6 text-uppercase text-muted mb-3">Timeline stato</h2>
                                <?php if ($statusTimeline): ?>
                                    <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                                        <?php foreach ($statusTimeline as $event): ?>
                                            <li class="d-flex align-items-start gap-2">
                                                <span class="badge bg-light text-muted border flex-shrink-0"><i class="fa-solid fa-clock me-1"></i><?php echo sanitize_output($event['label']); ?></span>
                                                <div class="small text-muted"><?php echo sanitize_output(format_datetime_locale($event['timestamp'] ?? null)); ?></div>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Nessuna informazione di timeline disponibile.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
