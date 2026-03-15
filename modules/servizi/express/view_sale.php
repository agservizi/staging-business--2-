<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

express_module_require_access($pdo, (int) ($_SESSION['user_id'] ?? 0));

$pageTitle = 'Dettaglio vendita Express';

express_module_bootstrap_schema($pdo);

$saleId = (int) ($_GET['id'] ?? 0);
$showDocument = (int) ($_GET['show_document'] ?? 0) === 1;
$autoPrintDocument = (int) ($_GET['autoprint'] ?? 0) === 1;
$adobeEmbedApiKey = trim((string) env('ADOBE_EMBED_API_KEY', ''));
$salePdfUrl = 'sale_pdf?id=' . $saleId;
$sale = $saleId > 0 ? express_module_sale_detail($pdo, $saleId) : null;

if ($sale === null) {
    add_flash('warning', 'Vendita non trovata.');
    header('Location: ' . express_module_url('sales'));
    exit;
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('sales'); ?>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h5 class="card-title mb-0">Vendita #<?php echo (int) $sale['id']; ?></h5>
                    <p class="text-muted mb-0"><?php echo sanitize_output(format_datetime_locale((string) ($sale['data_vendita'] ?? ''))); ?></p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(express_module_url('sales')); ?>">Torna all'elenco</a>
                    <button class="btn btn-warning" type="button" data-bs-toggle="modal" data-bs-target="#saleDocumentModal"><i class="fa-solid fa-print me-2"></i>Stampa documento</button>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="text-muted text-uppercase small">Cliente</div>
                        <div class="fw-semibold"><?php echo sanitize_output(express_module_sale_customer_label($sale)); ?></div>
                        <div class="small text-muted"><?php echo sanitize_output((string) (($sale['email'] ?? '') !== '' ? $sale['email'] : 'Vendita senza anagrafica cliente')); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted text-uppercase small">Metodo pagamento</div>
                        <div class="fw-semibold"><?php echo sanitize_output((string) ($sale['metodo_pagamento'] ?? '')); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted text-uppercase small">Operatore</div>
                        <div class="fw-semibold"><?php echo sanitize_output(trim((string) (($sale['user_nome'] ?? '') . ' ' . ($sale['user_cognome'] ?? '')))); ?></div>
                    </div>
                    <div class="col-md-2">
                        <div class="text-muted text-uppercase small">Totale</div>
                        <div class="fw-semibold text-success">&euro; <?php echo number_format((float) ($sale['totale'] ?? 0), 2, ',', '.'); ?></div>
                    </div>
                </div>

                <div class="table-responsive mb-4">
                    <table class="table table-dark table-hover align-middle mb-0" data-datatable="true" data-page-length="10">
                        <thead>
                            <tr>
                                <th>Tipo</th>
                                <th>Descrizione</th>
                                <th>Operatore</th>
                                <th>ICCID</th>
                                <th class="text-end">Q.tà</th>
                                <th class="text-end">Prezzo</th>
                                <th class="text-end">Totale</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (($sale['items'] ?? []) as $item): ?>
                                <tr>
                                    <td><?php echo sanitize_output((string) ($item['tipo'] ?? '')); ?></td>
                                    <td><?php echo sanitize_output((string) ($item['descrizione'] ?? '')); ?></td>
                                    <td><?php echo sanitize_output((string) (($item['operatore'] ?? '') !== '' ? $item['operatore'] : '—')); ?></td>
                                    <td><?php echo sanitize_output((string) (($item['iccid'] ?? '') !== '' ? $item['iccid'] : '—')); ?></td>
                                    <td class="text-end"><?php echo (int) ($item['quantita'] ?? 0); ?></td>
                                    <td class="text-end">&euro; <?php echo number_format((float) ($item['prezzo_unitario'] ?? 0), 2, ',', '.'); ?></td>
                                    <td class="text-end fw-semibold">&euro; <?php echo number_format((float) ($item['totale_riga'] ?? 0), 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($sale['note'])): ?>
                    <div>
                        <div class="text-muted text-uppercase small">Note</div>
                        <div><?php echo nl2br(sanitize_output((string) $sale['note'])); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<div class="modal fade" id="saleDocumentModal" tabindex="-1" aria-labelledby="saleDocumentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="saleDocumentModalLabel">Documento gestionale #<?php echo (int) $sale['id']; ?></h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body p-0 bg-body-tertiary" style="min-height: 75vh;">
                <div class="border-bottom px-3 py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="small text-muted" id="salePdfStatus">Caricamento documento...</div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="salePdfPrevPage">Precedente</button>
                        <span class="small text-muted" id="salePdfPageIndicator">Pagina 1 / 1</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="salePdfNextPage">Successiva</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="salePdfZoomOut">-</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="salePdfZoomIn">+</button>
                    </div>
                </div>
                <div id="salePdfViewer" class="d-flex justify-content-center align-items-start overflow-auto" style="height: calc(75vh - 49px); padding: 1rem;">
                    <canvas id="salePdfCanvas" style="max-width: 100%; height: auto; box-shadow: 0 14px 40px rgba(15, 23, 42, 0.16); background: #fff;"></canvas>
                </div>
                <iframe
                    id="saleDocumentFrame"
                    src="<?php echo sanitize_output($salePdfUrl); ?>"
                    title="Documento gestionale PDF"
                    style="width: 0; height: 0; border: 0; position: absolute; opacity: 0; pointer-events: none;"
                ></iframe>
            </div>
            <div class="modal-footer">
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($salePdfUrl); ?>" target="_blank" rel="noopener">Apri PDF</a>
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(express_module_url('print_sale', ['id' => (int) $sale['id']])); ?>" target="_blank" rel="noopener">Versione termica</a>
                <button type="button" class="btn btn-warning" id="printSaleDocumentButton">Stampa</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" referrerpolicy="no-referrer"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('saleDocumentModal');
    const iframeElement = document.getElementById('saleDocumentFrame');
    const statusElement = document.getElementById('salePdfStatus');
    const canvasElement = document.getElementById('salePdfCanvas');
    const pageIndicatorElement = document.getElementById('salePdfPageIndicator');
    const prevPageButton = document.getElementById('salePdfPrevPage');
    const nextPageButton = document.getElementById('salePdfNextPage');
    const zoomOutButton = document.getElementById('salePdfZoomOut');
    const zoomInButton = document.getElementById('salePdfZoomIn');
    const printButton = document.getElementById('printSaleDocumentButton');
    if (!modalElement || !iframeElement || !printButton || !canvasElement || typeof bootstrap === 'undefined' || !bootstrap.Modal) {
        return;
    }

    const saleDocumentModal = bootstrap.Modal.getOrCreateInstance(modalElement);
    const pdfUrl = <?php echo json_encode(base_url('modules/servizi/express/' . $salePdfUrl), JSON_UNESCAPED_SLASHES); ?>;
    const canvasContext = canvasElement.getContext('2d');
    let pdfDocument = null;
    let currentPage = 1;
    let currentScale = 1.25;
    let isRendering = false;

    const updateControls = () => {
        const totalPages = pdfDocument ? pdfDocument.numPages : 1;
        if (pageIndicatorElement) {
            pageIndicatorElement.textContent = 'Pagina ' + currentPage + ' / ' + totalPages;
        }
        if (prevPageButton) {
            prevPageButton.disabled = currentPage <= 1 || isRendering;
        }
        if (nextPageButton) {
            nextPageButton.disabled = !pdfDocument || currentPage >= totalPages || isRendering;
        }
        if (zoomOutButton) {
            zoomOutButton.disabled = isRendering || currentScale <= 0.6;
        }
        if (zoomInButton) {
            zoomInButton.disabled = isRendering || currentScale >= 2.2;
        }
    };

    const setStatus = (message, isError = false) => {
        if (!statusElement) {
            return;
        }
        statusElement.textContent = message;
        statusElement.classList.toggle('text-danger', isError);
    };

    const renderPage = (pageNumber) => {
        if (!pdfDocument || isRendering) {
            return;
        }

        isRendering = true;
        updateControls();
        setStatus('Rendering pagina ' + pageNumber + '...');

        pdfDocument.getPage(pageNumber).then(function (page) {
            const viewport = page.getViewport({ scale: currentScale });
            const outputScale = window.devicePixelRatio || 1;

            canvasElement.width = Math.floor(viewport.width * outputScale);
            canvasElement.height = Math.floor(viewport.height * outputScale);
            canvasElement.style.width = Math.floor(viewport.width) + 'px';
            canvasElement.style.height = Math.floor(viewport.height) + 'px';

            const renderContext = {
                canvasContext: canvasContext,
                viewport: viewport,
                transform: outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null
            };

            return page.render(renderContext).promise;
        }).then(function () {
            isRendering = false;
            updateControls();
            setStatus('Documento pronto');
        }).catch(function () {
            isRendering = false;
            updateControls();
            setStatus('Impossibile visualizzare il PDF nella modale.', true);
        });
    };

    const loadPdf = () => {
        if (pdfDocument || typeof pdfjsLib === 'undefined') {
            if (typeof pdfjsLib === 'undefined') {
                setStatus('Viewer PDF non disponibile.', true);
            }
            return;
        }

        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        setStatus('Caricamento documento...');

        pdfjsLib.getDocument(pdfUrl).promise.then(function (loadedPdf) {
            pdfDocument = loadedPdf;
            currentPage = 1;
            updateControls();
            renderPage(currentPage);
        }).catch(function () {
            setStatus('Impossibile caricare il PDF.', true);
        });
    };

    printButton.addEventListener('click', function () {
        if (iframeElement.contentWindow) {
            iframeElement.contentWindow.focus();
            iframeElement.contentWindow.print();
        }
    });

    modalElement.addEventListener('shown.bs.modal', function () {
        loadPdf();
    });

    prevPageButton?.addEventListener('click', function () {
        if (!pdfDocument || currentPage <= 1 || isRendering) {
            return;
        }
        currentPage -= 1;
        updateControls();
        renderPage(currentPage);
    });

    nextPageButton?.addEventListener('click', function () {
        if (!pdfDocument || currentPage >= pdfDocument.numPages || isRendering) {
            return;
        }
        currentPage += 1;
        updateControls();
        renderPage(currentPage);
    });

    zoomOutButton?.addEventListener('click', function () {
        if (isRendering) {
            return;
        }
        currentScale = Math.max(0.6, currentScale - 0.15);
        renderPage(currentPage);
    });

    zoomInButton?.addEventListener('click', function () {
        if (isRendering) {
            return;
        }
        currentScale = Math.min(2.2, currentScale + 0.15);
        renderPage(currentPage);
    });

    <?php if ($showDocument): ?>
    saleDocumentModal.show();
    <?php endif; ?>

    <?php if ($showDocument && $autoPrintDocument): ?>
    iframeElement.addEventListener('load', function onLoad() {
        iframeElement.removeEventListener('load', onLoad);
        window.setTimeout(function () {
            if (iframeElement.contentWindow) {
                iframeElement.contentWindow.focus();
                iframeElement.contentWindow.print();
            }
        }, 150);
    });
    <?php endif; ?>

    updateControls();
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
