<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuovo movimento fedeltà';

$movementTypes = [
    'Acquisto Servizio' => ['label' => 'Acquisto servizio', 'direction' => 'credit'],
    'Referral' => ['label' => 'Referral', 'direction' => 'credit'],
    'Rinnovo' => ['label' => 'Rinnovo', 'direction' => 'credit'],
    'Riscatto Promozione' => ['label' => 'Riscatto promozione', 'direction' => 'debit'],
    'Riscatto Consulenza' => ['label' => 'Riscatto consulenza', 'direction' => 'debit'],
];

$clients = $pdo->query('SELECT id, nome, cognome FROM clienti ORDER BY cognome, nome')->fetchAll();
$defaultType = array_key_first($movementTypes);
$now = new DateTimeImmutable('now');

$data = [
    'cliente_id' => '',
    'tipo_movimento' => $defaultType,
    'descrizione' => '',
    'punti' => '',
    'ricompensa' => '',
    'note' => '',
    'data_movimento' => $now->format('Y-m-d\TH:i'),
];
$errors = [];
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $data['cliente_id'] = trim($_POST['cliente_id'] ?? '');
    $data['tipo_movimento'] = trim($_POST['tipo_movimento'] ?? $defaultType);
    $data['descrizione'] = trim($_POST['descrizione'] ?? '');
    $data['punti'] = trim($_POST['punti'] ?? '');
    $data['ricompensa'] = trim($_POST['ricompensa'] ?? '');
    $data['note'] = trim($_POST['note'] ?? '');
    $data['data_movimento'] = trim($_POST['data_movimento'] ?? $data['data_movimento']);

    $clienteId = (int) $data['cliente_id'];
    if ($clienteId <= 0) {
        $errors[] = 'Seleziona un cliente valido.';
    }

    if (!isset($movementTypes[$data['tipo_movimento']])) {
        $errors[] = 'Seleziona una tipologia di movimento valida.';
        $data['tipo_movimento'] = $defaultType;
    }

    if ($data['descrizione'] === '') {
        $errors[] = 'Inserisci una descrizione del movimento.';
    }

    $rawPoints = $data['punti'];
    $points = filter_var($rawPoints, FILTER_VALIDATE_INT, ['options' => ['min_range' => -2147483648, 'max_range' => 2147483647]]);
    if ($rawPoints === '' || $points === false) {
        $errors[] = 'Inserisci un valore di punti valido.';
    } else {
        $points = (int) $points;
        if ($points === 0) {
            $errors[] = 'I punti non possono essere pari a zero.';
        } else {
            $direction = $movementTypes[$data['tipo_movimento']]['direction'] ?? 'credit';
            if ($direction === 'credit') {
                $points = abs($points);
                $data['ricompensa'] = '';
            } else {
                $points = -abs($points);
                if ($data['ricompensa'] === '') {
                    $errors[] = 'Specifica la ricompensa riscattata.';
                }
            }
        }
    }

    $movementDate = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $data['data_movimento']) ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $data['data_movimento']);
    if (!$movementDate) {
        $errors[] = 'Data movimento non valida.';
    }

    if (!$errors && isset($movementDate)) {
        try {
            $pdo->beginTransaction();

            $balanceStmt = $pdo->prepare('SELECT COALESCE(SUM(punti), 0) FROM fedelta_movimenti WHERE cliente_id = :cliente_id');
            $balanceStmt->execute([':cliente_id' => $clienteId]);
            $previousBalance = (int) $balanceStmt->fetchColumn();
            $newBalance = $previousBalance + $points;

            $insertStmt = $pdo->prepare('INSERT INTO fedelta_movimenti (cliente_id, tipo_movimento, descrizione, punti, saldo_post_movimento, ricompensa, operatore, note, data_movimento, created_at, updated_at) VALUES (:cliente_id, :tipo_movimento, :descrizione, :punti, :saldo_post_movimento, :ricompensa, :operatore, :note, :data_movimento, NOW(), NOW())');
            $insertStmt->execute([
                ':cliente_id' => $clienteId,
                ':tipo_movimento' => $data['tipo_movimento'],
                ':descrizione' => $data['descrizione'],
                ':punti' => $points,
                ':saldo_post_movimento' => $newBalance,
                ':ricompensa' => $data['ricompensa'] !== '' ? $data['ricompensa'] : null,
                ':operatore' => current_operator_label(),
                ':note' => $data['note'] !== '' ? $data['note'] : null,
                ':data_movimento' => $movementDate->format('Y-m-d H:i:s'),
            ]);

            recalculate_loyalty_balances($pdo, $clienteId);

            $pdo->commit();

            add_flash('success', 'Movimento fedeltà registrato correttamente.');
            header('Location: index.php?created=1');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Loyalty movement create failed: ' . $exception->getMessage());
            $errors[] = 'Errore durante il salvataggio del movimento fedeltà. Riprova.';
        }
    }
}

$totalPoints = (int) $pdo->query('SELECT COALESCE(SUM(punti), 0) FROM fedelta_movimenti')->fetchColumn();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Programma fedeltà</a>
            <div class="text-end">
                <div class="text-muted small text-uppercase">Totale punti attivi</div>
                <div class="fs-4 fw-semibold"><?php echo number_format($totalPoints, 0, ',', '.'); ?> pt</div>
            </div>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h1 class="h4 mb-0">Registra nuovo movimento</h1>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning"><?php echo implode('<br>', array_map('sanitize_output', $errors)); ?></div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleziona cliente</option>
                                <?php foreach ($clients as $client): ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo ((int) $data['cliente_id'] === (int) $client['id']) ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($client['cognome'] . ' ' . $client['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="data_movimento">Data movimento</label>
                            <input class="form-control" id="data_movimento" name="data_movimento" type="datetime-local" value="<?php echo sanitize_output($data['data_movimento']); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="tipo_movimento">Tipologia</label>
                            <select class="form-select" id="tipo_movimento" name="tipo_movimento">
                                <?php foreach ($movementTypes as $typeKey => $config): ?>
                                    <option value="<?php echo sanitize_output($typeKey); ?>" <?php echo $data['tipo_movimento'] === $typeKey ? 'selected' : ''; ?>><?php echo sanitize_output($config['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="descrizione">Descrizione</label>
                            <input class="form-control" id="descrizione" name="descrizione" value="<?php echo sanitize_output($data['descrizione']); ?>" placeholder="Es. Attivazione servizio fibra" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="punti">Punti</label>
                            <input class="form-control" id="punti" name="punti" type="number" step="1" value="<?php echo sanitize_output($data['punti']); ?>" required>
                            <small class="text-muted">Usa valori positivi: verranno sottratti automaticamente per i riscatti.</small>
                        </div>
                        <div class="col-md-3" id="ricompensaGroup" <?php echo in_array($data['tipo_movimento'], ['Riscatto Promozione', 'Riscatto Consulenza'], true) ? '' : 'hidden'; ?>>
                            <label class="form-label" for="ricompensa">Ricompensa</label>
                            <input class="form-control" id="ricompensa" name="ricompensa" value="<?php echo sanitize_output($data['ricompensa']); ?>" placeholder="Es. Sconto 20%" <?php echo in_array($data['tipo_movimento'], ['Riscatto Promozione', 'Riscatto Consulenza'], true) ? 'required' : ''; ?>>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="note">Note interne</label>
                            <textarea class="form-control" id="note" name="note" rows="4" placeholder="Indicazioni aggiuntive per il team"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-secondary" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit">Registra movimento</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var typeSelect = document.getElementById('tipo_movimento');
        var rewardGroup = document.getElementById('ricompensaGroup');
        var rewardInput = document.getElementById('ricompensa');

        if (!typeSelect || !rewardGroup) {
            return;
        }

        var toggleReward = function () {
            var value = typeSelect.value;
            var isRedemption = value === 'Riscatto Promozione' || value === 'Riscatto Consulenza';
            if (isRedemption) {
                rewardGroup.removeAttribute('hidden');
                if (rewardInput) {
                    rewardInput.required = true;
                }
            } else {
                rewardGroup.setAttribute('hidden', 'hidden');
                if (rewardInput) {
                    rewardInput.required = false;
                    rewardInput.value = '';
                }
            }
        };

        typeSelect.addEventListener('change', toggleReward);
        toggleReward();
    });
</script>
