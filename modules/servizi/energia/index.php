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
        header('Location: ' . energia_module_url('index'));
        exit;
    }

    $contract = energia_fetch_contract($pdo, $contractId);
    if ($contract === null) {
        add_flash('warning', 'Contratto energia non trovato.');
        header('Location: ' . energia_module_url('index'));
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

    header('Location: ' . energia_module_url('index'));
    exit;
}

$contracts = energia_fetch_contracts($pdo);

$energySummary = [
    'total' => count($contracts),
    'sent' => 0,
    'pending' => 0,
    'reminders' => 0,
    'attachments' => 0,
];

foreach ($contracts as $contract) {
    if (!empty($contract['email_sent_at'])) {
        $energySummary['sent']++;
    } else {
        $energySummary['pending']++;
    }

    if (!empty($contract['reminder_sent_at'])) {
        $energySummary['reminders']++;
    }

    $energySummary['attachments'] += (int) ($contract['attachments_count'] ?? 0);
    $energySummary['attachments'] += (int) ($contract['extra_attachments_count'] ?? 0);
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .energy-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .energy-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(250, 204, 21, 0.22), transparent 34%),
                radial-gradient(circle at top right, rgba(59, 130, 246, 0.16), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #fefbf1 38%, #eef6ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .energy-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -110px auto;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(245, 158, 11, 0.10);
            filter: blur(12px);
        }

        .energy-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .energy-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .energy-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .energy-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .energy-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .energy-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .energy-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .energy-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .energy-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .energy-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .energy-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .energy-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .energy-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .energy-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .energy-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .energy-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .energy-table-shell .table {
            margin-bottom: 0;
        }

        .energy-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .energy-id-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.38rem 0.7rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-weight: 700;
            font-size: 0.8rem;
        }

        .energy-code {
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.78rem;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.06);
            color: #172033;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .energy-empty {
            padding: 2rem 1.5rem;
            color: #64748b;
        }

        @media (max-width: 1199.98px) {
            .energy-hero-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .energy-hero,
            .energy-table-wrap {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .energy-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <main class="content-wrapper">
        <div class="module-hub-shell energy-shell">
            <section class="energy-hero">
                <div class="energy-hero-grid">
                    <div>
                        <span class="energy-eyebrow"><i class="fa-solid fa-bolt"></i> Operations energia</span>
                        <h1>Una vista piu' chiara su caricamenti, invii e reminder contratti.</h1>
                        <p>Tieni sotto controllo i contratti luce e gas, verifica chi ha gia' ricevuto presa in carico e individua rapidamente i dossier ancora da presidiare.</p>
                        <div class="energy-hero-actions">
                            <a class="btn btn-warning text-dark" href="<?php echo energia_module_url('create'); ?>">
                                <i class="fa-solid fa-circle-plus me-2"></i>Nuovo caricamento
                            </a>
                        </div>
                    </div>
                    <div class="energy-kpi-grid">
                        <article class="energy-kpi-card">
                            <span>Contratti visibili</span>
                            <strong><?php echo number_format($energySummary['total'], 0, ',', '.'); ?></strong>
                            <small>Pratiche presenti in archivio</small>
                        </article>
                        <article class="energy-kpi-card">
                            <span>Email inviate</span>
                            <strong><?php echo number_format($energySummary['sent'], 0, ',', '.'); ?></strong>
                            <small>Prese in carico gia' comunicate al cliente</small>
                        </article>
                        <article class="energy-kpi-card">
                            <span>Da presidiare</span>
                            <strong><?php echo number_format($energySummary['pending'], 0, ',', '.'); ?></strong>
                            <small>Contratti senza email iniziale inviata</small>
                        </article>
                        <article class="energy-kpi-card">
                            <span>Reminder inviati</span>
                            <strong><?php echo number_format($energySummary['reminders'], 0, ',', '.'); ?></strong>
                            <small>Follow-up gia' registrati sul parco contratti</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="energy-panel">
                <div class="energy-panel-header">
                    <h2 class="energy-panel-title">Contratti registrati</h2>
                    <p class="energy-panel-subtitle">Elenco operativo con stato invii, reminder e allegati per seguire ogni caricamento senza uscire dalla vista principale.</p>
                </div>
                <div class="energy-table-wrap">
                <?php if (!$contracts): ?>
                    <div class="energy-empty">Nessun contratto caricato finora.</div>
                <?php else: ?>
                    <div class="energy-table-shell table-responsive">
                        <table class="table table-hover align-middle module-hub-table">
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
                                        <td><span class="energy-id-badge">#<?php echo (int) $contract['id']; ?></span></td>
                                        <td>
                                            <?php if (!empty($contract['contract_code'])): ?>
                                                <span class="energy-code"><?php echo sanitize_output($contract['contract_code']); ?></span>
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
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo energia_module_url('view', ['id' => (int) $contract['id']]); ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo energia_module_url('view', ['id' => (int) $contract['id']]) . '#extra-docs'; ?>" title="Documenti aggiuntivi">
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
            </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
