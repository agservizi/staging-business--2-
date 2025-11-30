<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

require_role('Admin', 'Manager');
$pageTitle = 'Gestione utenti';
$csrfToken = csrf_token();

$allowedRoles = ['Admin', 'Manager', 'Operatore', 'Patronato', 'Cliente'];
$roleLabels = [
    'Admin' => 'Amministratore',
    'Manager' => 'Manager',
    'Operatore' => 'Operatore',
    'Patronato' => 'Operatore Patronato',
    'Cliente' => 'Cliente',
];
$createData = ['first_name' => '', 'last_name' => '', 'username' => '', 'email' => '', 'role' => 'Operatore'];
$createErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            $createData['first_name'] = trim($_POST['first_name'] ?? '');
            $createData['last_name'] = trim($_POST['last_name'] ?? '');
            $createData['username'] = trim($_POST['username'] ?? '');
            $createData['email'] = trim($_POST['email'] ?? '');
            $createData['role'] = in_array($_POST['role'] ?? '', $allowedRoles, true) ? $_POST['role'] : 'Operatore';
            $password = $_POST['password'] ?? '';

            $createData['first_name'] = mb_convert_case(mb_strtolower($createData['first_name'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            $createData['last_name'] = mb_convert_case(mb_strtolower($createData['last_name'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

            if ($createData['first_name'] === '' || $createData['last_name'] === '' || $createData['username'] === '' || $createData['email'] === '' || $password === '') {
                $createErrors[] = 'Compila tutti i campi obbligatori.';
            }
            if ($createData['first_name'] !== '' && mb_strlen($createData['first_name']) < 2) {
                $createErrors[] = 'Il nome deve contenere almeno 2 caratteri.';
            }
            if ($createData['last_name'] !== '' && mb_strlen($createData['last_name']) < 2) {
                $createErrors[] = 'Il cognome deve contenere almeno 2 caratteri.';
            }
            if ($createData['username'] !== '' && !preg_match('/^[A-Za-z0-9._-]{3,}$/', $createData['username'])) {
                $createErrors[] = 'Lo username deve contenere almeno 3 caratteri alfanumerici (ammessi . _ -).';
            }
            if ($createData['email'] !== '' && !filter_var($createData['email'], FILTER_VALIDATE_EMAIL)) {
                $createErrors[] = 'Inserisci un indirizzo email valido.';
            }
            if (strlen($password) < 8) {
                $createErrors[] = 'La password deve contenere almeno 8 caratteri.';
            }

            if (!$createErrors) {
                $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username OR email = :email');
                $dupStmt->execute([
                    ':username' => $createData['username'],
                    ':email' => $createData['email'],
                ]);
                if ($dupStmt->fetchColumn() > 0) {
                    $createErrors[] = 'Username o email già registrati.';
                }
            }

            if (!$createErrors) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO users (username, email, password, ruolo, nome, cognome) VALUES (:username, :email, :password, :ruolo, :nome, :cognome)');
                    $stmt->execute([
                        ':username' => $createData['username'],
                        ':email' => $createData['email'],
                        ':password' => password_hash($password, PASSWORD_DEFAULT),
                        ':ruolo' => $createData['role'],
                        ':nome' => $createData['first_name'],
                        ':cognome' => $createData['last_name'],
                    ]);

                    $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                        VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                    $logStmt->execute([
                        ':user_id' => $_SESSION['user_id'],
                        ':modulo' => 'Impostazioni',
                        ':azione' => 'Creazione utente',
                        ':dettagli' => trim($createData['first_name'] . ' ' . $createData['last_name']) . ' [' . $createData['username'] . '] (' . $createData['role'] . ')',
                    ]);

                    $mailSent = false;
                    if (function_exists('send_system_mail') && function_exists('render_mail_template') && $createData['email'] !== '') {
                        $baseUrl = env('APP_URL', sprintf('%s://%s', isset($_SERVER['HTTPS']) ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost'));
                        $loginUrl = rtrim((string) $baseUrl, '/') . '/login.php';
                        $displayName = trim($createData['first_name'] . ' ' . $createData['last_name']);
                        if ($displayName === '') {
                            $displayName = $createData['username'];
                        }

                        $subject = 'Il tuo accesso a Coresuite Business';
                        $bodyRows = [
                            'Username' => $createData['username'],
                            'Password temporanea' => $password,
                            'Pagina di accesso' => '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a>',
                        ];

                        $body = '<p>Ciao ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>';
                        $body .= '<p>è stato creato un nuovo account per te sulla piattaforma <strong>Coresuite Business</strong>. Di seguito trovi le credenziali temporanee per effettuare il primo accesso:</p>';
                        $body .= '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">';

                        foreach ($bodyRows as $label => $value) {
                            $body .= '<tr>';
                            $body .= '<th align="left" style="padding:10px 16px;background:#f8f9fc;width:220px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
                            if ($label === 'Pagina di accesso') {
                                $body .= '<td style="padding:10px 16px;">' . $value . '</td>';
                            } else {
                                $body .= '<td style="padding:10px 16px;">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
                            }
                            $body .= '</tr>';
                        }

                        $body .= '</table>';
                        $body .= '<p style="margin-top:16px;">Al primo accesso ti chiediamo di aggiornare la password per motivi di sicurezza. Se hai bisogno di assistenza contatta il tuo referente Coresuite Business.</p>';

                        $htmlBody = render_mail_template('Nuovo accesso Coresuite Business', $body);
                        $mailSent = send_system_mail($createData['email'], $subject, $htmlBody);
                        if (!$mailSent) {
                            error_log('Invio email nuovo utente fallito per ' . $createData['email']);
                        }
                    }

                    if ($mailSent) {
                        add_flash('success', 'Utente creato correttamente. Email di benvenuto inviata.');
                    } else {
                        add_flash('success', 'Utente creato correttamente.');
                        add_flash('warning', 'Impossibile inviare l\'email di benvenuto. Consegna manualmente le credenziali al nuovo utente.');
                    }

                    header('Location: users.php');
                    exit;
                } catch (Throwable $e) {
                    error_log('User creation failed: ' . $e->getMessage());
                    $createErrors[] = 'Errore durante la creazione dell\'utente. Consultare i log.';
                }
            }
            break;

        case 'update':
            $userId = (int)($_POST['user_id'] ?? 0);
            $data = [
                'first_name' => trim($_POST['edit_first_name'] ?? ''),
                'last_name' => trim($_POST['edit_last_name'] ?? ''),
                'username' => trim($_POST['edit_username'] ?? ''),
                'email' => trim($_POST['edit_email'] ?? ''),
                'role' => $_POST['edit_role'] ?? '',
            ];
            $newPassword = $_POST['edit_password'] ?? '';
            $confirmPassword = $_POST['edit_password_confirm'] ?? '';

            $data['first_name'] = mb_convert_case(mb_strtolower($data['first_name'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
            $data['last_name'] = mb_convert_case(mb_strtolower($data['last_name'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

            if ($userId <= 0) {
                add_flash('danger', 'Utente non valido.');
                header('Location: users.php');
                exit;
            }

            if (!in_array($data['role'], $allowedRoles, true)) {
                add_flash('danger', 'Ruolo selezionato non valido.');
                header('Location: users.php');
                exit;
            }

            $userStmt = $pdo->prepare('SELECT id, username, email, ruolo, nome, cognome FROM users WHERE id = :id');
            $userStmt->execute([':id' => $userId]);
            $currentUser = $userStmt->fetch();
            if (!$currentUser) {
                add_flash('danger', 'Utente non trovato.');
                header('Location: users.php');
                exit;
            }

            $updateErrors = [];
            if ($data['first_name'] === '' || mb_strlen($data['first_name']) < 2) {
                $updateErrors[] = 'Il nome deve contenere almeno 2 caratteri.';
            }
            if ($data['last_name'] === '' || mb_strlen($data['last_name']) < 2) {
                $updateErrors[] = 'Il cognome deve contenere almeno 2 caratteri.';
            }
            if ($data['username'] === '' || !preg_match('/^[A-Za-z0-9._-]{3,}$/', $data['username'])) {
                $updateErrors[] = 'Lo username deve contenere almeno 3 caratteri alfanumerici (ammessi . _ -).';
            }
            if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $updateErrors[] = 'Inserisci un indirizzo email valido.';
            }
            if ($newPassword !== '') {
                if (strlen($newPassword) < 8) {
                    $updateErrors[] = 'La nuova password deve contenere almeno 8 caratteri.';
                }
                if ($newPassword !== $confirmPassword) {
                    $updateErrors[] = 'La conferma password non coincide.';
                }
            }

            if (!$updateErrors) {
                $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE (username = :username OR email = :email) AND id <> :id');
                $dupStmt->execute([
                    ':username' => $data['username'],
                    ':email' => $data['email'],
                    ':id' => $userId,
                ]);
                if ($dupStmt->fetchColumn() > 0) {
                    $updateErrors[] = 'Username o email già in uso.';
                }
            }

            if ($currentUser['ruolo'] === 'Admin' && $data['role'] !== 'Admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE ruolo = 'Admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $updateErrors[] = 'Impossibile rimuovere l\'ultimo amministratore dal ruolo Admin.';
                }
            }

            if ($updateErrors) {
                foreach ($updateErrors as $error) {
                    add_flash('danger', $error);
                }
                header('Location: users.php');
                exit;
            }

            $query = 'UPDATE users SET nome = :nome, cognome = :cognome, username = :username, email = :email, ruolo = :ruolo';
            $params = [
                ':nome' => $data['first_name'],
                ':cognome' => $data['last_name'],
                ':username' => $data['username'],
                ':email' => $data['email'],
                ':ruolo' => $data['role'],
                ':id' => $userId,
            ];
            if ($newPassword !== '') {
                $query .= ', password = :password';
                $params[':password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            }

            $query .= ' WHERE id = :id';
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);

            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                $_SESSION['first_name'] = $data['first_name'];
                $_SESSION['last_name'] = $data['last_name'];
                $_SESSION['username'] = $data['username'];
                $_SESSION['email'] = $data['email'];
                $_SESSION['role'] = $data['role'];
                $_SESSION['display_name'] = format_user_display_name($data['username'], $data['email'], $data['first_name'], $data['last_name']);
            }

            $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':modulo' => 'Impostazioni',
                ':azione' => 'Aggiornamento utente',
                ':dettagli' => trim($data['first_name'] . ' ' . $data['last_name']) . ' [' . $data['username'] . '] (' . $data['role'] . ')',
            ]);

            add_flash('success', 'Profilo utente aggiornato con successo.');
            header('Location: users.php');
            exit;

        case 'delete':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                add_flash('danger', 'Seleziona un utente valido da eliminare.');
                header('Location: users.php');
                exit;
            }

            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                add_flash('danger', 'Non puoi eliminare il tuo account attivo.');
                header('Location: users.php');
                exit;
            }

            $userStmt = $pdo->prepare('SELECT id, username, ruolo FROM users WHERE id = :id');
            $userStmt->execute([':id' => $userId]);
            $userToDelete = $userStmt->fetch();
            if (!$userToDelete) {
                add_flash('danger', 'Utente già rimosso o inesistente.');
                header('Location: users.php');
                exit;
            }

            if ($userToDelete['ruolo'] === 'Admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE ruolo = 'Admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    add_flash('danger', 'Non è possibile eliminare l\'ultimo amministratore.');
                    header('Location: users.php');
                    exit;
                }
            }

            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);

            $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':modulo' => 'Impostazioni',
                ':azione' => 'Eliminazione utente',
                ':dettagli' => $userToDelete['username'] . ' (' . $userToDelete['ruolo'] . ')',
            ]);

            add_flash('success', 'Utente eliminato correttamente.');
            header('Location: users.php');
            exit;

        case 'resend_credentials':
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId <= 0) {
                add_flash('danger', 'Seleziona un utente valido.');
                header('Location: users.php');
                exit;
            }

            $userStmt = $pdo->prepare('SELECT id, nome, cognome, username, email FROM users WHERE id = :id');
            $userStmt->execute([':id' => $userId]);
            $targetUser = $userStmt->fetch();

            if (!$targetUser) {
                add_flash('danger', 'Utente non trovato.');
                header('Location: users.php');
                exit;
            }

            $newPassword = bin2hex(random_bytes(4));
            $passwordUpdate = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
            $passwordUpdate->execute([
                ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => $userId,
            ]);

            $displayName = format_user_display_name($targetUser['username'], $targetUser['email'], $targetUser['nome'], $targetUser['cognome']);
            $baseUrl = env('APP_URL', sprintf('%s://%s', isset($_SERVER['HTTPS']) ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost'));
            $loginUrl = rtrim((string) $baseUrl, '/') . '/login.php';

            $bodyRows = [
                'Username' => $targetUser['username'],
                'Nuova password temporanea' => $newPassword,
                'Pagina di accesso' => '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '" target="_blank" rel="noopener">' . htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8') . '</a>',
            ];

            $body = '<p>Ciao ' . htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') . ',</p>';
            $body .= '<p>di seguito trovi le credenziali aggiornate per accedere alla piattaforma <strong>Coresuite Business</strong>:</p>';
            $body .= '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">';

            foreach ($bodyRows as $label => $value) {
                $body .= '<tr>';
                $body .= '<th align="left" style="padding:10px 16px;background:#f8f9fc;width:220px;">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
                if ($label === 'Pagina di accesso') {
                    $body .= '<td style="padding:10px 16px;">' . $value . '</td>';
                } else {
                    $body .= '<td style="padding:10px 16px;">' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                $body .= '</tr>';
            }

            $body .= '</table>';
            $body .= '<p style="margin-top:16px;">Ti consigliamo di cambiare la password dopo il prossimo login. Se non hai richiesto l\'aggiornamento contatta subito l\'amministratore.</p>';

            $mailSent = false;
            if (function_exists('render_mail_template') && function_exists('send_system_mail')) {
                $subject = 'Credenziali aggiornate per Coresuite Business';
                $htmlBody = render_mail_template('Credenziali aggiornate', $body);
                $mailSent = send_system_mail((string) $targetUser['email'], $subject, $htmlBody);
                if (!$mailSent) {
                    error_log('Reinvio credenziali fallito per ' . ($targetUser['email'] ?? 'sconosciuto'));
                }
            }

            $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':modulo' => 'Impostazioni',
                ':azione' => 'Reinvio credenziali utente',
                ':dettagli' => trim(($targetUser['nome'] ?? '') . ' ' . ($targetUser['cognome'] ?? '')) . ' [' . $targetUser['username'] . ']',
            ]);

            if ($mailSent) {
                add_flash('success', 'Email con le nuove credenziali inviata correttamente.');
            } else {
                add_flash('warning', 'Email non inviata. Comunica manualmente la nuova password: ' . $newPassword);
            }

            header('Location: users.php');
            exit;

        default:
            add_flash('danger', 'Azione non riconosciuta.');
            header('Location: users.php');
            exit;
    }
}

$users = $pdo->query("SELECT id, username, email, ruolo, nome, cognome, last_login_at, created_at FROM users ORDER BY cognome, nome, username")
    ->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Gestione utenti</h1>
                <p class="text-muted mb-0">Crea, aggiorna ed elimina gli account del team.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-soft-accent" href="index.php"><i class="fa-solid fa-gear me-2"></i>Torna alle impostazioni</a>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-12 col-lg-5">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Nuovo utente</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($createErrors): ?>
                            <div class="alert alert-danger">
                                <?php foreach ($createErrors as $error): ?>
                                    <div><?php echo sanitize_output($error); ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <form method="post" novalidate>
                            <input type="hidden" name="action" value="create">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-3">
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="first_name">Nome *</label>
                                    <input class="form-control" id="first_name" name="first_name" required value="<?php echo sanitize_output($createData['first_name']); ?>">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="last_name">Cognome *</label>
                                    <input class="form-control" id="last_name" name="last_name" required value="<?php echo sanitize_output($createData['last_name']); ?>">
                                </div>
                            </div>
                            <div class="mt-3">
                            <div class="mb-3">
                                <label class="form-label" for="username">Username *</label>
                                <input class="form-control" id="username" name="username" required value="<?php echo sanitize_output($createData['username']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email">Email *</label>
                                <input class="form-control" id="email" name="email" type="email" required value="<?php echo sanitize_output($createData['email']); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="role">Ruolo *</label>
                                <select class="form-select" id="role" name="role">
                                    <?php foreach ($allowedRoles as $roleOption): ?>
                                        <option value="<?php echo $roleOption; ?>" <?php echo $createData['role'] === $roleOption ? 'selected' : ''; ?>><?php echo sanitize_output($roleLabels[$roleOption] ?? $roleOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="password">Password temporanea *</label>
                                <input class="form-control" id="password" name="password" type="password" required>
                                <div class="form-text">Minimo 8 caratteri. L'utente potrà cambiarla dopo il primo accesso.</div>
                            </div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-user-plus me-2"></i>Crea utente</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-7">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Utenti attivi</h5>
                        <span class="badge bg-secondary"><?php echo count($users); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Ruolo</th>
                                        <th>Ultimo accesso</th>
                                        <th>Creato il</th>
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($users as $user): ?>
                                    <?php $isCurrentUser = (int)$user['id'] === (int)($_SESSION['user_id'] ?? 0); ?>
                                    <tr id="user-row-<?php echo (int) $user['id']; ?>">
                                        <td><?php echo sanitize_output(format_user_display_name($user['username'], $user['email'], $user['nome'], $user['cognome'])); ?></td>
                                        <td><?php echo sanitize_output($user['username']); ?></td>
                                        <td><?php echo sanitize_output($user['email']); ?></td>
                                        <td><span class="badge ag-badge"><?php echo sanitize_output($roleLabels[$user['ruolo']] ?? $user['ruolo']); ?></span></td>
                                        <td><?php echo $user['last_login_at'] ? sanitize_output(date('d/m/Y H:i', strtotime($user['last_login_at']))) : '—'; ?></td>
                                        <td><?php echo sanitize_output(format_datetime($user['created_at'])); ?></td>
                                        <td class="text-end">
                                            <button class="btn btn-icon btn-soft-accent btn-sm me-2" type="button" title="Modifica utente"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editUserModal"
                                                data-user-id="<?php echo (int)$user['id']; ?>"
                                                data-first-name="<?php echo sanitize_output((string) $user['nome']); ?>"
                                                data-last-name="<?php echo sanitize_output((string) $user['cognome']); ?>"
                                                data-username="<?php echo sanitize_output($user['username']); ?>"
                                                data-email="<?php echo sanitize_output($user['email']); ?>"
                                                data-role="<?php echo sanitize_output($user['ruolo']); ?>">
                                                <i class="fa-solid fa-pen"></i>
                                            </button>
                                            <form method="post" class="d-inline me-2">
                                                <input type="hidden" name="action" value="resend_credentials">
                                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                                <button class="btn btn-icon btn-soft-primary btn-sm" type="submit" title="Reinvia credenziali"
                                                    <?php echo $user['email'] ? '' : 'disabled'; ?>>
                                                    <i class="fa-solid fa-paper-plane"></i>
                                                </button>
                                            </form>
                                            <?php if (!$isCurrentUser): ?>
                                                <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questo utente?');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                                    <input type="hidden" name="user_id" value="<?php echo (int)$user['id']; ?>">
                                                    <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina utente"><i class="fa-solid fa-user-minus"></i></button>
                                                </form>
                                            <?php else: ?>
                                                <span class="badge bg-info">Il tuo profilo</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$users): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">Ancora nessun utente definito.</td>
                                    </tr>
                                <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="editUserModalLabel">Modifica utente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <form method="post" id="editUserForm" novalidate>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="user_id" id="edit_user_id" value="">
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="edit_first_name">Nome *</label>
                            <input class="form-control" id="edit_first_name" name="edit_first_name" required>
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="edit_last_name">Cognome *</label>
                            <input class="form-control" id="edit_last_name" name="edit_last_name" required>
                        </div>
                    </div>
                    <div class="mt-3">
                    <div class="mb-3">
                        <label class="form-label" for="edit_username">Username *</label>
                        <input class="form-control" id="edit_username" name="edit_username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_email">Email *</label>
                        <input class="form-control" id="edit_email" name="edit_email" type="email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_role">Ruolo *</label>
                        <select class="form-select" id="edit_role" name="edit_role">
                            <?php foreach ($allowedRoles as $roleOption): ?>
                                <option value="<?php echo $roleOption; ?>"><?php echo sanitize_output($roleLabels[$roleOption] ?? $roleOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="edit_password">Nuova password</label>
                            <input class="form-control" id="edit_password" name="edit_password" type="password" placeholder="Lascia vuoto per non cambiare">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="edit_password_confirm">Conferma password</label>
                            <input class="form-control" id="edit_password_confirm" name="edit_password_confirm" type="password" placeholder="Ripeti nuova password">
                        </div>
                    </div>
                    </div>
                    <div class="form-text mt-2">La password deve contenere almeno 8 caratteri. Se lasci i campi vuoti verrà mantenuta quella attuale.</div>
                </form>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-soft-accent" data-bs-dismiss="modal"><i class="fa-solid fa-circle-xmark me-2"></i>Annulla</button>
                <button type="submit" form="editUserForm" class="btn btn-warning"><i class="fa-solid fa-floppy-disk me-2"></i>Salva modifiche</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
    const editUserModal = document.getElementById('editUserModal');
    editUserModal?.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        if (!button) {
            return;
        }
        const userId = button.getAttribute('data-user-id');
        const firstName = button.getAttribute('data-first-name');
        const lastName = button.getAttribute('data-last-name');
        const username = button.getAttribute('data-username');
        const email = button.getAttribute('data-email');
        const role = button.getAttribute('data-role');
        const form = document.getElementById('editUserForm');
        if (!form) {
            return;
        }
        form.querySelector('#edit_user_id').value = userId ?? '';
        form.querySelector('#edit_first_name').value = firstName ?? '';
        form.querySelector('#edit_last_name').value = lastName ?? '';
        form.querySelector('#edit_username').value = username ?? '';
        form.querySelector('#edit_email').value = email ?? '';
        form.querySelector('#edit_role').value = role ?? '';
        form.querySelector('#edit_password').value = '';
        form.querySelector('#edit_password_confirm').value = '';
    });

    editUserModal?.addEventListener('hidden.bs.modal', () => {
        const form = document.getElementById('editUserForm');
        if (form) {
            form.reset();
        }
    });
</script>
