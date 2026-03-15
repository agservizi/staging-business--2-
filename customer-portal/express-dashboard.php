<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/express_bootstrap.php';

$customer = $portalExpressContext['portalCustomer'];
$businessCustomer = $portalExpressContext['businessCustomer'];
$expressPortalService = $portalExpressContext['service'];
$stats = $expressPortalService->getDashboardData((int) $businessCustomer['id']);

$pageTitle = 'Area Express';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="card ag-card dashboard-card portal-hero text-white mb-4">
            <div class="card-body">
                <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                        <span class="dashboard-chip">Express telefonia</span>
                        <h1 class="dashboard-title">Area Express</h1>
                        <p class="dashboard-subtitle mb-0">
                            Consulta attivazioni, pagamenti e richieste collegate a
                            <?= htmlspecialchars($businessCustomer['ragione_sociale'] ?: trim(($businessCustomer['nome'] ?? '') . ' ' . ($businessCustomer['cognome'] ?? ''))) ?>.
                        </p>
                    </div>
                    <div class="col-lg-4">
                        <div class="dashboard-stat-grid">
                            <div class="dashboard-stat-card">
                                <span class="dashboard-stat-label">Attivazioni</span>
                                <span class="dashboard-stat-value"><?= number_format((int) $stats['sales_count'], 0, ',', '.') ?></span>
                                <span class="dashboard-stat-hint">Storico completo</span>
                            </div>
                            <div class="dashboard-stat-card">
                                <span class="dashboard-stat-label">Richieste aperte</span>
                                <span class="dashboard-stat-value"><?= number_format((int) $stats['requests_open'], 0, ',', '.') ?></span>
                                <span class="dashboard-stat-hint">In lavorazione</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
                <article class="dashboard-metric h-100">
                    <div class="dashboard-metric-icon bg-danger-subtle text-danger"><i class="fa-solid fa-sim-card"></i></div>
                    <div class="dashboard-metric-body">
                        <span class="dashboard-metric-label">Vendite completate</span>
                        <span class="dashboard-metric-value"><?= number_format((int) $stats['sales_count'], 0, ',', '.') ?></span>
                        <p class="dashboard-metric-text">SIM, offerte, dispositivi e servizi registrati sul tuo profilo.</p>
                    </div>
                </article>
            </div>
            <div class="col-md-6 col-xl-3">
                <article class="dashboard-metric h-100">
                    <div class="dashboard-metric-icon bg-success-subtle text-success"><i class="fa-solid fa-euro-sign"></i></div>
                    <div class="dashboard-metric-body">
                        <span class="dashboard-metric-label">Totale fatturato</span>
                        <span class="dashboard-metric-value">€ <?= number_format((float) $stats['revenue'], 2, ',', '.') ?></span>
                        <p class="dashboard-metric-text">Valore cumulato delle operazioni Express completate.</p>
                    </div>
                </article>
            </div>
            <div class="col-md-6 col-xl-3">
                <article class="dashboard-metric h-100">
                    <div class="dashboard-metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-wallet"></i></div>
                    <div class="dashboard-metric-body">
                        <span class="dashboard-metric-label">Pagamenti registrati</span>
                        <span class="dashboard-metric-value"><?= number_format((int) $stats['payments_count'], 0, ',', '.') ?></span>
                        <p class="dashboard-metric-text">Movimenti contabili agganciati alle vendite Express.</p>
                    </div>
                </article>
            </div>
            <div class="col-md-6 col-xl-3">
                <article class="dashboard-metric h-100">
                    <div class="dashboard-metric-icon bg-info-subtle text-info"><i class="fa-solid fa-headset"></i></div>
                    <div class="dashboard-metric-body">
                        <span class="dashboard-metric-label">Richieste aperte</span>
                        <span class="dashboard-metric-value"><?= number_format((int) $stats['requests_open'], 0, ',', '.') ?></span>
                        <p class="dashboard-metric-text">Ticket, prenotazioni e richieste ancora attive.</p>
                    </div>
                </article>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h5 mb-1">Ultime attivazioni</h2>
                            <p class="text-muted-soft mb-0">Le pratiche Express più recenti associate al tuo profilo.</p>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="express-sales.php">Vedi tutto</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats['recent_sales'])): ?>
                            <div class="dashboard-empty-state dashboard-empty-state-compact">
                                <span class="dashboard-empty-icon"><i class="fa-solid fa-file-invoice"></i></span>
                                <h3 class="dashboard-empty-title">Nessuna attivazione disponibile</h3>
                                <p class="dashboard-empty-text">Quando verranno registrate nuove pratiche Express, le troverai qui.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Data</th>
                                        <th>Pagamento</th>
                                        <th class="text-end">Totale</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($stats['recent_sales'] as $sale): ?>
                                        <tr>
                                            <td><a href="express-sale.php?id=<?= (int) $sale['id'] ?>">#<?= (int) $sale['id'] ?></a></td>
                                            <td><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $sale['data_vendita']))) ?></td>
                                            <td><?= htmlspecialchars((string) $sale['metodo_pagamento']) ?></td>
                                            <td class="text-end">€ <?= number_format((float) $sale['totale'] - (float) $sale['sconto'], 2, ',', '.') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div class="col-xl-5">
                <section class="card border-0 shadow-sm h-100">
                    <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h5 mb-1">Richieste recenti</h2>
                            <p class="text-muted-soft mb-0">Le ultime attività aperte con il team.</p>
                        </div>
                        <a class="btn btn-sm btn-outline-primary" href="express-support.php">Apri richieste</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($stats['recent_requests'])): ?>
                            <div class="dashboard-empty-state dashboard-empty-state-compact">
                                <span class="dashboard-empty-icon"><i class="fa-solid fa-headset"></i></span>
                                <h3 class="dashboard-empty-title">Nessuna richiesta recente</h3>
                                <p class="dashboard-empty-text">Puoi inviare nuove richieste direttamente dall'area Express.</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($stats['recent_requests'] as $request): ?>
                                    <div class="list-group-item px-0">
                                        <div class="d-flex justify-content-between align-items-start gap-3">
                                            <div>
                                                <div class="fw-semibold"><?= htmlspecialchars((string) $request['titolo']) ?></div>
                                                <div class="small text-muted-soft"><?= htmlspecialchars((string) $request['tipo_richiesta']) ?> · <?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $request['created_at']))) ?></div>
                                            </div>
                                            <span class="badge rounded-pill bg-light text-secondary"><?= htmlspecialchars((string) $request['stato']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
