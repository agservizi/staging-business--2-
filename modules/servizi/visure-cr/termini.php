<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Termini del servizio - Visura CR';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Termini del servizio</h1>
                <p class="text-muted mb-0">Condizioni per la richiesta di Visura CR.</p>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <p class="mb-2">Il servizio di Visura CR viene erogato previa verifica della documentazione e dei consensi obbligatori.</p>
                <ul class="mb-0">
                    <li>Le richieste incomplete o non conformi possono essere rifiutate.</li>
                    <li>Le tempistiche dipendono dagli enti competenti.</li>
                    <li>Le ricevute e i documenti vengono consegnati tramite canali concordati.</li>
                </ul>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
