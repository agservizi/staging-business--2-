<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

$pageTitle = 'Profilo personale';
$csrfToken = csrf_token();
$userId = (int)($_SESSION['user_id'] ?? 0);
$alerts = [];
$allowedThemes = ['dark', 'light'];

if ($userId <= 0) {
    add_flash('danger', 'Sessione non valida. Accedi nuovamente.');
    header('Location: ' . base_url('index.php'));
    exit;
}

$userStmt = $pdo->prepare('SELECT id, username, email, nome, cognome, ruolo, theme_preference, last_login_at, created_at, mfa_enabled, mfa_enabled_at, mfa_secret FROM users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => $userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    add_flash('danger', 'Profilo utente non trovato.');
    header('Location: ' . base_url('dashboard.php'));
    exit;
}

$formValues = [
    'first_name' => $user['nome'],
    'last_name' => $user['cognome'],
    'email' => $user['email'],
    'theme' => $user['theme_preference'],
];

$extraScripts = $extraScripts ?? [];
$extraScripts[] = asset('assets/js/mfa-qr-devices.js');

$mfaQrEndpoints = [
    'list' => base_url('api/mfa/qr/devices/index.php'),
    'create' => base_url('api/mfa/qr/devices/create.php'),
    'revoke' => base_url('api/mfa/qr/devices/revoke.php'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'details') {
        $data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'theme' => $_POST['theme_preference'] ?? $user['theme_preference'],
        ];

        $data['first_name'] = mb_convert_case(mb_strtolower($data['first_name'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        $data['last_name'] = mb_convert_case(mb_strtolower($data['last_name'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

    $formValues = array_merge($formValues, $data);

        if ($data['first_name'] === '' || mb_strlen($data['first_name']) < 2) {
            $alerts[] = ['type' => 'danger', 'text' => 'Il nome deve contenere almeno 2 caratteri.'];
        }
        if ($data['last_name'] === '' || mb_strlen($data['last_name']) < 2) {
            $alerts[] = ['type' => 'danger', 'text' => 'Il cognome deve contenere almeno 2 caratteri.'];
        }
        if ($data['email'] === '' || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $alerts[] = ['type' => 'danger', 'text' => 'Inserisci un indirizzo email valido.'];
        }
        if (!in_array($data['theme'], $allowedThemes, true)) {
            $alerts[] = ['type' => 'danger', 'text' => 'Tema selezionato non valido.'];
        }

        if ($data['email'] !== $user['email']) {
            $dupStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id <> :id');
            $dupStmt->execute([
                ':email' => $data['email'],
                ':id' => $userId,
            ]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $alerts[] = ['type' => 'danger', 'text' => 'Email già utilizzata da un altro utente.'];
            }
        }

        if (!$alerts) {
            try {
                $stmt = $pdo->prepare('UPDATE users SET nome = :nome, cognome = :cognome, email = :email, theme_preference = :theme WHERE id = :id');
                $stmt->execute([
                    ':nome' => $data['first_name'],
                    ':cognome' => $data['last_name'],
                    ':email' => $data['email'],
                    ':theme' => $data['theme'],
                    ':id' => $userId,
                ]);

                $_SESSION['first_name'] = $data['first_name'];
                $_SESSION['last_name'] = $data['last_name'];
                $_SESSION['email'] = $data['email'];
                $_SESSION['display_name'] = format_user_display_name($_SESSION['username'] ?? '', $data['email'], $data['first_name'], $data['last_name']);
                $_SESSION['theme_preference'] = $data['theme'];

                $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                $logStmt->execute([
                    ':user_id' => $userId,
                    ':modulo' => 'Profilo',
                    ':azione' => 'Aggiornamento informazioni',
                    ':dettagli' => json_encode([
                        'nome' => $data['first_name'],
                        'cognome' => $data['last_name'],
                        'email' => $data['email'],
                        'theme' => $data['theme'],
                    ], JSON_UNESCAPED_UNICODE),
                ]);

                add_flash('success', 'Profilo aggiornato con successo.');
                header('Location: profile.php');
                exit;
            } catch (Throwable $e) {
                error_log('Profile update failed: ' . $e->getMessage());
                $alerts[] = ['type' => 'danger', 'text' => 'Errore durante l\'aggiornamento del profilo.'];
            }
        }
    }

    if ($action === 'password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $alerts[] = ['type' => 'danger', 'text' => 'Compila tutti i campi per aggiornare la password.'];
        }
        if ($newPassword !== '' && strlen($newPassword) < 8) {
            $alerts[] = ['type' => 'danger', 'text' => 'La nuova password deve contenere almeno 8 caratteri.'];
        }
        if ($newPassword !== $confirmPassword) {
            $alerts[] = ['type' => 'danger', 'text' => 'La conferma password non coincide.'];
        }

        if (!$alerts) {
            $passwordStmt = $pdo->prepare('SELECT password FROM users WHERE id = :id');
            $passwordStmt->execute([':id' => $userId]);
            $currentHash = $passwordStmt->fetchColumn();

            if (!$currentHash || !password_verify($currentPassword, (string)$currentHash)) {
                $alerts[] = ['type' => 'danger', 'text' => 'La password attuale non è corretta.'];
            }
        }

        if (!$alerts) {
            try {
                $updateStmt = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $updateStmt->execute([
                    ':password' => password_hash($newPassword, PASSWORD_DEFAULT),
                    ':id' => $userId,
                ]);

                $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                $logStmt->execute([
                    ':user_id' => $userId,
                    ':modulo' => 'Profilo',
                    ':azione' => 'Aggiornamento password',
                    ':dettagli' => 'Password modificata',
                ]);

                add_flash('success', 'Password aggiornata correttamente.');
                header('Location: profile.php');
                exit;
            } catch (Throwable $e) {
                error_log('Password update failed: ' . $e->getMessage());
                $alerts[] = ['type' => 'danger', 'text' => 'Errore durante l\'aggiornamento della password.'];
            }
        }
    }

    if ($action === 'mfa_start') {
        $_SESSION['mfa_setup'] = [
            'mode' => 'manage',
            'user' => build_user_session_payload($user),
            'ip' => request_ip(),
            'user_agent' => request_user_agent(),
            'created_at' => time(),
            'expires_at' => time() + 900,
            'return_to' => base_url('modules/impostazioni/profile.php'),
            'reset' => !empty($_POST['reset']),
        ];

        add_flash('info', 'Completa la configurazione MFA per proteggere il tuo account.');
        header('Location: ' . base_url('mfa-setup.php'));
        exit;
    }

    if ($action === 'mfa_disable') {
        if ((int) ($user['mfa_enabled'] ?? 0) !== 1 || empty($user['mfa_secret'])) {
            $alerts[] = ['type' => 'warning', 'text' => 'L\'autenticazione a due fattori risulta già disattivata.'];
        } else {
            $code = preg_replace('/\s+/', '', (string) ($_POST['mfa_code'] ?? ''));
            if ($code === '') {
                $alerts[] = ['type' => 'danger', 'text' => 'Inserisci il codice MFA per confermare la disattivazione.'];
            } elseif (!preg_match('/^[0-9]{6}$/', $code)) {
                $alerts[] = ['type' => 'danger', 'text' => 'Il codice MFA deve contenere 6 cifre.'];
            } else {
                $totpClass = '\\OTPHP\\TOTP';
                if (!class_exists($totpClass)) {
                    $alerts[] = ['type' => 'danger', 'text' => 'Libreria MFA non disponibile. Contatta l\'amministratore.'];
                } else {
                    $validator = $totpClass::create($user['mfa_secret'], 30, 'sha1', 6);
                    if (!$validator->verify($code, null, 1)) {
                        $alerts[] = ['type' => 'danger', 'text' => 'Codice MFA non corretto. Riprova.'];
                    } else {
                        $pdo->prepare('UPDATE users SET mfa_secret = NULL, mfa_enabled = 0, mfa_enabled_at = NULL WHERE id = :id')
                            ->execute([':id' => $userId]);

                        $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                        $logStmt->execute([
                            ':user_id' => $userId,
                            ':modulo' => 'Profilo',
                            ':azione' => 'Disattivazione MFA',
                            ':dettagli' => json_encode(['reason' => 'user_request'], JSON_UNESCAPED_UNICODE),
                        ]);

                        unset($_SESSION['mfa_verified_at']);
                        unset($_SESSION['mfa_setup']);
                        add_flash('success', 'Autenticazione a due fattori disattivata correttamente.');
                        header('Location: profile.php');
                        exit;
                    }
                }
            }
        }
    }
}

$mfaEnabled = (int) ($user['mfa_enabled'] ?? 0) === 1;
$mfaEnabledAt = $user['mfa_enabled_at'] ?? null;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Profilo personale</h1>
                <p class="text-muted mb-0">Gestisci le tue informazioni, la sicurezza dell'account e le preferenze di interfaccia.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-gear me-2"></i>Torna alle impostazioni</a>
            </div>
        </div>

        <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-<?php echo sanitize_output($alert['type']); ?>"><?php echo sanitize_output($alert['text']); ?></div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-12 col-xl-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Informazioni personali</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" novalidate>
                            <input type="hidden" name="action" value="details">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-3">
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="first_name">Nome *</label>
                                    <input class="form-control" id="first_name" name="first_name" required value="<?php echo sanitize_output($formValues['first_name']); ?>">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="last_name">Cognome *</label>
                                    <input class="form-control" id="last_name" name="last_name" required value="<?php echo sanitize_output($formValues['last_name']); ?>">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="email">Email *</label>
                                    <input class="form-control" id="email" name="email" type="email" required value="<?php echo sanitize_output($formValues['email']); ?>">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="theme_preference">Tema interfaccia</label>
                                    <select class="form-select" id="theme_preference" name="theme_preference">
                                        <?php foreach ($allowedThemes as $theme): ?>
                                            <option value="<?php echo $theme; ?>" <?php echo ($formValues['theme'] === $theme) ? 'selected' : ''; ?>>
                                                <?php echo $theme === 'dark' ? 'Scuro' : 'Chiaro'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="username">Username</label>
                                    <input class="form-control" id="username" value="<?php echo sanitize_output($user['username']); ?>" disabled>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="role">Ruolo</label>
                                    <input class="form-control" id="role" value="<?php echo sanitize_output($user['ruolo']); ?>" disabled>
                                </div>
                            </div>
                            <div class="mt-4 text-end">
                                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva modifiche</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Protezione account</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" novalidate>
                            <input type="hidden" name="action" value="password">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="current_password">Password attuale *</label>
                                    <input class="form-control" id="current_password" name="current_password" type="password" required>
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="new_password">Nuova password *</label>
                                    <input class="form-control" id="new_password" name="new_password" type="password" required minlength="8">
                                </div>
                                <div class="col-12 col-lg-6">
                                    <label class="form-label" for="confirm_password">Conferma nuova password *</label>
                                    <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="8">
                                </div>
                            </div>
                            <div class="form-text mt-3">Suggerimento: utilizza almeno 8 caratteri con lettere maiuscole, minuscole, numeri e simboli.</div>
                            <div class="mt-4 text-end">
                                <button class="btn btn-outline-warning" type="submit"><i class="fa-solid fa-key me-2"></i>Aggiorna password</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Autenticazione a due fattori (MFA)</h5>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <p class="text-muted mb-0">Aggiungi un secondo passaggio di sicurezza utilizzando Google Authenticator o app compatibili.</p>
                        <div class="d-flex align-items-center gap-3">
                            <?php if ($mfaEnabled): ?>
                                <span class="badge text-bg-success px-3 py-2">Attiva</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary px-3 py-2">Non attiva</span>
                            <?php endif; ?>
                            <?php if ($mfaEnabled && $mfaEnabledAt): ?>
                                <span class="text-muted small">Attiva dal <?php echo sanitize_output(format_datetime($mfaEnabledAt)); ?></span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$mfaEnabled): ?>
                            <p class="mb-0">Consigliato per tutti gli utenti: attiva l'autenticazione a due fattori al prossimo accesso.</p>
                            <form method="post" class="d-flex flex-column flex-sm-row gap-2">
                                <input type="hidden" name="action" value="mfa_start">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <button class="btn btn-warning text-dark flex-fill" type="submit">
                                    <i class="fa-solid fa-qrcode me-2"></i>Configura con Authenticator
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="bg-body-secondary border rounded-3 p-3">
                                <span class="text-muted small d-block mb-2">Disattiva MFA</span>
                                <form method="post" class="row g-2 align-items-end">
                                    <input type="hidden" name="action" value="mfa_disable">
                                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                    <div class="col-sm-7">
                                        <label class="form-label" for="mfa_code">Codice attuale</label>
                                        <input class="form-control" id="mfa_code" name="mfa_code" type="text" inputmode="numeric" pattern="[0-9]{6}" placeholder="000000" required>
                                    </div>
                                    <div class="col-sm-5">
                                        <button class="btn btn-outline-warning w-100" type="submit"><i class="fa-solid fa-lock-open me-2"></i>Disattiva</button>
                                    </div>
                                </form>
                                <div class="form-text mt-2">Richiede il codice generato dall'app per confermare l'operazione.</div>
                            </div>
                            <form method="post" class="d-flex flex-column flex-sm-row gap-2">
                                <input type="hidden" name="action" value="mfa_start">
                                <input type="hidden" name="reset" value="1">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <button class="btn btn-warning text-dark flex-fill" type="submit">
                                    <i class="fa-solid fa-arrows-rotate me-2"></i>Rigenera configurazione
                                </button>
                            </form>
                        <?php endif; ?>
                        <div class="form-text">Durante la configurazione verrà richiesto di scannerizzare un nuovo QR code.</div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div
                    class="card ag-card h-100"
                    data-mfa-qr-root
                    data-endpoint-list="<?php echo sanitize_output($mfaQrEndpoints['list']); ?>"
                    data-endpoint-create="<?php echo sanitize_output($mfaQrEndpoints['create']); ?>"
                    data-endpoint-revoke="<?php echo sanitize_output($mfaQrEndpoints['revoke']); ?>"
                    data-csrf="<?php echo sanitize_output($csrfToken); ?>"
                >
                    <div class="card-header bg-transparent border-0 d-flex flex-column flex-lg-row gap-3 align-items-lg-center">
                        <div>
                            <h5 class="card-title mb-1">Dispositivi MFA via QR</h5>
                            <p class="text-muted mb-0 small">Approva gli accessi scannerizzando il QR dal dispositivo mobile e confermando il PIN.</p>
                        </div>
                        <div class="ms-lg-auto d-flex flex-wrap gap-2">
                            <button type="button" class="btn btn-outline-light btn-sm" data-mfa-qr-refresh>
                                <i class="fa-solid fa-rotate me-1"></i>Ricarica
                            </button>
                            <button type="button" class="btn btn-warning btn-sm" data-mfa-qr-toggle-form>
                                <i class="fa-solid fa-qrcode me-1"></i>Abbina dispositivo
                            </button>
                        </div>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <div class="alert alert-danger d-none" role="alert" data-mfa-qr-alert></div>
                        <div class="alert alert-secondary d-none small" role="status" data-mfa-qr-pin-policy></div>

                        <div class="text-center py-4 d-none" data-mfa-qr-loading>
                            <div class="spinner-border text-warning" role="status"></div>
                            <p class="text-muted small mt-3 mb-0">Caricamento dispositivi...</p>
                        </div>

                        <div class="list-group" data-mfa-qr-devices-list></div>
                        <div class="text-muted small" data-mfa-qr-empty>Nessun dispositivo QR registrato.</div>

                        <div class="alert alert-info d-none" data-mfa-qr-provisioning>
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <h6 class="fw-semibold mb-1">Dispositivo in attesa di scansione</h6>
                                    <p class="mb-0 small">Apri l'app mobile e inquadra il QR entro <span data-mfa-qr-provisioning-expiry>—</span>.</p>
                                </div>
                                <button type="button" class="btn-close" aria-label="Chiudi" data-mfa-qr-dismiss></button>
                            </div>
                            <div class="bg-white border rounded-3 p-3 mt-3">
                                <div class="small text-muted">Token di provisioning</div>
                                <code class="d-block text-break" data-mfa-qr-provisioning-token>—</code>
                            </div>
                            <div class="bg-white border rounded-3 p-3 mt-3">
                                <div class="small text-muted">Payload da convertire in QR</div>
                                <pre class="small mb-0 text-break" data-mfa-qr-provisioning-payload>{}</pre>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-3">
                                <button type="button" class="btn btn-outline-light btn-sm" data-mfa-qr-copy>
                                    <i class="fa-solid fa-copy me-1"></i>Copia payload
                                </button>
                                <button type="button" class="btn btn-outline-warning btn-sm" data-mfa-qr-refresh-after>
                                    <i class="fa-solid fa-circle-check me-1"></i>Ho completato l'abbinamento
                                </button>
                            </div>
                        </div>

                        <div class="border rounded-3 bg-body-secondary p-3 d-none" data-mfa-qr-form-wrapper>
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div>
                                    <h6 class="mb-1">Abbina un nuovo dispositivo</h6>
                                    <p class="mb-0 text-muted small">Imposta un PIN numerico: verrà richiesto sull'app ad ogni approvazione.</p>
                                </div>
                                <button type="button" class="btn-close" aria-label="Chiudi" data-mfa-qr-toggle-form></button>
                            </div>
                            <form data-mfa-qr-form autocomplete="off">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <div class="mb-3">
                                    <label class="form-label" for="qr_device_label">Nome dispositivo</label>
                                    <input class="form-control" id="qr_device_label" name="label" type="text" minlength="3" maxlength="100" required placeholder="es. iPhone assistenza">
                                </div>
                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <label class="form-label" for="qr_device_pin">PIN</label>
                                        <input class="form-control" id="qr_device_pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required placeholder="4-8 cifre">
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="form-label" for="qr_device_pin_confirm">Conferma PIN</label>
                                        <input class="form-control" id="qr_device_pin_confirm" name="pin_confirmation" type="password" inputmode="numeric" pattern="[0-9]{4,8}" maxlength="8" required placeholder="Ripeti PIN">
                                    </div>
                                </div>
                                <div class="form-text">Condividi il PIN solo con chi utilizzerà il dispositivo.</div>
                                <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                                    <button type="submit" class="btn btn-warning flex-fill" data-mfa-qr-submit>
                                        <i class="fa-solid fa-link me-2"></i>Genera QR di pairing
                                    </button>
                                    <button type="button" class="btn btn-outline-light flex-fill" data-mfa-qr-toggle-form>Chiudi</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card mt-4">
            <div class="card-header bg-transparent border-0">
                <h5 class="card-title mb-0">Metadati account</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 bg-body-secondary h-100">
                            <span class="text-muted small">Creato il</span>
                            <div class="fw-semibold fs-5 mt-1"><?php echo sanitize_output(format_datetime($user['created_at'])); ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 bg-body-secondary h-100">
                            <span class="text-muted small">Ultimo accesso</span>
                            <div class="fw-semibold fs-5 mt-1"><?php echo $user['last_login_at'] ? sanitize_output(format_datetime($user['last_login_at'])) : '—'; ?></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <div class="border rounded-3 p-3 bg-body-secondary h-100">
                            <span class="text-muted small">Identificativo utente</span>
                            <div class="fw-semibold fs-5 mt-1">#<?php echo (int)$user['id']; ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
