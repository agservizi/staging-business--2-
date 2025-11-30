<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Gestione certificato ANPR';

$praticaId = (int) ($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = anpr_fetch_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

$csrfToken = csrf_token();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? 'upload';

    if ($action === 'remove') {
        try {
            $pdo->beginTransaction();
            anpr_delete_certificate($pratica['certificato_path'] ?? null);
            $stmt = $pdo->prepare('UPDATE anpr_pratiche
                SET certificato_path = NULL,
                    certificato_hash = NULL,
                    certificato_caricato_at = NULL,
                    updated_at = NOW()
                WHERE id = :id');
            $stmt->execute([':id' => $praticaId]);
            $pdo->commit();
            anpr_log_action($pdo, 'Certificato rimosso', 'Rimosso certificato per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
            add_flash('success', 'Certificato rimosso con successo.');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ANPR certificate removal failed: ' . $exception->getMessage());
            add_flash('warning', 'Impossibile rimuovere il certificato.');
        }
        header('Location: view_request.php?id=' . $praticaId);
        exit;
    }

    if (empty($_FILES['certificato']['name'])) {
        $errors[] = 'Seleziona un file PDF da caricare.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stored = anpr_store_certificate($_FILES['certificato'], $praticaId);
            anpr_delete_certificate($pratica['certificato_path'] ?? null);

            $stmt = $pdo->prepare('UPDATE anpr_pratiche
                SET certificato_path = :path,
                    certificato_hash = :hash,
                    certificato_caricato_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id');
            $stmt->execute([
                ':path' => $stored['path'],
                ':hash' => $stored['hash'],
                ':id' => $praticaId,
            ]);

            $pdo->commit();
            anpr_log_action($pdo, 'Certificato aggiornato', 'Aggiornato certificato per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
            add_flash('success', 'Certificato caricato correttamente.');
            header('Location: view_request.php?id=' . $praticaId);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ANPR certificate upload failed: ' . $exception->getMessage());
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                $errors[] = 'Impossibile caricare il certificato. Riprova.';
            }
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certificato pratica <?php echo sanitize_output($pratica['pratica_code'] ?? ''); ?></h1>
                <p class="text-muted mb-0">Carica o sostituisci il certificato PDF.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="https://www.anagrafenazionale.interno.it/servizi-al-cittadino/" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square me-2"></i>Portale ANPR
                </a>
                <a class="btn btn-outline-warning" href="view_request.php?id=<?php echo $praticaId; ?>"><i class="fa-solid fa-arrow-left me-2"></i>Dettagli pratica</a>
            </div>
        </div>
        <?php if ($errors): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card ag-card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-4">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <input type="hidden" name="id" value="<?php echo $praticaId; ?>">
                    <div class="col-12">
                        <label class="form-label" for="certificato">Seleziona certificato (PDF)</label>
                        <input class="form-control" type="file" id="certificato" name="certificato" accept="application/pdf" required>
                        <small class="text-muted">Carica un file PDF, dimensione massima 15 MB. Conserva una copia anche sul repository di backup interno.</small>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-warning" href="view_request.php?id=<?php echo $praticaId; ?>">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-file-arrow-up me-2"></i>Carica certificato</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
