<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Credenziali Iliad';

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$credentials = $iliadService->listCredentials($page, $perPage);
$totalCredentials = $iliadService->countCredentials();
$totalPages = (int) ceil($totalCredentials / $perPage);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>

    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Credenziali Iliad</h1>
                <p class="text-muted mb-0">Gestisci le credenziali per i servizi Iliad da fornire ai clienti.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-plus me-2"></i>Nuove Credenziali</a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <?php if (empty($credentials)): ?>
                    <p class="text-muted mb-0">Nessuna credenziale presente.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID Fibra</th>
                                    <th>ID Mobile</th>
                                    <th>Password</th>
                                    <th>Include Fibra</th>
                                    <th>Include Mobile</th>
                                    <th>Creato il</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credentials as $cred): ?>
                                    <tr>
                                        <td><?php echo sanitize_output($cred['fibra_id'] ?? '—'); ?></td>
                                        <td><?php echo sanitize_output($cred['mobile_id'] ?? '—'); ?></td>
                                        <td><?php echo sanitize_output($cred['fibra_password']); ?></td>
                                        <td>
                                            <?php if ($cred['include_fibra']): ?>
                                                <i class="fa-solid fa-check text-success"></i>
                                            <?php else: ?>
                                                <i class="fa-solid fa-xmark text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cred['include_mobile']): ?>
                                                <i class="fa-solid fa-check text-success"></i>
                                            <?php else: ?>
                                                <i class="fa-solid fa-xmark text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo format_datetime_locale($cred['created_at']); ?></td>
                                        <td>
                                            <a href="generate_pdf.php?id=<?php echo $cred['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="fa-solid fa-file-pdf me-1"></i>PDF
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Navigazione pagine">
                            <ul class="pagination justify-content-center mt-4">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>">Precedente</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Successivo</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>