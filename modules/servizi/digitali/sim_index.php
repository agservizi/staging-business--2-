<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Vendite SIM';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h3 mb-0">Vendite SIM</h1>
            <a class="btn btn-primary" href="create_sim.php">
                <i class="fa-solid fa-plus"></i> Nuova vendita SIM
            </a>
        </div>
        <div class="card ag-card">
            <div class="card-body">
                <p class="text-muted">Modulo per la gestione delle vendite SIM con registrazione automatica delle entrate e sincronizzazione con Coresuite Express.</p>
                <div class="alert alert-info">
                    <strong>Funzionalità implementate:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Registrazione automatica delle entrate nel database locale</li>
                        <li>Sincronizzazione con Coresuite Express per clienti e vendite</li>
                        <li>Validazione completa dei dati di input</li>
                        <li>Gestione errori e logging</li>
                    </ul>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>