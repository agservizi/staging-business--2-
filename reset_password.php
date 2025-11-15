<?php
session_start();
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

$csrfToken = csrf_token();
$token = $_GET['token'] ?? '';
$error = '';
$success = false;

if ($token === '') {
    http_response_code(400);
    echo 'Token non valido.';
    exit;
}

$stmt = $pdo->prepare('SELECT pr.id, pr.user_id, pr.expires_at, u.username FROM password_resets pr JOIN users u ON pr.user_id = u.id WHERE pr.token = :token LIMIT 1');
$stmt->execute([':token' => $token]);
$reset = $stmt->fetch();

if (!$reset || new DateTime($reset['expires_at']) < new DateTime()) {
    $error = 'Link scaduto o non valido.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    require_valid_csrf();
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($password) < 8) {
        $error = 'La password deve contenere almeno 8 caratteri.';
    } elseif ($password !== $confirm) {
        $error = 'Le password non coincidono.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
            $updateUser = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $updateUser->execute([':password' => $hash, ':id' => $reset['user_id']]);

            $deleteToken = $pdo->prepare('DELETE FROM password_resets WHERE user_id = :id');
            $deleteToken->execute([':id' => $reset['user_id']]);

            $pdo->commit();
            $success = true;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Password reset failed: ' . $e->getMessage());
            $error = 'Errore durante l\'aggiornamento. Riprova.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nuova password | Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body">
    <main class="auth-wrapper">
        <div class="auth-card">
            <h1 class="h4 text-center mb-4">Imposta nuova password</h1>
            <?php if ($success): ?>
                <div class="alert alert-success">Password aggiornata con successo. <a class="link-warning" href="index.php">Accedi</a></div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="alert alert-warning"><?php echo sanitize_output($error); ?></div>
                <?php else: ?>
                    <p class="text-muted">Stai reimpostando la password per l'utente <strong><?php echo sanitize_output($reset['username']); ?></strong>.</p>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <div class="mb-3">
                        <label for="password" class="form-label">Nuova password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Conferma password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-warning">Aggiorna password</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
