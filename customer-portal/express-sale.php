<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/express_bootstrap.php';

$customer = $portalExpressContext['portalCustomer'];
$businessCustomer = $portalExpressContext['businessCustomer'];
$expressPortalService = $portalExpressContext['service'];
$saleId = (int) ($_GET['id'] ?? 0);
$sale = $saleId > 0 ? $expressPortalService->getSaleDetail((int) $businessCustomer['id'], $saleId) : null;

if ($sale === null) {
    http_response_code(404);
}

$pageTitle = 'Dettaglio pratica Express';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex justify-content-between align-items-start flex-column flex-lg-row gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1"><i class="fa-solid fa-receipt text-primary me-2"></i>Dettaglio pratica Express</h1>
                <p class="text-muted-soft mb-0">Scheda completa della vendita o attivazione registrata nel gestionale.</p>
            </div>
            <a class="btn btn-outline-primary" href="express-sales.php">Torna all'elenco</a>
        </div>

        <?php if ($sale === null): ?>
            <div class="alert alert-danger">Pratica non trovata o non associata al tuo profilo cliente.</div>
        <?php else: ?>
            <div class="row g-4 mb-4">
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h5 mb-3">Riepilogo</h2>
                            <div class="small text-muted-soft mb-2">Pratica</div>
                            <div class="fw-semibold mb-3">#<?= (int) $sale['id'] ?></div>
                            <div class="small text-muted-soft mb-2">Data registrazione</div>
                            <div class="mb-3"><?= htmlspecialchars(date('d/m/Y H:i', strtotime((string) $sale['data_vendita']))) ?></div>
                            <div class="small text-muted-soft mb-2">Metodo pagamento</div>
                            <div class="mb-3"><?= htmlspecialchars((string) $sale['metodo_pagamento']) ?></div>
                            <div class="small text-muted-soft mb-2">Stato</div>
                            <div class="mb-3"><span class="badge rounded-pill bg-light text-secondary"><?= htmlspecialchars((string) $sale['stato']) ?></span></div>
                            <div class="small text-muted-soft mb-2">Totale netto</div>
                            <div class="fs-4 fw-bold">€ <?= number_format((float) $sale['totale'] - (float) $sale['sconto'], 2, ',', '.') ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h5 mb-3">Voci pratica</h2>
                            <?php if (empty($sale['items'])): ?>
                                <p class="text-muted-soft mb-0">Nessuna riga disponibile.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                        <tr>
                                            <th>Descrizione</th>
                                            <th>Tipo</th>
                                            <th>Operatore</th>
                                            <th>Dettaglio</th>
                                            <th class="text-end">Totale</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($sale['items'] as $item): ?>
                                            <?php
                                            $detailParts = [];
                                            if (!empty($item['iccid'])) {
                                                $detailParts[] = 'ICCID ' . $item['iccid'];
                                            }
                                            if (!empty($item['prodotto_nome'])) {
                                                $detailParts[] = $item['prodotto_nome'];
                                            }
                                            if (!empty($item['offerta_titolo'])) {
                                                $detailParts[] = $item['offerta_titolo'];
                                            }
                                            ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) $item['descrizione']) ?></td>
                                                <td><?= htmlspecialchars((string) $item['tipo']) ?></td>
                                                <td><?= htmlspecialchars((string) ($item['operatore'] ?: 'N/D')) ?></td>
                                                <td><?= htmlspecialchars($detailParts ? implode(' · ', $detailParts) : 'N/D') ?></td>
                                                <td class="text-end">€ <?= number_format((float) $item['totale_riga'] - (float) $item['sconto_riga'], 2, ',', '.') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($sale['note'])): ?>
                                <hr>
                                <h3 class="h6">Note</h3>
                                <p class="mb-0"><?= nl2br(htmlspecialchars((string) $sale['note'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
