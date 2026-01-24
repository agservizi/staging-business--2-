<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Dashboard Visure CR';

$stati = ['Bozza', 'Inviata', 'In lavorazione', 'Completata', 'Rifiutata'];
$tipi = ['persona_fisica' => 'Persona fisica', 'persona_giuridica' => 'Persona giuridica'];

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM servizi_visure_cr_pratiche')->fetchColumn();
$inLavorazione = (int) $pdo->query("SELECT COUNT(*) FROM servizi_visure_cr_pratiche WHERE stato = 'In lavorazione'")->fetchColumn();
$inviate = (int) $pdo->query("SELECT COUNT(*) FROM servizi_visure_cr_pratiche WHERE stato = 'Inviata'")->fetchColumn();
$completate = (int) $pdo->query("SELECT COUNT(*) FROM servizi_visure_cr_pratiche WHERE stato = 'Completata'")->fetchColumn();

$byStatus = $pdo->query('SELECT stato, COUNT(*) AS totale FROM servizi_visure_cr_pratiche GROUP BY stato ORDER BY totale DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byType = $pdo->query('SELECT tipo_visura, COUNT(*) AS totale FROM servizi_visure_cr_pratiche GROUP BY tipo_visura ORDER BY totale DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recent = $pdo->query('SELECT id, tipo_visura, stato, nome, cognome, ragione_sociale, updated_at
    FROM servizi_visure_cr_pratiche
    ORDER BY updated_at DESC, id DESC
    LIMIT 8')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$puoCreare = current_user_can('Admin', 'Operatore', 'Manager');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Dashboard Visure CR</h1>
                <p class="text-muted mb-0">Panoramica richieste Centrale Rischi.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-table-list me-2"></i>Elenco richieste</a>
                <?php if ($puoCreare): ?>
                    <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuova richiesta</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-3">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Totale richieste</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($totalCount); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Inviate</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($inviate); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">In lavorazione</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($inLavorazione); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Completate</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($completate); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Richieste per stato</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($byStatus): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($byStatus as $row): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent text-white">
                                        <span><?php echo sanitize_output($row['stato']); ?></span>
                                        <span class="badge bg-secondary"><?php echo number_format((int) $row['totale']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun dato disponibile.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Richieste per tipologia</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($byType): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($byType as $row): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent text-white">
                                        <span><?php echo sanitize_output($tipi[$row['tipo_visura']] ?? $row['tipo_visura']); ?></span>
                                        <span class="badge bg-secondary"><?php echo number_format((int) $row['totale']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun dato disponibile.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Ultime richieste aggiornate</h2>
                <a class="btn btn-sm btn-outline-warning" href="index.php">Vedi elenco</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tipologia</th>
                                <th>Richiedente</th>
                                <th>Stato</th>
                                <th>Aggiornata</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recent): ?>
                                <?php foreach ($recent as $row): ?>
                                    <?php
                                        $displayName = $row['tipo_visura'] === 'persona_giuridica'
                                            ? (string) ($row['ragione_sociale'] ?? '')
                                            : trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''));
                                    ?>
                                    <tr>
                                        <td>#<?php echo (int) $row['id']; ?></td>
                                        <td><?php echo sanitize_output($tipi[$row['tipo_visura']] ?? $row['tipo_visura']); ?></td>
                                        <td><?php echo sanitize_output($displayName ?: '—'); ?></td>
                                        <td><span class="badge bg-secondary"><?php echo sanitize_output($row['stato']); ?></span></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($row['updated_at'] ?? null)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Nessuna richiesta recente.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
