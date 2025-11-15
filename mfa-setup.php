<?php
use App\Security\SecurityAuditLogger;

session_start();

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db_connect.php';

$setup = $_SESSION['mfa_setup'] ?? null;
if (!$setup || empty($setup['user']['id'])) {
    header('Location: index.php');
    exit;
}

$mode = $setup['mode'] ?? 'enroll';
$returnTo = $setup['return_to'] ?? base_url('modules/impostazioni/profile.php');
$resetRequested = !empty($setup['reset']);
$rememberLogin = !empty($setup['remember']);
if ($mode === 'enroll' && ($setup['expires_at'] ?? 0) < time()) {
    unset($_SESSION['mfa_setup']);
    $_SESSION['login_error'] = 'La sessione di configurazione MFA è scaduta. Effettua nuovamente il login.';
    header('Location: index.php');
    exit;
}

$userId = (int) ($setup['user']['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, username, email, nome, cognome, ruolo, theme_preference, mfa_secret, mfa_enabled FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    unset($_SESSION['mfa_setup']);
    $_SESSION['login_error'] = 'Utente non trovato. Effettua nuovamente il login.';
    header('Location: index.php');
    exit;
}

$sessionUser = build_user_session_payload($user);
$_SESSION['mfa_setup']['user'] = $sessionUser;

if ($mode === 'enroll' && (int) ($user['mfa_enabled'] ?? 0) === 1 && !empty($user['mfa_secret'])) {
    $_SESSION['mfa_challenge'] = [
        'user' => $sessionUser,
        'ip' => $setup['ip'] ?? request_ip(),
        'user_agent' => $setup['user_agent'] ?? request_user_agent(),
        'remember' => $rememberLogin,
        'expires_at' => time() + 300,
    ];
    unset($_SESSION['mfa_setup']);
    header('Location: mfa-verify.php');
    exit;
}

$totpClass = '\\OTPHP\\TOTP';
if (!class_exists($totpClass)) {
    unset($_SESSION['mfa_setup']);
    if ($mode === 'enroll') {
        $_SESSION['login_error'] = 'Servizio MFA non disponibile. Ripeti l\'accesso più tardi.';
        header('Location: index.php');
    } else {
        add_flash('danger', 'Impossibile attivare l\'autenticazione a due fattori in questo momento.');
        header('Location: ' . $returnTo);
    }
    exit;
}

if (empty($_SESSION['mfa_setup']['secret'])) {
    $generator = $totpClass::create(null, 30, 'sha1', 6);
    $_SESSION['mfa_setup']['secret'] = $generator->getSecret();
}

$secret = (string) $_SESSION['mfa_setup']['secret'];
$totp = $totpClass::create($secret, 30, 'sha1', 6);
$accountLabel = $sessionUser['username'] !== '' ? $sessionUser['username'] : ('utente-' . $sessionUser['id']);
$accountLabel = str_replace(':', ' ', $accountLabel);
$totp->setLabel($accountLabel);
$totp->setIssuer('Coresuite Business');
$otpauthUri = $totp->getProvisioningUri();
$qrSvg = null;
$rendererClass = '\\BaconQrCode\\Renderer\\ImageRenderer';
$rendererStyleClass = '\\BaconQrCode\\Renderer\\RendererStyle\\RendererStyle';
$backendClass = '\\BaconQrCode\\Renderer\\Image\\SvgImageBackEnd';
$writerClass = '\\BaconQrCode\\Writer';

if (class_exists($rendererClass) && class_exists($rendererStyleClass) && class_exists($backendClass) && class_exists($writerClass)) {
    try {
        $previousReporting = error_reporting();
        error_reporting($previousReporting & ~E_DEPRECATED);
        $renderer = new $rendererClass(new $rendererStyleClass(240), new $backendClass());
        $writer = new $writerClass($renderer);
        $qrSvg = $writer->writeString($otpauthUri);
        error_reporting($previousReporting);
    } catch (Throwable $qrException) {
        if (isset($previousReporting)) {
            error_reporting($previousReporting);
        }
        error_log('QR generation failed: ' . $qrException->getMessage());
    }
} else {
    error_log('QR generation classes not available.');
}
$displaySecret = chunk_split(strtoupper($secret), 4, ' ');

$csrfToken = csrf_token();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    if (isset($_POST['regen'])) {
        unset($_SESSION['mfa_setup']['secret']);
        header('Location: mfa-setup.php');
        exit;
    }

    $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));
    if ($code === '') {
        $errors[] = 'Inserisci il codice generato dall\'app Authenticator.';
    } elseif (!preg_match('/^[0-9]{6}$/', $code)) {
        $errors[] = 'Il codice deve contenere 6 cifre.';
    } else {
        $validator = $totpClass::create($secret, 30, 'sha1', 6);
        if (!$validator->verify($code, null, 1)) {
            $errors[] = 'Codice non corretto. Verifica di aver sincronizzato l\'app e riprova.';
        }
    }

    if (!$errors) {
        $pdo->prepare('UPDATE users SET mfa_secret = :secret, mfa_enabled = 1, mfa_enabled_at = NOW() WHERE id = :id')
            ->execute([
                ':secret' => $secret,
                ':id' => $userId,
            ]);

        unset($_SESSION['mfa_setup']['secret']);

        if ($mode === 'enroll') {
            $auditLogger = new SecurityAuditLogger($pdo);
            $loginUser = build_user_session_payload($user);
            $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
            $logStmt->execute([
                ':user_id' => $userId,
                ':modulo' => 'Profilo',
                ':azione' => 'Abilitazione MFA',
                ':dettagli' => json_encode(['issuer' => 'Coresuite Business', 'mode' => 'login_enroll'], JSON_UNESCAPED_UNICODE),
            ]);
            complete_user_login($pdo, $auditLogger, $loginUser, $setup['ip'] ?? request_ip(), $setup['user_agent'] ?? request_user_agent(), $rememberLogin, 'mfa_enroll');
            unset($_SESSION['mfa_setup']);
            redirect_by_role($loginUser['ruolo']);
            exit;
        }

        $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $logStmt->execute([
            ':user_id' => $userId,
            ':modulo' => 'Profilo',
            ':azione' => 'Abilitazione MFA',
            ':dettagli' => json_encode(['issuer' => 'Coresuite Business', 'mode' => 'manage'], JSON_UNESCAPED_UNICODE),
        ]);

        $_SESSION['mfa_verified_at'] = time();
        unset($_SESSION['mfa_failed_attempts']);
        unset($_SESSION['mfa_setup']);
        add_flash('success', 'Autenticazione a due fattori attivata correttamente.');
        header('Location: ' . $returnTo);
        exit;
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configura MFA | Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer" />
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body" data-bs-theme="light">
    <main class="login-shell">
        <div class="row g-0">
            <div class="col-md-5 login-side-brand d-flex flex-column justify-content-between">
                <div>
                    <span class="badge rounded-pill px-3 py-2 mb-4">Configura l'autenticazione</span>
                    <h1 class="display-6 fw-semibold mb-3">Proteggi il tuo account con Google Authenticator.</h1>
                    <p class="text-secondary mb-4">Scansiona il QR code oppure inserisci manualmente la chiave segreta nell'app Authenticator. Una volta configurato, inserisci il codice a 6 cifre generato.</p>
                    <ol class="ps-3">
                        <li class="mb-2">Apri Google Authenticator (o un'app compatibile) e aggiungi un nuovo account.</li>
                        <li class="mb-2">Scansiona il QR code mostrato oppure digita la chiave segreta manualmente.</li>
                        <li>Inserisci qui sotto il codice a 6 cifre per completare l'attivazione.</li>
                    </ol>
                </div>
                <div class="login-meta">
                    &copy; <?php echo date('Y'); ?> Coresuite Business
                </div>
            </div>
            <div class="col-md-7 login-form-area">
                <div class="mb-4 text-center text-md-start">
                    <h2 class="h4 fw-semibold mb-2">Autenticazione a due fattori</h2>
                    <p class="login-meta mb-0">Titolo account: <strong><?php echo sanitize_output($sessionUser['username']); ?></strong></p>
                </div>

                <?php if ($errors): ?>
                    <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
                        <?php echo implode('<br>', array_map('sanitize_output', $errors)); ?>
                    </div>
                <?php endif; ?>

                <?php if ($resetRequested && !$errors): ?>
                    <div class="alert alert-info border-0 shadow-sm mb-4" role="alert">
                        <i class="fa-solid fa-rotate me-2"></i>Stai rigenerando la configurazione MFA. Completa il processo per sostituire il vecchio codice.
                    </div>
                <?php endif; ?>

                <div class="mfa-qr-wrapper text-center mb-4">
                    <?php if ($qrSvg !== null): ?>
                        <div class="d-inline-block bg-white p-3 rounded-3 shadow-sm border border-light">
                            <?php echo $qrSvg; ?>
                        </div>
                        <div class="mt-3 small text-secondary">Scansiona con l'app Authenticator</div>
                    <?php else: ?>
                        <div class="alert alert-warning" role="alert">
                            Impossibile generare il QR code automaticamente. Usa la chiave manuale qui sotto.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="card bg-body-secondary border-0 mb-4">
                    <div class="card-body">
                        <span class="text-muted small d-block">Chiave segreta</span>
                        <span class="fs-5 fw-semibold text-monospace"><?php echo sanitize_output($displaySecret); ?></span>
                        <div class="form-text">Se non puoi scansionare il QR, inserisci questa chiave manualmente.</div>
                    </div>
                </div>

                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <div class="mb-4">
                        <label for="code" class="form-label">Codice a 6 cifre</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="fa-solid fa-shield-halved"></i></span>
                            <input type="text" class="form-control" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code" required>
                        </div>
                    </div>
                    <div class="d-flex flex-column flex-md-row gap-3">
                        <button type="submit" class="btn btn-warning fw-semibold flex-fill"><i class="fa-solid fa-circle-check me-2"></i>Conferma configurazione</button>
                        <button type="submit" name="regen" value="1" class="btn btn-outline-warning flex-fill"><i class="fa-solid fa-arrows-rotate me-2"></i>Genera un nuovo codice</button>
                    </div>
                </form>

                <?php if ($mode === 'manage'): ?>
                    <div class="login-meta mt-4 text-center text-md-start">
                        Una volta confermato, l'autenticazione a due fattori sarà attiva per gli accessi futuri. Tornerai automaticamente al profilo.
                    </div>
                <?php else: ?>
                    <div class="login-meta mt-4 text-center text-md-start">
                        Dopo la conferma verrai reindirizzato alla tua dashboard.
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
