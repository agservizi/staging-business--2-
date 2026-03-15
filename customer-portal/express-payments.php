<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/express_bootstrap.php';

$customer = $portalExpressContext['portalCustomer'];
$businessCustomer = $portalExpressContext['businessCustomer'];
$expressPortalService = $portalExpressContext['service'];
$payments = $expressPortalService->listPayments((int) $businessCustomer['id']);

$pageTitle = 'Pagamenti Express';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="fa-solid fa-wallet text-primary me-2"></i>Pagamenti Express</h1>
                <p class="text-muted-soft mb-0">Elenco movimenti economici collegati alle tue vendite e attivazioni Express.</p>
            </div>
            <a class="btn btn-outline-primary" href="express-dashboard.php">Torna alla dashboard Express</a>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <div class="dashboard-empty-state dashboard-empty-state-compact">
                        <span class="dashboard-empty-icon"><i class="fa-solid fa-credit-card"></i></span>
                        <h3 class="dashboard-empty-title">Nessun pagamento registrato</h3>
                        <p class="dashboard-empty-text">Quando una pratica Express sarà contabilizzata, il movimento apparirà qui.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Movimento</th>
                                <th>Data pagamento</th>
                                <th>Metodo</th>
                                <th>Stato</th>
                                <th>Pratica</th>
                                <th class="text-end">Importo</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= htmlspecialchars((string) $payment['descrizione']) ?></div>
                                        <?php if (!empty($payment['note'])): ?>
                                            <div class="small text-muted-soft"><?= htmlspecialchars((string) $payment['note']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($payment['data_pagamento'] ? date('d/m/Y', strtotime((string) $payment['data_pagamento'])) : 'Da confermare') ?></td>
                                    <td><?= htmlspecialchars((string) $payment['metodo']) ?></td>
                                    <td><span class="badge rounded-pill bg-light text-secondary"><?= htmlspecialchars((string) $payment['stato']) ?></span></td>
                                    <td>
                                        <?php if (!empty($payment['vendita_id'])): ?>
                                            <a href="express-sale.php?id=<?= (int) $payment['vendita_id'] ?>">#<?= (int) $payment['vendita_id'] ?></a>
                                        <?php else: ?>
                                            <span class="text-muted-soft">N/D</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">€ <?= number_format((float) $payment['importo'], 2, ',', '.') ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
