<?php
declare(strict_types=1);

use App\Services\Certi\CertiLogService;
use App\Services\Certi\CertiRequestRepository;
use App\Services\Certi\CertiWorkflowService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$requestId = (int) ($_GET['id'] ?? 0);
if ($requestId <= 0) {
    header('Location: index.php');
    exit;
}

$repository = new CertiRequestRepository($pdo);
$logService = new CertiLogService($pdo);
$workflow = new CertiWorkflowService($pdo);

try {
    $request = $repository->findById($requestId);
} catch (Throwable $exception) {
    require_once __DIR__ . '/../../../includes/header.php';
    require_once __DIR__ . '/../../../includes/sidebar.php';
    echo '<div class="alert alert-danger m-4">' . sanitize_output($exception->getMessage()) . '</div>';
    exit;
}

$messages = [];
$errorMessages = [];

$csrfToken = csrf_token();
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['form_action'] ?? '');
    try {
        require_valid_csrf();
        if ($action === 'assign') {
            $operatorId = (int) ($_POST['operator_id'] ?? 0);
            if ($operatorId <= 0) {
                $errorMessages[] = 'Seleziona un operatore valido.';
            } else {
                $request = $workflow->assignOperator($requestId, $operatorId, $userId);
                $messages[] = 'Richiesta assegnata correttamente.';
            }
        } elseif ($action === 'status') {
            $status = (string) ($_POST['status'] ?? '');
            if ($status === '') {
                $errorMessages[] = 'Seleziona uno stato valido.';
            } else {
                $request = $workflow->updateStatus($requestId, $status, $userId);
                $messages[] = 'Stato aggiornato correttamente.';
            }
        } elseif ($action === 'fetch_provider') {
            $request = $workflow->fetchProviderDocument($requestId, $userId);
            $messages[] = 'Il documento è stato recuperato dal provider e allegato alla richiesta.';
        } elseif ($action === 'upload_certificate') {
            if (empty($_FILES['certificate_file'])) {
                $errorMessages[] = 'Carica un file PDF valido.';
            } else {
                $request = $workflow->storeUploadedCertificate($requestId, $_FILES['certificate_file'], $userId);
                $messages[] = 'Documento caricato con successo.';
            }
        }
    } catch (Throwable $exception) {
        $errorMessages[] = $exception->getMessage();
    }
}

$timeline = $logService->listByRequest($requestId);
$operators = [];
$operatorsStmt = $pdo->query('SELECT id, cognome, nome FROM users WHERE ruolo IN ("Admin","Manager","Operatore") ORDER BY cognome ASC, nome ASC');
if ($operatorsStmt) {
    while ($row = $operatorsStmt->fetch(PDO::FETCH_ASSOC)) {
        $operators[(int) $row['id']] = trim((string) ($row['cognome'] . ' ' . $row['nome'])) ?: ('Operatore #' . $row['id']);
    }
}

$statusOptions = [
    'nuova' => 'In attesa',
    'in_validazione' => 'In validazione',
    'in_lavorazione' => 'In lavorazione',
    'in_attesa_api' => 'In attesa API',
    'completata' => 'Completata',
    'respinta' => 'Respinta',
    'errore_api' => 'Errore API',
];

$statusBadges = [
    'nuova' => 'badge text-bg-primary',
    'in_validazione' => 'badge text-bg-primary',
    'in_lavorazione' => 'badge text-bg-warning text-dark',
    'in_attesa_api' => 'badge text-bg-warning text-dark',
    'completata' => 'badge text-bg-success',
    'respinta' => 'badge text-bg-danger',
    'errore_api' => 'badge text-bg-danger',
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

$dati = $request['dati_intestatario'] ?? [];
$displayName = trim(($dati['denominazione'] ?? '') !== '' ? (string) $dati['denominazione'] : trim(($dati['cognome'] ?? '') . ' ' . ($dati['nome'] ?? '')));
if ($displayName === '') {
    $displayName = 'Richiesta #' . $requestId;
}

$provider = null;
if (!empty($request['docuengine_request_id'])) {
    $provider = 'DocuEngine';
} elseif (!empty($request['visengine_request_id'])) {
    $provider = 'VisEngine';
} elseif (!empty($request['catasto_request_id'])) {
    $provider = 'Catasto';
}

$moduleColor = '#0061ff';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <span class="badge text-bg-primary" style="background-color: <?php echo sanitize_output($moduleColor); ?>;">Certi³</span>
                <h1 class="h3 mb-1"><?php echo sanitize_output($displayName); ?></h1>
                <p class="text-muted mb-0">ID richiesta #<?php echo $requestId; ?> · <?php echo sanitize_output($categoryLabels[$request['categoria']] ?? ucfirst((string) $request['categoria'])); ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Tutte le richieste</a>
                <?php if (!empty($request['file_certificato'])): ?>
                <a class="btn btn-success" href="<?php echo base_url('api/certi/index.php?action=get_certificate&id=' . $requestId); ?>">
                    <i class="fa-solid fa-file-arrow-down me-2"></i>Scarica certificato
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['created'])): ?>
            <div class="alert alert-success">Richiesta creata con successo. Puoi ora completare la validazione o inviarla al provider.</div>
        <?php endif; ?>

        <?php foreach ($messages as $message): ?>
            <div class="alert alert-success" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>
        <?php foreach ($errorMessages as $message): ?>
            <div class="alert alert-danger" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-12 col-xl-8">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Dettagli richiesta</h2>
                        <span class="<?php echo $statusBadges[$request['stato']] ?? 'badge text-bg-secondary'; ?>"><?php echo sanitize_output($statusOptions[$request['stato']] ?? ucfirst((string) $request['stato'])); ?></span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Tipo certificato</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output((string) $request['tipo_certificato']); ?></dd>
                            <dt class="col-sm-4">Urgenza</dt>
                            <dd class="col-sm-8"><span class="badge <?php echo $request['urgenza'] === 'alta' ? 'text-bg-danger' : ($request['urgenza'] === 'low' ? 'text-bg-secondary' : 'text-bg-info'); ?>"><?php echo sanitize_output($urgencyLabels[$request['urgenza']] ?? ucfirst((string) $request['urgenza'])); ?></span></dd>
                            <dt class="col-sm-4">Assegnato a</dt>
                            <dd class="col-sm-8"><?php echo !empty($request['assegnato_a']) && isset($operators[(int) $request['assegnato_a']]) ? sanitize_output($operators[(int) $request['assegnato_a']]) : '<span class="text-muted">Non assegnato</span>'; ?></dd>
                            <dt class="col-sm-4">Provider</dt>
                            <dd class="col-sm-8"><?php echo $provider ? '<span class="badge" style="background-color: ' . sanitize_output($moduleColor) . ';">Acquisito da ' . sanitize_output($provider) . '</span>' : '<span class="text-muted">Non inviato</span>'; ?></dd>
                            <dt class="col-sm-4">Note interne</dt>
                            <dd class="col-sm-8"><?php echo $request['note_interne'] ? nl2br(sanitize_output((string) $request['note_interne'])) : '<span class="text-muted">Nessuna nota</span>'; ?></dd>
                        </dl>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Dati intestatario</h2>
                        <span class="badge text-bg-dark text-uppercase"><?php echo sanitize_output($dati['tipo'] ?? 'N/D'); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1 fw-semibold">Identità</p>
                                <p class="mb-2 text-muted">
                                    <?php echo sanitize_output($displayName); ?><br>
                                    CF/P.IVA: <?php echo sanitize_output((string) ($dati['cf_piva'] ?? 'N/D')); ?>
                                </p>
                                <?php if (!empty($dati['email'])): ?>
                                <p class="mb-1"><i class="fa-solid fa-envelope me-2"></i><?php echo sanitize_output((string) $dati['email']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($dati['telefono'])): ?>
                                <p class="mb-0"><i class="fa-solid fa-phone me-2"></i><?php echo sanitize_output((string) $dati['telefono']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1 fw-semibold">Indirizzo</p>
                                <p class="text-muted mb-2">
                                    <?php echo sanitize_output((string) ($dati['indirizzo'] ?? '')); ?><br>
                                    <?php echo sanitize_output((string) ($dati['cap'] ?? '')); ?> <?php echo sanitize_output((string) ($dati['comune'] ?? '')); ?> (<?php echo sanitize_output((string) ($dati['provincia'] ?? '')); ?>)
                                </p>
                                <?php if (!empty($dati['istat'])): ?>
                                <span class="badge bg-light text-dark">ISTAT: <?php echo sanitize_output((string) $dati['istat']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($request['categoria'] === 'catastale'): ?>
                        <hr>
                        <p class="fw-semibold mb-1">Parametri catastali</p>
                        <p class="text-muted mb-0">
                            Foglio: <?php echo sanitize_output((string) ($dati['catasto']['foglio'] ?? 'N/D')); ?> · Particella: <?php echo sanitize_output((string) ($dati['catasto']['particella'] ?? 'N/D')); ?> · Sub: <?php echo sanitize_output((string) ($dati['catasto']['subalterno'] ?? 'N/D')); ?>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Timeline attività</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!$timeline): ?>
                            <p class="text-muted mb-0">Non ci sono attività registrate per questa richiesta.</p>
                        <?php else: ?>
                            <ul class="timeline list-unstyled mb-0">
                                <?php foreach ($timeline as $entry): ?>
                                <li class="mb-4">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <p class="mb-1 fw-semibold"><?php echo sanitize_output((string) $entry['action']); ?></p>
                                            <p class="mb-1 text-muted small">Eseguito da <?php echo sanitize_output((string) ($entry['actor_name'] ?? 'Sistema')); ?></p>
                                            <?php if (!empty($entry['details'])): ?>
                                                <p class="mb-0 small"><?php echo sanitize_output((string) $entry['details']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <span class="text-muted small"><?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $entry['created_at']))); ?></span>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4 d-flex flex-column gap-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Assegnazione</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" class="d-flex flex-column gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="assign">
                            <select class="form-select" name="operator_id" required>
                                <option value="">Seleziona operatore</option>
                                <?php foreach ($operators as $id => $label): ?>
                                <option value="<?php echo (int) $id; ?>" <?php echo !empty($request['assegnato_a']) && (int) $request['assegnato_a'] === (int) $id ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary" type="submit"><i class="fa-solid fa-user-check me-2"></i>Assegna</button>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Aggiorna stato</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" class="d-flex flex-column gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="status">
                            <select class="form-select" name="status" required>
                                <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $request['stato'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-warning text-dark" type="submit"><i class="fa-solid fa-arrows-rotate me-2"></i>Aggiorna</button>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Documento</h2>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="fetch_provider">
                            <button class="btn btn-outline-info w-100" type="submit" <?php echo $provider ? '' : 'disabled'; ?>>
                                <i class="fa-solid fa-cloud-arrow-down me-2"></i>Scarica dal provider
                            </button>
                            <?php if (!$provider): ?>
                                <small class="text-muted">Invia la pratica al provider prima di recuperare l'esito.</small>
                            <?php endif; ?>
                        </form>
                        <form method="post" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="upload_certificate">
                            <label class="form-label" for="certificate_file">Caricamento manuale (PDF)</label>
                            <input class="form-control" type="file" id="certificate_file" name="certificate_file" accept="application/pdf" required>
                            <button class="btn btn-outline-success w-100 mt-2" type="submit"><i class="fa-solid fa-upload me-2"></i>Carica documento</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script src="<?php echo asset('assets/js/certi-module.js'); ?>" defer></script>
