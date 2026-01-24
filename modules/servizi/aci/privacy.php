<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Privacy Policy - Pratiche ACI';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Privacy Policy</h1>
                <p class="text-muted mb-0">Informativa per la gestione delle pratiche ACI.</p>
            </div>
        </div>
        <div class="card ag-card">
            <div class="card-body">
                <p class="mb-2">I dati raccolti sono utilizzati esclusivamente per l’esecuzione della pratica ACI richiesta.</p>
                <ul class="mb-0">
                    <li>Trattamento conforme al GDPR.</li>
                    <li>Conservazione limitata al tempo necessario.</li>
                    <li>Diritti dell’interessato garantiti.</li>
                </ul>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
