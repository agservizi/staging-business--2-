<?php
use App\Security\SecurityAuditLogger;

session_start();
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

if (isset($_SESSION['user_id'])) {
    redirect_by_role($_SESSION['role'] ?? '');
    exit;
}

$csrfToken = csrf_token();
$auditLogger = new SecurityAuditLogger($pdo);

$errors = [];
$input = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'username' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $input['first_name'] = trim((string) ($_POST['first_name'] ?? ''));
    $input['last_name'] = trim((string) ($_POST['last_name'] ?? ''));
    $input['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
    $input['username'] = strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirmation'] ?? '');
    $privacyAccepted = isset($_POST['privacy']) && (string) $_POST['privacy'] === '1';

    if ($input['first_name'] === '' || $input['last_name'] === '') {
        $errors[] = 'Nome e cognome sono obbligatori.';
    }

    if ($input['email'] === '' || !filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci un indirizzo email valido.';
    }

    if ($input['username'] === '' || !preg_match('/^[a-z0-9_.-]{4,32}$/', $input['username'])) {
        $errors[] = 'Scegli uno username valido (4-32 caratteri, lettere, numeri, . _ -).';
    }

    if (strlen($password) < 8) {
        $errors[] = 'La password deve avere almeno 8 caratteri.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Le password non coincidono.';
    }

    if (!$privacyAccepted) {
        $errors[] = 'Devi accettare l\'informativa privacy.';
    }

    if (!$errors) {
        // Verifica univocità username/email
        $checkStmt = $pdo->prepare('SELECT username, email FROM users WHERE username = :username OR email = :email LIMIT 1');
        $checkStmt->execute([':username' => $input['username'], ':email' => $input['email']]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if (isset($existing['username']) && strtolower((string) $existing['username']) === $input['username']) {
                $errors[] = 'Username già in uso.';
            }
            if (isset($existing['email']) && strtolower((string) $existing['email']) === $input['email']) {
                $errors[] = 'Email già registrata.';
            }
        }
    }

    if (!$errors) {
        $hashed = password_hash($password, PASSWORD_DEFAULT);

        $insert = $pdo->prepare('INSERT INTO users (username, email, nome, cognome, password, ruolo, theme_preference, created_at, updated_at)
            VALUES (:username, :email, :nome, :cognome, :password, :ruolo, :theme, NOW(), NOW())');

        $insert->execute([
            ':username' => $input['username'],
            ':email' => $input['email'],
            ':nome' => $input['first_name'],
            ':cognome' => $input['last_name'],
            ':password' => $hashed,
            ':ruolo' => 'Collaboratore',
            ':theme' => 'light',
        ]);

        $userId = (int) $pdo->lastInsertId();

        $userPayload = [
            'id' => $userId,
            'username' => $input['username'],
            'ruolo' => 'Collaboratore',
            'email' => $input['email'],
            'nome' => $input['first_name'],
            'cognome' => $input['last_name'],
            'theme_preference' => 'light',
        ];

        $ipAddress = request_ip();
        $userAgent = request_user_agent();

        complete_user_login($pdo, $auditLogger, $userPayload, $ipAddress, $userAgent, false, 'self_signup_collaborator');

        header('Location: ' . opportunities_collaborator_url('index'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Registrazione Collaboratore - Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" referrerpolicy="no-referrer" />
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
</head>
<body class="login-body" data-bs-theme="light">
    <main class="auth-layout login-shell">
        <div class="auth-grid">
            <section class="auth-panel auth-panel-brand login-side-brand">
                <div>
                    <span class="badge rounded-pill px-3 py-2 mb-4">Coresuite Business</span>
                    <h1 class="display-6 fw-semibold mb-3">Diventa collaboratore e accedi al portale.</h1>
                    <p class="text-secondary mb-4">Gestisci pratiche, ticket e opportunità con un workspace dedicato e sicuro.</p>
                    <ul class="mb-4">
                        <li><i class="fa-solid fa-briefcase"></i><span>Pipeline e ticket sempre sotto controllo</span></li>
                        <li><i class="fa-solid fa-shield-halved"></i><span>Autenticazione sicura e audit delle attività</span></li>
                        <li><i class="fa-solid fa-handshake"></i><span>Supporto dal team admin</span></li>
                    </ul>
                </div>
                <div class="login-meta auth-meta">
                    &copy; <?php echo date('Y'); ?> Coresuite Business
                </div>
            </section>
            <section class="auth-panel auth-panel-form login-form-area">
                <div class="auth-panel-inner">
                    <div class="mb-4 text-center text-md-start">
                        <h2 class="h4 fw-semibold mb-2">Crea il tuo account collaboratore</h2>
                        <p class="login-meta mb-0">Hai già un account? <a class="link-warning text-decoration-none" href="<?php echo login_url(); ?>">Accedi</a>.</p>
                    </div>
                    <?php if ($errors): ?>
                        <div class="alert alert-danger border-0 shadow-sm mb-4" role="alert">
                            <?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?>
                        </div>
                    <?php endif; ?>
                    <form method="post" novalidate>
                        <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label for="first_name" class="form-label">Nome</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" required autocomplete="given-name" value="<?php echo sanitize_output($input['first_name']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label for="last_name" class="form-label">Cognome</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" required autocomplete="family-name" value="<?php echo sanitize_output($input['last_name']); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email di lavoro</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-envelope"></i></span>
                                <input type="email" class="form-control" id="email" name="email" required autocomplete="email" placeholder="nome@azienda.it" value="<?php echo sanitize_output($input['email']); ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-user"></i></span>
                                <input type="text" class="form-control" id="username" name="username" required autocomplete="username" placeholder="nome.cognome" value="<?php echo sanitize_output($input['username']); ?>">
                            </div>
                            <div class="form-text">Ammessi lettere, numeri, punto, underscore, trattino. Min 4 caratteri.</div>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" class="form-control" id="password" name="password" required autocomplete="new-password" placeholder="••••••••">
                            </div>
                            <div class="form-text">Minimo 8 caratteri. Usa maiuscole, minuscole, numeri e simboli.</div>
                        </div>
                        <div class="mb-4">
                            <label for="password_confirmation" class="form-label">Conferma password</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                                <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" required autocomplete="new-password" placeholder="Ripeti la password">
                            </div>
                        </div>
                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" value="1" id="privacy" name="privacy" required>
                            <label class="form-check-label" for="privacy">Accetto l'informativa privacy e le condizioni d'uso.</label>
                        </div>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-warning fw-semibold">Crea account</button>
                        </div>
                    </form>
                    <div class="login-meta mt-5">
                        Le richieste sono registrate ai fini di sicurezza. L'accesso è riservato ai collaboratori autorizzati.
                    </div>
                </div>
            </section>
        </div>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
