<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Patronato');

$practiceId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($practiceId <= 0) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

$currentRole = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'Operatore';
$isPatronatoUser = current_user_can('Patronato');
$canManageServices = current_user_has_capability('services.manage');
$canCreatePractices = in_array($currentRole, ['Admin', 'Manager', 'Operatore'], true);
$canManagePractices = $isPatronatoUser || $canCreatePractices;
$useLegacyCreate = $canCreatePractices;

if (!$canManagePractices && !$canManageServices) {
    add_flash('warning', 'Non hai i permessi per modificare le pratiche.');
    header('Location: view.php?id=' . $practiceId);
    exit;
}

try {
    $projectRoot = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 4);
    $service = new PracticesService($pdo, $projectRoot);
} catch (Throwable $exception) {
    error_log('CAF/Patronato bootstrap failed: ' . $exception->getMessage());
    add_flash('danger', 'Modulo CAF & Patronato temporaneamente non disponibile.');
    header('Location: index.php');
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$operatorId = null;
try {
    $operatorId = $service->findOperatorIdByUser($userId);
} catch (Throwable $exception) {
    $operatorId = null;
}

try {
    $practice = $service->getPractice($practiceId, $canManagePractices || $canManageServices, $operatorId);
} catch (RuntimeException $exception) {
    add_flash('warning', 'Pratica non trovata oppure accesso non consentito.');
    header('Location: index.php');
    exit;
}

$practiceTitle = trim((string) ($practice['titolo'] ?? ''));
if ($practiceTitle === '') {
    $practiceTitle = 'Pratica #' . $practiceId;
}

$category = strtoupper((string) ($practice['categoria'] ?? 'CAF'));
$categoryBadgeClass = $category === 'PATRONATO' ? 'bg-warning text-dark' : 'bg-info';

$assignedLabel = 'Non assegnata';
if (isset($practice['assegnatario']) && is_array($practice['assegnatario'])) {
    $nameParts = array_filter([
        trim((string) ($practice['assegnatario']['nome'] ?? '')),
        trim((string) ($practice['assegnatario']['cognome'] ?? '')),
    ]);
    if ($nameParts) {
        $assignedLabel = implode(' ', $nameParts);
    } elseif (!empty($practice['assegnatario']['email'])) {
        $assignedLabel = (string) $practice['assegnatario']['email'];
    }
}

$statusCode = (string) ($practice['stato'] ?? '');
$statusLabel = $statusCode !== '' ? $statusCode : 'Sconosciuto';
$statusBadgeClass = 'bg-secondary';

$statuses = [];
try {
    $statuses = $service->listStatuses();
} catch (Throwable $exception) {
    $statuses = [];
}

foreach ($statuses as $status) {
    if (!is_array($status)) {
        continue;
    }
    if (($status['codice'] ?? '') === $statusCode) {
        $statusLabel = (string) ($status['nome'] ?? $statusCode);
        $color = strtolower((string) ($status['colore'] ?? ''));
        if ($color !== '' && preg_match('/^[a-z0-9_-]+$/', $color) === 1) {
            $statusBadgeClass = 'bg-' . $color;
        }
        break;
    }
}

$createdAt = $practice['data_creazione'] ?? null;
$updatedAt = $practice['data_aggiornamento'] ?? null;
$deadlineAt = $practice['scadenza'] ?? null;

$pageTitle = 'Modifica pratica #' . $practiceId;
$createPracticeUrl = base_url('modules/servizi/caf-patronato/create.php');
$apiBaseUrl = base_url('api/caf-patronato/index.php');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div id="caf-patronato-context" class="d-none"
             data-can-configure="<?php echo $canManageServices ? '1' : '0'; ?>"
             data-can-manage-practices="<?php echo $canManagePractices ? '1' : '0'; ?>"
             data-can-create-practices="<?php echo $canCreatePractices ? '1' : '0'; ?>"
             data-is-patronato="<?php echo $isPatronatoUser ? '1' : '0'; ?>"
             data-use-legacy-create="<?php echo $useLegacyCreate ? '1' : '0'; ?>"
             data-create-url="<?php echo htmlspecialchars($createPracticeUrl, ENT_QUOTES, 'UTF-8'); ?>"
             data-operator-id="<?php echo $operatorId !== null ? (int) $operatorId : ''; ?>"
             data-api-base="<?php echo htmlspecialchars($apiBaseUrl, ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1"><span id="practice-edit-title"><?php echo sanitize_output($practiceTitle); ?></span></h1>
                <p class="text-muted mb-0">Aggiorna i dati della pratica e salva le modifiche.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="view.php?id=<?php echo $practiceId; ?>">
                    <i class="fa-solid fa-eye me-2"></i>Vedi pratica
                </a>
                <a class="btn btn-outline-warning" href="index.php">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna all'elenco
                </a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body d-flex flex-wrap align-items-center gap-3">
                <span class="badge <?php echo sanitize_output($statusBadgeClass); ?>" id="practice-edit-status"><?php echo sanitize_output($statusLabel); ?></span>
                <span class="badge <?php echo sanitize_output($categoryBadgeClass); ?>" id="practice-edit-category"><?php echo sanitize_output($category); ?></span>
                <span class="text-muted">ID: <span id="practice-edit-code">#<?php echo $practiceId; ?></span></span>
                <span class="text-muted">Operatore: <span id="practice-edit-operator"><?php echo sanitize_output($assignedLabel); ?></span></span>
                <span class="badge bg-warning text-dark<?php echo $deadlineAt ? '' : ' d-none'; ?>" data-role="practice-deadline">
                    Scadenza: <span id="practice-edit-deadline"><?php echo $deadlineAt ? sanitize_output(format_datetime_locale($deadlineAt)) : ''; ?></span>
                </span>
                <span class="text-muted">Creata il <span id="practice-edit-created"><?php echo $createdAt ? sanitize_output(format_datetime_locale($createdAt)) : 'N/D'; ?></span></span>
                <span class="text-muted">Aggiornata il <span id="practice-edit-updated"><?php echo $updatedAt ? sanitize_output(format_datetime_locale($updatedAt)) : 'N/D'; ?></span></span>
            </div>
        </div>

        <div id="caf-patronato-practice-edit" data-practice-id="<?php echo $practiceId; ?>">
            <div class="d-flex justify-content-center py-5">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Caricamento...</span>
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
