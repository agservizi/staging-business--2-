<?php
declare(strict_types=1);

use Throwable;

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/brt_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pageTitle = 'Spedizioni BRT';

$brtModuleReady = true;
$brtModuleError = null;

try {
    new PickupBrtService();
} catch (Throwable $exception) {
    $brtModuleReady = false;
    $brtModuleError = $exception->getMessage();
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="portal-main d-flex flex-column flex-grow-1">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-truck-fast text-primary"></i>
                    Spedizioni BRT
                </h1>
                <p class="text-muted-soft mb-0">Gestisci le spedizioni create dal portale clienti, ristampa le etichette e aggiorna il tracking in tempo reale.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
                <a class="btn topbar-btn" href="brt-shipment-create.php">
                    <i class="fa-solid fa-circle-plus"></i>
                    <span class="topbar-btn-label">Nuova spedizione</span>
                </a>
                <button class="btn topbar-btn" type="button" data-action="reload-shipments">
                    <i class="fa-solid fa-rotate"></i>
                    <span class="topbar-btn-label">Aggiorna elenco</span>
                </button>
            </div>
        </div>

        <?php if (!$brtModuleReady): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                <?= htmlspecialchars($brtModuleError ?? 'Modulo BRT non disponibile', ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div id="brt-shipments-app" class="d-flex flex-column gap-4" data-ready="<?= $brtModuleReady ? '1' : '0' ?>" data-error="<?= htmlspecialchars($brtModuleError ?? '', ENT_QUOTES, 'UTF-8') ?>" data-limit="10">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                        <div class="text-muted-soft small">
                            Totale spedizioni: <span class="fw-semibold" data-role="shipments-total">0</span>
                        </div>
                        <div class="text-muted-soft small">
                            Ultimo aggiornamento: <span data-role="shipments-updated">-</span>
                        </div>
                    </div>

                    <form id="brtFilters" class="row g-3 align-items-end mb-4" autocomplete="off">
                        <div class="col-md-6 col-lg-5">
                            <label class="form-label" for="brtSearch">Ricerca</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input class="form-control" id="brtSearch" type="search" placeholder="Cerca per destinatario, riferimento o tracking">
                            </div>
                        </div>
                        <div class="col-md-4 col-lg-3">
                            <label class="form-label" for="brtStatus">Stato</label>
                            <select class="form-select" id="brtStatus">
                                <option value="all">Tutti</option>
                                <option value="created">Creati</option>
                                <option value="confirmed">Confermati</option>
                                <option value="warning">Attenzione</option>
                                <option value="cancelled">Annullati</option>
                            </select>
                        </div>
                        <div class="col-md-2 col-lg-2">
                            <label class="form-label" for="brtPageSize">Per pagina</label>
                            <select class="form-select" id="brtPageSize" disabled>
                                <option value="10" selected>10</option>
                            </select>
                        </div>
                        <div class="col-12 col-lg-2 d-flex gap-2">
                            <button class="btn btn-primary flex-grow-1" type="submit">
                                <i class="fa-solid fa-filter me-1"></i>
                                Applica
                            </button>
                            <button class="btn btn-outline-secondary" type="button" data-action="reset-filters" title="Azzera filtri">
                                <i class="fa-solid fa-eraser"></i>
                            </button>
                        </div>
                    </form>

                    <div class="alert alert-danger d-none" role="alert" data-role="shipments-error"></div>

                    <div class="text-center text-muted-soft py-5 d-none" data-role="shipments-empty">
                        <i class="fa-solid fa-truck-fast fa-2x mb-3"></i>
                        <p class="mb-0">Non ci sono spedizioni registrate al momento. Crea la prima spedizione per iniziare.</p>
                    </div>

                    <div class="list-group list-group-flush" data-role="shipments-list"></div>

                    <div class="mt-4" data-role="shipments-pagination"></div>
                </div>
            </div>
        </div>
    </main>
</div>


<?php include __DIR__ . '/includes/footer.php'; ?>
