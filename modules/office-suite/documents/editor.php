<?php
declare(strict_types=1);

use App\Services\OfficeSuite\DocumentService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Editor documento';
$csrfToken = csrf_token();
$documentService = new DocumentService($pdo);
$documentId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$statusOptions = [
    'draft' => 'Bozza',
    'review' => 'In revisione',
    'published' => 'Pubblicato',
    'archived' => 'Archiviato',
];

$categoryOptions = ['Template', 'Comunicazioni', 'Contratti', 'Operativo'];

$formError = null;
$document = null;
$latestRevision = null;
$formData = [
    'id' => $documentId,
    'title' => '',
    'category' => 'Template',
    'status' => 'draft',
    'tags' => '',
    'notes' => '',
    'cliente_id' => '',
    'content' => '',
];

if ($documentId > 0) {
    $document = $documentService->getDocument($documentId);
    if ($document) {
        $latestRevision = $documentService->getLatestRevision($documentId);
        $formData['title'] = (string) ($document['titolo'] ?? '');
        $formData['category'] = (string) ($document['categoria'] ?? 'Template');
        $formData['status'] = (string) ($document['stato'] ?? 'draft');
        $formData['notes'] = (string) ($document['notes'] ?? '');
        $formData['cliente_id'] = $document['cliente_id'] ? (string) $document['cliente_id'] : '';
        $formData['tags'] = $document['tags'] ? implode(', ', (array) $document['tags']) : '';
        $formData['content'] = $latestRevision['contenuto'] ?? '';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    if (isset($_POST['revert_revision_id']) && $documentId > 0) {
        $revisionId = (int) $_POST['revert_revision_id'];
        try {
            $documentService->revertToRevision($documentId, $revisionId, $userId);
            add_flash('success', 'Versione ripristinata correttamente.');
            header('Location: editor.php?id=' . $documentId);
            exit;
        } catch (RuntimeException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Office document revert error: ' . $exception->getMessage());
            $formError = 'Impossibile ripristinare la versione selezionata.';
        }
    } else {
        $clientInput = $_POST['cliente_id'] ?? null;
        $payload = [
            'id' => isset($_POST['document_id']) && $_POST['document_id'] !== '' ? (int) $_POST['document_id'] : null,
            'title' => $_POST['title'] ?? '',
            'category' => $_POST['category'] ?? 'Template',
            'status' => $_POST['status'] ?? 'draft',
            'tags' => $_POST['tags'] ?? '',
            'notes' => $_POST['notes'] ?? '',
            'content' => $_POST['content'] ?? '',
            'cliente_id' => $clientInput === '' ? null : $clientInput,
            'owner_id' => $userId,
        ];

        $formData = [
            'id' => $payload['id'] ?? 0,
            'title' => $payload['title'],
            'category' => $payload['category'],
            'status' => $payload['status'],
            'tags' => is_array($payload['tags']) ? implode(', ', $payload['tags']) : (string) $payload['tags'],
            'notes' => $payload['notes'],
            'cliente_id' => $payload['cliente_id'] !== null ? (string) $payload['cliente_id'] : '',
            'content' => $payload['content'],
        ];

        try {
            $saved = $documentService->saveDocument($payload, $userId);
            add_flash('success', 'Documento salvato correttamente.');
            header('Location: editor.php?id=' . (int) $saved['id']);
            exit;
        } catch (RuntimeException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Office document save error: ' . $exception->getMessage());
            $formError = 'Errore inatteso durante il salvataggio. Riprovare.';
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <form class="d-flex flex-column gap-4" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <input type="hidden" name="document_id" value="<?php echo (int) $formData['id']; ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <h1 class="h4 mb-1"><?php echo $formData['id'] ? 'Modifica documento' : 'Nuovo documento'; ?></h1>
                    <p class="text-muted mb-0">Shell editor stile Word con persistenza Office Suite.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary" href="<?php echo asset('modules/office-suite/documents/index.php'); ?>">
                        <i class="fa-solid fa-arrow-left me-2"></i>Ritorna alla lista
                    </a>
                    <button class="btn btn-outline-secondary" type="button" disabled>
                        <i class="fa-solid fa-clock-rotate-left me-2"></i>Versioni
                    </button>
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Salva bozza
                    </button>
                </div>
            </div>

            <?php if ($formError !== null): ?>
                <div class="alert alert-warning mb-0" role="alert"><?php echo sanitize_output($formError); ?></div>
            <?php endif; ?>

            <div class="office-editor card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <p class="small text-uppercase fw-semibold text-muted mb-1">Barra stile Word</p>
                            <p class="mb-0 text-muted">La barra completa è integrata direttamente nell'editor con menu File, Inserisci, Layout e Revisione.</p>
                        </div>
                        <span class="badge bg-primary text-uppercase">Editor avanzato</span>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="editor-layout d-flex flex-column flex-lg-row">
                        <aside class="editor-sidebar text-muted small">
                            <div>
                                <p class="text-uppercase fw-semibold mb-1">Metadati</p>
                                <label class="form-label mb-1" for="doc-title">Titolo</label>
                                <input id="doc-title" class="form-control form-control-sm mb-3" type="text" name="title" value="<?php echo sanitize_output($formData['title']); ?>" placeholder="Es. Contratto quadro" required>
                                <label class="form-label mb-1" for="doc-category">Categoria</label>
                                <select id="doc-category" class="form-select form-select-sm mb-3" name="category">
                                    <?php foreach ($categoryOptions as $option): ?>
                                        <option value="<?php echo sanitize_output($option); ?>" <?php echo $option === $formData['category'] ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="form-label mb-1" for="doc-status">Stato</label>
                                <select id="doc-status" class="form-select form-select-sm mb-3" name="status">
                                    <?php foreach ($statusOptions as $value => $label): ?>
                                        <option value="<?php echo sanitize_output($value); ?>" <?php echo $value === $formData['status'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="form-label mb-1" for="doc-tags">Tag</label>
                                <input id="doc-tags" class="form-control form-control-sm" type="text" name="tags" value="<?php echo sanitize_output($formData['tags']); ?>" placeholder="crm, onboarding">
                                <label class="form-label mb-1 mt-3" for="doc-client">Cliente collegato (ID)</label>
                                <input id="doc-client" class="form-control form-control-sm" type="number" name="cliente_id" value="<?php echo sanitize_output($formData['cliente_id']); ?>" min="0" placeholder="Facoltativo">
                            </div>
                            <hr>
                            <div>
                                <p class="text-uppercase fw-semibold mb-1">Revisione</p>
                                <?php if ($latestRevision): ?>
                                    <p class="mb-2">Versione <?php echo (int) $latestRevision['versione']; ?> aggiornata il <?php echo sanitize_output(format_datetime_locale($latestRevision['created_at'] ?? null)); ?>.</p>
                                <?php else: ?>
                                    <p class="mb-2">Bozza locale in attesa del primo salvataggio.</p>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-primary w-100" type="button" disabled>Workflow revisione</button>
                                <?php if (!empty($document) && !empty($document['revisions']) && $documentId > 0): ?>
                                    <hr>
                                    <p class="text-uppercase fw-semibold mb-1">Versioni salvate</p>
                                    <div class="revision-history">
                                        <?php foreach ($document['revisions'] as $revision): ?>
                                            <div class="revision-entry mb-2 p-2 border rounded small">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <strong>v<?php echo (int) ($revision['versione'] ?? 0); ?></strong>
                                                        <span class="text-muted">· <?php echo sanitize_output(format_datetime_locale($revision['created_at'] ?? null)); ?></span>
                                                        <?php if (!empty($revision['commento'])): ?>
                                                            <div class="text-muted"><?php echo sanitize_output($revision['commento']); ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="revert_revision_id" value="<?php echo (int) ($revision['id'] ?? 0); ?>" onclick="return confirm('Ripristinare la versione selezionata?');">Ripristina</button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </aside>
                        <section class="editor-canvas flex-grow-1">
                            <div class="canvas-toolbar small text-muted d-flex justify-content-between align-items-center">
                                <div>
                                    <span><i class="fa-solid fa-paragraph me-2"></i>Paragrafo principale</span>
                                </div>
                                <div>
                                    <span class="me-3">Versione attuale: <strong><?php echo (int) ($document['current_version'] ?? 0); ?></strong></span>
                                    <span><?php echo $formData['status'] === 'published' ? 'Pubblicato' : 'Modalità bozza'; ?></span>
                                </div>
                            </div>
                            <div class="canvas-body">
                                <textarea id="document-content" class="form-control border-0 shadow-none office-textarea" name="content" rows="18" placeholder="Scrivi o incolla il testo del documento" data-placeholder="Scrivi o incolla il testo del documento" required><?php echo $formData['content'] ?? ''; ?></textarea>
                            </div>
                        </section>
                        <aside class="editor-notes text-muted small">
                            <p class="text-uppercase fw-semibold mb-1">Note interne</p>
                            <textarea class="form-control" rows="6" name="notes" placeholder="Annotazioni per gli operatori"><?php echo sanitize_output($formData['notes']); ?></textarea>
                            <hr>
                            <p class="text-uppercase fw-semibold mb-1">Checklist</p>
                            <ul class="ps-3 mb-0">
                                <li>Verifica tag CRM e placeholder.</li>
                                <li>Allinea loghi e riferimenti.</li>
                                <li>Controlla lingua e allegati.</li>
                            </ul>
                        </aside>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
<script src="https://cdn.tiny.cloud/1/4t5ejej45xh1r2c9zhuz1z2p1tqzjmg8htmjfp5xwox91534/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const selector = '#document-content';
        const form = document.querySelector('form');
        const textarea = document.querySelector(selector);

        if (typeof tinymce === 'undefined') {
            console.error('TinyMCE non disponibile.');
            return;
        }

        if (!textarea) {
            return;
        }

        const placeholderText = textarea.dataset.placeholder || textarea.getAttribute('placeholder') || '';

        tinymce.init({
            selector: selector,
            height: 640,
            language: 'it',
            language_url: 'https://cdn.tiny.cloud/1/4t5ejej45xh1r2c9zhuz1z2p1tqzjmg8htmjfp5xwox91534/tinymce/6/langs/it.js',
            menubar: 'file edit view insert format tools table help',
            toolbar_mode: 'wrap',
            toolbar_sticky: true,
            branding: false,
            promotion: false,
            placeholder: placeholderText,
            plugins: 'advlist anchor autolink charmap code codesample directionality emoticons fullscreen help hr image insertdatetime link lists media nonbreaking pagebreak placeholder preview quickbars searchreplace table visualblocks visualchars wordcount',
            toolbar: 'undo redo | formatselect fontselect fontsizeselect | bold italic underline strikethrough forecolor backcolor removeformat | alignleft aligncenter alignright alignjustify | outdent indent | numlist bullist | subscript superscript | table image media link anchor | insertdatetime charmap emoticons hr pagebreak codesample | blockquote | fullscreen preview print | searchreplace | visualblocks visualchars code | help',
            quickbars_selection_toolbar: 'bold italic underline | forecolor backcolor | link blockquote',
            image_title: true,
            automatic_uploads: false,
            file_picker_types: 'image',
            contextmenu: 'link table lists',
            convert_urls: false,
            content_style: 'body { font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif; font-size: 16px; line-height: 1.6; padding: 1.5rem; }',
            block_formats: 'Paragrafo=p; Titolo 1=h2; Titolo 2=h3; Titolo 3=h4; Citazione=blockquote; Codice=pre',
            setup: function (editor) {
                editor.on('change', function () {
                    editor.save();
                });
            }
        });

        if (form) {
            form.addEventListener('submit', function () {
                if (typeof tinymce !== 'undefined') {
                    tinymce.triggerSave();
                }
            });
        }
    });
</script>
<style>
    .editor-layout {
        min-height: 640px;
    }
    .editor-sidebar,
    .editor-notes {
        width: 260px;
        padding: 1.5rem;
        background: #f8f9fc;
        border-right: 1px solid rgba(15,23,42,0.08);
    }
    .editor-notes {
        border-right: 0;
        border-left: 1px solid rgba(15,23,42,0.08);
    }
    .editor-canvas {
        background: #fff;
        display: flex;
        flex-direction: column;
    }
    .canvas-toolbar {
        padding: 0.75rem 1.25rem;
        border-bottom: 1px solid rgba(15,23,42,0.08);
        background: #fdfdff;
    }
    .canvas-body {
        flex-grow: 1;
        padding: 0;
        background: #fff;
    }
    .office-textarea {
        width: 100%;
        height: 100%;
        resize: none;
        padding: 2rem 3rem;
        font-size: 1rem;
        line-height: 1.6;
        background: transparent;
    }
    @media (max-width: 991px) {
        .editor-sidebar,
        .editor-notes {
            width: 100%;
            border-right: 0;
            border-left: 0;
        }
    }
</style>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
