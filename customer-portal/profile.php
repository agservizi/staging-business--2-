<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pickup_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pickupService = new PickupService();

$alerts = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Riprova a salvare i dati.';
    } else {
        try {
            $updated = $pickupService->updateCustomerProfile((int) $customer['id'], $_POST);
            $customer = $updated;
            $_SESSION['customer_name'] = $customer['name'];
            $_SESSION['customer_email'] = $customer['email'];
            $_SESSION['customer_id'] = $customer['id'];
            $alerts[] = 'Profilo aggiornato correttamente.';
        } catch (Exception $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$summary = $pickupService->getCustomerSummary((int) $customer['id']);
$preferences = $pickupService->getCustomerPreferences((int) $customer['id']);
$activity = $pickupService->getCustomerActivity((int) $customer['id'], 10);

$actionLabels = [
    'profile_updated' => 'Profilo aggiornato',
    'package_reported' => 'Segnalazione creata',
    'package_report_created' => 'Segnalazione creata',
    'preferences_updated' => 'Preferenze aggiornate',
    'notifications_cleared' => 'Notifiche lette',
    'support_request_sent' => 'Richiesta assistenza inviata',
];

$pageTitle = 'Profilo';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2"><i class="fa-solid fa-id-badge text-primary"></i>Profilo account</h1>
                <p class="text-muted-soft mb-0">Gestisci i dati di contatto utilizzati per gli accessi e le comunicazioni del portale clienti.</p>
            </div>
            <a class="btn topbar-btn" href="settings.php">
                <i class="fa-solid fa-sliders"></i>
                <span class="topbar-btn-label">Preferenze notifiche</span>
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($message) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($alerts as $message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
            </div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-12 col-xl-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h5 mb-1">Dati anagrafici</h2>
                        <p class="text-muted-soft mb-0">I recapiti indicati verranno utilizzati per inviare le notifiche di stato.</p>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="profile.php" novalidate class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">

                            <div class="col-md-6">
                                <label class="form-label" for="profile-name">Nome e cognome</label>
                                <input class="form-control form-control-lg" id="profile-name" name="name" value="<?= htmlspecialchars($customer['name'] ?? '') ?>" placeholder="Inserisci il referente principale">
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="profile-email">Email</label>
                                <input class="form-control form-control-lg" id="profile-email" name="email" type="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" placeholder="es. nome@azienda.it">
                                <small class="text-muted">Utilizzata per inviare il codice OTP e le notifiche operative.</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label" for="profile-phone">Telefono</label>
                                <input class="form-control form-control-lg" id="profile-phone" name="phone" value="<?= htmlspecialchars($customer['phone'] ?? '') ?>" placeholder="Formato internazionale es. +390123456789">
                                <small class="text-muted">Richiesto per notifiche SMS. Inserire con prefisso internazionale.</small>
                            </div>

                            <div class="col-12 d-flex justify-content-between align-items-center mt-3">
                                <small class="text-muted">I dati vengono salvati in tempo reale e utilizzati dai nostri operatori per eventuali contatti.</small>
                                <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva modifiche</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h6 mb-1">Stato account</h2>
                        <p class="text-muted-soft mb-0">Riepilogo delle informazioni registrate nel sistema.</p>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted">ID cliente</dt>
                            <dd class="col-7">#<?= (int) $customer['id'] ?></dd>

                            <dt class="col-5 text-muted">Stato</dt>
                            <dd class="col-7"><span class="badge bg-<?= $summary['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($summary['status'])) ?></span></dd>

                            <dt class="col-5 text-muted">Email verificata</dt>
                            <dd class="col-7"><?= !empty($summary['email_verified']) ? 'Sì' : 'No' ?></dd>

                            <dt class="col-5 text-muted">Telefono verificato</dt>
                            <dd class="col-7"><?= !empty($summary['phone_verified']) ? 'Sì' : 'No' ?></dd>

                            <dt class="col-5 text-muted">Ultimo accesso</dt>
                            <dd class="col-7">
                                <?= !empty($summary['last_login']) ? date('d/m/Y H:i', strtotime($summary['last_login'])) : 'N/D' ?>
                            </dd>

                            <dt class="col-5 text-muted">Registrato il</dt>
                            <dd class="col-7"><?= date('d/m/Y', strtotime($summary['created_at'])) ?></dd>

                            <dt class="col-5 text-muted">Lingua</dt>
                            <dd class="col-7"><?= strtoupper($preferences['language']) ?></dd>

                            <dt class="col-5 text-muted">Timezone</dt>
                            <dd class="col-7"><?= htmlspecialchars($preferences['timezone']) ?></dd>
                        </dl>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0 d-flex justify-content-between align-items-center">
                        <h2 class="h6 mb-0">Attività recenti</h2>
                        <span class="small text-muted-soft">Ultimi <?= count($activity) ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($activity)): ?>
                            <p class="text-muted-soft mb-0">Non sono ancora registrate attività per il tuo profilo.</p>
                        <?php else: ?>
                            <ul class="list-unstyled mb-0 small">
                                <?php foreach ($activity as $log): ?>
                                    <?php
                                    $details = [];
                                    if (!empty($log['details'])) {
                                        $details = json_decode((string) $log['details'], true) ?: [];
                                    }
                                    $label = $actionLabels[$log['action']] ?? ucfirst(str_replace('_', ' ', $log['action']));
                                    ?>
                                    <li class="mb-3">
                                        <div class="fw-semibold d-flex justify-content-between">
                                            <span><?= htmlspecialchars($label) ?></span>
                                            <span class="text-muted"><?= date('d/m H:i', strtotime($log['created_at'])) ?></span>
                                        </div>
                                        <?php if (!empty($details)): ?>
                                            <div class="text-muted">
                                                <?php foreach ($details as $key => $value): ?>
                                                    <div><?= htmlspecialchars($key) ?>: <?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value)) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
