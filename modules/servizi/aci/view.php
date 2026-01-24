<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');
$pageTitle = 'Dettaglio pratica ACI';

$praticaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = aci_get_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

$attachments = aci_get_attachments($pdo, $praticaId);
$categorieLabels = [
    'documento_identita' => 'Documento identità intestatario',
    'tessera_sanitaria' => 'Tessera sanitaria',
    'carta_circolazione' => 'Carta di circolazione',
    'certificato_proprieta' => 'Certificato di proprietà (CDP)',
    'atto_vendita' => 'Atto di vendita',
    'delega' => 'Delega firmata',
    'visura_pra' => 'Visura PRA',
    'generico' => 'Allegato',
];

$clienteLabelParts = array_filter([
    $pratica['ragione_sociale'] ?? null,
    trim(($pratica['cognome'] ?? '') . ' ' . ($pratica['nome'] ?? '')) ?: null,
]);
$clienteLabel = $clienteLabelParts ? implode(' - ', $clienteLabelParts) : null;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Pratica ACI #<?php echo (int) $pratica['id']; ?></h1>
                <p class="text-muted mb-0">Dettaglio pratica automobilistica.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
                <?php if (current_user_can('Admin', 'Operatore', 'Manager')): ?>
                    <a class="btn btn-warning text-dark" href="edit.php?id=<?php echo (int) $pratica['id']; ?>"><i class="fa-solid fa-pen me-2"></i>Modifica</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted small">Tipo pratica</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['tipo_pratica']); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Stato</div>
                        <span class="badge bg-secondary"><?php echo sanitize_output($pratica['stato']); ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Cliente</div>
                        <div class="fw-semibold"><?php echo sanitize_output($clienteLabel ?? '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Targa</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['targa'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Telaio</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['telaio'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Intestatario</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['intestatario'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Protocollo</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['protocollo'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data apertura</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_date_locale($pratica['data_apertura'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data scadenza</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_date_locale($pratica['data_scadenza'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data chiusura</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_date_locale($pratica['data_chiusura'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Costo</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_currency((float) ($pratica['totale'] ?? $pratica['costo'] ?? 0))); ?></div>
                    </div>
                </div>

                <?php if (!empty($pratica['note'])): ?>
                    <div class="mt-4">
                        <div class="text-muted small">Note</div>
                        <div class="border rounded-3 p-3 bg-body-tertiary">
                            <?php echo nl2br(sanitize_output((string) $pratica['note'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dati intestatario</h2>
            </div>
            <div class="card-body">
                <?php if ((int) ($pratica['persona_giuridica'] ?? 0) === 1): ?>
                    <div class="row g-3">
                        <div class="col-md-6"><strong>Ragione sociale:</strong> <?php echo sanitize_output($pratica['intestatario_ragione_sociale'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Partita IVA:</strong> <?php echo sanitize_output($pratica['intestatario_partita_iva'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Codice fiscale:</strong> <?php echo sanitize_output($pratica['intestatario_codice_fiscale_giuridico'] ?? '—'); ?></div>
                        <div class="col-md-12"><strong>Sede legale:</strong> <?php echo sanitize_output($pratica['intestatario_sede_legale'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Email:</strong> <?php echo sanitize_output($pratica['intestatario_email'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Telefono:</strong> <?php echo sanitize_output($pratica['intestatario_telefono'] ?? '—'); ?></div>
                    </div>
                <?php else: ?>
                    <div class="row g-3">
                        <div class="col-md-6"><strong>Nome:</strong> <?php echo sanitize_output($pratica['intestatario_nome'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Cognome:</strong> <?php echo sanitize_output($pratica['intestatario_cognome'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Codice fiscale:</strong> <?php echo sanitize_output($pratica['intestatario_codice_fiscale'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Data nascita:</strong> <?php echo sanitize_output(format_date_locale($pratica['intestatario_data_nascita'] ?? null)); ?></div>
                        <div class="col-md-6"><strong>Luogo nascita:</strong> <?php echo sanitize_output($pratica['intestatario_luogo_nascita'] ?? '—'); ?></div>
                        <div class="col-md-12"><strong>Residenza:</strong> <?php echo sanitize_output($pratica['intestatario_residenza'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Email:</strong> <?php echo sanitize_output($pratica['intestatario_email'] ?? '—'); ?></div>
                        <div class="col-md-6"><strong>Telefono:</strong> <?php echo sanitize_output($pratica['intestatario_telefono'] ?? '—'); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dati veicolo</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><strong>Targa:</strong> <?php echo sanitize_output($pratica['targa'] ?: '—'); ?></div>
                    <div class="col-md-3"><strong>Tipo veicolo:</strong> <?php echo sanitize_output($pratica['veicolo_tipo'] ?? '—'); ?></div>
                    <div class="col-md-3"><strong>Marca:</strong> <?php echo sanitize_output($pratica['veicolo_marca'] ?? '—'); ?></div>
                    <div class="col-md-3"><strong>Modello:</strong> <?php echo sanitize_output($pratica['veicolo_modello'] ?? '—'); ?></div>
                    <div class="col-md-3"><strong>Anno immatr.:</strong> <?php echo sanitize_output($pratica['veicolo_anno_immatricolazione'] ?? '—'); ?></div>
                    <div class="col-md-3"><strong>Telaio:</strong> <?php echo sanitize_output($pratica['telaio'] ?: '—'); ?></div>
                    <div class="col-md-3"><strong>Alimentazione:</strong> <?php echo sanitize_output($pratica['veicolo_alimentazione'] ?? '—'); ?></div>
                    <div class="col-md-3"><strong>Potenza (kW):</strong> <?php echo sanitize_output($pratica['veicolo_potenza_kw'] ?? '—'); ?></div>
                    <div class="col-md-3"><strong>Classe ambientale:</strong> <?php echo sanitize_output($pratica['veicolo_classe_ambientale'] ?? '—'); ?></div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Venditore / Acquirente</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><strong>Venditore:</strong> <?php echo sanitize_output($pratica['venditore_nome'] ?? '—'); ?></div>
                    <div class="col-md-6"><strong>Codice fiscale / P.IVA:</strong> <?php echo sanitize_output($pratica['venditore_codice_fiscale'] ?? '—'); ?></div>
                    <div class="col-md-12"><strong>Indirizzo venditore:</strong> <?php echo sanitize_output($pratica['venditore_indirizzo'] ?? '—'); ?></div>
                    <div class="col-md-6"><strong>Acquirente:</strong> <?php echo sanitize_output($pratica['acquirente_nome'] ?? '—'); ?></div>
                    <div class="col-md-6"><strong>Codice fiscale / P.IVA:</strong> <?php echo sanitize_output($pratica['acquirente_codice_fiscale'] ?? '—'); ?></div>
                    <div class="col-md-12"><strong>Indirizzo acquirente:</strong> <?php echo sanitize_output($pratica['acquirente_indirizzo'] ?? '—'); ?></div>
                    <div class="col-md-12"><strong>Coincidono:</strong> <?php echo ((int) ($pratica['venditore_acquirente_coincidono'] ?? 0) === 1) ? 'Sì' : 'No'; ?></div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Costi e pagamenti</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><strong>Diritti ACI:</strong> <?php echo sanitize_output(format_currency((float) ($pratica['diritti_aci'] ?? 0))); ?></div>
                    <div class="col-md-3"><strong>Imposta di bollo:</strong> <?php echo sanitize_output(format_currency((float) ($pratica['imposta_bollo'] ?? 0))); ?></div>
                    <div class="col-md-3"><strong>Emolumenti:</strong> <?php echo sanitize_output(format_currency((float) ($pratica['emolumenti'] ?? 0))); ?></div>
                    <div class="col-md-3"><strong>Compenso agenzia:</strong> <?php echo sanitize_output(format_currency((float) ($pratica['compenso_agenzia'] ?? 0))); ?></div>
                    <div class="col-md-3"><strong>Totale:</strong> <?php echo sanitize_output(format_currency((float) ($pratica['totale'] ?? 0))); ?></div>
                    <div class="col-md-3"><strong>Metodo pagamento:</strong> <?php echo sanitize_output($pratica['metodo_pagamento'] ?? '—'); ?></div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Consensi</h2>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li>Privacy: <strong><?php echo !empty($pratica['consenso_privacy']) ? 'Sì' : 'No'; ?></strong></li>
                    <li>Autorizzazione ACI: <strong><?php echo !empty($pratica['consenso_aci']) ? 'Sì' : 'No'; ?></strong></li>
                    <li>Veridicità dati: <strong><?php echo !empty($pratica['consenso_veridicita']) ? 'Sì' : 'No'; ?></strong></li>
                </ul>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Allegati</h2>
                <span class="badge ag-badge"><?php echo count($attachments); ?></span>
            </div>
            <div class="card-body">
                <?php if (!$attachments): ?>
                    <p class="text-muted mb-0">Nessun allegato disponibile.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?php echo sanitize_output($categorieLabels[$attachment['categoria'] ?? 'generico'] ?? 'Allegato'); ?></div>
                                    <small class="text-muted d-block"><?php echo sanitize_output($attachment['file_name'] ?? ''); ?></small>
                                    <small class="text-muted"><?php echo sanitize_output($attachment['mime_type'] ?? ''); ?> · <?php echo number_format((int) ($attachment['file_size'] ?? 0) / 1024, 1); ?> KB</small>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?php echo (int) $attachment['id']; ?>"><i class="fa-solid fa-download me-1"></i>Scarica</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
