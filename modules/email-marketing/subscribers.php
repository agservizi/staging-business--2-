<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Iscritti email marketing';

if (!function_exists('email_marketing_tables_ready')) {
    function email_marketing_tables_ready(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        try {
            $pdo->query('SELECT 1 FROM email_subscribers LIMIT 1');
            $pdo->query('SELECT 1 FROM email_lists LIMIT 1');
            $cache = true;
        } catch (PDOException $exception) {
            error_log('Email marketing tables missing: ' . $exception->getMessage());
            $cache = false;
        }

        return $cache;
    }
}

$emailTablesReady = email_marketing_tables_ready($pdo);
$csrfToken = csrf_token();
$errors = [];

if ($emailTablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrfToken, (string) ($_POST['_token'] ?? ''))) {
        $errors[] = 'Sessione scaduta, ricarica la pagina e riprova.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add-subscriber') {
            $email = strtolower(trim($_POST['email'] ?? ''));
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $status = $_POST['status'] ?? 'active';
            $allowedStatuses = ['active', 'unsubscribed', 'bounced'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'active';
            }
            $listIds = isset($_POST['list_ids']) ? array_map('intval', (array) $_POST['list_ids']) : [];

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Inserisci un indirizzo email valido.';
            }

            if (!$errors) {
                try {
                    $stmt = $pdo->prepare("INSERT INTO email_subscribers (email, first_name, last_name, status, source, created_at, updated_at)
                        VALUES (:email, :first_name, :last_name, :status, :source, NOW(), NOW())
                        ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), last_name = VALUES(last_name), status = VALUES(status), updated_at = NOW()");
                    $stmt->execute([
                        ':email' => $email,
                        ':first_name' => $firstName !== '' ? $firstName : null,
                        ':last_name' => $lastName !== '' ? $lastName : null,
                        ':status' => $status,
                        ':source' => 'manual',
                    ]);

                    $subscriberId = (int) $pdo->lastInsertId();
                    if ($subscriberId === 0) {
                        $fetchId = $pdo->prepare('SELECT id FROM email_subscribers WHERE email = :email LIMIT 1');
                        $fetchId->execute([':email' => $email]);
                        $subscriberId = (int) $fetchId->fetchColumn();
                    }

                    if ($subscriberId > 0) {
                        $pdo->prepare('DELETE FROM email_list_subscribers WHERE subscriber_id = :id')->execute([':id' => $subscriberId]);
                        if ($listIds) {
                            $link = $pdo->prepare("INSERT INTO email_list_subscribers (list_id, subscriber_id, status, subscribed_at)
                                VALUES (:list_id, :subscriber_id, :status, NOW())
                                ON DUPLICATE KEY UPDATE status = VALUES(status), unsubscribed_at = CASE WHEN VALUES(status) = 'active' THEN NULL ELSE unsubscribed_at END");
                            foreach ($listIds as $listId) {
                                $link->execute([
                                    ':list_id' => $listId,
                                    ':subscriber_id' => $subscriberId,
                                    ':status' => $status,
                                ]);
                            }
                        }
                    }

                    add_flash('success', 'Iscritto salvato.');
                    header('Location: subscribers.php');
                    exit;
                } catch (PDOException $exception) {
                    error_log('Subscriber save failed: ' . $exception->getMessage());
                    $errors[] = "Errore durante il salvataggio dell'iscritto.";
                }
            }
        }

        if ($action === 'create-list') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '') {
                $errors[] = 'Inserisci un nome per la lista.';
            }

            if (!$errors) {
                try {
                    $stmt = $pdo->prepare('INSERT INTO email_lists (name, description, created_at, updated_at)
                        VALUES (:name, :description, NOW(), NOW())');
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description !== '' ? $description : null,
                    ]);
                    add_flash('success', 'Lista creata.');
                    header('Location: subscribers.php');
                    exit;
                } catch (PDOException $exception) {
                    error_log('List create failed: ' . $exception->getMessage());
                    $errors[] = 'Errore durante la creazione della lista.';
                }
            }
        }

        if ($action === 'toggle-status') {
            $subscriberId = (int) ($_POST['subscriber_id'] ?? 0);
            $newStatus = $_POST['new_status'] ?? 'active';
            if ($subscriberId > 0 && in_array($newStatus, ['active', 'unsubscribed', 'bounced'], true)) {
                try {
                    $stmt = $pdo->prepare("UPDATE email_subscribers SET status = :status, updated_at = NOW(), unsubscribed_at = CASE WHEN :status = 'unsubscribed' THEN NOW() ELSE unsubscribed_at END WHERE id = :id");
                    $stmt->execute([
                        ':status' => $newStatus,
                        ':id' => $subscriberId,
                    ]);

                    if ($newStatus === 'active') {
                        $pdo->prepare("UPDATE email_list_subscribers SET status = 'active', unsubscribed_at = NULL WHERE subscriber_id = :id")
                            ->execute([':id' => $subscriberId]);
                    } else {
                        $pdo->prepare('UPDATE email_list_subscribers SET status = :status, unsubscribed_at = NOW() WHERE subscriber_id = :id')
                            ->execute([
                                ':status' => $newStatus,
                                ':id' => $subscriberId,
                            ]);
                    }

                    add_flash('success', 'Stato aggiornato.');
                    header('Location: subscribers.php');
                    exit;
                } catch (PDOException $exception) {
                    error_log('Subscriber toggle failed: ' . $exception->getMessage());
                    $errors[] = 'Impossibile aggiornare lo stato.';
                }
            }
        }

        if ($action === 'import-clients') {
            try {
                $clientStmt = $pdo->query("SELECT nome, cognome, email FROM clienti WHERE email IS NOT NULL AND email <> ''");
                $insert = $pdo->prepare("INSERT INTO email_subscribers (email, first_name, last_name, status, source, created_at, updated_at)
                    VALUES (:email, :first_name, :last_name, 'active', :source, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE first_name = COALESCE(VALUES(first_name), first_name), last_name = COALESCE(VALUES(last_name), last_name)");
                $count = 0;
                while ($row = $clientStmt->fetch(PDO::FETCH_ASSOC)) {
                    $email = strtolower(trim((string) ($row['email'] ?? '')));
                    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        continue;
                    }
                    $insert->execute([
                        ':email' => $email,
                        ':first_name' => trim((string) ($row['nome'] ?? '')) ?: null,
                        ':last_name' => trim((string) ($row['cognome'] ?? '')) ?: null,
                        ':source' => 'crm-import',
                    ]);
                    $count++;
                }
                add_flash('success', 'Importazione completata. Nuovi iscritti: ' . $count);
                header('Location: subscribers.php');
                exit;
            } catch (PDOException $exception) {
                error_log('Client import failed: ' . $exception->getMessage());
                $errors[] = "Errore durante l'importazione clienti.";
            }
        }
    }
}

$subscribers = [];
$lists = [];
$statusCounters = [
    'active' => 0,
    'unsubscribed' => 0,
    'bounced' => 0,
];

if ($emailTablesReady) {
    try {
        $subStmt = $pdo->query('SELECT id, email, first_name, last_name, status, created_at FROM email_subscribers ORDER BY created_at DESC LIMIT 100');
        $subscribers = $subStmt->fetchAll() ?: [];
        $listStmt = $pdo->query("SELECT l.id, l.name, l.description, COUNT(ls.subscriber_id) AS subscribers
            FROM email_lists l
            LEFT JOIN email_list_subscribers ls ON ls.list_id = l.id AND ls.status = 'active'
            GROUP BY l.id, l.name, l.description
            ORDER BY l.name");
        $lists = $listStmt->fetchAll() ?: [];
        $statusStmt = $pdo->query('SELECT status, COUNT(*) AS total FROM email_subscribers GROUP BY status');
        while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
            $status = $row['status'] ?? 'active';
            if (isset($statusCounters[$status])) {
                $statusCounters[$status] = (int) $row['total'];
            }
        }
    } catch (PDOException $exception) {
        error_log('Email subscribers load failed: ' . $exception->getMessage());
        $errors[] = 'Impossibile caricare gli iscritti.';
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1">Iscritti email marketing</h1>
                <p class="text-muted mb-0">Gestisci contatti, liste e stato iscrizione.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <?php if (!$emailTablesReady): ?>
            <div class="alert alert-warning">
                Per utilizzare questa sezione assicurati di aver eseguito le migrazioni email marketing.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo sanitize_output($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($emailTablesReady): ?>
            <div class="row g-4 mb-4">
                <div class="col-12 col-xl-4">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-user-check"></i> Attivi</span>
                            <h3 class="mb-0"><?php echo number_format($statusCounters['active']); ?></h3>
                            <small class="text-muted">Iscritti che riceveranno campagne</small>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-user-xmark"></i> Disiscritti</span>
                            <h3 class="mb-0"><?php echo number_format($statusCounters['unsubscribed']); ?></h3>
                            <small class="text-muted">Contatti che non vogliono più ricevere email</small>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-triangle-exclamation"></i> Bounced</span>
                            <h3 class="mb-0"><?php echo number_format($statusCounters['bounced']); ?></h3>
                            <small class="text-muted">Email con errori permanenti</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12 col-xl-6">
                    <div class="card ag-card mb-4">
                        <div class="card-header bg-transparent border-0">
                            <h5 class="card-title mb-0">Nuovo iscritto</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" class="row g-3">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="add-subscriber">
                                <div class="col-12">
                                    <label class="form-label" for="email">Email *</label>
                                    <input class="form-control" id="email" name="email" type="email" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="first_name">Nome</label>
                                    <input class="form-control" id="first_name" name="first_name">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="last_name">Cognome</label>
                                    <input class="form-control" id="last_name" name="last_name">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="status">Stato</label>
                                    <select class="form-select" id="status" name="status">
                                        <option value="active">Attivo</option>
                                        <option value="unsubscribed">Disiscritto</option>
                                        <option value="bounced">Bounced</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="list_ids">Liste</label>
                                    <select class="form-select" id="list_ids" name="list_ids[]" multiple size="4">
                                        <?php foreach ($lists as $list): ?>
                                            <option value="<?php echo (int) $list['id']; ?>"><?php echo sanitize_output($list['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Premi CTRL/CMD per selezione multipla.</small>
                                </div>
                                <div class="col-12 text-end">
                                    <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-user-plus me-2"></i>Salva iscritto</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0">
                            <h5 class="card-title mb-0">Importa da clienti</h5>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-3">Aggiorna l'anagrafica iscritti importando gli indirizzi email presenti nei clienti CRM.</p>
                            <form method="post">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="import-clients">
                                <button class="btn btn-outline-warning" type="submit" onclick="return confirm('Importare tutti i clienti con email come iscritti attivi?');"><i class="fa-solid fa-cloud-arrow-down me-2"></i>Importa clienti</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="card ag-card mb-4">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Liste</h5>
                            <button class="btn btn-sm btn-outline-light" type="button" data-bs-toggle="collapse" data-bs-target="#createList" aria-expanded="false">
                                <i class="fa-solid fa-plus"></i>
                            </button>
                        </div>
                        <div class="collapse" id="createList">
                            <div class="card-body border-top">
                                <form method="post" class="row g-3">
                                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="create-list">
                                    <div class="col-12">
                                        <label class="form-label" for="list_name">Nome lista</label>
                                        <input class="form-control" id="list_name" name="name" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="list_description">Descrizione</label>
                                        <textarea class="form-control" id="list_description" name="description" rows="2"></textarea>
                                    </div>
                                    <div class="col-12 text-end">
                                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Crea lista</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if ($lists): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($lists as $list): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span>
                                                <span class="fw-semibold"><?php echo sanitize_output($list['name']); ?></span>
                                                <?php if (!empty($list['description'])): ?>
                                                    <br><small class="text-muted"><?php echo sanitize_output($list['description']); ?></small>
                                                <?php endif; ?>
                                            </span>
                                            <span class="badge bg-secondary"><?php echo (int) $list['subscribers']; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted mb-0">Crea una lista per iniziare a segmentare gli iscritti.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0">
                            <h5 class="card-title mb-0">Ultimi iscritti</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Email</th>
                                            <th>Nome</th>
                                            <th>Stato</th>
                                            <th>Registrato</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($subscribers): ?>
                                            <?php foreach ($subscribers as $subscriber): ?>
                                                <tr id="subscriber-row-<?php echo (int) $subscriber['id']; ?>">
                                                    <td><?php echo sanitize_output($subscriber['email']); ?></td>
                                                    <td><?php echo sanitize_output(trim(($subscriber['first_name'] ?? '') . ' ' . ($subscriber['last_name'] ?? '')) ?: '—'); ?></td>
                                                    <td><?php echo sanitize_output($subscriber['status']); ?></td>
                                                    <td><?php echo sanitize_output(format_datetime($subscriber['created_at'] ?? '')); ?></td>
                                                    <td class="text-end">
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="toggle-status">
                                                            <input type="hidden" name="subscriber_id" value="<?php echo (int) $subscriber['id']; ?>">
                                                            <?php if ($subscriber['status'] !== 'active'): ?>
                                                                <input type="hidden" name="new_status" value="active">
                                                                <button class="btn btn-sm btn-outline-success" type="submit"><i class="fa-solid fa-rotate"></i></button>
                                                            <?php else: ?>
                                                                <input type="hidden" name="new_status" value="unsubscribed">
                                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="fa-solid fa-ban"></i></button>
                                                            <?php endif; ?>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">Nessun iscritto presente.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
