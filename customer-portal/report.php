<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pickup_service.php';

// Verifica autenticazione
if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pickupService = new PickupService();

$errors = [];
$success = false;
$reportData = [];

// Gestione form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Verifica CSRF token
        if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
            throw new Exception('Token di sicurezza non valido');
        }
        
        $reportData = [
            'tracking_code' => trim($_POST['tracking_code'] ?? ''),
            'courier_name' => trim($_POST['courier_name'] ?? ''),
            'recipient_name' => trim($_POST['recipient_name'] ?? ''),
            'expected_delivery_date' => trim($_POST['expected_delivery_date'] ?? '') ?: null,
            'delivery_location' => trim($_POST['delivery_location'] ?? ''),
            'notes' => trim($_POST['notes'] ?? '')
        ];
        
        // Validazione base
        if (empty($reportData['tracking_code'])) {
            $errors[] = 'Il codice tracking è obbligatorio';
        }
        
        if (strlen($reportData['tracking_code']) < portal_config('min_tracking_length')) {
            $errors[] = 'Il codice tracking deve essere di almeno ' . portal_config('min_tracking_length') . ' caratteri';
        }
        
        if (strlen($reportData['tracking_code']) > portal_config('max_tracking_length')) {
            $errors[] = 'Il codice tracking non può superare ' . portal_config('max_tracking_length') . ' caratteri';
        }
        
        if ($reportData['expected_delivery_date'] && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportData['expected_delivery_date'])) {
            $errors[] = 'Formato data consegna non valido';
        }
        
        if (empty($errors)) {
            $report = $pickupService->reportPackage($customer['id'], $reportData);
            $success = true;
            $reportData = []; // Reset form
            
            // Log attività
            $pickupService->logCustomerActivity($customer['id'], 'package_report_created', 'report', $report['id']);
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        portal_error_log('Package report error: ' . $e->getMessage(), [
            'customer_id' => $customer['id'],
            'data' => $reportData
        ]);
    }
}

$pageTitle = 'Segnala Pacco';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <div class="portal-main d-flex flex-column flex-grow-1">
            <?php require_once __DIR__ . '/includes/topbar.php'; ?>

            <main class="portal-content">
                <div class="d-flex flex-column flex-md-row align-items-start align-items-md-center justify-content-between gap-3 mb-4">
                    <div>
                        <h1 class="h3 mb-1"><i class="fa-solid fa-plus me-2 text-primary"></i>Segnala un nuovo pacco</h1>
                        <p class="text-muted-soft mb-0">Tieni sotto controllo i tuoi ordini registrando il tracking appena disponibile.</p>
                    </div>
                    <div class="d-flex gap-2">
                        <a class="btn topbar-btn" href="dashboard.php"><i class="fa-solid fa-arrow-left"></i><span class="topbar-btn-label">Dashboard</span></a>
                        <a class="btn topbar-btn" href="packages.php"><i class="fa-solid fa-boxes-stacked"></i><span class="topbar-btn-label">Pacchi</span></a>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
                        <div class="d-flex gap-3">
                            <span class="portal-stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></span>
                            <div>
                                <strong>Correggi i seguenti errori</strong>
                                <ul class="mb-0 mt-2">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?= htmlspecialchars($error) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="alert alert-success border-0 shadow-sm mb-4 alert-dismissible fade show" role="alert">
                        <div class="d-flex gap-3 align-items-start">
                            <span class="portal-stat-icon"><i class="fa-solid fa-circle-check"></i></span>
                            <div>
                                <strong>Segnalazione registrata correttamente!</strong>
                                <div class="text-muted">Riceverai un avviso quando il pacco arriverà presso il punto di ritiro.</div>
                            </div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <div class="col-12 col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="h5 mb-0">Dettagli del pacco</h2>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="" novalidate>
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label" for="tracking_code">Codice tracking *</label>
                                            <input class="form-control form-control-lg<?= in_array('Il codice tracking è obbligatorio', $errors, true) ? ' is-invalid' : '' ?>" id="tracking_code" name="tracking_code" value="<?= htmlspecialchars($reportData['tracking_code'] ?? '') ?>" placeholder="es. 1Z999AA1234567890" required maxlength="<?= portal_config('max_tracking_length') ?>">
                                            <small class="text-muted">Minimo <?= portal_config('min_tracking_length') ?> caratteri.</small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="courier_name">Corriere</label>
                                            <input class="form-control form-control-lg" id="courier_name" name="courier_name" value="<?= htmlspecialchars($reportData['courier_name'] ?? '') ?>" placeholder="es. DHL, GLS, Poste">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="recipient_name">Destinatario</label>
                                            <input class="form-control form-control-lg" id="recipient_name" name="recipient_name" value="<?= htmlspecialchars($reportData['recipient_name'] ?? $customer['name'] ?? '') ?>" placeholder="Chi ritirerà il pacco">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label" for="expected_delivery_date">Data consegna prevista</label>
                                            <input class="form-control form-control-lg" type="date" id="expected_delivery_date" name="expected_delivery_date" value="<?= htmlspecialchars($reportData['expected_delivery_date'] ?? '') ?>" min="<?= date('Y-m-d') ?>">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="delivery_location">Luogo di consegna / ritiro</label>
                                            <input class="form-control form-control-lg" id="delivery_location" name="delivery_location" value="<?= htmlspecialchars($reportData['delivery_location'] ?? '') ?>" placeholder="Ad esempio: Magazzino principale">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="notes">Note aggiuntive</label>
                                            <textarea class="form-control" id="notes" name="notes" rows="4" placeholder="Informazioni utili per il team"><?= htmlspecialchars($reportData['notes'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-4">
                                        <small class="text-muted">I campi contrassegnati con * sono obbligatori.</small>
                                        <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>Invia segnalazione</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-lg-4">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h2 class="h6 mb-0">Suggerimenti rapidi</h2>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li class="mb-3">
                                        <strong>1. Registra subito il codice</strong>
                                        <p class="text-muted small mb-0">Aumenta la precisione degli aggiornamenti inserendo il tracking non appena disponibile.</p>
                                    </li>
                                    <li class="mb-3">
                                        <strong>2. Aggiungi dettagli utili</strong>
                                        <p class="text-muted small mb-0">Corriere, destinatario e note ci aiutano a gestire il tuo pacco più velocemente.</p>
                                    </li>
                                    <li>
                                        <strong>3. Controlla lo stato</strong>
                                        <p class="text-muted small mb-0">Riceverai notifiche email o SMS quando il pacco arriva o rischia di scadere.</p>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div class="card">
                            <div class="card-header">
                                <h2 class="h6 mb-0">Ultime segnalazioni</h2>
                            </div>
                            <div class="card-body">
                                <?php $recentReports = $pickupService->getCustomerReports($customer['id'], ['limit' => 5]); ?>
                                <?php if (empty($recentReports)): ?>
                                    <p class="text-muted mb-0">Non hai ancora segnalato pacchi.</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($recentReports as $report): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                                                <div>
                                                    <div class="fw-semibold text-truncate"><?= htmlspecialchars($report['tracking_code']) ?></div>
                                                    <div class="text-muted small"><?= htmlspecialchars($report['courier_name'] ?? 'Corriere non indicato') ?></div>
                                                    <div class="text-muted small">Segnalato il <?= date('d/m/Y', strtotime($report['created_at'])) ?></div>
                                                </div>
                                                <span class="badge bg-<?= $report['status'] === 'reported' ? 'warning-subtle text-warning-emphasis' : 'success-subtle text-success-emphasis' ?> text-uppercase small align-self-start">
                                                    <?= ucfirst($report['status']) ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', () => {
            const trackingInput = document.getElementById('tracking_code');
            const minLength = <?= (int) portal_config('min_tracking_length') ?>;

            if (trackingInput) {
                trackingInput.addEventListener('input', function () {
                    const value = this.value.trim();
                    this.value = value;
                    if (value.length >= minLength) {
                        this.classList.add('is-valid');
                        this.classList.remove('is-invalid');
                    } else {
                        this.classList.remove('is-valid');
                    }
                });
            }

            const courierInput = document.getElementById('courier_name');
            if (courierInput) {
                const suggestions = ['Amazon', 'DHL', 'GLS', 'Poste Italiane', 'UPS', 'FedEx', 'TNT', 'Bartolini', 'SDA', 'Nexive', 'INPOST'];
                let datalist = document.getElementById('courier-suggestions');
                if (!datalist) {
                    datalist = document.createElement('datalist');
                    datalist.id = 'courier-suggestions';
                    suggestions.forEach((name) => {
                        const option = document.createElement('option');
                        option.value = name;
                        datalist.appendChild(option);
                    });
                    courierInput.setAttribute('list', 'courier-suggestions');
                    courierInput.parentNode.appendChild(datalist);
                }
            }

            const recipientInput = document.getElementById('recipient_name');
            if (recipientInput && !recipientInput.value) {
                recipientInput.value = <?= json_encode($customer['name'] ?? '') ?>;
            }
        });
        </script>

        <?php require_once __DIR__ . '/includes/footer.php'; ?>