<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

use App\Services\Opportunities\PromotionLibraryService;

$library = new PromotionLibraryService();
$currentPath = isset($_GET['path']) ? (string) $_GET['path'] : '';
$errors = [];
try {
    $listing = $library->listContents($currentPath);
} catch (RuntimeException $exception) {
    $errors[] = $exception->getMessage();
    $listing = $library->listContents('');
}

$currentPath = $listing['current_path'];
$breadcrumbs = $listing['breadcrumbs'];
$directories = $listing['directories'];
$files = $listing['files'];
$previewableExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
$hasItems = $directories !== [] || $files !== [];
$backLink = $currentPath !== '' ? dirname($currentPath) : '';
if ($backLink === '.' || $backLink === '/') {
    $backLink = '';
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Materiale promo</p>
                <h1 class="h4 mb-0">File condivisi dal team</h1>
                <p class="text-muted mb-0">Scarica brochure e kit informativi per supportare la vendita delle opportunity.</p>
            </div>
            <a class="btn btn-primary" href="<?php echo sanitize_output(asset('modules/opportunities/collaborator/index.php')); ?>">
                <i class="fa-solid fa-sitemap me-2"></i>Dashboard OP
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <p class="text-uppercase small text-muted mb-1">Percorso</p>
                        <nav aria-label="Percorso cartelle">
                            <ol class="breadcrumb mb-0">
                                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                                    <?php $crumbUrl = 'promotions.php' . ($crumb['path'] !== '' ? '?path=' . urlencode($crumb['path']) : ''); ?>
                                    <li class="breadcrumb-item<?php echo $index === count($breadcrumbs) - 1 ? ' active' : ''; ?>">
                                        <?php if ($index === count($breadcrumbs) - 1): ?>
                                            <?php echo sanitize_output($crumb['label']); ?>
                                        <?php else: ?>
                                            <a href="<?php echo sanitize_output($crumbUrl); ?>"><?php echo sanitize_output($crumb['label']); ?></a>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    </div>
                    <?php if ($currentPath !== ''): ?>
                        <a class="btn btn-sm btn-light" href="<?php echo sanitize_output('promotions.php' . ($backLink !== '' ? '?path=' . urlencode($backLink) : '')); ?>">
                            <i class="fa-solid fa-arrow-turn-up me-1"></i>Cartella superiore
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$hasItems): ?>
                    <div class="text-center py-5 text-muted">
                        <p class="mb-1">Nessun file disponibile qui.</p>
                        <p class="small mb-0">Torna più tardi o scegli un'altra cartella dal percorso in alto.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($directories as $directory): ?>
                            <?php $folderUrl = 'promotions.php?path=' . urlencode($directory['path']); ?>
                            <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="text-warning" aria-hidden="true"><i class="fa-solid fa-folder fa-lg"></i></span>
                                    <div>
                                        <a class="fw-semibold" href="<?php echo sanitize_output($folderUrl); ?>"><?php echo sanitize_output($directory['name']); ?></a>
                                        <div class="text-muted small"><?php echo (int) $directory['items']; ?> elementi</div>
                                    </div>
                                </div>
                                <a class="btn btn-outline-secondary btn-sm" href="<?php echo sanitize_output($folderUrl); ?>">
                                    <i class="fa-solid fa-folder-open me-1"></i>Apri
                                </a>
                            </div>
                        <?php endforeach; ?>
                        <?php foreach ($files as $file): ?>
                            <?php
                                $fileUrl = asset($file['public_url']);
                                $updatedLabel = format_datetime_locale($file['modified_at']);
                                $metaLabel = sprintf('%s · Aggiornato il %s', $file['size_label'], $updatedLabel);
                                $extension = strtolower((string) $file['extension']);
                                $supportsPreview = in_array($extension, $previewableExtensions, true);
                            ?>
                            <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="text-primary" aria-hidden="true"><i class="fa-solid fa-file-lines fa-lg"></i></span>
                                    <div>
                                        <p class="mb-0 fw-semibold"><?php echo sanitize_output($file['name']); ?></p>
                                        <div class="text-muted small"><?php echo sanitize_output($metaLabel); ?></div>
                                    </div>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    <?php if ($supportsPreview): ?>
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            data-preview-trigger
                                            data-file-name="<?php echo sanitize_output($file['name']); ?>"
                                            data-file-url="<?php echo sanitize_output($fileUrl); ?>"
                                            data-file-extension="<?php echo sanitize_output($extension); ?>"
                                            data-file-meta="<?php echo sanitize_output($metaLabel); ?>"
                                        >
                                            <i class="fa-solid fa-eye me-1"></i>Visualizza
                                        </button>
                                    <?php endif; ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo sanitize_output($fileUrl); ?>" target="_blank" rel="noreferrer">
                                        <i class="fa-solid fa-download me-1"></i>Scarica
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<div class="promo-preview-overlay" data-preview-modal hidden aria-hidden="true">
    <div class="promo-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="promo-preview-title">
        <div class="promo-preview-header d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <p class="text-uppercase small text-muted mb-1">Anteprima file</p>
                <h2 class="h5 mb-1" id="promo-preview-title" data-preview-title>Seleziona un file</h2>
                <p class="text-muted small mb-0" data-preview-meta>La preview si aprirà qui.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-sm btn-primary" href="#" target="_blank" rel="noreferrer" data-preview-open>
                    <i class="fa-solid fa-arrow-up-right-from-square me-1"></i>Apri in nuova scheda
                </a>
                <button type="button" class="btn btn-light btn-sm" data-preview-dismiss>
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
        <div class="promo-preview-body mt-3">
            <div class="promo-preview-stage" data-preview-stage>
                <iframe class="promo-preview-frame d-none" data-preview-frame title="Anteprima"></iframe>
                <img class="promo-preview-image d-none" data-preview-image alt="Anteprima" loading="lazy">
                <div class="promo-preview-placeholder" data-preview-placeholder>
                    Seleziona un documento per visualizzarlo senza scaricarlo.
                </div>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php echo sanitize_output(asset('modules/opportunities/assets/opportunities.css')); ?>">
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.querySelector('[data-preview-modal]');
    const triggers = document.querySelectorAll('[data-preview-trigger]');
    if (!modal || triggers.length === 0) {
        return;
    }

    const frame = modal.querySelector('[data-preview-frame]');
    const image = modal.querySelector('[data-preview-image]');
    const placeholder = modal.querySelector('[data-preview-placeholder]');
    const title = modal.querySelector('[data-preview-title]');
    const meta = modal.querySelector('[data-preview-meta]');
    const openLink = modal.querySelector('[data-preview-open]');
    const dismissControls = modal.querySelectorAll('[data-preview-dismiss]');
    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    const resetPreview = () => {
        if (frame) {
            frame.src = '';
            frame.classList.add('d-none');
        }
        if (image) {
            image.src = '';
            image.classList.add('d-none');
        }
        if (placeholder) {
            placeholder.classList.remove('d-none');
        }
    };

    const openModal = (fileData) => {
        if (!modal) {
            return;
        }
        modal.removeAttribute('hidden');
        modal.classList.add('is-visible');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('promo-preview-open');
        if (title) {
            title.textContent = fileData.name || 'Anteprima file';
        }
        if (meta) {
            meta.textContent = fileData.meta || '';
        }
        if (openLink) {
            openLink.href = fileData.url || '#';
        }

        resetPreview();

        if (!fileData.url) {
            return;
        }

        if (image && imageExtensions.includes((fileData.extension || '').toLowerCase())) {
            image.src = fileData.url;
            image.alt = fileData.name || 'Documento';
            image.classList.remove('d-none');
        } else if (frame) {
            frame.src = fileData.url;
            frame.classList.remove('d-none');
        }
        if (placeholder) {
            placeholder.classList.add('d-none');
        }
    };

    const closeModal = () => {
        if (!modal) {
            return;
        }
        modal.classList.remove('is-visible');
        modal.setAttribute('hidden', 'hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('promo-preview-open');
        resetPreview();
    };

    triggers.forEach((trigger) => {
        trigger.addEventListener('click', () => {
            openModal({
                name: trigger.getAttribute('data-file-name') || '',
                url: trigger.getAttribute('data-file-url') || '',
                extension: trigger.getAttribute('data-file-extension') || '',
                meta: trigger.getAttribute('data-file-meta') || '',
            });
        });
    });

    dismissControls.forEach((button) => {
        button.addEventListener('click', closeModal);
    });

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hasAttribute('hidden')) {
            closeModal();
        }
    });
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
