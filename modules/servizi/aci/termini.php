<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Termini del servizio - Pratiche ACI';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Termini del servizio</h1>
                <p class="text-muted mb-0">Condizioni per le pratiche ACI.</p>
            </div>
        </div>
        <div class="card ag-card">
            <div class="card-body">
                <p class="mb-2">Le richieste vengono evase previa verifica della documentazione e dei consensi.</p>
                <ul class="mb-0">
                    <li>Le pratiche incomplete possono essere sospese o rifiutate.</li>
                    <li>I tempi dipendono dagli enti competenti.</li>
                    <li>La ricevuta può essere richiesta dall’operatore.</li>
                </ul>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
