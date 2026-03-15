<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/express_bootstrap.php';

$customer = $portalExpressContext['portalCustomer'];
$businessCustomer = $portalExpressContext['businessCustomer'];
$expressPortalService = $portalExpressContext['service'];

$alerts = [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Ricarica la pagina e riprova.';
    } else {
        $result = $expressPortalService->createPortalRequest((int) $businessCustomer['id'], (array) $customer, $_POST);
        if (!empty($result['success'])) {
            $alerts[] = (string) $result['message'];
        } else {
            $errors[] = (string) ($result['message'] ?? 'Impossibile inviare la richiesta.');
        }
    }
}

$requests = $expressPortalService->listRequests((int) $businessCustomer['id']);
$products = $expressPortalService->productOptions();

$pageTitle = 'Richieste Express';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="row g-4">
            <div class="col-xl-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h1 class="h4 mb-1"><i class="fa-solid fa-headset text-primary me-2"></i>Nuova richiesta Express</h1>
                        <p class="text-muted-soft mb-0">Invia assistenza, prenotazioni o richieste commerciali direttamente al team.</p>
                    </div>
                    <div class="card-body">
                        <?php foreach ($errors as $message): ?>
                            <div class="alert alert-danger"><?= htmlspecialchars($message) ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($alerts as $message): ?>
                            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
                        <?php endforeach; ?>

                        <form method="POST" action="express-support.php" class="row g-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                            <div class="col-12">
                                <label class="form-label" for="titolo">Oggetto</label>
                                <input class="form-control" type="text" id="titolo" name="titolo" maxlength="180" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="tipo_richiesta">Tipologia</label>
                                <select class="form-select" id="tipo_richiesta" name="tipo_richiesta">
                                    <option value="Support">Supporto</option>
                                    <option value="Purchase">Acquisto</option>
                                    <option value="Reservation">Prenotazione</option>
                                    <option value="Deposit">Acconto</option>
                                    <option value="Installment">Rateizzazione</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="product_id">Prodotto collegato</label>
                                <select class="form-select" id="product_id" name="product_id">
                                    <option value="0">Nessuno</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= (int) $product['id'] ?>"><?= htmlspecialchars((string) $product['nome']) ?><?php if (!empty($product['categoria'])): ?> · <?= htmlspecialchars((string) $product['categoria']) ?><?php endif; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="data_desiderata">Data desiderata</label>
                                <input class="form-control" type="date" id="data_desiderata" name="data_desiderata">
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="note_cliente">Dettagli</label>
                                <textarea class="form-control" id="note_cliente" name="note_cliente" rows="5" placeholder="Descrivi il motivo della richiesta, il servizio atteso o le informazioni utili per il team."></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia richiesta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-xl-7">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent border-0 pb-0">
                        <h2 class="h5 mb-1">Storico richieste</h2>
                        <p class="text-muted-soft mb-0">Monitoraggio delle richieste aperte, confermate o concluse.</p>
                    </div>
                    <div class="card-body">
                        <?php if (empty($requests)): ?>
                            <div class="dashboard-empty-state dashboard-empty-state-compact">
                                <span class="dashboard-empty-icon"><i class="fa-solid fa-message"></i></span>
                                <h3 class="dashboard-empty-title">Nessuna richiesta presente</h3>
                                <p class="dashboard-empty-text">Usa il modulo qui a sinistra per aprire la prima richiesta Express.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($requests as $request): ?>
                                    <div class="list-group-item px-0 py-3">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars((string) $request['titolo']) ?></div>
                                                <div class="small text-muted-soft mb-2"><?= htmlspecialchars((string) $request['tipo_richiesta']) ?> · <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $request['created_at']))) ?></div>
                                                <?php if (!empty($request['prodotto_nome'])): ?>
                                                    <div class="small mb-1"><span class="badge bg-light text-secondary">Prodotto: <?= htmlspecialchars((string) $request['prodotto_nome']) ?></span></div>
                                                <?php endif; ?>
                                                <?php if (!empty($request['note_cliente'])): ?>
                                                    <div class="small"><?= nl2br(htmlspecialchars((string) $request['note_cliente'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="badge rounded-pill bg-light text-secondary"><?= htmlspecialchars((string) $request['stato']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>