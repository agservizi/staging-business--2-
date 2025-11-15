<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore');
$pageTitle = 'Nuovo cliente';

$csrfToken = csrf_token();
$errors = [];
$data = [
    'ragione_sociale' => '',
    'nome' => '',
    'cognome' => '',
    'cf_piva' => '',
    'email' => '',
    'telefono' => '',
    'indirizzo' => '',
    'note' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    foreach ($data as $field => $_) {
        $data[$field] = trim($_POST[$field] ?? '');
    }

    if ($data['ragione_sociale'] !== '' && mb_strlen($data['ragione_sociale']) > 160) {
        $errors[] = 'La ragione sociale non può superare i 160 caratteri.';
    }

    if ($data['nome'] === '' || $data['cognome'] === '') {
        $errors[] = 'Nome e cognome sono obbligatori.';
    }
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email non valida.';
    }
    if ($data['cf_piva'] !== '' && !preg_match('/^[A-Z0-9]{11,16}$/i', $data['cf_piva'])) {
        $errors[] = 'Codice fiscale o partita IVA non ha un formato valido.';
    }
    if ($data['telefono'] !== '' && !preg_match('/^[0-9+()\s-]{6,}$/', $data['telefono'])) {
        $errors[] = 'Numero di telefono non valido.';
    }
    if (mb_strlen($data['note']) > 2000) {
        $errors[] = 'Le note non possono superare i 2000 caratteri.';
    }

    if (!$errors && $data['email'] !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clienti WHERE email = :email');
        $stmt->execute([':email' => $data['email']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Esiste già un cliente con questa email.';
        }
    }

    if (!$errors && $data['cf_piva'] !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clienti WHERE cf_piva = :cf_piva');
        $stmt->execute([':cf_piva' => $data['cf_piva']]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Esiste già un cliente con questo codice fiscale / partita IVA.';
        }
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO clienti (ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note) VALUES (:ragione_sociale, :nome, :cognome, :cf_piva, :email, :telefono, :indirizzo, :note)');
        $stmt->execute([
            ':ragione_sociale' => $data['ragione_sociale'],
            ':nome' => $data['nome'],
            ':cognome' => $data['cognome'],
            ':cf_piva' => $data['cf_piva'],
            ':email' => $data['email'],
            ':telefono' => $data['telefono'],
            ':indirizzo' => $data['indirizzo'],
            ':note' => $data['note'],
        ]);

        $clientId = (int) $pdo->lastInsertId();
        $logLabel = $data['ragione_sociale'] !== ''
            ? $data['ragione_sociale']
            : trim($data['cognome'] . ' ' . $data['nome']);
        if ($logLabel === '') {
            $logLabel = 'Cliente';
        }

        $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $logStmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':modulo' => 'Clienti',
            ':azione' => 'Creazione cliente',
            ':dettagli' => sprintf('%s (#%d)', $logLabel, $clientId),
        ]);

        add_flash('success', 'Cliente creato con successo.');
        header('Location: index.php');
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Nuovo cliente</h1>
                <p class="text-muted mb-0">Completa i dati anagrafici e di contatto per aggiungere un nuovo cliente in rubrica.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna ai clienti</a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dati anagrafici</h2>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning">
                        <?php echo implode('<br>', array_map('sanitize_output', $errors)); ?>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label" for="ragione_sociale">Ragione sociale</label>
                            <input class="form-control" id="ragione_sociale" name="ragione_sociale" value="<?php echo sanitize_output($data['ragione_sociale']); ?>" maxlength="160" placeholder="Es. Azienda SRL">
                            <small class="text-muted">Compila se il cliente è un'azienda.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nome">Nome</label>
                            <input class="form-control" id="nome" name="nome" value="<?php echo sanitize_output($data['nome']); ?>" autocomplete="given-name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cognome">Cognome</label>
                            <input class="form-control" id="cognome" name="cognome" value="<?php echo sanitize_output($data['cognome']); ?>" autocomplete="family-name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cf_piva">CF / P.IVA</label>
                            <input class="form-control" id="cf_piva" name="cf_piva" value="<?php echo sanitize_output($data['cf_piva']); ?>" maxlength="16" autocomplete="off">
                            <small class="text-muted">Inserisci codice fiscale o partita IVA senza spazi.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" id="email" type="email" name="email" value="<?php echo sanitize_output($data['email']); ?>" autocomplete="email">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="telefono">Telefono</label>
                            <input class="form-control" id="telefono" name="telefono" value="<?php echo sanitize_output($data['telefono']); ?>" autocomplete="tel">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="indirizzo">Indirizzo</label>
                            <input class="form-control" id="indirizzo" name="indirizzo" value="<?php echo sanitize_output($data['indirizzo']); ?>" autocomplete="street-address">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="note">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="4" placeholder="Informazioni operative, preferenze o altre note interne."><?php echo sanitize_output($data['note']); ?></textarea>
                            <small class="text-muted">Massimo 2000 caratteri. Non visibile ai clienti.</small>
                        </div>
                    </div>
                    <div class="stack-sm justify-content-end mt-4">
                        <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-rotate-left me-2"></i>Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva cliente</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
