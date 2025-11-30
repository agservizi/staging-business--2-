<?php
declare(strict_types=1);

use Throwable;

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/payment_finalizer.php';
require_once __DIR__ . '/includes/brt_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pageTitle = 'Pagamento spedizione BRT';

$referenceParam = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
$reference = strtoupper(trim($referenceParam));
$reference = preg_replace('/[^A-Z0-9]/', '', $reference ?? '');

$paymentManager = new PickupPortalPaymentManager();
$payment = null;
$status = null;
$shipment = null;
$messages = [];
$errors = [];
$finalizationAttempted = false;
$finalizationMessage = null;
$shipmentFetchError = null;

if ($reference === '') {
    $errors[] = 'Riferimento pagamento mancante.';
} else {
    $payment = $paymentManager->findByReference($reference);
    if ($payment === null) {
        $errors[] = 'Pagamento non trovato. Verifica il link ricevuto da Stripe.';
    } elseif ((int) $payment['customer_id'] !== (int) ($customer['id'] ?? 0)) {
        $errors[] = 'Non hai accesso a questo pagamento.';
        $payment = null;
    }
}

if ($payment !== null) {
    $status = (string) $payment['status'];

    if (in_array($status, ['pending', 'processing'], true)) {
        try {
            $finalizationAttempted = true;
            $result = portal_finalize_payment($payment, (int) $customer['id']);
            $payment = $result['payment'];
            $status = $result['status'];
            $shipment = $result['shipment'];
            $finalizationMessage = $result['message'];
        } catch (Throwable $exception) {
            $finalizationAttempted = true;
            $errors[] = $exception->getMessage();
            $payment = $paymentManager->findByReference($reference) ?? $payment;
            $status = (string) ($payment['status'] ?? 'failed');
        }
    }

    if ($shipment === null && isset($payment['shipment_portal_id']) && (int) $payment['shipment_portal_id'] > 0) {
        try {
            $service = new PickupBrtService();
            $shipment = $service->getShipment((int) $customer['id'], (int) $payment['shipment_portal_id']);
        } catch (Throwable $exception) {
            $shipmentFetchError = $exception->getMessage();
        }
    }
}

$statusMap = [
    'paid' => ['label' => 'Pagato', 'badge' => 'success', 'hint' => 'Pagamento completato. La spedizione è stata creata.'],
    'pending' => ['label' => 'In attesa', 'badge' => 'warning', 'hint' => 'Stripe non ha ancora confermato il pagamento.'],
    'processing' => ['label' => 'In elaborazione', 'badge' => 'info', 'hint' => 'Stiamo verificando l\'esito del pagamento.'],
    'failed' => ['label' => 'Errore', 'badge' => 'danger', 'hint' => 'Il pagamento è andato a buon fine ma la spedizione non è stata generata.'],
    'cancelled' => ['label' => 'Annullato', 'badge' => 'secondary', 'hint' => 'Pagamento annullato dal cliente.'],
];

$showDropoffModal = $payment !== null && $status === 'paid' && $shipment !== null;

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>

<div class="portal-main d-flex flex-column flex-grow-1">
    <?php include __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-receipt text-primary"></i>
                    Stato pagamento spedizione BRT
                </h1>
                <p class="text-muted-soft mb-0">Aggiorna questa pagina per verificare l\'esito del pagamento Stripe.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-start justify-content-lg-end">
                <a class="btn topbar-btn" href="brt-shipment-create.php">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span class="topbar-btn-label">Torna al form</span>
                </a>
                <a class="btn topbar-btn" href="brt-shipments.php">
                    <i class="fa-solid fa-truck"></i>
                    <span class="topbar-btn-label">Le mie spedizioni</span>
                </a>
            </div>
        </div>

        <?php if ($errors !== []): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-circle-exclamation me-2"></i>
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($payment !== null): ?>
            <?php $statusDescriptor = $statusMap[$status] ?? ['label' => ucfirst($status), 'badge' => 'secondary', 'hint' => '']; ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4 p-lg-5">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start align-items-lg-center">
                        <div>
                            <h2 class="h5 mb-1">Pagamento #<?= htmlspecialchars($payment['public_reference'], ENT_QUOTES, 'UTF-8') ?></h2>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge text-bg-<?= htmlspecialchars($statusDescriptor['badge'], ENT_QUOTES, 'UTF-8') ?> text-uppercase fw-semibold px-3 py-2">
                                    <?= htmlspecialchars($statusDescriptor['label'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <?php if ($finalizationAttempted && $finalizationMessage): ?>
                                    <span class="text-muted small"><?= htmlspecialchars($finalizationMessage, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php else: ?>
                                    <span class="text-muted small"><?= htmlspecialchars($statusDescriptor['hint'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </div>
                            <dl class="row small mb-0">
                                <dt class="col-sm-4">Importo</dt>
                                <dd class="col-sm-8 fw-semibold">
                                    <?php
                                    $amountFormatted = number_format(((int) $payment['amount_cents']) / 100, 2, ',', '.');
                                    $currency = strtoupper((string) $payment['currency']);
                                    echo htmlspecialchars($amountFormatted . ' ' . $currency, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </dd>
                                <dt class="col-sm-4">Scaglione</dt>
                                <dd class="col-sm-8"><?= htmlspecialchars((string) $payment['tier_label'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <dt class="col-sm-4">Creato il</dt>
                                <dd class="col-sm-8"><?= htmlspecialchars((string) $payment['created_at'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <?php if (!empty($payment['paid_at'])): ?>
                                    <dt class="col-sm-4">Pagato il</dt>
                                    <dd class="col-sm-8"><?= htmlspecialchars((string) $payment['paid_at'], ENT_QUOTES, 'UTF-8') ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                        <div class="text-lg-end">
                            <?php if (in_array($status, ['pending', 'processing'], true)): ?>
                                <button class="btn btn-outline-primary" type="button" id="refreshPaymentStatus">
                                    <i class="fa-solid fa-rotate"></i>
                                    Aggiorna stato
                                </button>
                                <p class="text-muted small mt-2 mb-0" id="refreshHint">Stripe potrebbe impiegare qualche secondo a confermare il pagamento.</p>
                            <?php elseif ($status === 'paid' && $shipment !== null): ?>
                                <a class="btn btn-success" href="brt-shipments.php">
                                    <i class="fa-solid fa-badge-check"></i>
                                    Vedi spedizione
                                </a>
                            <?php elseif ($status === 'failed'): ?>
                                <p class="text-danger small mb-0">Contatta l&#39;assistenza indicando il riferimento.</p>
                            <?php elseif ($status === 'cancelled'): ?>
                                <p class="text-muted small mb-0">Hai annullato il pagamento su Stripe.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($shipment !== null): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-body-secondary border-0 py-3 px-4">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-truck text-success"></i>
                            <span class="fw-semibold">Spedizione generata</span>
                        </div>
                    </div>
                    <div class="card-body p-4">
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4">Riferimento numerico</dt>
                            <dd class="col-sm-8 fw-semibold"><?= htmlspecialchars((string) ($shipment['reference']['numeric'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt class="col-sm-4">Riferimento alfanumerico</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars((string) ($shipment['reference']['alphanumeric'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt class="col-sm-4">Destinatario</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars((string) ($shipment['destination']['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt class="col-sm-4">Tracking</dt>
                            <dd class="col-sm-8"><?= htmlspecialchars((string) ($shipment['tracking_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></dd>
                        </dl>
                    </div>
                </div>
            <?php elseif ($shipmentFetchError !== null): ?>
                <div class="alert alert-warning" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2"></i>
                    <?= htmlspecialchars('Spedizione creata ma impossibile recuperare i dettagli: ' . $shipmentFetchError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

<?php if ($showDropoffModal): ?>
<div class="modal fade" id="dropoffModal" tabindex="-1" aria-labelledby="dropoffModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="dropoffModalLabel">Punto di consegna spedizione</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div class="card border-0">
                    <div class="card-body">
                        <p class="mb-3">Consegnare il pacco presso <strong>AG SERVIZI, Via Plinio il Vecchio 72, 80053 Castellammare di Stabia (NA)</strong>. Ricorda di portare con te etichetta e borderò stampati.</p>
                        <div class="rounded-3 overflow-hidden border" id="dropoffMap" style="height: 320px;"></div>
                        <p class="text-muted small mt-3 mb-0"><i class="fa-solid fa-location-dot me-2 text-primary"></i>Coordinate: 40.69974, 14.48523</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <small class="text-muted">Una volta consegnato il collo, conserva la ricevuta rilasciata dal punto BRT.</small>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Ho capito</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($payment !== null && in_array($status, ['pending', 'processing'], true) && $reference !== ''): ?>
<script>
(function() {
    const button = document.getElementById('refreshPaymentStatus');
    const hint = document.getElementById('refreshHint');
    const POLL_INTERVAL = 4000;
    let polling = false;

    async function refreshStatus(forceAlert) {
        if (polling) {
            return;
        }
        polling = true;
        try {
            const response = await fetch('api/payments/status.php?ref=<?= urlencode($reference) ?>', {
                headers: {'Accept': 'application/json'}
            });
            const body = await response.json();
            if (!response.ok) {
                throw new Error(body?.error || response.statusText || 'Errore di rete');
            }
            if (body.status === 'paid') {
                window.location.reload();
                return;
            }
            if (forceAlert && body.message) {
                alert(body.message);
            }
        } catch (error) {
            console.error('Refresh status error', error);
            if (forceAlert) {
                alert(error.message || 'Errore durante l\'aggiornamento dello stato.');
            }
        } finally {
            polling = false;
        }
    }

    if (button) {
        button.addEventListener('click', function() {
            refreshStatus(true);
        });
    }

    setInterval(() => refreshStatus(false), POLL_INTERVAL);
    if (hint) {
        hint.textContent = 'Stiamo verificando automaticamente con Stripe. Questa pagina si aggiornerà appena il pagamento sarà confermato.';
    }
})();
</script>
<?php endif; ?>

<?php if ($showDropoffModal): ?>
<script>
(function() {
    const MAP_COORDS = [40.69974, 14.48523];

    const resolveTileTemplate = () => {
        if (window.PickupPortal && typeof window.PickupPortal.getLeafletTileUrlTemplate === 'function') {
            return window.PickupPortal.getLeafletTileUrlTemplate();
        }

        const base = window.portalConfig && typeof window.portalConfig.apiBaseUrl === 'string'
            ? window.portalConfig.apiBaseUrl
            : 'api/';
        const normalized = base.endsWith('/') ? base : `${base}/`;
        return `${normalized}leaflet-tiles.php?z={z}&x={x}&y={y}`;
    };

    function showModalWithMap() {
        const modalElement = document.getElementById('dropoffModal');
        if (!modalElement) {
            return;
        }

        const modal = new bootstrap.Modal(modalElement, {backdrop: 'static', keyboard: true});
        modal.show();

        modalElement.addEventListener('shown.bs.modal', function handleShown() {
            modalElement.removeEventListener('shown.bs.modal', handleShown);
            const mapContainer = document.getElementById('dropoffMap');
            if (!mapContainer) {
                return;
            }

            const map = L.map(mapContainer, {
                scrollWheelZoom: false,
                zoomControl: true
            }).setView(MAP_COORDS, 17);

            L.tileLayer(resolveTileTemplate(), {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                maxZoom: 19
            }).addTo(map);

            L.marker(MAP_COORDS).addTo(map)
                .bindPopup('<strong>AG SERVIZI</strong><br>Via Plinio il Vecchio 72<br>80053 Castellammare di Stabia (NA)')
                .openPopup();
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (window.PickupPortal && typeof window.PickupPortal.ensureLeaflet === 'function') {
            window.PickupPortal.ensureLeaflet(showModalWithMap);
        } else if (window.L && typeof window.L.map === 'function') {
            showModalWithMap();
        } else {
            console.error('PickupPortal.ensureLeaflet non disponibile: impossibile caricare la mappa.');
        }
    });
})();
</script>
<?php endif; ?>
