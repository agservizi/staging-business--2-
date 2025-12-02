<?php
declare(strict_types=1);

use App\Services\Certi\CertiRequestRepository;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$pageTitle = 'Certi³ | Gestione Certificati';
$moduleColor = '#0061ff';

$category = strtolower(trim((string) ($_GET['categoria'] ?? '')));
$allowedCategories = ['comunale', 'camerale', 'catastale'];
if ($category !== '' && !in_array($category, $allowedCategories, true)) {
    $category = '';
}

$status = strtolower(trim((string) ($_GET['stato'] ?? '')));
$allowedStatuses = ['nuova','in_validazione','in_lavorazione','in_attesa_api','completata','respinta','errore_api'];
if ($status !== '' && !in_array($status, $allowedStatuses, true)) {
    $status = '';
}

$urgency = strtolower(trim((string) ($_GET['urgenza'] ?? '')));
$allowedUrgency = ['low','standard','alta'];
if ($urgency !== '' && !in_array($urgency, $allowedUrgency, true)) {
    $urgency = '';
}

$search = trim((string) ($_GET['search'] ?? ''));
$assignedTo = (int) ($_GET['assegnato_a'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = min(50, max(10, (int) ($_GET['per_page'] ?? 15)));

$filters = [
    'categoria' => $category,
    'stato' => $status,
    'urgency' => $urgency,
    'assigned_to' => $assignedTo > 0 ? $assignedTo : null,
    'search' => $search !== '' ? $search : null,
    'page' => $page,
    'per_page' => $perPage,
];

/**
 * @param array<string,mixed> $params
 */
function certi_build_page_url(array $params): string
{
    $filtered = array_filter($params, static fn($value) => !in_array($value, [null, ''], true));
    $query = http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);
    return 'index.php' . ($query !== '' ? ('?' . $query) : '');
}

$repository = new CertiRequestRepository($pdo);
$result = $repository->listRequests($filters);
$requests = $result['items'];
$totalItems = (int) $result['total'];
$summary = $result['summary'];

$summaryStatus = $summary['status'] ?? [];
$summaryCards = [
    'pending' => [
        'label' => 'In attesa',
        'icon' => 'fa-regular fa-clock',
        'color' => 'linear-gradient(120deg, ' . $moduleColor . ', #33a1ff)',
        'value' => ($summaryStatus['nuova'] ?? 0) + ($summaryStatus['in_validazione'] ?? 0),
        'statuses' => ['nuova','in_validazione'],
    ],
    'progress' => [
        'label' => 'In lavorazione',
        'icon' => 'fa-solid fa-spinner',
        'color' => 'linear-gradient(120deg, #ffd200, #ffae00)',
        'value' => ($summaryStatus['in_lavorazione'] ?? 0) + ($summaryStatus['in_attesa_api'] ?? 0),
        'statuses' => ['in_lavorazione','in_attesa_api'],
    ],
    'completed' => [
        'label' => 'Completate',
        'icon' => 'fa-solid fa-circle-check',
        'color' => 'linear-gradient(120deg, #33c674, #1faa59)',
        'value' => ($summaryStatus['completata'] ?? 0),
        'statuses' => ['completata'],
    ],
    'rejected' => [
        'label' => 'Respinte',
        'icon' => 'fa-solid fa-circle-xmark',
        'color' => 'linear-gradient(120deg, #ff5f6d, #ff2d55)',
        'value' => ($summaryStatus['respinta'] ?? 0) + ($summaryStatus['errore_api'] ?? 0),
        'statuses' => ['respinta','errore_api'],
    ],
];

$operators = [];
$operatorsStmt = $pdo->query('SELECT id, cognome, nome FROM users WHERE role IN ("Admin","Manager","Operatore") ORDER BY cognome ASC, nome ASC');
if ($operatorsStmt) {
    while ($row = $operatorsStmt->fetch(PDO::FETCH_ASSOC)) {
        $operators[(int) $row['id']] = trim((string) ($row['cognome'] . ' ' . $row['nome'])) ?: ('Operatore #' . $row['id']);
    }
}

$statusBadges = [
    'nuova' => 'bg-primary',
    'in_validazione' => 'bg-info text-dark',
    'in_lavorazione' => 'bg-warning text-dark',
    'in_attesa_api' => 'bg-warning text-dark',
    'completata' => 'bg-success',
    'respinta' => 'bg-danger',
    'errore_api' => 'bg-danger',
];

$statusLabels = [
    'nuova' => 'In attesa',
    'in_validazione' => 'In validazione',
    'in_lavorazione' => 'In lavorazione',
    'in_attesa_api' => 'In attesa API',
    'completata' => 'Completata',
    'respinta' => 'Respinta',
    'errore_api' => 'Errore API',
];

$categoryLabels = [
    'comunale' => 'Comunale',
    'camerale' => 'Camerale',
    'catastale' => 'Catastale',
];

$urgencyLabels = [
    'low' => 'Bassa',
    'standard' => 'Normale',
    'alta' => 'Alta',
];

$totalPages = (int) ceil(max(1, $totalItems) / $perPage);
$hasFilters = $category || $status || $urgency || $assignedTo || $search !== '';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="badge rounded-pill text-bg-primary" style="background-color: <?php echo sanitize_output($moduleColor); ?>;">Certi³</span>
                <h1 class="h3 mb-1">Modulo Certi³</h1>
                <p class="text-muted mb-0">Gestisci richieste comunali, camerali e catastali con workflow unico, integrazioni API e consegna digitale.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-light" href="<?php echo base_url('modules/servizi/certi/index.php'); ?>">
                    <i class="fa-solid fa-rotate"></i>
                </a>
                <a class="btn btn-primary" style="background-color: <?php echo sanitize_output($moduleColor); ?>; border-color: <?php echo sanitize_output($moduleColor); ?>;" href="<?php echo base_url('modules/servizi/certi/create.php'); ?>">
                    <i class="fa-solid fa-circle-plus me-2"></i>Crea nuova richiesta
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <?php foreach ($summaryCards as $key => $card): ?>
            <div class="col-12 col-sm-6 col-xl-3">
                <button class="certi-metric card w-100 text-start border-0" type="button" data-certi-status="<?php echo implode(',', $card['statuses']); ?>" data-active="<?php echo in_array($status, $card['statuses'], true) ? '1' : '0'; ?>">
                    <div class="card-body" style="background: <?php echo sanitize_output($card['color']); ?>; color: #fff;">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="fw-semibold text-uppercase small"><?php echo sanitize_output($card['label']); ?></span>
                            <i class="<?php echo sanitize_output($card['icon']); ?>"></i>
                        </div>
                        <p class="display-6 fw-bold mb-0"><?php echo (int) $card['value']; ?></p>
                        <small>Clicca per filtrare</small>
                    </div>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header border-0 bg-transparent">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h2 class="h5 mb-0">Filtri avanzati</h2>
                        <small class="text-muted">Affina i risultati per categoria, stato, urgenza, operatore o testo libero.</small>
                    </div>
                    <?php if ($hasFilters): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="index.php">
                        <i class="fa-solid fa-broom me-1"></i>Reimposta
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" autocomplete="off">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="filter-search">Ricerca avanzata</label>
                        <input class="form-control" type="search" id="filter-search" name="search" placeholder="Intestatario, protocollo, CF" value="<?php echo sanitize_output($search); ?>">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="filter-categoria">Categoria</label>
                        <select class="form-select" id="filter-categoria" name="categoria">
                            <option value="">Tutte</option>
                            <?php foreach ($allowedCategories as $value): ?>
                            <option value="<?php echo sanitize_output($value); ?>" <?php echo $category === $value ? 'selected' : ''; ?>><?php echo sanitize_output($categoryLabels[$value]); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="filter-stato">Stato</label>
                        <select class="form-select" id="filter-stato" name="stato">
                            <option value="">Tutti</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                            <option value="<?php echo sanitize_output($value); ?>" <?php echo $status === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="filter-urgenza">Urgenza</label>
                        <select class="form-select" id="filter-urgenza" name="urgenza">
                            <option value="">Qualsiasi</option>
                            <?php foreach ($urgencyLabels as $value => $label): ?>
                            <option value="<?php echo sanitize_output($value); ?>" <?php echo $urgency === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="filter-assegnato">Assegnato a</label>
                        <select class="form-select" id="filter-assegnato" name="assegnato_a">
                            <option value="">Tutti</option>
                            <?php foreach ($operators as $id => $label): ?>
                            <option value="<?php echo (int) $id; ?>" <?php echo $assignedTo === (int) $id ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="filter-perpage">Per pagina</label>
                        <select class="form-select" id="filter-perpage" name="per_page">
                            <?php foreach ([15,25,50] as $option): ?>
                            <option value="<?php echo $option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo $option; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2 justify-content-end">
                        <button class="btn btn-warning text-dark" type="submit">
                            <i class="fa-solid fa-filter me-2"></i>Applica filtri
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 mb-0">Richieste registrate</h2>
                    <small class="text-muted">Totale risultati: <?php echo $totalItems; ?></small>
                </div>
                <span class="badge bg-dark">Pagina <?php echo $page; ?> / <?php echo max(1, $totalPages); ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (!$requests): ?>
                <div class="text-center py-5 px-4">
                    <p class="text-muted mb-3">Non ci sono richieste che soddisfano i criteri selezionati.</p>
                    <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-eraser me-2"></i>Rimuovi filtri</a>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Intestatario</th>
                                <th>Categoria</th>
                                <th>Tipo</th>
                                <th>Stato</th>
                                <th>Urgenza</th>
                                <th>Operatore</th>
                                <th>Ultimo aggiornamento</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                    $intestatario = $request['dati_intestatario'] ?? [];
                                    $displayName = trim(($intestatario['denominazione'] ?? '') !== '' ? (string) $intestatario['denominazione'] : trim(($intestatario['cognome'] ?? '') . ' ' . ($intestatario['nome'] ?? '')));
                                    if ($displayName === '') {
                                        $displayName = 'Richiesta #' . (int) $request['id'];
                                    }
                                    $stato = (string) $request['stato'];
                                    $badgeClass = $statusBadges[$stato] ?? 'bg-secondary';
                                    $statoLabel = $statusLabels[$stato] ?? ucfirst($stato);
                                    $providerBadge = null;
                                    if (!empty($request['docuengine_request_id'])) {
                                        $providerBadge = 'DocuEngine';
                                    } elseif (!empty($request['visengine_request_id'])) {
                                        $providerBadge = 'VisEngine';
                                    } elseif (!empty($request['catasto_request_id'])) {
                                        $providerBadge = 'Catasto';
                                    }
                                ?>
                                <tr>
                                    <td class="fw-semibold">#<?php echo (int) $request['id']; ?></td>
                                    <td>
                                        <div class="fw-semibold"><?php echo sanitize_output($displayName); ?></div>
                                        <small class="text-muted">CF/P.IVA <?php echo sanitize_output((string) ($intestatario['cf_piva'] ?? 'N/D')); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?php echo sanitize_output($categoryLabels[$request['categoria']] ?? ucfirst((string) $request['categoria'])); ?></span>
                                        <?php if ($providerBadge): ?>
                                            <span class="badge bg-indigo text-uppercase ms-1" style="background-color: <?php echo sanitize_output($moduleColor); ?>;">Acquisito da <?php echo sanitize_output($providerBadge); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize_output((string) $request['tipo_certificato']); ?></td>
                                    <td><span class="badge <?php echo $badgeClass; ?>"><?php echo sanitize_output($statoLabel); ?></span></td>
                                    <td>
                                        <span class="badge text-bg-<?php echo $request['urgenza'] === 'alta' ? 'danger' : ($request['urgenza'] === 'low' ? 'secondary' : 'info'); ?>"><?php echo sanitize_output($urgencyLabels[$request['urgenza']] ?? ucfirst((string) $request['urgenza'])); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($request['assegnato_a']) && isset($operators[(int) $request['assegnato_a']])): ?>
                                            <?php echo sanitize_output($operators[(int) $request['assegnato_a']]); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Non assegnato</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <small class="text-muted">Aggiornato il <?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $request['updated_at']))); ?></small>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group" role="group">
                                            <a class="btn btn-sm btn-outline-light" href="view.php?id=<?php echo (int) $request['id']; ?>" title="Dettagli">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <?php if (!empty($request['file_certificato'])): ?>
                                            <a class="btn btn-sm btn-outline-success" href="<?php echo base_url('api/certi/index.php?action=get_certificate&id=' . (int) $request['id']); ?>" title="Scarica certificato">
                                                <i class="fa-solid fa-file-arrow-down"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 p-3 border-top">
                    <div>
                        <small class="text-muted">Mostrati <?php echo count($requests); ?> elementi su <?php echo $totalItems; ?> totali.</small>
                    </div>
                    <div class="btn-group" role="group">
                        <?php $prev = max(1, $page - 1); $next = min($totalPages, $page + 1); ?>
                        <a class="btn btn-outline-light<?php echo $page <= 1 ? ' disabled' : ''; ?>" href="<?php echo certi_build_page_url(array_merge($_GET, ['page' => $prev])); ?>">
                            <i class="fa-solid fa-arrow-left"></i>
                        </a>
                        <a class="btn btn-outline-light<?php echo $page >= $totalPages ? ' disabled' : ''; ?>" href="<?php echo certi_build_page_url(array_merge($_GET, ['page' => $next])); ?>">
                            <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script src="<?php echo asset('assets/js/certi-module.js'); ?>" defer></script>
