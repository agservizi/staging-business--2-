<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/notifications.php';

$pageTitle = 'Notifiche';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');

$limit = 20;
$beforeId = isset($_GET['before_id']) && ctype_digit((string) $_GET['before_id']) ? (int) $_GET['before_id'] : null;

$payload = fetch_notifications($pdo, $userId, $role, $limit, $beforeId);
$items = $payload['items'] ?? [];
$nextCursor = $payload['nextCursor'] ?? null;
$hasMore = $payload['hasMore'] ?? false;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Notifiche</h1>
                <p class="text-muted mb-0">Storico completo delle notifiche più recenti.</p>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-outline-secondary" type="button" id="markAllNotifications">Segna tutte come lette</button>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <?php if (!$items): ?>
                    <p class="text-muted mb-0">Nessuna notifica disponibile.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($items as $item): ?>
                            <div class="list-group-item notification-item<?php echo $item['isRead'] ? '' : ' is-unread'; ?>" data-notification-id="<?php echo (int) $item['id']; ?>">
                                <div class="<?php echo sanitize_output($item['colorClass']); ?>" aria-hidden="true">
                                    <i class="fa-solid <?php echo sanitize_output($item['icon']); ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="notification-item-title"><?php echo sanitize_output($item['title']); ?></div>
                                    <div class="notification-item-message"><?php echo sanitize_output($item['message']); ?></div>
                                    <?php if ($item['type'] === 'bug' && !empty($item['metadata']['suggestions'])): ?>
                                        <div class="notification-item-suggestions">
                                            <?php
                                                $suggestions = $item['metadata']['suggestions'];
                                                $causes = !empty($suggestions['causes']) ? 'Cause: ' . implode(' • ', $suggestions['causes']) : '';
                                                $checks = !empty($suggestions['checks']) ? 'Verifiche: ' . implode(' • ', $suggestions['checks']) : '';
                                                $fix = !empty($suggestions['fix']) ? 'Fix: ' . $suggestions['fix'] : '';
                                                $lines = array_filter([$causes, $checks, $fix]);
                                            ?>
                                            <?php echo sanitize_output(implode("\n", $lines)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="notification-item-meta"><?php echo sanitize_output($item['createdAtLabel']); ?></div>
                                </div>
                                <div class="ms-auto">
                                    <button class="btn btn-sm btn-outline-primary notification-mark-read" type="button"<?php echo $item['isRead'] ? ' disabled' : ''; ?>>Leggi</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($hasMore && $nextCursor): ?>
                <div class="card-footer bg-transparent border-0">
                    <a class="btn btn-outline-primary" href="<?php echo base_url('modules/impostazioni/notifications.php?before_id=' . (int) $nextCursor); ?>">Mostra di più</a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script>
(() => {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const markAllButton = document.getElementById('markAllNotifications');
    const markAllEndpoint = '<?php echo sanitize_output(base_url('api/mark_notifications.php')); ?>';
    const markEndpoint = '<?php echo sanitize_output(base_url('api/mark_notification.php')); ?>';

    const markRowAsRead = (row) => {
        if (!row) {
            return;
        }
        row.classList.remove('is-unread');
        const button = row.querySelector('.notification-mark-read');
        if (button) {
            button.disabled = true;
        }
    };

    document.querySelectorAll('.notification-mark-read').forEach((button) => {
        button.addEventListener('click', async () => {
            const row = button.closest('[data-notification-id]');
            const id = row?.getAttribute('data-notification-id');
            if (!id) {
                return;
            }
            try {
                const response = await fetch(markEndpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ id }),
                });
                if (response.ok) {
                    markRowAsRead(row);
                }
            } catch (e) {
                // ignore
            }
        });
    });

    markAllButton?.addEventListener('click', async () => {
        try {
            const response = await fetch(markAllEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'mark_all' }),
            });
            if (response.ok) {
                document.querySelectorAll('.notification-item').forEach(markRowAsRead);
            }
        } catch (e) {
            // ignore
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
