<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuovo ticket';

$clients = $pdo->query('SELECT id, nome, cognome FROM clienti ORDER BY cognome, nome')->fetchAll();
$statuses = ['Aperto', 'In corso', 'Chiuso'];

$data = [
    'cliente_id' => '',
    'titolo' => '',
    'descrizione' => '',
    'stato' => 'Aperto',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($data as $field => $_) {
        $data[$field] = trim($_POST[$field] ?? '');
    }

    if ($data['titolo'] === '' || $data['descrizione'] === '') {
        $errors[] = 'Titolo e descrizione sono obbligatori.';
    }
    if ($data['cliente_id'] !== '' && (int) $data['cliente_id'] <= 0) {
        $errors[] = 'Seleziona un cliente valido o lascia vuoto per ticket interno.';
    }
    if (!in_array($data['stato'], $statuses, true)) {
        $errors[] = 'Stato non valido.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO ticket (cliente_id, titolo, descrizione, stato, created_by) VALUES (:cliente_id, :titolo, :descrizione, :stato, :created_by)');
        $stmt->execute([
            ':cliente_id' => $data['cliente_id'] !== '' ? (int) $data['cliente_id'] : null,
            ':titolo' => $data['titolo'],
            ':descrizione' => $data['descrizione'],
            ':stato' => $data['stato'],
            ':created_by' => $_SESSION['user_id'],
        ]);
        $ticketId = (int) $pdo->lastInsertId();
        header('Location: view.php?id=' . $ticketId . '&created=1');
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
            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Tutti i ticket</a>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h1 class="h4 mb-0">Nuovo ticket di assistenza</h1>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning"><?php echo implode('<br>', array_map('sanitize_output', $errors)); ?></div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="cliente_id">Cliente (opzionale)</label>
                            <select class="form-select" id="cliente_id" name="cliente_id">
                                <option value="">Ticket interno</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo ((int) $data['cliente_id'] === (int) $client['id']) ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($client['cognome'] . ' ' . $client['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="stato">Stato</label>
                            <select class="form-select" id="stato" name="stato">
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo $status; ?>" <?php echo $data['stato'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="titolo">Titolo</label>
                            <input class="form-control" id="titolo" name="titolo" value="<?php echo sanitize_output($data['titolo']); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="descrizione">Descrizione</label>
                            <textarea class="form-control" id="descrizione" name="descrizione" rows="5" required><?php echo sanitize_output($data['descrizione']); ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-secondary" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit">Crea ticket</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
