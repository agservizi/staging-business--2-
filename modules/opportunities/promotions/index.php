<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Admin', 'Manager');

use App\Services\Opportunities\PromotionLibraryService;

$library = new PromotionLibraryService();
$csrfToken = csrf_token();
$currentPath = isset($_GET['path']) ? (string) $_GET['path'] : '';
$errors = [];
$defaultLibraryPath = PromotionLibraryService::DEFAULT_ROOT_FOLDER;
try {
    $library->ensureFolderExists($defaultLibraryPath);
} catch (RuntimeException $exception) {
    $errors[] = $exception->getMessage();
}
if ($currentPath === '') {
    $currentPath = $defaultLibraryPath;
}
$normalizeUploads = static function (?array $input): array {
    if ($input === null || !isset($input['name'])) {
        return [];
    }

    if (!is_array($input['name'])) {
        return [$input];
    }

    $files = [];
    $total = count($input['name']);
    for ($index = 0; $index < $total; $index += 1) {
        $name = $input['name'][$index] ?? '';
        $error = isset($input['error'][$index]) ? (int) $input['error'][$index] : UPLOAD_ERR_NO_FILE;
        $tmpName = $input['tmp_name'][$index] ?? '';
        $size = $input['size'][$index] ?? 0;
        $type = $input['type'][$index] ?? '';

        if ((string) $name === '' && $error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        $files[] = [
            'name' => $name,
            'type' => $type,
            'tmp_name' => $tmpName,
            'size' => $size,
            'error' => $error,
        ];
    }

    return $files;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $currentPath = $library->normalizeInputPath((string) ($_POST['current_path'] ?? ''));

    try {
        if ($action === 'create_folder') {
            $folderName = (string) ($_POST['folder_name'] ?? '');
            $newPath = $library->createFolder($folderName, $currentPath);
            add_flash('success', 'Cartella creata correttamente.');
            header('Location: index.php' . ($newPath !== '' ? '?path=' . urlencode($newPath) : ''));
            exit;
        }

        if ($action === 'upload_files') {
            $uploads = $normalizeUploads($_FILES['promo_files'] ?? null);
            if ($uploads === []) {
                throw new RuntimeException('Seleziona almeno un file da caricare.');
            }

            $result = $library->uploadMany($uploads, $currentPath);
            if ($result['uploaded'] > 0) {
                add_flash('success', sprintf('%d file caricati correttamente.', $result['uploaded']));
            }
            if ($result['errors'] !== []) {
                $message = implode(' ', $result['errors']);
                add_flash($result['uploaded'] > 0 ? 'warning' : 'danger', $message);
            }

            header('Location: index.php' . ($currentPath !== '' ? '?path=' . urlencode($currentPath) : ''));
            exit;
        }

        if ($action === 'delete_file') {
            $target = (string) ($_POST['target'] ?? '');
            $library->deleteFile($target);
            add_flash('success', 'File eliminato.');
            header('Location: index.php' . ($currentPath !== '' ? '?path=' . urlencode($currentPath) : ''));
            exit;
        }

        if ($action === 'delete_folder') {
            $target = $library->normalizeInputPath((string) ($_POST['target'] ?? ''));
            $library->deleteFolder($target);
            $segments = $target !== '' ? explode('/', $target) : [];
            array_pop($segments);
            $redirectPath = implode('/', $segments);
            add_flash('success', 'Cartella rimossa.');
            header('Location: index.php' . ($redirectPath !== '' ? '?path=' . urlencode($redirectPath) : ''));
            exit;
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

try {
    $listing = $library->listContents($currentPath);
} catch (RuntimeException $exception) {
    add_flash('warning', $exception->getMessage());
    header('Location: index.php');
    exit;
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
                <p class="text-uppercase small fw-semibold text-muted mb-1">Promo opportunity</p>
                <h1 class="h4 mb-0">File manager collaboratori</h1>
                <p class="text-muted mb-0">Carica PDF e immagini organizzandoli in cartelle condivise.</p>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(asset('modules/opportunities/index.php')); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Torna alla pipeline
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Nuova cartella</p>
                        <form method="post" class="d-flex flex-column gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="action" value="create_folder">
                            <input type="hidden" name="current_path" value="<?php echo sanitize_output($currentPath); ?>">
                            <div>
                                <label class="form-label">Nome cartella</label>
                                <input class="form-control" type="text" name="folder_name" placeholder="Es. Promo Inverno" required>
                            </div>
                            <button class="btn btn-outline-primary" type="submit">
                                <i class="fa-solid fa-folder-plus me-2"></i>Crea cartella
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Carica file promo</p>
                        <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-3" novalidate>
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="action" value="upload_files">
                            <input type="hidden" name="current_path" value="<?php echo sanitize_output($currentPath); ?>">
                            <div>
                                <label class="form-label">Seleziona uno o più file</label>
                                <div class="dropzone-area" data-promo-dropzone role="button" tabindex="0">
                                    <p class="mb-1"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Trascina qui i file oppure clicca per selezionarli</p>
                                    <p class="text-muted small mb-0">PDF o immagini (max 20MB ciascuno).</p>
                                    <input class="d-none" type="file" name="promo_files[]" id="promo-files" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" multiple>
                                </div>
                                <div class="dropzone-files" data-dropzone-files></div>
                            </div>
                            <button class="btn btn-primary" type="submit">
                                <i class="fa-solid fa-cloud-arrow-up me-2"></i>Carica nella cartella corrente
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                    <div>
                        <p class="text-uppercase small text-muted mb-1">Percorso corrente</p>
                        <nav aria-label="Navigazione cartelle">
                            <ol class="breadcrumb mb-0">
                                <?php foreach ($breadcrumbs as $index => $crumb): ?>
                                    <?php $crumbUrl = 'index.php' . ($crumb['path'] !== '' ? '?path=' . urlencode($crumb['path']) : ''); ?>
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
                        <a class="btn btn-sm btn-light" href="<?php echo sanitize_output('index.php' . ($backLink !== '' ? '?path=' . urlencode($backLink) : '')); ?>">
                            <i class="fa-solid fa-arrow-turn-up me-1"></i>Cartella superiore
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (!$hasItems): ?>
                    <div class="text-center py-5 text-muted">
                        <p class="mb-1">Cartella vuota.</p>
                        <p class="small mb-0">Crea una cartella o carica il primo documento per i collaboratori.</p>
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($directories as $directory): ?>
                            <?php $folderUrl = 'index.php?path=' . urlencode($directory['path']); ?>
                            <div class="list-group-item d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                                <div class="d-flex align-items-center gap-3">
                                    <span class="text-warning" aria-hidden="true"><i class="fa-solid fa-folder fa-lg"></i></span>
                                    <div>
                                        <a class="fw-semibold" href="<?php echo sanitize_output($folderUrl); ?>"><?php echo sanitize_output($directory['name']); ?></a>
                                        <div class="text-muted small"><?php echo (int) $directory['items']; ?> elementi</div>
                                    </div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo sanitize_output($folderUrl); ?>">
                                        <i class="fa-solid fa-folder-open me-1"></i>Apri
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questa cartella?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete_folder">
                                        <input type="hidden" name="current_path" value="<?php echo sanitize_output($currentPath); ?>">
                                        <input type="hidden" name="target" value="<?php echo sanitize_output($directory['path']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit"<?php echo $directory['is_empty'] ? '' : ' disabled'; ?>>
                                            <i class="fa-solid fa-trash-can me-1"></i>Elimina
                                        </button>
                                    </form>
                                </div>
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
                                <div class="d-flex gap-2">
                                    <a class="btn btn-outline-primary btn-sm" href="<?php echo sanitize_output($fileUrl); ?>" target="_blank" rel="noreferrer">
                                        <i class="fa-solid fa-download me-1"></i>Scarica
                                    </a>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Eliminare questo file?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                        <input type="hidden" name="action" value="delete_file">
                                        <input type="hidden" name="current_path" value="<?php echo sanitize_output($currentPath); ?>">
                                        <input type="hidden" name="target" value="<?php echo sanitize_output($file['path']); ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">
                                            <i class="fa-solid fa-trash-can me-1"></i>Elimina
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo sanitize_output(asset('modules/opportunities/assets/opportunities.css')); ?>">
<script>
document.addEventListener('DOMContentLoaded', () => {
    const dropzone = document.querySelector('[data-promo-dropzone]');
    const input = document.getElementById('promo-files');
    const list = document.querySelector('[data-dropzone-files]');
    if (!dropzone || !input || !list || typeof DataTransfer === 'undefined') {
        return;
    }

    const refreshList = () => {
        list.innerHTML = '';
        Array.from(input.files || []).forEach((file, index) => {
            const entry = document.createElement('div');
            entry.className = 'dropzone-file-entry';
            entry.innerHTML = `
                <div class="file-meta">
                    <strong>${file.name}</strong>
                    <small>${(file.size / 1024).toFixed(1)} KB</small>
                </div>
                <div class="file-actions">
                    <button type="button" data-index="${index}" aria-label="Rimuovi ${file.name}">
                        <i class="fa-solid fa-times"></i>
                    </button>
                </div>
            `;
            entry.querySelector('button')?.addEventListener('click', (event) => {
                const target = event.currentTarget;
                const removeIndex = Number(target?.getAttribute('data-index'));
                const transfer = new DataTransfer();
                Array.from(input.files || []).forEach((fileItem, idx) => {
                    if (idx !== removeIndex) {
                        transfer.items.add(fileItem);
                    }
                });
                input.files = transfer.files;
                refreshList();
            });
            list.appendChild(entry);
        });
    };

    const appendFiles = (fileList) => {
        const transfer = new DataTransfer();
        Array.from(input.files || []).forEach((existing) => transfer.items.add(existing));
        Array.from(fileList || []).forEach((file) => transfer.items.add(file));
        input.files = transfer.files;
        refreshList();
    };

    dropzone.addEventListener('click', () => input.click());
    dropzone.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            input.click();
        }
    });

    dropzone.addEventListener('dragover', (event) => {
        event.preventDefault();
        dropzone.classList.add('dragover');
    });

    dropzone.addEventListener('dragleave', () => {
        dropzone.classList.remove('dragover');
    });

    dropzone.addEventListener('drop', (event) => {
        event.preventDefault();
        dropzone.classList.remove('dragover');
        appendFiles(event.dataTransfer?.files);
    });

    input.addEventListener('change', () => refreshList());

    refreshList();
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
