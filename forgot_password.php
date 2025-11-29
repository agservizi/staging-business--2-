<?php
session_start();
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/mailer.php';

$csrfToken = csrf_token();
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Inserisci un indirizzo email valido.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new DateTime('+1 hour'))->format('Y-m-d H:i:s');
            $insert = $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (:user_id, :token, :expires_at)');
            $insert->execute([
                ':user_id' => $user['id'],
                ':token' => $token,
                ':expires_at' => $expiresAt,
            ]);

            $baseUrl = env('APP_URL', sprintf('%s://%s', isset($_SERVER['HTTPS']) ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost'));
            $resetLink = rtrim($baseUrl, '/') . '/reset_password.php?token=' . $token;
            $mailBody = render_mail_template('Reset password', sprintf('<p>Ciao,</p><p>È stata richiesta la reimpostazione della password per il tuo account CRM.</p><p><a href="%s">Clicca qui per impostare una nuova password</a>.</p><p>Se non hai richiesto tu questa operazione ignora il messaggio.</p>', $resetLink));
            if (send_system_mail($email, 'Reimposta la tua password', $mailBody)) {
                $success = true;
            } else {
                $error = 'Impossibile inviare l\'email in questo momento. Riprovare più tardi.';
            }
        }

        if (!$success) {
            $error = 'Se l\'email esiste, riceverai un link per il reset.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recupero password | Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer" />
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body" data-bs-theme="light">
    <main class="auth-layout login-shell">
        <div class="auth-grid">
            <section class="auth-panel auth-panel-brand login-side-brand">
                <div>
                    <span class="badge rounded-pill px-3 py-2 mb-4">Recupera l'accesso</span>
                    <h1 class="display-6 fw-semibold mb-3">Hai dimenticato la password?</h1>
                    <p class="text-secondary mb-4">Nessun problema. Inserisci l'email aziendale e riceverai un link temporaneo per impostare una nuova password in modo sicuro.</p>
                    <ul class="mb-4">
                        <li><i class="fa-solid fa-envelope-circle-check"></i><span>Link valido per 60 minuti</span></li>
                        <li><i class="fa-solid fa-shield"></i><span>Le richieste vengono registrate per motivi di sicurezza</span></li>
                        <li><i class="fa-solid fa-arrow-rotate-left"></i><span>Potrai accedere nuovamente subito dopo il reset</span></li>
                    </ul>
                </div>
                <div class="login-meta auth-meta">
                    &copy; <?php echo date('Y'); ?> Coresuite Business
                </div>
            </section>
            <section class="auth-panel auth-panel-form login-form-area">
                <div class="auth-panel-inner">
                    <div class="mb-4 text-center text-md-start">
                        <h2 class="h4 fw-semibold mb-2">Recupera la tua password</h2>
                        <p class="login-meta mb-0">Inserisci l'indirizzo email associato all'account.</p>
                    </div>
                    <?php if ($success): ?>
                        <div class="alert alert-success border-0 shadow-sm mb-4" role="alert">
                            Se l'email è registrata riceverai un messaggio con le istruzioni entro pochi minuti.
                        </div>
                    <?php elseif ($error): ?>
                        <div class="alert alert-warning border-0 shadow-sm mb-4" role="alert">
                            <?php echo sanitize_output($error); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" novalidate>
                        <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                        <div class="mb-4">
                            <label for="email" class="form-label">Email aziendale</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" placeholder="nome.cognome@azienda.it" required autocomplete="email">
                            </div>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning fw-semibold">Invia istruzioni di reset</button>
                        </div>
                    </form>
                    <div class="login-meta mt-4 text-center text-md-start">
                        Ti sei ricordato la password? <a class="link-warning text-decoration-none" href="index.php">Torna al login</a>.
                    </div>
                </div>
            </section>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
