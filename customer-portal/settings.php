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

$whatsappEnabled = (bool) portal_config('enable_whatsapp');

$preferences = $pickupService->getCustomerPreferences((int) $customer['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save-preferences';

    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Riprova a salvare le impostazioni.';
    } else {
        try {
            if ($action === 'delete-account') {
                $pickupService->deleteCustomerAccount((int) $customer['id']);
                CustomerAuth::logout();
                header('Location: login.php?message=' . urlencode('Il tuo account è stato eliminato con successo.'));
                exit;
            }

            $preferences = $pickupService->updateCustomerPreferences((int) $customer['id'], $_POST);
            $alerts[] = 'Preferenze aggiornate correttamente.';
        } catch (Exception $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

$languageOptions = [
    'it' => 'Italiano',
    'en' => 'English',
    'de' => 'Deutsch',
    'fr' => 'Français',
    'es' => 'Español'
];

$timezoneOptions = [
    'Europe/Rome' => 'Europe/Rome (UTC+01:00)',
    'Europe/Berlin' => 'Europe/Berlin (UTC+01:00)',
    'Europe/London' => 'Europe/London (UTC+00:00)',
    'Europe/Madrid' => 'Europe/Madrid (UTC+01:00)',
    'Europe/Paris' => 'Europe/Paris (UTC+01:00)',
    'America/New_York' => 'America/New_York (UTC-05:00)',
    'America/Chicago' => 'America/Chicago (UTC-06:00)',
    'America/Los_Angeles' => 'America/Los_Angeles (UTC-08:00)'
];

$pageTitle = 'Impostazioni';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2"><i class="fa-solid fa-sliders text-primary"></i>Preferenze e notifiche</h1>
                <p class="text-muted-soft mb-0">Personalizza modalità di contatto, lingua e fuso orario utilizzati per invii automatici e comunicazioni.</p>
            </div>
            <a class="btn topbar-btn" href="profile.php">
                <i class="fa-solid fa-id-card"></i>
                <span class="topbar-btn-label">Profilo account</span>
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
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h5 mb-1">Notifiche operative</h2>
                        <p class="text-muted-soft mb-0">Scegli come vuoi essere avvisato quando cambia lo stato dei tuoi pacchi.</p>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="settings.php" class="vstack gap-4">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                            <input type="hidden" name="action" value="save-preferences">

                            <div class="form-check form-switch">
                                <input class="form-check-input" id="notification-email" name="notification_email" type="checkbox" value="1" <?= !empty($preferences['notification_email']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-semibold" for="notification-email">Email</label>
                                <p class="text-muted small mb-0">Ricevi aggiornamenti istantanei nella casella di posta <?= htmlspecialchars($customer['email'] ?? '') ?>.</p>
                            </div>

                            <div class="form-check form-switch">
                                <input class="form-check-input" id="notification-whatsapp" name="notification_whatsapp" type="checkbox" value="1" <?= !empty($preferences['notification_whatsapp']) ? 'checked' : '' ?> <?= $whatsappEnabled ? '' : 'disabled' ?>>
                                <label class="form-check-label fw-semibold" for="notification-whatsapp">WhatsApp Business</label>
                                <p class="text-muted small mb-0">
                                    <?php if ($whatsappEnabled): ?>
                                        Riceverai aggiornamenti sullo stesso numero di telefono impostato nel profilo.
                                    <?php else: ?>
                                        Funzionalità non attiva. Contatta l'assistenza per abilitarla.
                                    <?php endif; ?>
                                </p>
                                <?php if (!$whatsappEnabled): ?>
                                    <input type="hidden" name="notification_whatsapp" value="0">
                                <?php endif; ?>
                            </div>

                            <hr class="text-muted opacity-10">

                            <div class="row g-4">
                                <div class="col-md-6">
                                    <label class="form-label" for="language-select">Lingua del portale</label>
                                    <select class="form-select form-select-lg" id="language-select" name="language">
                                        <?php foreach ($languageOptions as $code => $label): ?>
                                            <option value="<?= htmlspecialchars($code) ?>" <?= $preferences['language'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="timezone-select">Fuso orario</label>
                                    <select class="form-select form-select-lg" id="timezone-select" name="timezone">
                                        <?php foreach ($timezoneOptions as $tz => $label): ?>
                                            <option value="<?= htmlspecialchars($tz) ?>" <?= $preferences['timezone'] === $tz ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted">Le modifiche si applicano immediatamente alle prossime notifiche.</small>
                                <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva impostazioni</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h6 mb-1">Riepilogo invii</h2>
                        <p class="text-muted-soft mb-0">Controlla rapidamente quali canali sono attivi e sincronizzati.</p>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span><i class="fa-solid fa-envelope-open-text text-primary me-2"></i>Email</span>
                                <span class="badge <?= !empty($preferences['notification_email']) ? 'bg-success' : 'bg-secondary' ?>"><?= !empty($preferences['notification_email']) ? 'Attivo' : 'Disattivo' ?></span>
                            </li>
                            <li class="d-flex justify-content-between align-items-center py-2">
                                <span><i class="fa-brands fa-whatsapp text-success me-2"></i>WhatsApp</span>
                                <span class="badge <?= $whatsappEnabled && !empty($preferences['notification_whatsapp']) ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= $whatsappEnabled && !empty($preferences['notification_whatsapp']) ? 'Attivo' : 'Disattivo' ?>
                                </span>
                            </li>
                        </ul>

                        <div class="alert alert-info mt-4 mb-0" role="alert">
                            <i class="fa-solid fa-circle-info me-2"></i>
                            Le notifiche vengono inviate solo se il recapito è verificato e presente nel tuo profilo.
                        </div>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mt-4">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h6 mb-1 text-danger">Chiusura account</h2>
                        <p class="text-muted-soft mb-0">Elimina definitivamente il profilo e tutti i dati collegati al portale pickup.</p>
                    </div>
                    <div class="card-body">
                        <p class="small text-muted">L'operazione rimuove immediatamente dati anagrafici, preferenze, segnalazioni di pacchi, notifiche e log di accesso. L'azione è irreversibile e richiederà una nuova registrazione per eventuali accessi futuri.</p>
                <form method="POST" action="settings.php" class="d-flex flex-column flex-sm-row gap-2"
                    data-confirm="Confermi di voler eliminare definitivamente il tuo account e tutti i dati associati? L'operazione non può essere annullata."
                    data-confirm-title="Elimina account"
                    data-confirm-confirm-label="Elimina"
                    data-confirm-class="btn btn-danger">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete-account">
                            <button class="btn btn-danger flex-grow-1" type="submit"><i class="fa-solid fa-user-slash me-2"></i>Elimina account</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
