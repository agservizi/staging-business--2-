<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuovo movimento fedeltà';

$movementTypes = loyalty_movement_types();
$movementDirections = [];
foreach ($movementTypes as $typeKey => $config) {
    $movementDirections[$typeKey] = $config['direction'] ?? 'credit';
}

$clients = $pdo->query('SELECT id, nome, cognome FROM clienti ORDER BY cognome, nome')->fetchAll();
$clientBalances = loyalty_fetch_client_balances($pdo);
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
            $direction = loyalty_movement_direction($data['tipo_movimento']);
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
            $movementId = (int) $pdo->lastInsertId();

            recalculate_loyalty_balances($pdo, $clienteId);

            $pdo->commit();

            try {
                $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                $logStmt->execute([
                    ':user_id' => $_SESSION['user_id'] ?? null,
                    ':modulo' => 'Programma Fedeltà',
                    ':azione' => 'Creazione movimento',
                    ':dettagli' => sprintf('Movimento #%d (%s) cliente #%d, %s pt', $movementId, $data['tipo_movimento'], $clienteId, $points),
                ]);
            } catch (Throwable $logException) {
                error_log('Loyalty movement log failure (create): ' . $logException->getMessage());
            }

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

$initialBalance = 0;
$initialProjectedBalance = 0;
if ((int) $data['cliente_id'] > 0) {
    $initialBalance = $clientBalances[(int) $data['cliente_id']] ?? 0;
    $previewPoints = (int) ($data['punti'] !== '' ? $data['punti'] : 0);
    $previewDirection = loyalty_movement_direction($data['tipo_movimento']);
    $previewPoints = abs($previewPoints);
    if ($previewDirection === 'debit') {
        $previewPoints = -$previewPoints;
    }
    $initialProjectedBalance = $initialBalance + $previewPoints;
}
$balanceVisible = (int) $data['cliente_id'] > 0;

$totalPoints = (int) $pdo->query('SELECT COALESCE(SUM(punti), 0) FROM fedelta_movimenti')->fetchColumn();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Nuovo movimento fedeltà</h1>
                <p class="text-muted mb-0">Registra accumuli o riscatti per mantenere aggiornato il saldo clienti.</p>
            </div>
            <div class="toolbar-actions align-items-end">
                <div class="text-end me-3">
                    <div class="text-muted small text-uppercase">Totale punti attivi</div>
                    <div class="fs-5 fw-semibold mb-0"><?php echo loyalty_format_points($totalPoints); ?> pt</div>
                </div>
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna allo storico</a>
            </div>
        </div>
        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dettagli movimento</h2>
            </div>
            <div class="card-body">
                <?php if ($errors): ?>
                    <div class="alert alert-warning mb-4"><?php echo implode('<br>', array_map('sanitize_output', $errors)); ?></div>
                <?php endif; ?>
                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <label class="form-label" for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" required>
                                <option value="">Seleziona cliente</option>
                                <?php foreach ($clients as $client): ?>
                                    <?php $clientId = (int) $client['id']; ?>
                                    <option value="<?php echo $clientId; ?>" data-balance="<?php echo (int) ($clientBalances[$clientId] ?? 0); ?>" <?php echo ((int) $data['cliente_id'] === $clientId) ? 'selected' : ''; ?>>
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
                            <small class="text-muted">Inserisci valori positivi: i riscatti verranno convertiti in negativo automaticamente.</small>
                        </div>
                        <div class="col-md-3" id="ricompensaGroup" <?php echo in_array($data['tipo_movimento'], ['Riscatto Promozione', 'Riscatto Consulenza'], true) ? '' : 'hidden'; ?>>
                            <label class="form-label" for="ricompensa">Ricompensa</label>
                            <input class="form-control" id="ricompensa" name="ricompensa" value="<?php echo sanitize_output($data['ricompensa']); ?>" placeholder="Es. Sconto 20%" <?php echo in_array($data['tipo_movimento'], ['Riscatto Promozione', 'Riscatto Consulenza'], true) ? 'required' : ''; ?>>
                        </div>
                        <div class="col-12">
                            <div id="balanceSummary" class="alert alert-secondary d-flex align-items-center flex-wrap gap-4" <?php echo $balanceVisible ? '' : 'hidden'; ?>>
                                <div>
                                    <div class="text-muted small text-uppercase">Saldo attuale</div>
                                    <div class="fs-5 fw-semibold mb-0"><span id="balanceCurrent"><?php echo loyalty_format_points($initialBalance); ?></span> pt</div>
                                </div>
                                <div>
                                    <div class="text-muted small text-uppercase">Saldo dopo movimento</div>
                                    <div class="fs-5 fw-semibold mb-0"><span id="balanceProjected"><?php echo loyalty_format_points($initialProjectedBalance); ?></span> pt</div>
                                </div>
                                <div id="balanceWarning" class="text-danger small" <?php echo ($balanceVisible && $initialProjectedBalance < 0) ? '' : 'hidden'; ?>>Il saldo risulterà negativo: verifica i punti disponibili.</div>
                            </div>
                            <label class="form-label" for="note">Note interne</label>
                            <textarea class="form-control" id="note" name="note" rows="4" placeholder="Indicazioni aggiuntive per il team"><?php echo sanitize_output($data['note']); ?></textarea>
                        </div>
                    </div>
                    <div class="stack-sm justify-content-end mt-4">
                        <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-rotate-left me-2"></i>Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Registra movimento</button>
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
        var clientSelect = document.getElementById('cliente_id');
        var pointsInput = document.getElementById('punti');
        var balanceSummary = document.getElementById('balanceSummary');
        var balanceCurrent = document.getElementById('balanceCurrent');
        var balanceProjected = document.getElementById('balanceProjected');
        var balanceWarning = document.getElementById('balanceWarning');
        var movementDirections = <?php echo json_encode($movementDirections, JSON_THROW_ON_ERROR); ?>;

        var toggleReward = function () {
            if (!typeSelect || !rewardGroup) {
                return;
            }
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

        var parsePoints = function (value) {
            if (typeof value !== 'string' || value.trim() === '') {
                return 0;
            }
            var parsed = parseInt(value, 10);
            return isNaN(parsed) ? 0 : parsed;
        };

        var formatPoints = function (value) {
            return new Intl.NumberFormat('it-IT').format(value);
        };

        var updateBalancePreview = function () {
            if (!clientSelect || !balanceSummary || !balanceCurrent || !balanceProjected || !typeSelect) {
                return;
            }

            if (!clientSelect.value) {
                balanceSummary.setAttribute('hidden', 'hidden');
                return;
            }

            var selectedOption = clientSelect.options[clientSelect.selectedIndex];
            var baseBalance = selectedOption ? parseInt(selectedOption.dataset.balance || '0', 10) : 0;
            if (isNaN(baseBalance)) {
                baseBalance = 0;
            }

            var rawPoints = parsePoints(pointsInput ? pointsInput.value : '0');
            var direction = movementDirections[typeSelect.value] || 'credit';
            var signedPoints = Math.abs(rawPoints);
            if (direction === 'debit') {
                signedPoints = -signedPoints;
            }

            var projected = baseBalance + signedPoints;

            balanceCurrent.textContent = formatPoints(baseBalance);
            balanceProjected.textContent = formatPoints(projected);
            balanceSummary.removeAttribute('hidden');

            if (balanceWarning) {
                if (direction === 'debit' && projected < 0) {
                    balanceWarning.removeAttribute('hidden');
                } else {
                    balanceWarning.setAttribute('hidden', 'hidden');
                }
            }
        };

        if (clientSelect) {
            clientSelect.addEventListener('change', updateBalancePreview);
        }
        if (pointsInput) {
            pointsInput.addEventListener('input', updateBalancePreview);
        }
        if (typeSelect) {
            typeSelect.addEventListener('change', function () {
                toggleReward();
                updateBalancePreview();
            });
        }

        toggleReward();
        updateBalancePreview();
    });
</script>
