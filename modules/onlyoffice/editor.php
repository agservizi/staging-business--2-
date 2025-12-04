<?php

declare(strict_types=1);

use Modules\Onlyoffice as OnlyOffice;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/config.php';

require_role('Admin', 'Operatore', 'Manager', 'Support', 'Viewer');

$id = $_GET['id'] ?? '';
if ($id === '') {
    add_flash('danger', 'Documento non trovato.');
    header('Location: index.php');
    exit;
}

$roleMap = [
    'Admin' => 'admin',
    'Operatore' => 'operator',
    'Manager' => 'manager',
    'Support' => 'support',
    'Viewer' => 'viewer',
];

$currentRole = $_SESSION['role'] ?? 'user';
$normalizedRole = $roleMap[$currentRole] ?? 'user';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    $_SESSION['user'] = [];
}

$_SESSION['user']['id'] = (int) ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
$_SESSION['user']['name'] = $_SESSION['user']['name'] ?? current_user_display_name();
$_SESSION['user']['email'] = $_SESSION['user']['email'] ?? ($_SESSION['email'] ?? '');
$_SESSION['user']['role'] = $normalizedRole;

try {
    $user = OnlyOffice\requireUser();
    $file = OnlyOffice\getFileInfo($id);
    $config = OnlyOffice\buildDocumentConfig($file, $user);
    $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $documentServer = rtrim(Modules\Onlyoffice\DOCUMENT_SERVER_URL, '/');
} catch (Throwable $exception) {
    add_flash('danger', 'Impossibile aprire il documento: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}

$pageTitle = 'Editor ONLYOFFICE';
$extraStyles = [asset('modules/onlyoffice/assets/css/editor.css')];
$extraScripts = [
    $documentServer . '/web-apps/apps/api/documents/api.js',
    asset('modules/onlyoffice/assets/js/editor.js'),
];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper onlyoffice-dashboard">
        <div class="page-toolbar mb-4 d-flex flex-column flex-lg-row justify-content-between align-items-start align-items-lg-center gap-3">
            <div>
                <a class="btn btn-link px-0 mb-2" href="index.php">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna all'elenco
                </a>
                <h1 class="h3 mb-1"><?php echo sanitize_output($file['name']); ?></h1>
                <p class="text-muted mb-0">
                    Tipo: <?php echo strtoupper(sanitize_output($file['extension'])); ?> · Ultimo aggiornamento: <?php echo sanitize_output(date('d/m/Y H:i', (int) $file['updatedAt'])); ?>
                </p>
            </div>
            <div class="oo-editor-actions">
                <button id="oo-btn-save" type="button" class="btn onlyoffice-btn">Salva</button>
                <button id="oo-btn-close" type="button" class="btn btn-outline-secondary">Chiudi editor</button>
            </div>
        </div>

        <div class="onlyoffice-editor-shell card ag-card">
            <div class="card-body p-0">
                <div id="onlyoffice-editor" class="onlyoffice-editor onlyoffice-editor-embed"></div>
            </div>
        </div>
</main>
</div>
<script>
    window.onlyofficeConfig = <?php echo $configJson; ?>;
    window.onlyofficeDocId = <?php echo json_encode($file['id'], JSON_THROW_ON_ERROR); ?>;
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
