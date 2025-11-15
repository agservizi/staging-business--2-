<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Ticket di assistenza';

$sql = "SELECT t.id, t.titolo, t.stato, t.created_at, c.nome, c.cognome
    FROM ticket t
    LEFT JOIN clienti c ON t.cliente_id = c.id
    ORDER BY t.created_at DESC";
$stmt = $pdo->query($sql);
$tickets = $stmt->fetchAll();

$statuses = ['Aperto', 'In corso', 'Chiuso'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Ticket e comunicazioni</h1>
                <p class="text-muted mb-0">Gestisci richieste di assistenza interne ed esterne.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket</a>
            </div>
        </div>
        <div class="card ag-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover" data-datatable="true">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Titolo</th>
                                <th>Cliente</th>
                                <th>Stato</th>
                                <th>Creato il</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tickets as $ticket): ?>
                                <tr>
                                    <td>#<?php echo (int) $ticket['id']; ?></td>
                                    <td><?php echo sanitize_output($ticket['titolo']); ?></td>
                                    <td><?php echo sanitize_output(trim(($ticket['cognome'] ?? '') . ' ' . ($ticket['nome'] ?? '')) ?: 'N/A'); ?></td>
                                    <td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($ticket['stato']); ?></span></td>
                                    <td><?php echo sanitize_output(date('d/m/Y H:i', strtotime($ticket['created_at']))); ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $ticket['id']; ?>" title="Dettagli">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
