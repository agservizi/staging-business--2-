<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$opportunities = $opportunityService->listCollaboratorOpportunities($collaboratorId);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Le tue richieste</h1>
            </div>
            <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                <i class="fa-solid fa-plus me-2"></i>Nuova OP
            </a>
        </div>
        <div class="row g-4">
            <?php if (!$opportunities): ?>
                <div class="col-12">
                    <div class="alert alert-info mb-0" role="alert">
                        Nessuna opportunity registrata. Crea la prima utilizzando il pulsante in alto.
                    </div>
                </div>
            <?php endif; ?>
            <?php foreach ($opportunities as $opportunity): ?>
                <div class="col-12">
                    <div class="card opportunity-card shadow-sm">
                        <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1 d-flex align-items-center gap-2 flex-wrap">
                                    <span><?php echo sanitize_output(strtoupper($opportunity['category'] ?? '')); ?></span>
                                    <span class="opportunity-code"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></span>
                                </p>
                                <h3 class="h5 mb-1"><?php echo sanitize_output($opportunity['customer_first_name'] . ' ' . $opportunity['customer_last_name']); ?></h3>
                                <p class="text-muted mb-0">Gestore: <?php echo sanitize_output($opportunity['provider_label'] ?? ''); ?></p>
                            </div>
                            <div class="text-end">
                                <?php
                                $badgeClass = 'bg-secondary';
                                $statusColor = $opportunity['status_color'] ?? '';
                                $colorToBootstrap = [
                                    'warning' => 'bg-warning text-dark',
                                    'info' => 'bg-info text-dark',
                                    'primary' => 'bg-primary',
                                    'danger' => 'bg-danger',
                                    'success' => 'bg-success',
                                ];
                                if ($statusColor && isset($colorToBootstrap[$statusColor])) {
                                    $badgeClass = $colorToBootstrap[$statusColor];
                                }
                                ?>
                                <span class="badge <?php echo $badgeClass; ?> opportunity-status-badge">
                                    <?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?>
                                </span>
                                <p class="text-muted small mb-0 mt-2">
                                    Inviata il <?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
