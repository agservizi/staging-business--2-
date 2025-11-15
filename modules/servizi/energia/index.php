<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Contratti energia';

$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $contractId = (int) ($_POST['id'] ?? 0);

    if ($contractId <= 0) {
        add_flash('warning', 'Richiesta non valida.');
        header('Location: index.php');
        exit;
    }

    $contract = energia_fetch_contract($pdo, $contractId);
    if ($contract === null) {
        add_flash('warning', 'Contratto energia non trovato.');
        header('Location: index.php');
        exit;
    }

    if ($action === 'send_email') {
        if (!empty($contract['email_sent_at'])) {
            add_flash('warning', 'Email già inviata per questo contratto.');
        } else {
            $sent = energia_send_contract_mail($pdo, $contract, false, 'manual');
            if ($sent) {
                $latest = energia_fetch_contract($pdo, $contractId);
                if ($latest && !empty($latest['contract_code'])) {
                    add_flash('success', 'Email inviata correttamente. Codice contratto: ' . $latest['contract_code'] . '.');
                } else {
                    add_flash('success', 'Email inviata correttamente.');
                }
            } else {
                add_flash('warning', 'Impossibile inviare l\'email.');
            }
        }
    } elseif ($action === 'send_reminder') {
        if (empty($contract['email_sent_at'])) {
            add_flash('warning', 'Invia prima l\'email di presa in carico.');
        } else {
            $sent = energia_send_contract_mail($pdo, $contract, true, 'manual');
            add_flash($sent ? 'success' : 'warning', $sent ? 'Reminder inviato correttamente.' : 'Impossibile inviare il reminder.');
        }
    } else {
        add_flash('warning', 'Azione non supportata.');
    }

    header('Location: index.php');
    exit;
}

$contracts = energia_fetch_contracts($pdo);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Contratti energia</h1>
                <p class="text-muted mb-0">Caricamenti contratti Enel luce e gas con promemoria di gestione.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo caricamento</a>
            </div>
        </div>
        <div class="card ag-card">
            <div class="card-body">
                <?php if (!$contracts): ?>
                    <p class="text-muted mb-0">Nessun contratto caricato finora.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Codice</th>
                                    <th>Nominativo</th>
                                    <th>Fornitura</th>
                                    <th>Operazione</th>
                                    <th>Stato</th>
                                    <th>Email inviata</th>
                                    <th>Reminder</th>
                                    <th>Allegati</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contracts as $contract): ?>
                                    <tr>
                                        <td>#<?php echo (int) $contract['id']; ?></td>
                                        <td>
                                            <?php if (!empty($contract['contract_code'])): ?>
                                                <?php echo sanitize_output($contract['contract_code']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo sanitize_output($contract['nominativo'] ?? ''); ?></strong><br>
                                            <?php $createdAt = format_datetime_locale($contract['created_at'] ?? ''); ?>
                                            <small class="text-muted">Creato il <?php echo sanitize_output($createdAt); ?></small>
                                            <br>
                                            <?php if (!empty($contract['reminder_sent_at'])): ?>
                                                <?php $reminderAt = format_datetime_locale($contract['reminder_sent_at']); ?>
                                                <small class="text-muted">Ultimo reminder: <?php echo sanitize_output($reminderAt); ?></small>
                                            <?php else: ?>
                                                <small class="text-muted">Ultimo reminder: mai inviato</small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output($contract['fornitura'] ?? ''); ?></td>
                                        <td><?php echo sanitize_output($contract['operazione'] ?? ''); ?></td>
                                        <td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($contract['stato'] ?? ''); ?></span></td>
                                        <td>
                                            <?php if (!empty($contract['email_sent_at'])): ?>
                                                <span class="text-success"><?php echo sanitize_output(format_datetime_locale($contract['email_sent_at'])); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Non inviata</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($contract['reminder_sent_at'])): ?>
                                                <span class="text-warning"><?php echo sanitize_output(format_datetime_locale($contract['reminder_sent_at'])); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">Mai inviato</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                $count = (int) ($contract['attachments_count'] ?? 0);
                                                $extraCount = (int) ($contract['extra_attachments_count'] ?? 0);
                                            ?>
                                            <?php if ($count > 0): ?>
                                                <span class="badge bg-secondary"><?php echo $count; ?> file</span>
                                                <?php if ($extraCount > 0): ?>
                                                    <span class="badge bg-primary ms-1">+<?php echo $extraCount; ?> extra</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap" role="group">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $contract['id']; ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $contract['id']; ?>#extra-docs" title="Documenti aggiuntivi">
                                                    <i class="fa-solid fa-file-circle-plus"></i>
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $contract['id']; ?>">
                                                    <input type="hidden" name="action" value="send_email">
                                                    <button class="btn btn-icon btn-soft-accent btn-sm" type="submit" title="Invia email" <?php echo !empty($contract['email_sent_at']) ? 'disabled' : ''; ?>>
                                                        <i class="fa-solid fa-paper-plane"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $contract['id']; ?>">
                                                    <input type="hidden" name="action" value="send_reminder">
                                                    <button class="btn btn-icon btn-soft-accent btn-sm" type="submit" title="Invia reminder" <?php echo empty($contract['email_sent_at']) ? 'disabled' : ''; ?>>
                                                        <i class="fa-solid fa-bell"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
