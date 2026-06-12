<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Live Board Pickup';
$feedUrl = base_url('api/pickup-report-feed.php');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Live Board Pickup</h1>
                <p class="text-muted mb-0">Monitoraggio in tempo reale delle segnalazioni dal portale clienti.</p>
            </div>
            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Logistica</a>
        </div>

        <div class="card ag-card" id="pickupLiveBoard" data-feed-url="<?php echo sanitize_output($feedUrl); ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="badge bg-success" id="pickupLiveStatus">Live</span>
                    <small class="text-muted" id="pickupLiveUpdated">Aggiornamento...</small>
                </div>
                <div id="pickupLiveEvents" class="list-group list-group-flush"></div>
            </div>
        </div>
    </main>
</div>
<script src="<?php echo asset('assets/js/pickup-live-board.js'); ?>"></script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
