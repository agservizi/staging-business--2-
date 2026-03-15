<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

$pageTitle = 'CAF & Patronato';
$isPatronatoUser = current_user_can('Patronato');
$canManageServices = current_user_has_capability('services.manage');
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$dashboardUsername = current_user_display_name();
$currentRole = isset($_SESSION['role']) ? (string) $_SESSION['role'] : '';
$canCreatePractices = in_array($currentRole, ['Admin', 'Manager', 'Operatore'], true);
$canManagePractices = $isPatronatoUser || $canCreatePractices;
$useLegacyCreate = in_array($currentRole, ['Admin', 'Manager', 'Operatore'], true);
$createPracticeUrl = caf_patronato_module_url('create');

$operatorId = null;
try {
    $projectRoot = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 4);
    $service = new PracticesService($pdo, $projectRoot);
    $operatorId = $service->findOperatorIdByUser($currentUserId);
} catch (Throwable $exception) {
    error_log('CAF/Patronato operator resolution error: ' . $exception->getMessage());
}

$inProgressCount = 0;
$awaitingCount = 0;
$linkedAppointments = 0;

$quickFilters = [
    [
        'id' => 'all',
        'label' => 'Tutte le pratiche',
        'icon' => 'fa-layer-group',
        'filters' => new stdClass(),
    ],
    [
        'id' => 'in-progress',
        'label' => 'In lavorazione',
        'icon' => 'fa-gears',
        'filters' => ['stato' => 'in_lavorazione'],
        'status' => 'in_lavorazione',
    ],
    [
        'id' => 'waiting',
        'label' => 'In attesa',
        'icon' => 'fa-hourglass-half',
        'filters' => ['stato' => 'sospesa'],
        'status' => 'sospesa',
    ],
    [
        'id' => 'completed',
        'label' => 'Completate',
        'icon' => 'fa-circle-check',
        'filters' => ['stato' => 'completata'],
        'status' => 'completata',
    ],
    [
        'id' => 'archived',
        'label' => 'Archiviate',
        'icon' => 'fa-box-archive',
        'filters' => ['stato' => 'archiviata'],
        'status' => 'archiviata',
    ],
    [
        'id' => 'unassigned',
        'label' => 'Non assegnate',
        'icon' => 'fa-user-slash',
        'filters' => ['assegnata' => '0'],
    ],
    [
        'id' => 'due-soon',
        'label' => 'Scadenza vicina',
        'icon' => 'fa-calendar-exclamation',
        'filters' => ['order' => 'scadenza'],
    ],
];

$quickFiltersMap = [];
foreach ($quickFilters as $filterDefinition) {
    $quickFiltersMap[$filterDefinition['id']] = $filterDefinition['filters'];
}

$defaultPerPage = ($canManagePractices || $canManageServices) ? 10 : 15;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main id="caf-patronato-practices" class="content-wrapper">
        <style>
            .caf-shell {
                display: grid;
                gap: 1.5rem;
            }

            .caf-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%),
                    #fff;
            }

            .caf-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: rgba(58, 123, 213, 0.10);
                color: #2154d7;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .caf-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .caf-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .caf-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .caf-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .caf-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .caf-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .caf-quick-filters {
                display: flex;
                flex-wrap: wrap;
                gap: 0.65rem;
            }

            .caf-quick-filters .btn {
                border-radius: 999px;
            }

            .caf-table-card-body {
                padding: 1.25rem 1.25rem 1.4rem !important;
            }

            #practices-table-container > .table-responsive,
            #practices-table-container .table-responsive {
                border: 1px solid rgba(15, 23, 42, 0.06);
                border-radius: 1rem;
                overflow: hidden;
            }

            #practices-table-container .dt-container .dt-layout-row:not(.dt-layout-table) {
                margin: 0;
                padding-inline: 0.15rem;
            }

            #practices-table-container .dt-container .dt-layout-row:first-child {
                padding-bottom: 1rem;
            }

            #practices-table-container .dt-container .dt-layout-row:last-child {
                padding-top: 1rem;
            }

            @media (max-width: 1199.98px) {
                .caf-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .caf-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <div class="caf-shell">
           <div id="caf-patronato-context"
               data-can-configure="<?php echo $canManageServices ? '1' : '0'; ?>"
               data-can-manage-practices="<?php echo $canManagePractices ? '1' : '0'; ?>"
               data-can-create-practices="<?php echo $canCreatePractices ? '1' : '0'; ?>"
               data-is-patronato="<?php echo $isPatronatoUser ? '1' : '0'; ?>"
               data-use-legacy-create="<?php echo $useLegacyCreate ? '1' : '0'; ?>"
               data-create-url="<?php echo htmlspecialchars($createPracticeUrl, ENT_QUOTES, 'UTF-8'); ?>"
               data-operator-id="<?php echo $operatorId !== null ? (int) $operatorId : ''; ?>"
               data-api-base="<?php echo htmlspecialchars(base_url('api/caf-patronato/index.php'), ENT_QUOTES, 'UTF-8'); ?>"
               data-tracking-base-url="<?php echo htmlspecialchars(base_url('tracking.php?code='), ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <section class="card caf-hero">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-xl-7">
                        <span class="caf-pill"><i class="fa-solid fa-folder-tree"></i>Pratiche assistite</span>
                        <h1 class="mt-3 mb-2 fw-bold" style="max-width: 13ch;">CAF &amp; Patronato più chiaro per pratiche, stati e carico operativo.</h1>
                        <p class="text-muted mb-0" style="max-width: 70ch;">
                            Monitora pratiche, assegnazioni e scadenze in un’unica regia, con filtri rapidi e una lettura più ordinata del lavoro in corso.
                        </p>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="caf-kpis" id="hero-status-grid">
                            <div class="caf-kpi">
                                <span class="caf-kpi-label">Pratiche totali</span>
                                <span class="caf-kpi-value" id="summary-total">0</span>
                                <span class="caf-kpi-note">Registro complessivo filtrabile</span>
                            </div>
                            <div class="caf-kpi">
                                <span class="caf-kpi-label">In lavorazione</span>
                                <span class="caf-kpi-value" id="summary-status-in_lavorazione">0</span>
                                <span class="caf-kpi-note">Attività operative aperte</span>
                            </div>
                            <div class="caf-kpi">
                                <span class="caf-kpi-label">In attesa</span>
                                <span class="caf-kpi-value" id="summary-status-sospesa">0</span>
                                <span class="caf-kpi-note">Pratiche sospese o ferme</span>
                            </div>
                            <div class="caf-kpi">
                                <span class="caf-kpi-label">Completate</span>
                                <span class="caf-kpi-value" id="summary-status-completata">0</span>
                                <span class="caf-kpi-note">Pronte per archivio o consegna</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card caf-panel">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1"><?php echo $isPatronatoUser ? 'Bentornato, ' . sanitize_output($dashboardUsername) : 'CAF & Patronato'; ?></h2>
                        <p class="text-muted small mb-0">Utilizza i filtri per individuare rapidamente pratiche e operatori assegnati.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <?php if ($canCreatePractices): ?>
                            <a class="btn btn-warning text-dark" id="create-practice-btn" href="<?php echo htmlspecialchars($useLegacyCreate ? $createPracticeUrl : '#', ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-circle-plus me-2"></i>Nuova pratica
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="caf-quick-filters" role="toolbar" aria-label="Filtri rapidi pratiche">
                    <?php foreach ($quickFilters as $index => $filter): ?>
                        <?php
                            $filtersJson = json_encode($filter['filters'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            $isActive = $index === 0;
                        ?>
                        <button type="button"
                                class="btn btn-outline-secondary btn-sm<?php echo $isActive ? ' active' : ''; ?>"
                                data-quick-filter="<?php echo sanitize_output($filter['id']); ?>"
                                data-quick-filter-label="<?php echo sanitize_output($filter['label']); ?>"
                                data-filters='<?php echo htmlspecialchars($filtersJson, ENT_QUOTES, 'UTF-8'); ?>'<?php echo isset($filter['status']) ? ' data-quick-filter-status="' . sanitize_output($filter['status']) . '"' : ''; ?>>
                            <i class="fa-solid <?php echo sanitize_output($filter['icon']); ?> me-2"></i>
                            <span><?php echo sanitize_output($filter['label']); ?></span>
                            <span class="badge bg-secondary-subtle text-secondary ms-2 d-none" data-quick-filter-count>0</span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="card caf-panel">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">Filtri pratiche</h2>
                        <p class="text-muted small mb-0">Raffina il registro per categoria, stato, assegnazione, scadenze e tracking.</p>
                    </div>
                </div>
                <form id="practices-filters-form" class="row g-3 align-items-end">
                    <div class="col-12 col-md-4 col-xl-3">
                        <label class="form-label" for="filter-search">Ricerca</label>
                        <input class="form-control mb-2" type="search" id="filter-search" name="search" placeholder="Titolo, descrizione, note" autocomplete="off">
                    </div>
                    <div class="col-12 col-md-4 col-xl-3">
                        <label class="form-label" for="filter-tracking-code">Codice tracking</label>
                        <input class="form-control mb-2" type="text" id="filter-tracking-code" name="tracking_code" placeholder="CAF-PRAT-00000" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-categoria">Categoria</label>
                        <select class="form-select mb-2" id="filter-categoria" name="categoria">
                            <option value="">Tutte</option>
                            <option value="CAF">CAF</option>
                            <option value="PATRONATO">Patronato</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-stato">Stato</label>
                        <select class="form-select mb-2" id="filter-stato" name="stato">
                            <option value="">Tutti</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-tipo">Tipologia</label>
                        <select class="form-select mb-2" id="filter-tipo" name="tipo_pratica">
                            <option value="">Tutte</option>
                        </select>
                    </div>
                    <?php if ($canManageServices): ?>
                        <div class="col-12 col-md-3 col-xl-2">
                            <label class="form-label" for="filter-operatore">Operatore</label>
                            <select class="form-select mb-2" id="filter-operatore" name="operatore">
                                <option value="">Tutti</option>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-dal">Dal</label>
                        <input class="form-control mb-2" type="date" id="filter-dal" name="dal">
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-al">Al</label>
                        <input class="form-control mb-2" type="date" id="filter-al" name="al">
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-assegnata">Assegnazione</label>
                        <select class="form-select mb-2" id="filter-assegnata" name="assegnata">
                            <option value="">Tutte</option>
                            <option value="1">Solo assegnate</option>
                            <option value="0">Solo non assegnate</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-order">Ordina per</label>
                        <select class="form-select mb-2" id="filter-order" name="order">
                            <option value="recenti">Ultimi aggiornamenti</option>
                            <option value="scadenza">Scadenza</option>
                            <option value="stato">Stato</option>
                            <option value="assegnatario">Operatore</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <label class="form-label" for="filter-per-page">Risultati</label>
                        <select class="form-select mb-2" id="filter-per-page" name="per_page">
                            <option value="10"<?php echo $defaultPerPage === 10 ? ' selected' : ''; ?>>10</option>
                            <option value="15"<?php echo $defaultPerPage === 15 ? ' selected' : ''; ?>>15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-3 col-xl-2 d-flex gap-2 flex-wrap">
                        <button class="btn btn-primary flex-grow-1 mb-2" type="submit"><i class="fa-solid fa-sliders me-2"></i>Applica</button>
                        <button class="btn btn-outline-secondary flex-shrink-0 mb-2" type="button" id="clear-filters" title="Reimposta filtri">
                            <i class="fa-solid fa-rotate-left"></i>
                        </button>
                    </div>
                </form>

                <div id="active-filters-display" class="mt-3" style="display: none;">
                    <div class="small text-muted mb-2">Filtri attivi</div>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <div id="active-filters-list" class="d-flex flex-wrap gap-2"></div>
                        <span id="active-quick-filter-wrapper" class="d-inline-flex align-items-center gap-2" style="display: none;">
                            <i class="fa-solid fa-bolt text-warning"></i>
                            <span class="badge bg-warning text-dark" id="active-quick-filter"></span>
                        </span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card caf-panel">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2">
                    <h2 class="h5 mb-0">Pratiche registrate</h2>
                    <span class="badge ag-badge" id="summary-total-badge">0</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-secondary btn-sm" type="button" id="refresh-practices">
                        <i class="fa-solid fa-rotate"></i>
                    </button>
                </div>
            </div>
            <div class="card-body caf-table-card-body" id="practices-table-container">
                <div class="p-4 text-center text-muted">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Caricamento...</span>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0">
                <nav id="practices-pagination" class="d-none">
                    <ul class="pagination justify-content-center mb-0"></ul>
                </nav>
            </div>
        </section>

        <div id="practices-summary-container" class="d-none"></div>

        <div class="row g-4 mt-1">
            <div class="col-12">
                <div class="card caf-panel h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Azioni rapide</h2>
                        <i class="fa-solid fa-bolt text-warning"></i>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-3">Applica con un clic i filtri più comuni oppure crea rapidamente una nuova attività.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-soft-secondary btn-sm" data-quick-filter="in-progress" data-filters='<?php echo htmlspecialchars(json_encode($quickFiltersMap['in-progress'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>
                                <i class="fa-solid fa-gears me-1"></i>Pratiche in lavorazione
                            </button>
                            <button type="button" class="btn btn-soft-secondary btn-sm" data-quick-filter="unassigned" data-filters='<?php echo htmlspecialchars(json_encode($quickFiltersMap['unassigned'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>
                                <i class="fa-solid fa-user-slash me-1"></i>Non assegnate
                            </button>
                            <button type="button" class="btn btn-soft-secondary btn-sm" data-quick-filter="due-soon" data-filters='<?php echo htmlspecialchars(json_encode($quickFiltersMap['due-soon'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>'>
                                <i class="fa-solid fa-calendar-exclamation me-1"></i>Scadenza vicina
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-12">
                <div class="card caf-panel h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Attività rapide</h2>
                        <span class="badge ag-badge"><?php echo $linkedAppointments; ?></span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Utilizza questo pannello per raggiungere rapidamente le pratiche in lavorazione o filtrare quelle in attesa.</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-outline-secondary btn-sm" href="#" data-filter="in_progress">
                                <i class="fa-solid fa-play me-2"></i>Pratiche in lavorazione (<?php echo $inProgressCount; ?>)
                            </a>
                            <a class="btn btn-outline-secondary btn-sm" href="#" data-filter="waiting">
                                <i class="fa-solid fa-hourglass-half me-2"></i>Pratiche in attesa (<?php echo $awaitingCount; ?>)
                            </a>
                        </div>
                    </div>
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
