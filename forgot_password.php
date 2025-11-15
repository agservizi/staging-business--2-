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
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body">
    <main class="auth-wrapper">
        <div class="auth-card">
            <h1 class="h4 text-center mb-4">Recupera password</h1>
            <p class="text-muted">Inserisci l'email associata al tuo account. Riceverai un link per reimpostare la password.</p>
            <?php if ($success): ?>
                <div class="alert alert-success">Se l'email è registrata riceverai un messaggio con le istruzioni.</div>
            <?php elseif ($error): ?>
                <div class="alert alert-warning"><?php echo sanitize_output($error); ?></div>
            <?php endif; ?>
            <form method="post" novalidate>
                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" name="email" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-warning">Invia istruzioni</button>
                </div>
            </form>
            <div class="mt-3 text-center">
                <a class="link-warning" href="index.php">Torna al login</a>
            </div>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
