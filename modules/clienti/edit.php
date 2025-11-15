<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore');
$pageTitle = 'Modifica cliente';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM clienti WHERE id = :id');
$stmt->execute([':id' => $id]);
$client = $stmt->fetch();

if (!$client) {
    header('Location: index.php?notfound=1');
    exit;
}

$errors = [];
$data = $client;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $fields = ['ragione_sociale', 'nome', 'cognome', 'cf_piva', 'email', 'telefono', 'indirizzo', 'note'];
    foreach ($fields as $field) {
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
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clienti WHERE email = :email AND id <> :id');
        $stmt->execute([':email' => $data['email'], ':id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Esiste già un cliente con questa email.';
        }
    }

    if (!$errors && $data['cf_piva'] !== '') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM clienti WHERE cf_piva = :cf_piva AND id <> :id');
        $stmt->execute([':cf_piva' => $data['cf_piva'], ':id' => $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Esiste già un cliente con questo codice fiscale / partita IVA.';
        }
    }

    if (!$errors) {
        $update = $pdo->prepare('UPDATE clienti SET ragione_sociale = :ragione_sociale, nome = :nome, cognome = :cognome, cf_piva = :cf_piva, email = :email, telefono = :telefono, indirizzo = :indirizzo, note = :note WHERE id = :id');
        $update->execute([
            ':ragione_sociale' => $data['ragione_sociale'],
            ':nome' => $data['nome'],
            ':cognome' => $data['cognome'],
            ':cf_piva' => $data['cf_piva'],
            ':email' => $data['email'],
            ':telefono' => $data['telefono'],
            ':indirizzo' => $data['indirizzo'],
            ':note' => $data['note'],
            ':id' => $id,
        ]);

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
            ':azione' => 'Aggiornamento cliente',
            ':dettagli' => sprintf('%s (#%d)', $logLabel, $id),
        ]);

        add_flash('success', 'Cliente aggiornato con successo.');
        header('Location: view.php?id=' . $id);
        exit;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="mb-4">
            <a class="btn btn-outline-warning" href="view.php?id=<?php echo $id; ?>"><i class="fa-solid fa-arrow-left"></i> Dettaglio cliente</a>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h1 class="h4 mb-0">Modifica cliente</h1>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning">
                        <?php echo implode('<br>', array_map('sanitize_output', $errors)); ?>
                    </div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label" for="ragione_sociale">Ragione sociale</label>
                            <input class="form-control" id="ragione_sociale" name="ragione_sociale" value="<?php echo sanitize_output($data['ragione_sociale']); ?>" maxlength="160">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="nome">Nome</label>
                            <input class="form-control" id="nome" name="nome" value="<?php echo sanitize_output($data['nome']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cognome">Cognome</label>
                            <input class="form-control" id="cognome" name="cognome" value="<?php echo sanitize_output($data['cognome']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="cf_piva">CF / P.IVA</label>
                            <input class="form-control" id="cf_piva" name="cf_piva" value="<?php echo sanitize_output($data['cf_piva']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" id="email" type="email" name="email" value="<?php echo sanitize_output($data['email']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="telefono">Telefono</label>
                            <input class="form-control" id="telefono" name="telefono" value="<?php echo sanitize_output($data['telefono']); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="indirizzo">Indirizzo</label>
                            <input class="form-control" id="indirizzo" name="indirizzo" value="<?php echo sanitize_output($data['indirizzo']); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="note">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="4"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-secondary" href="view.php?id=<?php echo $id; ?>">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit">Salva modifiche</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
