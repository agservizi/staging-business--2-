<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

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
                            <?php $fileUrl = asset($file['public_url']); ?>
                            <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="text-primary" aria-hidden="true"><i class="fa-solid fa-file-lines fa-lg"></i></span>
                                    <div>
                                        <p class="mb-0 fw-semibold"><?php echo sanitize_output($file['name']); ?></p>
                                        <div class="text-muted small"><?php echo sanitize_output($file['size_label']); ?> · Aggiornato il <?php echo sanitize_output(format_datetime_locale($file['modified_at'])); ?></div>
                                    </div>
                                </div>
                                <a class="btn btn-outline-primary btn-sm" href="<?php echo sanitize_output($fileUrl); ?>" target="_blank" rel="noreferrer">
                                    <i class="fa-solid fa-download me-1"></i>Scarica
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo sanitize_output(asset('modules/opportunities/assets/opportunities.css')); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
