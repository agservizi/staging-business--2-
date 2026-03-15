<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Prenotazione CIE';

$csrfToken = csrf_token();

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'stato' => (string) ($_GET['stato'] ?? ''),
    'cliente_id' => (int) ($_GET['cliente_id'] ?? 0),
    'created_from' => (string) ($_GET['created_from'] ?? ''),
    'created_to' => (string) ($_GET['created_to'] ?? ''),
];

if ($filters['cliente_id'] <= 0) {
    unset($filters['cliente_id']);
}

$bookings = cie_fetch_bookings($pdo, $filters);
$stats = cie_fetch_stats($pdo);
$clients = cie_fetch_clients($pdo);
$statuses = cie_status_map();

$summaryCards = [
    [
        'label' => 'Richieste totali',
        'value' => (int) ($stats['total'] ?? 0),
    ],
];

foreach ($statuses as $key => $config) {
    $summaryCards[] = [
        'label' => (string) ($config['label'] ?? ucfirst($key)),
        'value' => (int) ($stats['by_status'][$key] ?? 0),
    ];
}

$cieSummary = [
    'total' => count($bookings),
    'scheduled' => 0,
    'waiting' => 0,
    'portal_ready' => 0,
    'docs_missing' => 0,
];

foreach ($bookings as $booking) {
    if (!empty($booking['appuntamento_data'])) {
        $cieSummary['scheduled']++;
    } else {
        $cieSummary['waiting']++;
    }

    if (!empty($booking['portal_opened_at']) || !empty($booking['appuntamento_data'])) {
        $cieSummary['portal_ready']++;
    }

    if (empty($booking['documento_identita_path']) && empty($booking['ricevuta_pagamento_path'])) {
        $cieSummary['docs_missing']++;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .cie-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .cie-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.16), transparent 34%),
                radial-gradient(circle at top right, rgba(245, 158, 11, 0.16), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 54%, #eef5ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .cie-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.08);
            filter: blur(12px);
        }

        .cie-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .cie-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .cie-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .cie-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .cie-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .cie-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .cie-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .cie-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .cie-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .cie-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .cie-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .cie-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .cie-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .cie-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .cie-filter-form {
            padding: 1.35rem 1.5rem 1.5rem;
        }

        .cie-filter-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 1rem;
        }

        .cie-field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #52607a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .cie-field .form-control,
        .cie-field .form-select {
            min-height: 48px;
            border-radius: 15px;
            border-color: #d7dfeb;
            box-shadow: none;
        }

        .cie-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .cie-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .cie-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .cie-table-shell .table {
            margin-bottom: 0;
        }

        .cie-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .cie-code-badge {
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

        .cie-empty {
            padding: 2rem 1.5rem;
            color: #64748b;
        }

        @media (max-width: 1199.98px) {
            .cie-hero-grid,
            .cie-filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .cie-hero,
            .cie-filter-form,
            .cie-table-wrap {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .cie-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <main class="content-wrapper">
        <div class="module-hub-shell cie-shell">
            <section class="cie-hero">
                <div class="cie-hero-grid">
                    <div>
                        <span class="cie-eyebrow"><i class="fa-solid fa-id-card"></i> Carta d'identita'</span>
                        <h1>Una cabina di regia piu' chiara per prenotazioni, appuntamenti e documenti.</h1>
                        <p>Monitora le richieste CIE, isola quelle in attesa, verifica gli slot gia' fissati e tieni allineati portale, documentazione e stato pratica in un'unica vista.</p>
                        <div class="cie-hero-actions">
                            <a class="btn btn-outline-warning" href="https://www.prenotazionicie.interno.gov.it/cittadino/n/sc/wizardAppuntamentoCittadino/sceltaComune" target="_blank" rel="noopener">
                                <i class="fa-solid fa-up-right-from-square me-2"></i>Portale CIE
                            </a>
                            <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(cie_module_url('create')); ?>">
                                <i class="fa-solid fa-circle-plus me-2"></i>Nuova richiesta
                            </a>
                        </div>
                    </div>
                    <div class="cie-kpi-grid">
                        <article class="cie-kpi-card">
                            <span>Richieste visibili</span>
                            <strong><?php echo number_format($cieSummary['total'], 0, ',', '.'); ?></strong>
                            <small>Prenotazioni presenti nel perimetro filtrato</small>
                        </article>
                        <article class="cie-kpi-card">
                            <span>Appuntamenti fissati</span>
                            <strong><?php echo number_format($cieSummary['scheduled'], 0, ',', '.'); ?></strong>
                            <small>Richieste gia' convertite in slot confermato</small>
                        </article>
                        <article class="cie-kpi-card">
                            <span>In attesa</span>
                            <strong><?php echo number_format($cieSummary['waiting'], 0, ',', '.'); ?></strong>
                            <small>Pratiche ancora senza appuntamento assegnato</small>
                        </article>
                        <article class="cie-kpi-card">
                            <span>Documenti mancanti</span>
                            <strong><?php echo number_format($cieSummary['docs_missing'], 0, ',', '.'); ?></strong>
                            <small>Richieste da completare lato allegati</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="cie-panel">
                <div class="cie-panel-header">
                    <h2 class="cie-panel-title">Filtri operativi</h2>
                    <p class="cie-panel-subtitle">Riduci la vista per stato, cittadino o intervallo temporale per lavorare piu' velocemente sulle richieste aperte.</p>
                </div>
                <form class="cie-filter-form" method="get">
                    <div class="cie-filter-grid">
                        <div class="cie-field">
                            <label for="search">Ricerca</label>
                            <input class="form-control" id="search" name="search" placeholder="Codice, cittadino, comune" value="<?php echo sanitize_output($filters['search']); ?>">
                        </div>
                        <div class="cie-field">
                            <label for="stato">Stato</label>
                            <select class="form-select" id="stato" name="stato">
                                <option value="">Tutti</option>
                                <?php foreach ($statuses as $key => $config): ?>
                                    <option value="<?php echo sanitize_output($key); ?>" <?php echo $filters['stato'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($config['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cie-field">
                            <label for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id">
                                <option value="">Tutti</option>
                                <?php foreach ($clients as $client): ?>
                                    <?php $clientId = (int) ($client['id'] ?? 0); ?>
                                    <option value="<?php echo $clientId; ?>" <?php echo ($filters['cliente_id'] ?? 0) === $clientId ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output(trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cie-field">
                            <label for="created_from">Dal</label>
                            <input class="form-control" type="date" id="created_from" name="created_from" value="<?php echo sanitize_output($filters['created_from']); ?>">
                        </div>
                        <div class="cie-field">
                            <label for="created_to">Al</label>
                            <input class="form-control" type="date" id="created_to" name="created_to" value="<?php echo sanitize_output($filters['created_to']); ?>">
                        </div>
                    </div>
                    <div class="cie-filter-actions">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra</button>
                        <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(cie_module_url('index')); ?>"><i class="fa-solid fa-eraser me-2"></i>Pulisci</a>
                    </div>
                </form>
            </section>

            <section class="cie-panel">
                <div class="cie-panel-header">
                    <h2 class="cie-panel-title">Prenotazioni registrate</h2>
                    <p class="cie-panel-subtitle">Vista ordinata di cittadini, comune, disponibilita' e appuntamenti per intervenire subito sulle pratiche in corso.</p>
                </div>
                <div class="cie-table-wrap">
                <?php if (!$bookings): ?>
                    <div class="cie-empty">Nessuna prenotazione presente. Crea una nuova richiesta per iniziare.</div>
                <?php else: ?>
                    <div class="cie-table-shell table-responsive">
                        <table class="table table-hover align-middle module-hub-table">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Cittadino</th>
                                    <th>Comune</th>
                                    <th>Disponibilità</th>
                                    <th>Appuntamento</th>
                                    <th>Stato</th>
                                    <th>Operatore</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <span class="cie-code-badge"><?php echo sanitize_output((string) ($booking['booking_code'] ?? cie_booking_code($booking))); ?></span><br>
                                            <small class="text-muted">Creato il <?php echo sanitize_output(format_datetime_locale((string) ($booking['created_at'] ?? ''))); ?></small>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo sanitize_output(trim(($booking['cittadino_cognome'] ?? '') . ' ' . ($booking['cittadino_nome'] ?? ''))); ?></span><br>
                                            <?php if (!empty($booking['cittadino_cf'])): ?>
                                                <small class="text-muted">CF: <?php echo sanitize_output($booking['cittadino_cf']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo sanitize_output((string) ($booking['comune_richiesta'] ?? '')); ?><br>
                                            <?php if (!empty($booking['disponibilita_data'])): ?>
                                                <small class="text-muted">Preferenza: <?php echo sanitize_output(format_date_locale($booking['disponibilita_data'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['disponibilita_data'])): ?>
                                                <?php echo sanitize_output(format_date_locale($booking['disponibilita_data'])); ?><br>
                                                <small class="text-muted"><?php echo sanitize_output((string) ($booking['disponibilita_fascia'] ?? '')); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['appuntamento_data'])): ?>
                                                <span class="text-warning fw-semibold"><?php echo sanitize_output(format_date_locale($booking['appuntamento_data'])); ?></span><br>
                                                <small class="text-muted"><?php echo sanitize_output((string) ($booking['appuntamento_orario'] ?? '')); ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">In attesa</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo sanitize_output(cie_status_badge((string) $booking['stato'])); ?>"><?php echo sanitize_output(cie_status_label((string) $booking['stato'])); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['created_by_username'])): ?>
                                                <small class="text-muted">Creato da <?php echo sanitize_output((string) $booking['created_by_username']); ?></small><br>
                                            <?php endif; ?>
                                            <?php if (!empty($booking['updated_by_username'])): ?>
                                                <small class="text-muted">Ultimo aggiornamento di <?php echo sanitize_output((string) $booking['updated_by_username']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap" role="group">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(cie_module_url('view', ['id' => (int) $booking['id']])); ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(cie_module_url('edit', ['id' => (int) $booking['id']])); ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(cie_module_url('open_portal', ['id' => (int) $booking['id']])); ?>" title="Apri portale" target="_blank">
                                                    <i class="fa-solid fa-up-right-from-square"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-danger btn-sm" href="<?php echo sanitize_output(cie_module_url('delete', ['id' => (int) $booking['id']])); ?>" title="Elimina">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
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
