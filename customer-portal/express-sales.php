<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/express_bootstrap.php';

$customer = $portalExpressContext['portalCustomer'];
$businessCustomer = $portalExpressContext['businessCustomer'];
$expressPortalService = $portalExpressContext['service'];
$sales = $expressPortalService->listSales((int) $businessCustomer['id']);

$pageTitle = 'Attivazioni Express';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="fa-solid fa-file-invoice text-primary me-2"></i>Attivazioni e ordini Express</h1>
                <p class="text-muted-soft mb-0">Storico vendite e pratiche commerciali registrate sul tuo profilo cliente.</p>
            </div>
            <a class="btn btn-outline-primary" href="express-dashboard.php">Torna alla dashboard Express</a>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <?php if (empty($sales)): ?>
                    <div class="dashboard-empty-state dashboard-empty-state-compact">
                        <span class="dashboard-empty-icon"><i class="fa-solid fa-sim-card"></i></span>
                        <h3 class="dashboard-empty-title">Nessuna pratica Express presente</h3>
                        <p class="dashboard-empty-text">Le nuove attivazioni appariranno qui non appena verranno registrate nel gestionale.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                            <tr>
                                <th>Pratica</th>
                                <th>Data</th>
                                <th>Dettaglio</th>
                                <th>Pagamento</th>
                                <th>Stato</th>
                                <th class="text-end">Totale</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sales as $sale): ?>
                                <tr>
                                    <td>
                                        <a class="fw-semibold" href="express-sale.php?id=<?= (int) $sale['id'] ?>">#<?= (int) $sale['id'] ?></a>
                                        <div class="small text-muted-soft"><?= (int) $sale['righe_count'] ?> righe</div>
                                    </td>
                                    <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $sale['data_vendita']))) ?></td>
                                    <td>
                                        <div class="small"><?= htmlspecialchars((string) ($sale['anteprima_righe'] ?: 'Nessun dettaglio disponibile')) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars((string) $sale['metodo_pagamento']) ?></td>
                                    <td><span class="badge rounded-pill bg-light text-secondary"><?= htmlspecialchars((string) $sale['stato']) ?></span></td>
                                    <td class="text-end">€ <?= number_format((float) $sale['totale'] - (float) $sale['sconto'], 2, ',', '.') ?></td>
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
