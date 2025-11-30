<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Manager');
require_capability('services.manage');

$pageTitle = 'Operatori CAF & Patronato';
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$dashboardUsername = current_user_display_name();

$operatorId = null;
try {
    $projectRoot = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 4);
    $service = new PracticesService($pdo, $projectRoot);
    $operatorId = $service->findOperatorIdByUser($currentUserId);
} catch (Throwable $exception) {
    error_log('CAF/Patronato operator resolution error: ' . $exception->getMessage());
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper" id="caf-patronato-operators">
        <div id="caf-patronato-context"
             data-is-admin="1"
               data-operator-id="<?php echo $operatorId !== null ? (int) $operatorId : ''; ?>"
               data-api-base="<?php echo htmlspecialchars(base_url('api/caf-patronato/index.php'), ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="card ag-card dashboard-hero mb-4">
            <div class="card-body d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-4">
                <div class="hero-copy">
                    <h2 class="hero-title mb-2">Team CAF &amp; Patronato</h2>
                    <p class="hero-subtitle mb-0">Amministra gli operatori dello sportello, assegna pratiche e mantieni sotto controllo le notifiche operative.</p>
                </div>
                <div class="hero-kpi-grid">
                    <div class="hero-kpi">
                        <div class="hero-kpi-icon hero-kpi-icon-services"><i class="fa-solid fa-users"></i></div>
                        <div class="hero-kpi-body">
                            <span class="hero-kpi-label">Operatori attivi</span>
                            <span class="hero-kpi-value" id="operators-count-active">—</span>
                        </div>
                    </div>
                    <div class="hero-kpi">
                        <div class="hero-kpi-icon hero-kpi-icon-revenue"><i class="fa-solid fa-user-gear"></i></div>
                        <div class="hero-kpi-body">
                            <span class="hero-kpi-label">Operatori totali</span>
                            <span class="hero-kpi-value" id="operators-count-total">—</span>
                        </div>
                    </div>
                    <div class="hero-kpi">
                        <div class="hero-kpi-icon hero-kpi-icon-clients"><i class="fa-solid fa-bell"></i></div>
                        <div class="hero-kpi-body">
                            <span class="hero-kpi-label">Notifiche aperte</span>
                            <span class="hero-kpi-value" id="notifications-count-open">—</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h1 class="h5 mb-1">Operatori CAF &amp; Patronato</h1>
                    <p class="text-muted mb-0">Gestisci account, ruoli e collegamenti con gli utenti di sistema.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary" type="button" id="refresh-operators">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                    <button class="btn btn-primary" type="button" id="create-operator-btn">
                        <i class="fa-solid fa-user-plus me-2"></i>Nuovo operatore
                    </button>
                </div>
            </div>
            <div class="card-body" id="operators-table-container">
                <div class="text-center text-muted py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Caricamento...</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 mb-1">Notifiche operative</h2>
                    <p class="text-muted mb-0">Avvisi generati dalle pratiche e dalle attività degli operatori.</p>
                </div>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" id="show-read-notifications">
                    <label class="form-check-label" for="show-read-notifications">Mostra anche notifiche lette</label>
                </div>
            </div>
            <div class="card-body" id="notifications-list">
                <div class="text-center text-muted py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Caricamento...</span>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div id="caf-patronato-modal-root">
    <div class="modal fade" id="cafPatronatoModal" tabindex="-1" aria-labelledby="cafPatronatoModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cafPatronatoModalLabel"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer"></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cafPatronatoConfirmModal" tabindex="-1" aria-labelledby="cafPatronatoConfirmLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="cafPatronatoConfirmLabel">Conferma</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" id="cafPatronatoConfirmAction">Conferma</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo asset('assets/js/caf-patronato-helpers.js'); ?>?v=<?php echo filemtime(public_path('assets/js/caf-patronato-helpers.js')); ?>"></script>
<script src="<?php echo asset('assets/js/caf-patronato.js'); ?>?v=<?php echo filemtime(public_path('assets/js/caf-patronato.js')); ?>"></script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
