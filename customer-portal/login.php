<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Se già autenticato, reindirizza alla dashboard
if (CustomerAuth::isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

// Gestione errori/messaggi dalla query string
$message = $_GET['message'] ?? '';
$error = $_GET['error'] ?? '';
$assetVersion = defined('APP_VERSION') ? constant('APP_VERSION') : '1.0.0';
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pickup Portal | Coresuite Business</title>
    <meta name="description" content="Accedi al Pickup Portal di Coresuite Business per tracciare spedizioni, gestire anomalie e collaborare con gli operatori logistici.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="assets/css/portal.css?v=<?= urlencode($assetVersion) ?>">
</head>
<body class="login-body">
    <div class="login-layout">
        <aside class="login-hero">
            <div class="login-hero-content">
                <div class="login-brand">
                    <div class="login-brand-symbol" aria-hidden="true">
                        <i class="fa-solid fa-box"></i>
                    </div>
                    <div>
                        <span class="login-brand-label">Coresuite Business</span>
                        <span class="login-brand-title">
                            <strong>Pickup Portal</strong>
                            Accesso Clienti
                        </span>
                    </div>
                </div>
                <p class="login-hero-lead">L'hub digitale per i clienti logistici: traccia arrivi, segnala anomalie e resta aggiornato su ogni spedizione.</p>
                <ul class="login-highlights">
                    <li>
                        <span class="login-highlight-icon" aria-hidden="true"><i class="fa-solid fa-route"></i></span>
                        <span>Monitoraggio in tempo reale degli stati di consegna</span>
                    </li>
                    <li>
                        <span class="login-highlight-icon" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
                        <span>Autenticazione sicura con OTP temporaneo</span>
                    </li>
                    <li>
                        <span class="login-highlight-icon" aria-hidden="true"><i class="fa-solid fa-headset"></i></span>
                        <span>Supporto dedicato e flussi collaborativi con gli operatori</span>
                    </li>
                    <li>
                        <span class="login-highlight-icon" aria-hidden="true"><i class="fa-solid fa-truck-fast"></i></span>
                        <span>Novità BRT: tracking in tempo reale e stampa etichette direttamente dal portale</span>
                    </li>
                </ul>
            </div>
            <div class="login-hero-footer">
                <span>Hai bisogno di assistenza?</span>
                <a class="login-hero-cta" href="mailto:support@coresuite.it">
                    <i class="fa-solid fa-envelope"></i>
                    support@coresuite.it
                </a>
            </div>
        </aside>

        <main class="login-panel" role="main">
            <div class="login-panel-inner">
                <header class="login-panel-header">
                    <span class="login-pill">Accesso clienti pickup</span>
                    <h2>Accedi al tuo spazio operativo</h2>
                    <p>Inserisci il tuo indirizzo email aziendale per ricevere un codice monouso e confermare l'identità.</p>
                </header>

                <?php if ($error): ?>
                    <div class="alert alert-danger border-0 shadow-sm login-alert" role="alert">
                        <i class="fa-solid fa-triangle-exclamation me-2" aria-hidden="true"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($message): ?>
                    <div class="alert alert-info border-0 shadow-sm login-alert" role="alert">
                        <i class="fa-solid fa-circle-info me-2" aria-hidden="true"></i><?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <div class="login-progress" aria-hidden="true">
                    <div class="login-progress-step active" data-login-step="login-form">
                        <span class="login-progress-index">1</span>
                        <span>Email</span>
                    </div>
                    <div class="login-progress-divider"></div>
                    <div class="login-progress-step" data-login-step="otp-form">
                        <span class="login-progress-index">2</span>
                        <span>Codice OTP</span>
                    </div>
                </div>

                <section id="login-form" class="login-step active" aria-labelledby="login-form-heading">
                    <h3 id="login-form-heading" class="login-step-title">Inserisci l'email aziendale</h3>
                    <form id="loginForm" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <div class="login-field">
                            <label class="login-label" for="email">Email aziendale *</label>
                            <input class="form-control form-control-lg" id="email" name="email" type="email" placeholder="nome@azienda.it" required>
                            <div class="invalid-feedback">Inserisci un indirizzo email valido.</div>
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" id="rememberLogin" name="remember_login">
                            <label class="form-check-label small text-muted" for="rememberLogin">
                                Mantieni l'accesso su questo dispositivo
                            </label>
                        </div>
                        <button class="btn btn-primary btn-lg w-100" type="submit">
                            <span class="btn-label">Invia codice di verifica</span>
                            <i class="fa-solid fa-arrow-right-long ms-2" aria-hidden="true"></i>
                        </button>
                        <p class="login-hint">Riceverai una mail con un codice di sicurezza valido per alcuni minuti.</p>
                    </form>
                </section>

                <section id="otp-form" class="login-step" aria-labelledby="otp-form-heading">
                    <h3 id="otp-form-heading" class="login-step-title">Conferma il codice ricevuto</h3>
                    <p class="login-step-subtitle" id="otp-destination-text">Abbiamo inviato un codice di 6 cifre</p>
                    <form id="otpForm" class="login-otp-form" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                        <input type="hidden" id="customer_id" name="customer_id">
                        <input type="hidden" id="remember_login_choice" name="remember_login" value="0">
                        <div class="login-field">
                            <label class="login-label" for="otp">Codice di verifica</label>
                            <input class="form-control form-control-lg text-center login-otp-input" id="otp" name="otp" maxlength="6" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" required>
                            <div class="form-text">Il codice scade allo scadere del timer.</div>
                        </div>
                        <button class="btn btn-success btn-lg w-100" type="submit">
                            <span class="btn-label">Verifica e accedi</span>
                            <i class="fa-solid fa-lock-open ms-2" aria-hidden="true"></i>
                        </button>
                        <button class="btn btn-outline-secondary w-100 login-resend" type="button" id="resendOtp">
                            <i class="fa-solid fa-paper-plane me-2" aria-hidden="true"></i>
                            Invia un nuovo codice
                        </button>
                        <div class="login-countdown">
                            <i class="fa-regular fa-clock" aria-hidden="true"></i>
                            <span id="countdown-text">Codice valido per 05:00</span>
                        </div>
                    </form>
                </section>

                <section id="loading" class="login-step login-loading" aria-live="polite">
                </section>

                <footer class="login-privacy">
                    <i class="fa-regular fa-shield" aria-hidden="true"></i>
                    <span>Accesso riservato ai clienti dei servizi logistici Pickup di Coresuite. Le informazioni sono protette secondo gli standard GDPR.</span>
                </footer>
            </div>
        </main>
    </div>

    <div id="alert-container" class="position-fixed top-0 start-50 translate-middle-x w-100 px-3" style="max-width: 420px; z-index: 1080; margin-top: 1.5rem;"></div>

    <div id="portalCookieBanner" class="portal-cookie-banner" role="dialog" aria-live="polite" aria-label="Informativa sui cookie" hidden>
        <div class="portal-cookie-banner__inner">
            <div class="portal-cookie-banner__text">
                <h2 class="portal-cookie-banner__title">Usiamo solo cookie tecnici</h2>
                <p class="mb-0">Questo portale utilizza esclusivamente cookie necessari per la sessione di accesso e per ricordare la tua scelta. Proseguendo accetti i cookie tecnici descritti nell'informativa.</p>
            </div>
            <div class="portal-cookie-banner__actions">
                <button type="button" class="btn btn-primary" id="portalCookieAccept">
                    <i class="fa-solid fa-check me-1" aria-hidden="true"></i>Accetta e continua
                </button>
                <a class="btn btn-link text-decoration-none" href="privacy.php#cookies">
                    <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Dettagli privacy
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        window.portalConfig = {
            csrfToken: '<?= htmlspecialchars(get_csrf_token(), ENT_QUOTES, 'UTF-8') ?>',
            apiBaseUrl: 'api/',
            enableServiceWorker: false
        };
    </script>
    <script src="assets/js/portal.js?v=<?= urlencode($assetVersion) ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (window.PickupPortal?.CookieConsent) {
                window.PickupPortal.CookieConsent.init();
            }
            initializeLogin();
        });
    </script>
</body>
</html>