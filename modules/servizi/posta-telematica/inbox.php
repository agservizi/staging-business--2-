<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Inbox PEC';

$limit = isset($_GET['limit']) ? max(5, min(200, (int) $_GET['limit'])) : 50;
$selectedId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$search = trim((string) ($_GET['search'] ?? ''));

$messages = posta_telematica_list_cached_messages($pdo, $limit, $search !== '' ? $search : null);
$selectedMessage = $selectedId > 0 ? posta_telematica_get_cached_message($pdo, $selectedId) : null;
$attachments = $selectedId > 0 ? posta_telematica_get_cached_attachments($pdo, $selectedId) : [];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Inbox PEC</h1>
                <p class="text-muted mb-0">Visualizza le PEC in arrivo dalla casella configurata.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
                <a class="btn btn-primary" href="create.php"><i class="fa-solid fa-paper-plane me-2"></i>Nuovo invio</a>
                <a class="btn btn-warning text-dark" href="sync.php"><i class="fa-solid fa-arrows-rotate me-2"></i>Sincronizza</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-5">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Messaggi</h2>
                        <span class="badge ag-badge"><?php echo count($messages); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <form class="p-3 border-bottom" method="get">
                            <div class="input-group">
                                <span class="input-group-text bg-transparent"><i class="fa-solid fa-magnifying-glass"></i></span>
                                <input type="search" class="form-control" name="search" placeholder="Cerca oggetto o mittente" value="<?php echo sanitize_output($search); ?>">
                                <?php if ($search !== ''): ?>
                                    <a class="btn btn-outline-secondary" href="inbox.php">Reset</a>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if (!$messages): ?>
                            <div class="p-4 text-center text-muted">Nessun messaggio in cache. Usa "Sincronizza".</div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($messages as $message): ?>
                                    <?php
                                        $isActive = $selectedId === (int) $message['id'];
                                        $dateLabel = $message['received_at'] ? date('d/m/Y H:i', strtotime((string) $message['received_at'])) : '';
                                    ?>
                                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-start<?php echo $isActive ? ' active' : ''; ?>" href="?id=<?php echo (int) $message['id']; ?>">
                                        <div class="me-2">
                                            <div class="fw-semibold text-truncate" style="max-width: 260px;">
                                                <?php echo sanitize_output($message['subject'] ?: '(Senza oggetto)'); ?>
                                            </div>
                                            <div class="small text-muted text-truncate" style="max-width: 260px;">
                                                <?php echo sanitize_output($message['sender'] ?? ''); ?>
                                            </div>
                                        </div>
                                        <div class="text-end">
                                            <div class="small text-muted"><?php echo sanitize_output($dateLabel); ?></div>
                                            <?php if (empty($message['seen'])): ?>
                                                <span class="badge bg-warning text-dark">Nuovo</span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-7">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Dettaglio messaggio</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!$selectedMessage): ?>
                            <div class="text-muted">Seleziona un messaggio per leggerlo.</div>
                        <?php else: ?>
                            <div class="mb-3">
                                <div class="text-muted small">Oggetto</div>
                                <div class="fw-semibold"><?php echo sanitize_output($selectedMessage['subject'] ?: '(Senza oggetto)'); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="text-muted small">Da</div>
                                <div><?php echo sanitize_output($selectedMessage['sender'] ?? ''); ?></div>
                            </div>
                            <div class="mb-3">
                                <div class="text-muted small">Data</div>
                                <div><?php echo sanitize_output($selectedMessage['received_at'] ?? ''); ?></div>
                            </div>
                            <div class="border rounded-3 p-3 bg-body-tertiary" style="white-space: pre-wrap;">
                                <?php echo sanitize_output((string) ($selectedMessage['body'] ?? '')); ?>
                            </div>
                            <?php if ($attachments): ?>
                                <div class="mt-4">
                                    <div class="fw-semibold mb-2">Allegati</div>
                                    <div class="list-group">
                                        <?php foreach ($attachments as $attachment): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="fw-semibold"><?php echo sanitize_output($attachment['file_name'] ?? 'Allegato'); ?></div>
                                                    <small class="text-muted"><?php echo sanitize_output($attachment['mime_type'] ?? ''); ?></small>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
