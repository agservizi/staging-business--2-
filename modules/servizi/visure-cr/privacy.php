<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Informativa Privacy - Visura CR';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Informativa Privacy</h1>
                <p class="text-muted mb-0">Visura CR - trattamento dei dati personali.</p>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <p class="mb-2">Questa informativa descrive le finalità e le modalità di trattamento dei dati personali raccolti per la richiesta di Visura CR.</p>
                <ul class="mb-0">
                    <li>Dati trattati esclusivamente per l’esecuzione del servizio richiesto.</li>
                    <li>Conservazione per il tempo necessario all’evasione e agli obblighi di legge.</li>
                    <li>Diritti dell’interessato: accesso, rettifica, cancellazione, limitazione e opposizione.</li>
                </ul>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
