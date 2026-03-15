<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Nuove Credenziali Iliad';

$errors = [];
$oldInput = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $serviceType = trim($_POST['service_type'] ?? '');
    $fibraId = trim($_POST['fibra_id'] ?? '');
    $mobileId = trim($_POST['mobile_id'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($serviceType)) {
        $errors['service_type'] = 'Seleziona il tipo di servizio.';
    }

    if (empty($password)) {
        $errors['password'] = 'La password è obbligatoria.';
    }

    if ($serviceType === 'fibra' || $serviceType === 'both') {
        if (empty($fibraId)) {
            $errors['fibra_id'] = 'L\'ID Fibra è obbligatorio per questo servizio.';
        }
    }

    if ($serviceType === 'mobile' || $serviceType === 'both') {
        if (empty($mobileId)) {
            $errors['mobile_id'] = 'L\'ID Mobile è obbligatorio per questo servizio.';
        }
    }

    if (empty($errors)) {
        try {
            $iliadService->createCredential([
                'fibra_id' => $fibraId ?: null,
                'fibra_password' => $password,
                'mobile_id' => $mobileId ?: null,
                'mobile_password' => $password,
                'include_fibra' => in_array($serviceType, ['fibra', 'both']),
                'include_mobile' => in_array($serviceType, ['mobile', 'both']),
            ], $_SESSION['user_id']);

            add_flash('success', 'Credenziali create con successo.');
            header('Location: ' . iliad_module_url('index'));
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
                <a class="btn btn-outline-warning" href="<?php echo iliad_module_url('index'); ?>"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna alle credenziali</a>
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
                    <div class="row g-4">
                        <div class="col-12">
                            <label class="form-label" for="service_type">Tipo di servizio</label>
                            <select class="form-select" id="service_type" name="service_type" required>
                                <option value="">Seleziona...</option>
                                <option value="fibra" <?php echo (isset($oldInput['service_type']) && $oldInput['service_type'] === 'fibra') ? 'selected' : ''; ?>>Solo Fibra</option>
                                <option value="mobile" <?php echo (isset($oldInput['service_type']) && $oldInput['service_type'] === 'mobile') ? 'selected' : ''; ?>>Solo Mobile</option>
                                <option value="both" <?php echo (isset($oldInput['service_type']) && $oldInput['service_type'] === 'both') ? 'selected' : (!isset($_POST['service_type']) ? 'selected' : ''); ?>>Fibra e Mobile</option>
                            </select>
                            <small class="text-muted">Scegli il tipo di servizio da attivare per il cliente.</small>
                        </div>
                        <div class="col-md-6 fibra-fields" style="display: none;">
                            <h5>Fibra</h5>
                            <div class="mb-3">
                                <label for="fibra_id" class="form-label">ID Fibra</label>
                                <input type="text" class="form-control <?php echo isset($errors['fibra_id']) ? 'is-invalid' : ''; ?>" id="fibra_id" name="fibra_id" value="<?php echo sanitize_output($oldInput['fibra_id'] ?? ''); ?>">
                                <?php if (isset($errors['fibra_id'])): ?>
                                    <div class="invalid-feedback"><?php echo sanitize_output($errors['fibra_id']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-md-6 mobile-fields" style="display: none;">
                            <h5>Mobile</h5>
                            <div class="mb-3">
                                <label for="mobile_id" class="form-label">ID Mobile</label>
                                <input type="text" class="form-control <?php echo isset($errors['mobile_id']) ? 'is-invalid' : ''; ?>" id="mobile_id" name="mobile_id" value="<?php echo sanitize_output($oldInput['mobile_id'] ?? ''); ?>">
                                <?php if (isset($errors['mobile_id'])): ?>
                                    <div class="invalid-feedback"><?php echo sanitize_output($errors['mobile_id']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="text" class="form-control <?php echo isset($errors['password']) ? 'is-invalid' : ''; ?>" id="password" name="password" required>
                            <small class="text-muted">Password condivisa per tutti i servizi selezionati.</small>
                            <?php if (isset($errors['password'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize_output($errors['password']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (isset($errors['include'])): ?>
                        <div class="alert alert-danger mt-3"><?php echo sanitize_output($errors['include']); ?></div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <a href="<?php echo iliad_module_url('index'); ?>" class="btn btn-outline-secondary">Annulla</a>
                        <button type="submit" class="btn btn-primary">Crea Credenziali</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const serviceTypeSelect = document.getElementById('service_type');
    const fibraFields = document.querySelector('.fibra-fields');
    const mobileFields = document.querySelector('.mobile-fields');
    const fibraIdInput = document.getElementById('fibra_id');
    const mobileIdInput = document.getElementById('mobile_id');

    function toggleFields() {
        const selectedValue = serviceTypeSelect.value;
        if (selectedValue === 'fibra' || selectedValue === 'both') {
            fibraFields.style.display = 'block';
            fibraIdInput.setAttribute('required', 'required');
        } else {
            fibraFields.style.display = 'none';
            fibraIdInput.removeAttribute('required');
        }

        if (selectedValue === 'mobile' || selectedValue === 'both') {
            mobileFields.style.display = 'block';
            mobileIdInput.setAttribute('required', 'required');
        } else {
            mobileFields.style.display = 'none';
            mobileIdInput.removeAttribute('required');
        }
    }

    serviceTypeSelect.addEventListener('change', toggleFields);
    toggleFields(); // Initialize on page load
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
