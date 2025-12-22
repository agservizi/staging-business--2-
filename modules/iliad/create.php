<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Nuove Credenziali Iliad';

$errors = [];
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $fibraId = trim($_POST['fibra_id'] ?? '');
    $fibraPassword = trim($_POST['fibra_password'] ?? '');
    $mobileId = trim($_POST['mobile_id'] ?? '');
    $mobilePassword = trim($_POST['mobile_password'] ?? '');
    $includeFibra = isset($_POST['include_fibra']);
    $includeMobile = isset($_POST['include_mobile']);

    if (empty($fibraPassword)) {
        $errors['fibra_password'] = 'La password per Fibra è obbligatoria.';
    }

    if (empty($mobilePassword)) {
        $errors['mobile_password'] = 'La password per Mobile è obbligatoria.';
    }

    if (!$includeFibra && !$includeMobile) {
        $errors['include'] = 'Devi includere almeno Fibra o Mobile nel PDF.';
    }

    if (empty($errors)) {
        try {
            $iliadService->createCredential([
                'fibra_id' => $fibraId ?: null,
                'fibra_password' => $fibraPassword,
                'mobile_id' => $mobileId ?: null,
                'mobile_password' => $mobilePassword,
                'include_fibra' => $includeFibra,
                'include_mobile' => $includeMobile,
            ], $_SESSION['user_id']);

            add_flash('success', 'Credenziali create con successo.');
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $errors['general'] = 'Errore durante la creazione delle credenziali.';
        }
    }

    $oldInput = $_POST;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Nuove Credenziali Iliad</h1>
                <p class="text-muted mb-0">Inserisci le credenziali per Fibra e Mobile da fornire ai clienti.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna alle credenziali</a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Dati credenziali</h2>
            </div>
            <div class="card-body">
                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo sanitize_output($errors['general']); ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h5>Fibra</h5>
                            <div class="mb-3">
                                <label for="fibra_id" class="form-label">ID Fibra</label>
                                <input type="text" class="form-control <?php echo isset($errors['fibra_id']) ? 'is-invalid' : ''; ?>" id="fibra_id" name="fibra_id" value="<?php echo sanitize_output($oldInput['fibra_id'] ?? ''); ?>">
                                <?php if (isset($errors['fibra_id'])): ?>
                                    <div class="invalid-feedback"><?php echo sanitize_output($errors['fibra_id']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="fibra_password" class="form-label">Password Fibra <span class="text-danger">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['fibra_password']) ? 'is-invalid' : ''; ?>" id="fibra_password" name="fibra_password" required>
                                <?php if (isset($errors['fibra_password'])): ?>
                                    <div class="invalid-feedback"><?php echo sanitize_output($errors['fibra_password']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="include_fibra" name="include_fibra" <?php echo (isset($oldInput['include_fibra']) || !isset($_POST['include_fibra'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="include_fibra">
                                    Includi Fibra nel PDF
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5>Mobile</h5>
                            <div class="mb-3">
                                <label for="mobile_id" class="form-label">ID Mobile</label>
                                <input type="text" class="form-control <?php echo isset($errors['mobile_id']) ? 'is-invalid' : ''; ?>" id="mobile_id" name="mobile_id" value="<?php echo sanitize_output($oldInput['mobile_id'] ?? ''); ?>">
                                <?php if (isset($errors['mobile_id'])): ?>
                                    <div class="invalid-feedback"><?php echo sanitize_output($errors['mobile_id']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="mb-3">
                                <label for="mobile_password" class="form-label">Password Mobile <span class="text-danger">*</span></label>
                                <input type="text" class="form-control <?php echo isset($errors['mobile_password']) ? 'is-invalid' : ''; ?>" id="mobile_password" name="mobile_password" required>
                                <?php if (isset($errors['mobile_password'])): ?>
                                    <div class="invalid-feedback"><?php echo sanitize_output($errors['mobile_password']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="include_mobile" name="include_mobile" <?php echo (isset($oldInput['include_mobile']) || !isset($_POST['include_mobile'])) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="include_mobile">
                                    Includi Mobile nel PDF
                                </label>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($errors['include'])): ?>
                        <div class="alert alert-danger mt-3"><?php echo sanitize_output($errors['include']); ?></div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="index.php" class="btn btn-outline-secondary">Annulla</a>
                        <button type="submit" class="btn btn-primary">Crea Credenziali</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>