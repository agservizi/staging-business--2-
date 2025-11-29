<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;

session_start();

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db_connect.php';

$pendingLogin = $_SESSION['mfa_challenge'] ?? null;
if (!$pendingLogin || empty($pendingLogin['user']['id'])) {
    header('Location: index.php');
    exit;
}

if (($pendingLogin['expires_at'] ?? 0) < time()) {
    unset($_SESSION['mfa_challenge']);
    $_SESSION['login_error'] = 'Sessione MFA scaduta. Effettua nuovamente l\'accesso.';
    header('Location: index.php');
    exit;
}

$userId = (int) ($pendingLogin['user']['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, username, email, nome, cognome, ruolo, theme_preference, mfa_enabled, mfa_secret FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset($_SESSION['mfa_challenge']);
    $_SESSION['login_error'] = 'Utente non trovato. Effettua nuovamente l\'accesso.';
    header('Location: index.php');
    exit;
}

$hasTotp = (int) ($user['mfa_enabled'] ?? 0) === 1 && !empty($user['mfa_secret']);
$rememberLogin = !empty($pendingLogin['remember']);
$csrfToken = csrf_token();

$existingChallengeToken = $_SESSION['mfa_qr_challenge']['token'] ?? null;
$qrEnabled = true;

$qrPinAttemptLimit = null;
$qrPinLockSeconds = null;
try {
    $qrService = new MfaQrService($pdo);
    $qrPinAttemptLimit = $qrService->getPinAttemptLimit();
    $qrPinLockSeconds = $qrService->getPinLockSeconds();
} catch (Throwable $qrPolicyException) {
    error_log('Impossibile ottenere la policy PIN QR: ' . $qrPolicyException->getMessage());
}
$qrPinLockMinutes = $qrPinLockSeconds ? (int) max(1, ceil($qrPinLockSeconds / 60)) : null;

?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conferma accesso | Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer" />
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body" data-bs-theme="light" data-mfa-choice
      data-challenge-create="<?php echo base_url('api/mfa/qr/challenges/create.php'); ?>"
      data-challenge-status="<?php echo base_url('api/mfa/qr/challenges/status.php'); ?>"
      data-challenge-token="<?php echo sanitize_output($existingChallengeToken ?? ''); ?>"
      data-csrf="<?php echo $csrfToken; ?>"
    data-pin-attempt-limit="<?php echo (int) ($qrPinAttemptLimit ?? 0); ?>"
    data-pin-lock-seconds="<?php echo (int) ($qrPinLockSeconds ?? 0); ?>"
>
    <main class="login-shell">
        <div class="row g-0">
            <div class="col-lg-5 login-side-brand d-flex flex-column justify-content-between">
                <div>
                    <span class="badge rounded-pill px-3 py-2 mb-4">Verifica accesso</span>
                    <h1 class="display-6 fw-semibold mb-3">Scegli come confermare l'accesso</h1>
                    <p class="text-secondary">Puoi usare il codice temporaneo dell'app Authenticator oppure approvare dal dispositivo mobile abilitato al QR+PIN.</p>
                    <div class="bg-dark text-white rounded-3 p-3 mt-4">
                        <div class="small text-uppercase text-muted">Utente</div>
                        <div class="fs-5 fw-semibold mb-1"><?php echo sanitize_output(format_user_display_name($user['username'] ?? '', $user['email'] ?? null, $user['nome'] ?? null, $user['cognome'] ?? null)); ?></div>
                        <div class="small text-muted" title="<?php echo sanitize_output($user['email'] ?? ''); ?>"><?php echo sanitize_output($user['username'] ?? ''); ?></div>
                    </div>
                </div>
                <div class="login-meta">&copy; <?php echo date('Y'); ?> Coresuite Business</div>
            </div>
            <div class="col-lg-7 login-form-area">
                <div class="mb-4 text-center text-lg-start">
                    <h2 class="h4 fw-semibold mb-2">Conferma richiesta MFA</h2>
                    <p class="login-meta mb-0">Quando l'accesso viene approvato, verrai reindirizzato automaticamente alla dashboard.</p>
                </div>

                <div class="alert alert-danger d-none" role="alert" data-mfa-alert></div>

                <div class="row g-4">
                    <?php if ($hasTotp): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm h-100">
                                <div class="card-body d-flex flex-column gap-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge text-bg-primary">Authenticator</span>
                                            <h3 class="h5 mb-0">Codice a 6 cifre</h3>
                                        </div>
                                        <p class="text-muted mb-0">Apri l'app Authenticator configurata e inserisci il codice temporaneo nella schermata classica.</p>
                                    </div>
                                    <a class="btn btn-outline-primary" href="<?php echo base_url('mfa-verify.php'); ?>">
                                        <i class="fa-solid fa-shield-halved me-2"></i>Usa codice temporaneo
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($qrEnabled): ?>
                        <div class="col-12">
                            <div class="card border-0 shadow-sm h-100" data-mfa-qr-card>
                                <div class="card-body d-flex flex-column gap-3">
                                    <div>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span class="badge text-bg-success">QR + PIN</span>
                                            <h3 class="h5 mb-0">Approva dal dispositivo mobile</h3>
                                        </div>
                                        <p class="text-muted mb-0">Genera un QR dinamico sullo schermo, inquadrato dall'app mobile registrata. Inserisci il PIN sul dispositivo per confermare.</p>
                                        <?php if ($qrPinAttemptLimit && $qrPinLockMinutes): ?>
                                            <div class="alert alert-secondary border-0 py-2 px-3 small mt-3 mb-0">
                                                <i class="fa-solid fa-lock me-2"></i>
                                                Hai <?php echo (int) $qrPinAttemptLimit; ?> tentativi per il PIN. Al superamento il dispositivo si blocca per circa <?php echo (int) $qrPinLockMinutes; ?> minuti.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex flex-column flex-md-row gap-2">
                                        <button type="button" class="btn btn-warning flex-fill" data-mfa-qr-start>
                                            <i class="fa-solid fa-qrcode me-2"></i>Attiva richiesta QR
                                        </button>
                                        <button type="button" class="btn btn-outline-light flex-fill d-none" data-mfa-qr-cancel>Termina richiesta</button>
                                    </div>
                                    <div class="border rounded-3 p-3 bg-body-secondary d-none" data-mfa-qr-progress>
                                        <div class="small text-muted">Stato QR</div>
                                        <div class="fw-semibold" data-mfa-qr-status-text>In attesa di generazione...</div>
                                        <div class="progress mt-3" role="progressbar" aria-valuemin="0" aria-valuemax="100">
                                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 25%" data-mfa-qr-progressbar></div>
                                        </div>
                                        <div class="mt-3 text-center">
                                            <div class="ratio ratio-1x1 w-100 w-sm-50 mx-auto bg-white rounded-3 p-3" data-mfa-qr-code></div>
                                            <div class="small text-muted mt-2" data-mfa-qr-hint>Inquadra il QR con l'app mobile registrata.</div>
                                        </div>
                                        <div class="mt-3 d-flex flex-column flex-sm-row gap-2 align-items-sm-center">
                                            <code class="text-break flex-fill" data-mfa-qr-token></code>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-mfa-qr-copy-token>
                                                <i class="fa-solid fa-copy me-1"></i>Copia token
                                            </button>
                                        </div>
                                        <div class="mt-3 small text-muted" data-mfa-qr-timer></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="login-meta mt-5 text-center text-lg-start">
                    Hai problemi con il QR? Torna al codice temporaneo oppure contatta l'amministratore per assistenza.
                </div>
            </div>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?php echo asset('assets/vendor/qrcodejs/qrcode.min.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/mfa-choice.js'); ?>"></script>
</body>
</html>
