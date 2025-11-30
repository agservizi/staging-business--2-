<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    define('CORESUITE_PICKUP_BOOTSTRAP', true);
}

require_once __DIR__ . '/functions.php';

ensure_pickup_tables();

$package = get_package_details($id);
if (!$package) {
    add_flash('warning', 'Pickup non trovato.');
    header('Location: index.php');
    exit;
}

$linkedReport = null;
try {
    $linkedReport = get_customer_report_for_pickup($id);
} catch (Throwable $reportException) {
    error_log('Impossibile recuperare la segnalazione portal collegata: ' . $reportException->getMessage());
}

$notifications = get_notifications_for_package($id);
$statuses = pickup_statuses();
$formToken = csrf_token();

$pageTitle = 'Pickup #' . ($package['tracking'] ?? $id);
$extraStyles = [asset('modules/servizi/logistici/css/style.css')];
$extraScripts = [asset('modules/servizi/logistici/js/script.js')];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100 pickup-module">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Tutti i pickup</a>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-warning text-dark" href="edit.php?id=<?php echo $id; ?>"><i class="fa-solid fa-pen"></i> Modifica</a>
                <form method="post" action="delete.php" onsubmit="return confirm('Confermi l\'eliminazione del pickup?');">
                    <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                    <input type="hidden" name="id" value="<?php echo $id; ?>">
                    <button class="btn btn-outline-warning" type="submit"><i class="fa-solid fa-trash"></i></button>
                </form>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Dettagli pickup</h2>
                        <span class="badge-status" data-status="<?php echo sanitize_output($package['status']); ?>" data-status-badge><?php echo pickup_status_label($package['status']); ?></span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Tracking</dt>
                            <dd class="col-sm-8">#<?php echo sanitize_output($package['tracking']); ?></dd>
                            <dt class="col-sm-4">Corriere</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($package['courier_name'] ?? 'N/D'); ?></dd>
                            <dt class="col-sm-4">Punto ritiro</dt>
                            <dd class="col-sm-8">
                                <?php if (!empty($package['location_name'])): ?>
                                    <?php echo sanitize_output($package['location_name']); ?>
                                <?php else: ?>
                                    <span class="text-muted">N/D</span>
                                <?php endif; ?>
                            </dd>
                            <?php if (!empty($package['location_address'])): ?>
                                <dt class="col-sm-4">Indirizzo ritiro</dt>
                                <dd class="col-sm-8"><?php echo nl2br(sanitize_output($package['location_address'])); ?></dd>
                            <?php endif; ?>
                            <dt class="col-sm-4">Previsto</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($package['expected_at'] ? format_datetime_locale($package['expected_at']) : 'N/D'); ?></dd>
                            <dt class="col-sm-4">Creato</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($package['created_at'] ?? '')); ?></dd>
                            <dt class="col-sm-4">Aggiornato</dt>
                            <dd class="col-sm-8" data-updated-at><?php echo sanitize_output(format_datetime_locale($package['updated_at'] ?? '')); ?></dd>
                            <dt class="col-sm-4">Note interne</dt>
                            <dd class="col-sm-8"><?php echo $package['notes'] ? nl2br(sanitize_output($package['notes'])) : '<span class="text-muted">Nessuna nota.</span>'; ?></dd>
                        </dl>
                    </div>
                </div>

                <?php if ($linkedReport): ?>
                    <?php $reportMeta = pickup_customer_report_status_meta($linkedReport['status']); ?>
                    <div class="card ag-card mb-4">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h2 class="h5 mb-0">Segnalazione portal</h2>
                            <span class="badge rounded-pill <?php echo sanitize_output($reportMeta['badge']); ?>"><?php echo sanitize_output($reportMeta['label']); ?></span>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-4">ID segnalazione</dt>
                                <dd class="col-sm-8">#<?php echo (int) $linkedReport['id']; ?> · <a class="link-warning" href="report.php?id=<?php echo (int) $linkedReport['id']; ?>">Apri dettaglio</a></dd>
                                <dt class="col-sm-4">Tracking portal</dt>
                                <dd class="col-sm-8">#<?php echo sanitize_output($linkedReport['tracking_code'] ?? ''); ?></dd>
                                <dt class="col-sm-4">Segnalato il</dt>
                                <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($linkedReport['created_at'] ?? '')); ?></dd>
                                <dt class="col-sm-4">Ultimo aggiornamento</dt>
                                <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($linkedReport['updated_at'] ?? '')); ?></dd>
                                <?php if (!empty($linkedReport['customer_name']) || !empty($linkedReport['recipient_name'])): ?>
                                    <dt class="col-sm-4">Cliente</dt>
                                    <dd class="col-sm-8"><?php echo sanitize_output($linkedReport['customer_name'] ?? $linkedReport['recipient_name']); ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($linkedReport['customer_email'])): ?>
                                    <dt class="col-sm-4">Email</dt>
                                    <dd class="col-sm-8"><a class="link-warning" href="mailto:<?php echo sanitize_output($linkedReport['customer_email']); ?>"><?php echo sanitize_output($linkedReport['customer_email']); ?></a></dd>
                                <?php endif; ?>
                                <?php if (!empty($linkedReport['customer_phone'])): ?>
                                    <dt class="col-sm-4">Telefono</dt>
                                    <dd class="col-sm-8"><a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', (string) $linkedReport['customer_phone'])); ?>"><?php echo sanitize_output($linkedReport['customer_phone']); ?></a></dd>
                                <?php endif; ?>
                                <?php if (!empty($linkedReport['delivery_location'])): ?>
                                    <dt class="col-sm-4">Luogo consegna</dt>
                                    <dd class="col-sm-8"><?php echo sanitize_output($linkedReport['delivery_location']); ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($linkedReport['notes'])): ?>
                                    <dt class="col-sm-4">Note cliente</dt>
                                    <dd class="col-sm-8"><?php echo nl2br(sanitize_output($linkedReport['notes'])); ?></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Aggiorna stato</h2>
                    </div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end" method="post" action="index.php" data-pickup-status-form>
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="package_id" value="<?php echo $id; ?>">
                            <div class="col-md-6">
                                <label class="form-label" for="status_change">Nuovo stato</label>
                                <select class="form-select" id="status_change" name="status">
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo $status === $package['status'] ? 'selected' : ''; ?>><?php echo pickup_status_label($status); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <button class="btn btn-warning text-dark w-100" type="submit">Aggiorna stato</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Contatti cliente</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Nome</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output($package['customer_name']); ?></dd>
                            <dt class="col-sm-5">Telefono</dt>
                            <dd class="col-sm-7"><a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', $package['customer_phone'])); ?>"><?php echo sanitize_output($package['customer_phone']); ?></a></dd>
                            <dt class="col-sm-5">Email</dt>
                            <dd class="col-sm-7">
                                <?php if (!empty($package['customer_email'])): ?>
                                    <a class="link-warning" href="mailto:<?php echo sanitize_output($package['customer_email']); ?>"><?php echo sanitize_output($package['customer_email']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted small">N/D</span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>

                <?php if (!empty($package['location_name']) || !empty($package['location_address']) || !empty($package['location_phone']) || !empty($package['location_email'])): ?>
                    <div class="card ag-card mb-4">
                        <div class="card-header bg-transparent border-0">
                            <h2 class="h5 mb-0">Contatti punto ritiro</h2>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <?php if (!empty($package['location_name'])): ?>
                                    <dt class="col-sm-5">Nome</dt>
                                    <dd class="col-sm-7"><?php echo sanitize_output($package['location_name']); ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($package['location_address'])): ?>
                                    <dt class="col-sm-5">Indirizzo</dt>
                                    <dd class="col-sm-7"><?php echo nl2br(sanitize_output($package['location_address'])); ?></dd>
                                <?php endif; ?>
                                <?php if (!empty($package['location_phone'])): ?>
                                    <dt class="col-sm-5">Telefono</dt>
                                    <dd class="col-sm-7"><a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', $package['location_phone'])); ?>"><?php echo sanitize_output($package['location_phone']); ?></a></dd>
                                <?php endif; ?>
                                <?php if (!empty($package['location_email'])): ?>
                                    <dt class="col-sm-5">Email</dt>
                                    <dd class="col-sm-7"><a class="link-warning" href="mailto:<?php echo sanitize_output($package['location_email']); ?>"><?php echo sanitize_output($package['location_email']); ?></a></dd>
                                <?php endif; ?>
                            </dl>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Invia notifica</h2>
                    </div>
                    <div class="card-body">
                        <form class="mb-4" method="post" action="index.php" data-pickup-notification-form>
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="send_notification">
                            <input type="hidden" name="channel" value="email">
                            <input type="hidden" name="package_id" value="<?php echo $id; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="email_recipient">Email destinatario</label>
                                <input class="form-control" id="email_recipient" name="recipient" type="email" placeholder="cliente@example.com" value="<?php echo sanitize_output($package['customer_email'] ?? ''); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email_subject">Oggetto</label>
                                <input class="form-control" id="email_subject" name="subject" value="<?php echo sanitize_output(pickup_email_subject_template($package)); ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email_message">Messaggio</label>
                                <textarea class="form-control" id="email_message" name="message" rows="3" required><?php echo sanitize_output(pickup_email_message_template($package)); ?></textarea>
                            </div>
                            <button class="btn btn-warning text-dark w-100" type="submit">Invia email</button>
                        </form>

                        <form method="post" action="index.php" data-pickup-notification-form>
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="send_notification">
                            <input type="hidden" name="channel" value="whatsapp">
                            <input type="hidden" name="package_id" value="<?php echo $id; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="whatsapp_recipient">Numero WhatsApp</label>
                                <input class="form-control" id="whatsapp_recipient" name="recipient" value="<?php echo sanitize_output($package['customer_phone']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="whatsapp_message">Messaggio</label>
                                <textarea class="form-control" id="whatsapp_message" name="message" rows="3" required><?php echo sanitize_output(pickup_whatsapp_message_template($package)); ?></textarea>
                            </div>
                            <button class="btn btn-outline-warning w-100" type="submit"><i class="fa-brands fa-whatsapp me-1"></i>Invia WhatsApp</button>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Storico notifiche</h2>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush" data-notification-log>
                            <?php if (!$notifications): ?>
                                <div class="text-muted small">Nessuna notifica inviata per questo pickup.</div>
                            <?php endif; ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="list-group-item bg-transparent border-secondary-subtle text-body-secondary">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-warning text-uppercase fw-semibold"><?php echo sanitize_output($notification['channel']); ?></span>
                                        <span class="small"><?php echo sanitize_output(format_datetime_locale($notification['created_at'] ?? '')); ?></span>
                                    </div>
                                    <div class="small text-secondary">Stato: <?php echo sanitize_output(ucfirst($notification['status'] ?? '')); ?></div>
                                    <div class="small mt-2 text-body"><?php echo nl2br(sanitize_output($notification['message'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
