<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$perPage = 20;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalCustomers = $opportunityService->countCollaboratorCustomers($collaboratorId, $searchQuery);
$totalPages = max(1, (int) ceil($totalCustomers / $perPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;
$customers = $opportunityService->listCollaboratorCustomers($collaboratorId, $searchQuery, $perPage, $offset);
$displayStart = $totalCustomers > 0 ? $offset + 1 : 0;
$displayEnd = $totalCustomers > 0 ? min($totalCustomers, $offset + count($customers)) : 0;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Clienti</p>
                <h1 class="h4 mb-0">Rubrica clienti</h1>
                <p class="text-muted mb-0">Elenco clienti che hai già inserito in una opportunity.</p>
            </div>
            <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                <i class="fa-solid fa-plus me-2"></i>Nuova OP
            </a>
        </div>

        <form class="card shadow-sm mb-4" method="get">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label text-uppercase small text-muted">Cerca cliente</label>
                        <input type="text" class="form-control" name="q" value="<?php echo sanitize_output($searchQuery); ?>" placeholder="Nome, cognome, CF o email">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <button class="btn btn-outline-primary w-100" type="submit">
                            <i class="fa-solid fa-search me-2"></i>Cerca
                        </button>
                    </div>
                    <?php if ($searchQuery !== ''): ?>
                        <div class="col-md-3 col-lg-2">
                            <a class="btn btn-outline-secondary w-100" href="<?php echo asset('modules/opportunities/collaborator/customers.php'); ?>">
                                <i class="fa-solid fa-rotate-left me-2"></i>Reset
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <p class="text-uppercase small text-muted mb-1">Totale clienti</p>
                        <h2 class="h5 mb-0"><?php echo sanitize_output(number_format($totalCustomers)); ?></h2>
                    </div>
                    <div class="text-muted small">
                        <?php if ($totalCustomers > 0): ?>
                            Mostrati <?php echo $displayStart; ?>–<?php echo $displayEnd; ?> di <?php echo $totalCustomers; ?>
                        <?php else: ?>
                            Nessun cliente trovato.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($customers): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Cliente</th>
                                    <th>Contatti</th>
                                    <th>Documento</th>
                                    <th>Ultima OP</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <?php
                                        $fullName = trim(($customer['customer_first_name'] ?? '') . ' ' . ($customer['customer_last_name'] ?? ''));
                                        $rawStatusLabel = $customer['status_label'] ?? $customer['status_code'] ?? '';
                                        $statusLabel = str_replace('_', ' ', $rawStatusLabel);
                                        $statusClass = 'badge bg-secondary';
                                        $statusColor = $customer['status_color'] ?? '';
                                        $morositaScore = $customer['morosita_score'] ?? null;
                                        $morositaUpdated = $customer['morosita_aggiornato_il'] ?? null;
                                        $morositaMap = [
                                            'ok' => ['label' => 'Regolare', 'class' => 'badge bg-success'],
                                            'attenzione' => ['label' => 'Attenzione', 'class' => 'badge bg-warning text-dark'],
                                            'bloccato' => ['label' => 'Bloccato', 'class' => 'badge bg-danger'],
                                        ];
                                        $morosita = $morositaMap[$morositaScore] ?? ['label' => 'Non verificato', 'class' => 'badge bg-secondary'];
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
                                        $taxCode = $customer['customer_tax_code'] ?? '';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize_output($fullName ?: 'Cliente non indicato'); ?></div>
                                            <div class="text-muted small"><?php echo sanitize_output($taxCode ?: 'CF mancante'); ?></div>
                                        </td>
                                        <td>
                                            <div><?php echo sanitize_output($customer['customer_phone'] ?? '—'); ?></div>
                                            <div class="text-muted small"><?php echo sanitize_output($customer['customer_email'] ?? ''); ?></div>
                                        </td>
                                        <td class="text-muted small">
                                            <?php echo sanitize_output($customer['document_type'] ?? ''); ?>
                                            <?php echo !empty($customer['document_number']) ? ' · ' . sanitize_output($customer['document_number']) : ''; ?>
                                        </td>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize_output($customer['code'] ?? ''); ?></div>
                                            <div class="text-muted small">Aggiornata: <?php echo sanitize_output(format_datetime_locale($customer['updated_at'] ?? $customer['created_at'] ?? null)); ?></div>
                                            <div class="d-flex flex-wrap align-items-center gap-2">
                                                <span class="<?php echo $statusClass; ?>"><?php echo sanitize_output($statusLabel); ?></span>
                                                <span class="<?php echo $morosita['class']; ?>">
                                                    Morosità: <?php echo sanitize_output($morosita['label']); ?>
                                                </span>
                                            </div>
                                            <div class="text-muted small">
                                                <?php echo $morositaUpdated ? 'Aggiornata: ' . sanitize_output(format_datetime_locale($morositaUpdated)) : 'Mai verificata'; ?>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($taxCode !== ''): ?>
                                                <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('modules/opportunities/collaborator/customer.php?tax_code=' . urlencode((string) $taxCode)); ?>">
                                                    <i class="fa-solid fa-user me-1"></i>Apri scheda
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small">CF mancante</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($totalPages > 1): ?>
                        <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
                            <div class="text-muted small">Pagina <?php echo $currentPage; ?> di <?php echo $totalPages; ?></div>
                            <div class="btn-group" role="group" aria-label="Paginazione">
                                <?php $prevPage = max(1, $currentPage - 1); ?>
                                <?php $nextPage = min($totalPages, $currentPage + 1); ?>
                                <a class="btn btn-outline-secondary btn-sm<?php echo $currentPage <= 1 ? ' disabled' : ''; ?>" href="<?php echo $currentPage <= 1 ? '#' : '?q=' . urlencode($searchQuery) . '&page=' . $prevPage; ?>">
                                    <i class="fa-solid fa-angle-left me-1"></i>Precedente
                                </a>
                                <a class="btn btn-outline-secondary btn-sm<?php echo $currentPage >= $totalPages ? ' disabled' : ''; ?>" href="<?php echo $currentPage >= $totalPages ? '#' : '?q=' . urlencode($searchQuery) . '&page=' . $nextPage; ?>">
                                    Successiva<i class="fa-solid fa-angle-right ms-1"></i>
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted mb-0">Non ci sono clienti salvati. Inserisci una opportunity per popolare la rubrica.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
