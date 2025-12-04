<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Office Suite';
$csrfToken = csrf_token();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100 office-suite">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="office-hero card border-0 shadow-sm mb-4">
            <div class="card-body d-flex flex-column flex-lg-row gap-4 align-items-start align-items-lg-center justify-content-between">
                <div class="hero-copy">
                    <p class="text-uppercase fw-semibold small mb-2 text-white-50">AG Productivity</p>
                    <h1 class="h3 mb-3 text-white">Suite documentale completa</h1>
                    <p class="mb-0 text-white-75">Editor testuale avanzato e fogli di calcolo intelligenti, sincronizzati con il gestionale e pronti alla collaborazione estesa.</p>
                </div>
                <div class="hero-actions d-flex flex-wrap gap-2">
                    <a class="btn btn-light" href="<?php echo asset('modules/office-suite/documents/editor.php'); ?>">
                        <i class="fa-solid fa-file-pen me-2"></i>Nuovo documento
                    </a>
                    <a class="btn btn-light" href="<?php echo asset('modules/office-suite/spreadsheets/editor.php'); ?>">
                        <i class="fa-solid fa-table-columns me-2"></i>Nuovo foglio
                    </a>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <p class="text-uppercase small fw-semibold text-muted mb-1">Word-style</p>
                                <h2 class="h5 mb-0">Documenti smart</h2>
                            </div>
                            <span class="badge bg-primary-subtle text-primary">Beta</span>
                        </div>
                        <p class="text-muted">Stili tipografici, commenti inline, cronologia versioni e template dedicati per contratti, lettere e comunicazioni ufficiali.</p>
                        <ul class="text-muted small mb-4">
                            <li>Editor rich text (TipTap/Ckeditor) con controlli ribbon personalizzati.</li>
                            <li>Esportazione DOCX/PDF tramite pipeline server.</li>
                            <li>Check di conformita' e note interne collegate al CRM.</li>
                        </ul>
                        <div class="mt-auto d-flex justify-content-between align-items-center">
                            <a class="btn btn-outline-primary" href="<?php echo asset('modules/office-suite/documents/index.php'); ?>">
                                Apri raccolta documenti
                            </a>
                            <span class="text-muted small">Realtime e versioning in arrivo</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <p class="text-uppercase small fw-semibold text-muted mb-1">Excel-style</p>
                                <h2 class="h5 mb-0">Fogli dinamici</h2>
                            </div>
                            <span class="badge bg-success-subtle text-success">Preview</span>
                        </div>
                        <p class="text-muted">Dashboard numeriche, piani economici, liste KPI e riconciliazioni: tutto in un foglio reattivo collegato ai dati aziendali.</p>
                        <ul class="text-muted small mb-4">
                            <li>Formule compatibili Excel, filtri, formattazioni condizionate.</li>
                            <li>Import/export XLSX tramite PhpSpreadsheet.</li>
                            <li>Widget pronti per scenari finanziari, ordini e inventario.</li>
                        </ul>
                        <div class="mt-auto d-flex justify-content-between align-items-center">
                            <a class="btn btn-outline-success" href="<?php echo asset('modules/office-suite/spreadsheets/index.php'); ?>">
                                Apri fogli condivisi
                            </a>
                            <span class="text-muted small">Data-link e formule custom in sviluppo</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                    <div>
                        <p class="text-uppercase small fw-semibold text-muted mb-1">Roadmap</p>
                        <h2 class="h5 mb-0">Timeline funzioni principali</h2>
                    </div>
                    <span class="badge bg-dark text-white">Aggiornata <?php echo date('d/m/Y'); ?></span>
                </div>
                <div class="row g-3 roadmap">
                    <div class="col-md-4">
                        <div class="roadmap-step p-3 rounded-3 h-100">
                            <p class="small text-muted mb-1">Fase 1</p>
                            <h3 class="h6 mb-2">Editor fondamentali</h3>
                            <p class="small text-muted mb-0">Toolbar personalizzate, librerie front-end installate e storage JSON per documenti e fogli.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="roadmap-step p-3 rounded-3 h-100">
                            <p class="small text-muted mb-1">Fase 2</p>
                            <h3 class="h6 mb-2">Export & integrazioni</h3>
                            <p class="small text-muted mb-0">Pipeline PDF/DOCX/XLSX, template dinamici e collegamenti ai moduli pratiche.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="roadmap-step p-3 rounded-3 h-100">
                            <p class="small text-muted mb-1">Fase 3</p>
                            <h3 class="h6 mb-2">Collaborazione avanzata</h3>
                            <p class="small text-muted mb-0">Editing realtime, commenti contestuali, ACL granulari e automazioni su webhook.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-start">
                    <div>
                        <h2 class="h5 mb-2">Perche' costruire ora?</h2>
                        <p class="text-muted mb-0">Centralizziamo la produzione documentale: niente piu' allegati sparsi, versioni perse o template datati. Le pratiche rimangono coerenti e verificabili.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary" href="<?php echo asset('modules/office-suite/documents/editor.php'); ?>">
                            Inizia dal documento
                        </a>
                        <a class="btn btn-success" href="<?php echo asset('modules/office-suite/spreadsheets/editor.php'); ?>">
                            Crea un foglio pilota
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<style>
    .office-suite .office-hero {
        background: linear-gradient(135deg, #111c44, #233876);
        color: #fff;
    }
    .office-suite .roadmap-step {
        background: #f5f6fb;
        border: 1px solid rgba(17,28,68,0.08);
    }
</style>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
