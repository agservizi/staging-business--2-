<?php
use App\Security\SecurityAuditLogger;

session_start();

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db_connect.php';

$challenge = $_SESSION['mfa_challenge'] ?? null;
if (!$challenge || empty($challenge['user']['id'])) {
    header('Location: index.php');
    exit;
}

if (($challenge['expires_at'] ?? 0) < time()) {
    unset($_SESSION['mfa_challenge']);
    unset($_SESSION['mfa_failed_attempts']);
    $_SESSION['login_error'] = 'Sessione MFA scaduta. Effettua nuovamente l\'accesso.';
    header('Location: index.php');
    exit;
}

$userId = (int) ($challenge['user']['id'] ?? 0);
$stmt = $pdo->prepare('SELECT id, username, email, nome, cognome, ruolo, theme_preference, mfa_secret, mfa_enabled FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || (int) ($user['mfa_enabled'] ?? 0) !== 1 || empty($user['mfa_secret'])) {
    unset($_SESSION['mfa_challenge']);
    $_SESSION['login_error'] = 'La configurazione MFA non è valida. Contatta l\'amministratore.';
    header('Location: index.php');
    exit;
}

$csrfToken = csrf_token();
$error = '';
$lockSeconds = 300;
$rememberLogin = !empty($challenge['remember']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $code = preg_replace('/\s+/', '', (string) ($_POST['code'] ?? ''));

    if ($code === '') {
        $error = 'Inserisci il codice di verifica generato dall\'Authenticator.';
    } elseif (!preg_match('/^[0-9]{6}$/', $code)) {
        $error = 'Il codice deve contenere 6 cifre.';
    } else {
        $totpClass = '\\OTPHP\\TOTP';
        if (!class_exists($totpClass)) {
            unset($_SESSION['mfa_challenge']);
            unset($_SESSION['mfa_failed_attempts']);
            $_SESSION['login_error'] = 'Servizio MFA non disponibile. Contatta l\'amministratore.';
            header('Location: index.php');
            exit;
        }
        $totp = $totpClass::create($user['mfa_secret'], 30, 'sha1', 6);
        if ($totp->verify($code, null, 1)) {
            $auditLogger = new SecurityAuditLogger($pdo);
            $sessionUser = build_user_session_payload($user);
            complete_user_login($pdo, $auditLogger, $sessionUser, $challenge['ip'], $challenge['user_agent'], $rememberLogin, 'mfa_verified');
            unset($_SESSION['mfa_challenge']);
            redirect_by_role($user['ruolo']);
            exit;
        }

        $error = 'Codice non corretto. Riprova.';
        $_SESSION['mfa_failed_attempts'] = ($_SESSION['mfa_failed_attempts'] ?? 0) + 1;

        $auditLogger = new SecurityAuditLogger($pdo);
        $auditLogger->logLoginAttempt($user['id'], $user['username'], false, $challenge['ip'], $challenge['user_agent'], 'mfa_failed');

        if ($_SESSION['mfa_failed_attempts'] >= 5) {
            $_SESSION['login_locked_until'] = time() + $lockSeconds;
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            unset($_SESSION['mfa_challenge']);
            $_SESSION['login_error'] = 'Troppi tentativi MFA. Effettua nuovamente l\'accesso tra qualche minuto.';
            header('Location: index.php');
            exit;
        }
    }
}

$userDisplay = format_user_display_name(
    $challenge['user']['username'] ?? '',
    $challenge['user']['email'] ?? '',
    $challenge['user']['nome'] ?? '',
    $challenge['user']['cognome'] ?? ''
);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verifica MFA | Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer" />
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body" data-bs-theme="light">
    <main class="login-shell">
        <div class="row g-0">
            <div class="col-md-5 login-side-brand d-flex flex-column justify-content-between">
                <div>
                    <span class="badge rounded-pill px-3 py-2 mb-4">Verifica a due fattori</span>
                    <h1 class="display-6 fw-semibold mb-3">Ciao <?php echo sanitize_output($userDisplay); ?>, conferma l'accesso.</h1>
                    <p class="text-secondary mb-4">Apri l'app Authenticator sul tuo dispositivo e inserisci il codice temporaneo visualizzato. Il codice cambia ogni 30 secondi.</p>
                    <ul class="mb-4">
                        <li><i class="fa-solid fa-shield-halved"></i><span>Protezione avanzata contro accessi non autorizzati</span></li>
                        <li><i class="fa-solid fa-mobile-screen"></i><span>Disponibile con Google Authenticator, 1Password, Authy e compatibili</span></li>
                        <li><i class="fa-solid fa-circle-info"></i><span>Se non riesci ad accedere, contatta subito l'amministratore</span></li>
                    </ul>
                </div>
                <div class="login-meta">
                    &copy; <?php echo date('Y'); ?> Coresuite Business
                </div>
            </div>
            <div class="col-md-7 login-form-area">
                <div class="mb-4 text-center text-md-start">
                    <h2 class="h4 fw-semibold mb-2">Inserisci il codice di verifica</h2>
                    <p class="login-meta mb-0">Il codice contiene 6 cifre. Usa l'opzione di ri-sincronizzazione dell'app se i codici risultano sempre errati.</p>
                </div>
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
                        <?php echo sanitize_output($error); ?>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <div class="mb-4">
                        <label for="code" class="form-label">Codice a 6 cifre</label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text"><i class="fa-solid fa-key"></i></span>
                            <input type="text" class="form-control" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" autocomplete="one-time-code" required>
                        </div>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning fw-semibold">Verifica e accedi</button>
                    </div>
                </form>
                <div class="login-meta mt-5">
                    Non hai accesso al generatore di codici? Contatta l'assistenza per il reset MFA.
                </div>
            </div>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
