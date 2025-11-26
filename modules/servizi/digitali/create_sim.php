<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Nuova vendita SIM';

$clients = $pdo->query('SELECT id, nome, cognome, email, telefono FROM clienti ORDER BY cognome, nome')->fetchAll();

$data = [
    'cliente_id' => '',
    'sim_iccid' => '',
    'prodotto' => '',
    'importo' => '',
    'data_vendita' => date('Y-m-d\TH:i'),
    'metodo_pagamento' => 'Contanti',
    'note' => '',
];
$errors = [];
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $data['cliente_id'] = trim($_POST['cliente_id'] ?? '');
    $data['sim_iccid'] = trim($_POST['sim_iccid'] ?? '');
    $data['prodotto'] = trim($_POST['prodotto'] ?? '');
    $data['importo'] = trim($_POST['importo'] ?? '');
    $data['data_vendita'] = trim($_POST['data_vendita'] ?? '');
    $data['metodo_pagamento'] = trim($_POST['metodo_pagamento'] ?? 'Contanti');
    $data['note'] = trim($_POST['note'] ?? '');

    // Validazione
    if ($data['cliente_id'] === '') {
        $errors[] = 'Seleziona un cliente.';
    }
    if ($data['sim_iccid'] === '') {
        $errors[] = 'Inserisci l\'ICCID della SIM.';
    }
    if ($data['prodotto'] === '') {
        $errors[] = 'Inserisci il prodotto.';
    }
    if ($data['importo'] === '' || !is_numeric($data['importo']) || (float) $data['importo'] <= 0) {
        $errors[] = 'Inserisci un importo valido.';
    }
    if ($data['data_vendita'] === '') {
        $errors[] = 'Inserisci la data di vendita.';
    }

    if (!$errors) {
        // Chiama l'API
        $apiData = [
            'cliente_id' => (int) $data['cliente_id'],
            'sim_iccid' => $data['sim_iccid'],
            'prodotto' => $data['prodotto'],
            'importo' => (float) $data['importo'],
            'data_vendita' => $data['data_vendita'],
            'metodo_pagamento' => $data['metodo_pagamento'],
            'note' => $data['note'],
        ];

        $ch = curl_init('http://localhost/api/sim_sale.php'); // Cambia con l'URL corretto
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($apiData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . ($_SESSION['api_token'] ?? ''), // Se necessario
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if ($result['success']) {
                add_flash('success', 'Vendita SIM registrata con successo.');
                header('Location: index.php?created=1');
                exit;
            } else {
                $errors[] = $result['error'] ?? 'Errore durante la registrazione.';
            }
        } else {
            $errors[] = 'Errore di connessione all\'API.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="mb-4">
            <a class="btn btn-outline-primary" href="index.php"><i class="fa-solid fa-arrow-left"></i> Torna alle vendite SIM</a>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h1 class="h4 mb-0">Registra vendita SIM</h1>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-danger"><?php echo implode('<br>', array_map('sanitize_output', $errors)); ?></div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleziona cliente</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo $data['cliente_id'] === (string) $client['id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($client['cognome'] . ' ' . $client['nome'] . ' (' . $client['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="sim_iccid">ICCID SIM</label>
                            <input class="form-control" id="sim_iccid" name="sim_iccid" value="<?php echo sanitize_output($data['sim_iccid']); ?>" placeholder="8901234567890123456" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="prodotto">Prodotto</label>
                            <input class="form-control" id="prodotto" name="prodotto" value="<?php echo sanitize_output($data['prodotto']); ?>" placeholder="SIM Vodafone 50GB" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="importo">Importo (€)</label>
                            <input class="form-control" id="importo" name="importo" type="number" step="0.01" min="0" value="<?php echo sanitize_output($data['importo']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="data_vendita">Data vendita</label>
                            <input class="form-control" id="data_vendita" name="data_vendita" type="datetime-local" value="<?php echo sanitize_output($data['data_vendita']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="metodo_pagamento">Metodo pagamento</label>
                            <select class="form-select" id="metodo_pagamento" name="metodo_pagamento">
                                <option value="Contanti" <?php echo $data['metodo_pagamento'] === 'Contanti' ? 'selected' : ''; ?>>Contanti</option>
                                <option value="Carta di credito" <?php echo $data['metodo_pagamento'] === 'Carta di credito' ? 'selected' : ''; ?>>Carta di credito</option>
                                <option value="Bonifico" <?php echo $data['metodo_pagamento'] === 'Bonifico' ? 'selected' : ''; ?>>Bonifico</option>
                                <option value="PayPal" <?php echo $data['metodo_pagamento'] === 'PayPal' ? 'selected' : ''; ?>>PayPal</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="note">Note</label>
                            <textarea class="form-control" id="note" name="note" rows="3" placeholder="Note aggiuntive"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-secondary" href="index.php">Annulla</a>
                        <button class="btn btn-primary" type="submit">Registra vendita</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>