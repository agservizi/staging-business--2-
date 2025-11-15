<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Programma Fedeltà';

$movementsStmt = $pdo->query("SELECT fm.id,
                                      fm.cliente_id,
                                      fm.tipo_movimento,
                                      fm.descrizione,
                                      fm.punti,
                                      fm.saldo_post_movimento,
                                      fm.ricompensa,
                                      fm.operatore,
                                      fm.data_movimento,
                                      c.nome,
                                      c.cognome
                               FROM fedelta_movimenti fm
                               LEFT JOIN clienti c ON fm.cliente_id = c.id
                               ORDER BY fm.data_movimento DESC, fm.id DESC");
$movements = $movementsStmt->fetchAll();

$statsStmt = $pdo->query("SELECT
    COALESCE(SUM(punti), 0) AS totale,
    COALESCE(SUM(CASE WHEN punti > 0 THEN punti ELSE 0 END), 0) AS accumulati,
    COALESCE(ABS(SUM(CASE WHEN punti < 0 THEN punti ELSE 0 END)), 0) AS riscattati
FROM fedelta_movimenti");
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: ['totale' => 0, 'accumulati' => 0, 'riscattati' => 0];

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Programma Fedeltà</h1>
                <p class="text-muted mb-0">Monitoraggio dei movimenti punti tra accumulo e riscatti.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo movimento</a>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Punti attivi</div>
                        <div class="fs-3 fw-semibold"><?php echo number_format((int) $stats['totale'], 0, ',', '.'); ?> pt</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Punti accumulati</div>
                        <div class="fs-3 fw-semibold text-success">+<?php echo number_format((int) $stats['accumulati'], 0, ',', '.'); ?> pt</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Punti riscattati</div>
                        <div class="fs-3 fw-semibold text-danger">-<?php echo number_format((int) $stats['riscattati'], 0, ',', '.'); ?> pt</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover" data-datatable="true">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Tipologia</th>
                                <th>Descrizione</th>
                                <th class="text-end">Punti</th>
                                <th class="text-end">Saldo</th>
                                <th>Ricompensa</th>
                                <th>Operatore</th>
                                <th>Data</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td>#<?php echo (int) $movement['id']; ?></td>
                                    <td><?php echo sanitize_output(trim(($movement['cognome'] ?? '') . ' ' . ($movement['nome'] ?? '')) ?: 'N/D'); ?></td>
                                    <td><?php echo sanitize_output($movement['tipo_movimento']); ?></td>
                                    <td><?php echo sanitize_output($movement['descrizione']); ?></td>
                                    <td class="text-end fw-semibold <?php echo ((int) $movement['punti']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo ((int) $movement['punti']) >= 0 ? '+' : ''; ?><?php echo number_format((int) $movement['punti'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="text-end"><?php echo number_format((int) ($movement['saldo_post_movimento'] ?? 0), 0, ',', '.'); ?></td>
                                    <td>
                                        <?php if ($movement['ricompensa']): ?>
                                            <?php echo sanitize_output($movement['ricompensa']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize_output($movement['operatore'] ?: 'Sistema'); ?></td>
                                    <td><?php echo sanitize_output(format_datetime_locale($movement['data_movimento'])); ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $movement['id']; ?>" title="Dettagli">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $movement['id']; ?>" title="Modifica">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <form method="post" action="delete.php" onsubmit="return confirm('Confermi eliminazione del movimento?');">
                                                <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $movement['id']; ?>">
                                                <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
