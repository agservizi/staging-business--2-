<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$taxCodeParam = isset($_GET['tax_code']) ? strtoupper(trim((string) $_GET['tax_code'])) : '';
if ($taxCodeParam === '') {
    add_flash('warning', 'Cliente non trovato.');
    header('Location: ' . opportunities_collaborator_url('customers'));
    exit;
}

$customerData = $opportunityService->findCollaboratorCustomer($collaboratorId, $taxCodeParam);
if ($customerData === null) {
    add_flash('warning', 'Cliente non trovato.');
    header('Location: ' . opportunities_collaborator_url('customers'));
    exit;
}

$customer = $customerData['customer'] ?? [];
$customerOps = $customerData['opportunities'] ?? [];
$customerName = trim(($customer['customer_first_name'] ?? '') . ' ' . ($customer['customer_last_name'] ?? ''));
$morositaScore = $customer['morosita_score'] ?? null;
$morositaUpdated = $customer['morosita_aggiornato_il'] ?? null;
$morositaMap = [
    'ok' => ['label' => 'Regolare', 'class' => 'badge bg-success'],
    'attenzione' => ['label' => 'Attenzione', 'class' => 'badge bg-warning text-dark'],
    'bloccato' => ['label' => 'Bloccato', 'class' => 'badge bg-danger'],
];
$morosita = $morositaMap[$morositaScore] ?? ['label' => 'Non verificato', 'class' => 'badge bg-secondary'];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Cliente</p>
                <h1 class="h4 mb-0"><?php echo sanitize_output($customerName ?: 'Cliente'); ?></h1>
                <p class="text-muted mb-0"><?php echo sanitize_output($customer['customer_tax_code'] ?? ''); ?></p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo opportunities_collaborator_url('customers'); ?>">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna ai clienti
                </a>
                <a class="btn btn-primary" href="<?php echo opportunities_collaborator_url('create'); ?>">
                    <i class="fa-solid fa-plus me-2"></i>Nuova OP
                </a>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <p class="text-uppercase small text-muted mb-1">Controllo morosità</p>
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="<?php echo $morosita['class']; ?>">
                            Morosità: <?php echo sanitize_output($morosita['label']); ?>
                        </span>
                        <span class="text-muted small">
                            <?php echo $morositaUpdated ? 'Aggiornata: ' . sanitize_output(format_datetime_locale($morositaUpdated)) : 'Mai verificata'; ?>
                        </span>
                    </div>
                    <?php if (!empty($customer['morosita_note'])): ?>
                        <p class="text-muted small mb-0">Nota: <?php echo sanitize_output($customer['morosita_note']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dati cliente</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Nome</p>
                                <p class="mb-0"><?php echo sanitize_output($customerName ?: '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Codice fiscale</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['customer_tax_code'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Telefono</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['customer_phone'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Email</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['customer_email'] ?? '—'); ?></p>
                            </div>
                            <div class="col-12">
                                <p class="text-uppercase small text-muted mb-1">Indirizzo</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['customer_address'] ?? '—'); ?></p>
                                <p class="text-muted small mb-0">
                                    <?php echo sanitize_output($customer['customer_postal_code'] ?? ''); ?>
                                    <?php echo sanitize_output($customer['customer_city'] ?? ''); ?>
                                    <?php echo $customer['customer_province'] ? ' (' . sanitize_output($customer['customer_province']) . ')' : ''; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Documento d'identità</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Tipo</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['document_type'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Numero</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['document_number'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Rilasciato da</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['document_issued_by'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Rilasciato il</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['document_issued_at'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Scadenza</p>
                                <p class="mb-0"><?php echo sanitize_output($customer['document_expires_at'] ?? '—'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <p class="text-uppercase small text-muted mb-1">Opportunity collegate</p>
                        <h2 class="h6 mb-0">Storico inviato</h2>
                    </div>
                    <span class="badge bg-secondary"><?php echo count($customerOps); ?> OP</span>
                </div>
                <?php if ($customerOps): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Codice</th>
                                    <th>Categoria</th>
                                    <th>Gestore</th>
                                    <th>Stato</th>
                                    <th class="text-end">Inviata il</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customerOps as $op): ?>
                                    <?php
                                        $statusLabel = $op['status_label'] ?? $op['status_code'] ?? '';
                                        $statusClass = 'badge bg-secondary';
                                        $statusColor = $op['status_color'] ?? '';
                                        $colorToBootstrap = [
                                            'warning' => 'badge bg-warning text-dark',
                                            'info' => 'badge bg-info text-dark',
                                            'primary' => 'badge bg-primary',
                                            'danger' => 'badge bg-danger',
                                            'success' => 'badge bg-success',
                                        ];
                                        if ($statusColor && isset($colorToBootstrap[$statusColor])) {
                                            $statusClass = $colorToBootstrap[$statusColor];
                                        }
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <a href="<?php echo opportunities_collaborator_url('view', ['id' => (int) ($op['id'] ?? 0)]); ?>" class="link-body-emphasis text-decoration-none">
                                                <?php echo sanitize_output($op['code'] ?? ''); ?>
                                            </a>
                                        </td>
                                        <td class="text-uppercase text-muted small"><?php echo sanitize_output($op['category'] ?? ''); ?></td>
                                        <td>
                                            <div><?php echo sanitize_output($op['provider_label'] ?? '—'); ?></div>
                                            <?php if (!empty($op['offer_label'])): ?>
                                                <div class="text-muted small">Offerta: <?php echo sanitize_output($op['offer_label']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="<?php echo $statusClass; ?>"><?php echo sanitize_output($statusLabel); ?></span></td>
                                        <td class="text-end text-muted small"><?php echo sanitize_output(format_datetime_locale($op['created_at'] ?? null)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Non ci sono opportunity collegate a questo cliente.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
