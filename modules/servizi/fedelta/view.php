<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Dettaglio movimento fedeltà';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$movementStmt = $pdo->prepare('SELECT fm.*, c.nome, c.cognome, c.email
    FROM fedelta_movimenti fm
    LEFT JOIN clienti c ON fm.cliente_id = c.id
    WHERE fm.id = :id');
$movementStmt->execute([':id' => $id]);
$movement = $movementStmt->fetch();

if (!$movement) {
    header('Location: index.php?notfound=1');
    exit;
}

$balanceTotalStmt = $pdo->prepare('SELECT COALESCE(SUM(punti), 0) FROM fedelta_movimenti WHERE cliente_id = :cliente_id');
$balanceTotalStmt->execute([':cliente_id' => (int) $movement['cliente_id']]);
$currentBalance = (int) $balanceTotalStmt->fetchColumn();

$currentPostBalance = (int) ($movement['saldo_post_movimento'] ?? 0);
$balanceBefore = $currentPostBalance - (int) $movement['punti'];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Movimento #<?php echo (int) $movement['id']; ?></h1>
                <p class="text-muted mb-0">Dettaglio completo del movimento e del saldo cliente.</p>
            </div>
            <div class="toolbar-actions align-items-end">
                <div class="text-end me-3">
                    <div class="text-muted small text-uppercase">Saldo cliente</div>
                    <div class="fs-5 fw-semibold mb-0"><?php echo loyalty_format_points($currentBalance); ?> pt</div>
                </div>
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna allo storico</a>
                <a class="btn btn-warning text-dark" href="edit.php?id=<?php echo $id; ?>"><i class="fa-solid fa-pen me-2"></i>Modifica</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Dati movimento</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">ID movimento</dt>
                            <dd class="col-sm-7">#<?php echo (int) $movement['id']; ?></dd>
                            <dt class="col-sm-5">Tipologia</dt>
                            <dd class="col-sm-7">
                                <span class="badge ag-badge text-uppercase <?php echo ((int) $movement['punti']) >= 0 ? 'bg-success text-dark' : 'bg-danger'; ?>"><?php echo sanitize_output($movement['tipo_movimento']); ?></span>
                            </dd>
                            <dt class="col-sm-5">Descrizione</dt>
                            <dd class="col-sm-7"><?php echo nl2br(sanitize_output($movement['descrizione'])); ?></dd>
                            <dt class="col-sm-5">Punti</dt>
                            <dd class="col-sm-7 fw-semibold <?php echo ((int) $movement['punti']) >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ((int) $movement['punti']) >= 0 ? '+' : ''; ?><?php echo loyalty_format_points((int) $movement['punti']); ?> pt
                            </dd>
                            <dt class="col-sm-5">Saldo dopo il movimento</dt>
                            <dd class="col-sm-7"><?php echo loyalty_format_points($currentPostBalance); ?> pt</dd>
                            <dt class="col-sm-5">Saldo precedente</dt>
                            <dd class="col-sm-7"><?php echo loyalty_format_points($balanceBefore); ?> pt</dd>
                            <dt class="col-sm-5">Operatore</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output($movement['operatore'] ?: 'Sistema'); ?></dd>
                            <dt class="col-sm-5">Ricompensa</dt>
                            <dd class="col-sm-7">
                                <?php if ($movement['ricompensa']): ?>
                                    <?php echo sanitize_output($movement['ricompensa']); ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-5">Data movimento</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output(format_datetime_locale($movement['data_movimento'])); ?></dd>
                            <dt class="col-sm-5">Creato il</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output(format_datetime_locale($movement['created_at'])); ?></dd>
                            <dt class="col-sm-5">Aggiornato il</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output(format_datetime_locale($movement['updated_at'])); ?></dd>
                            <dt class="col-sm-5">Note interne</dt>
                            <dd class="col-sm-7"><?php echo nl2br(sanitize_output($movement['note'] ?: 'Nessuna nota.')); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Cliente</h5>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Nome</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output(trim(($movement['cognome'] ?? '') . ' ' . ($movement['nome'] ?? '')) ?: 'N/D'); ?></dd>
                            <dt class="col-sm-5">Email</dt>
                            <dd class="col-sm-7">
                                <?php if (!empty($movement['email'])): ?>
                                    <a class="link-warning" href="mailto:<?php echo sanitize_output($movement['email']); ?>"><?php echo sanitize_output($movement['email']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">Non disponibile</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-5">Saldo attuale</dt>
                            <dd class="col-sm-7"><?php echo loyalty_format_points($currentBalance); ?> pt</dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
