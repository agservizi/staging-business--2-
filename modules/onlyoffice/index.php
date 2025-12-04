<?php

declare(strict_types=1);

use Modules\Onlyoffice as OnlyOffice;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/config.php';

require_role('Admin', 'Operatore', 'Manager', 'Support', 'Viewer');

$pageTitle = 'Documenti ONLYOFFICE';
$extraStyles = [asset('modules/onlyoffice/assets/css/editor.css')];

$roleMap = [
    'Admin' => 'admin',
    'Operatore' => 'operator',
    'Manager' => 'manager',
    'Support' => 'support',
    'Viewer' => 'viewer',
];

$currentRole = $_SESSION['role'] ?? 'user';
$normalizedRole = $roleMap[$currentRole] ?? 'user';

if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    $_SESSION['user'] = [];
}

$_SESSION['user']['id'] = (int) ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0));
$_SESSION['user']['name'] = $_SESSION['user']['name'] ?? current_user_display_name();
$_SESSION['user']['email'] = $_SESSION['user']['email'] ?? ($_SESSION['email'] ?? '');
$_SESSION['user']['role'] = $normalizedRole;

$user = OnlyOffice\requireUser();
$documents = OnlyOffice\listDocuments();
$documentServer = rtrim(Modules\Onlyoffice\DOCUMENT_SERVER_URL, '/');

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper onlyoffice-dashboard">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-1">Documenti ONLYOFFICE</h1>
                <p class="text-muted mb-0">Crea, apri e cifra i documenti Office direttamente dal gestionale.</p>
            </div>
            <div class="text-end">
                <span class="badge rounded-pill text-bg-primary-subtle text-primary-emphasis me-2">Server: <?php echo sanitize_output($documentServer); ?></span>
                <span class="badge rounded-pill <?php echo Modules\Onlyoffice\DOCUMENT_SERVER_USE_JWT ? 'text-bg-success-subtle text-success-emphasis' : 'text-bg-secondary-subtle text-secondary-emphasis'; ?>">
                    JWT <?php echo Modules\Onlyoffice\DOCUMENT_SERVER_USE_JWT ? 'attivo' : 'disattivo'; ?>
                </span>
            </div>
        </div>

        <div class="row g-4 onlyoffice-panels">
            <div class="col-xl-6">
                <div class="card ag-card onlyoffice-card h-100">
                    <div class="card-header border-0">
                        <div>
                            <h2 class="card-title h5 mb-1">Crea un documento</h2>
                            <p class="text-muted mb-0">Genera un template DOCX/XLSX/PPTX pronto per l'editor.</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="onlyoffice-create-grid">
                            <form class="onlyoffice-create-form" data-type="docx">
                                <label class="form-label">Nome documento</label>
                                <input class="form-control" type="text" name="title" placeholder="Nuovo documento" required>
                                <input type="hidden" name="type" value="docx">
                                <button class="btn onlyoffice-btn" type="submit">Nuovo DOCX</button>
                            </form>
                            <form class="onlyoffice-create-form" data-type="xlsx">
                                <label class="form-label">Nome foglio</label>
                                <input class="form-control" type="text" name="title" placeholder="Nuovo foglio" required>
                                <input type="hidden" name="type" value="xlsx">
                                <button class="btn onlyoffice-btn" type="submit">Nuovo XLSX</button>
                            </form>
                            <form class="onlyoffice-create-form" data-type="pptx">
                                <label class="form-label">Nome presentazione</label>
                                <input class="form-control" type="text" name="title" placeholder="Nuova presentazione" required>
                                <input type="hidden" name="type" value="pptx">
                                <button class="btn onlyoffice-btn" type="submit">Nuovo PPTX</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-6">
                <div class="card ag-card onlyoffice-card h-100">
                    <div class="card-header border-0">
                        <div>
                            <h2 class="card-title h5 mb-1">Carica un file esistente</h2>
                            <p class="text-muted mb-0">Il file verrà cifrato e salvato nella cartella protetta.</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form id="onlyoffice-upload-form" enctype="multipart/form-data" class="onlyoffice-upload-form">
                            <label class="form-label" for="onlyoffice-upload-input">Seleziona documento (.docx, .xlsx, .pptx)</label>
                            <input class="form-control" id="onlyoffice-upload-input" type="file" name="file" accept=".docx,.xlsx,.pptx" required>
                            <button class="btn onlyoffice-btn" type="submit">Carica e apri</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card onlyoffice-card mt-4">
            <div class="card-header border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="card-title h5 mb-1">Documenti disponibili</h2>
                    <p class="text-muted mb-0">Ultimo salvataggio gestito da ONLYOFFICE Document Server.</p>
                </div>
                <span class="badge rounded-pill text-bg-light text-secondary">
                    <?php echo sanitize_output((string) count($documents)); ?> file
                </span>
            </div>
            <div class="card-body">
                <?php if (empty($documents)): ?>
                    <p class="text-muted mb-0">Nessun documento presente. Crea un nuovo file oppure carica un documento esistente.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table align-middle onlyoffice-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Tipo</th>
                                    <th>Ultimo aggiornamento</th>
                                    <th>Dimensione</th>
                                    <th class="text-end">Azione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td><?php echo sanitize_output($doc['name']); ?></td>
                                        <td><span class="badge text-bg-primary-subtle text-primary-emphasis text-uppercase"><?php echo sanitize_output($doc['extension']); ?></span></td>
                                        <td><?php echo sanitize_output(date('d/m/Y H:i', (int) $doc['updatedAt'])); ?></td>
                                        <td><?php echo sanitize_output(number_format((($doc['size'] ?? 0) / 1024), 1, ',', '.')); ?> KB</td>
                                        <td class="text-end">
                                            <a class="btn onlyoffice-btn" href="editor.php?id=<?php echo urlencode($doc['id']); ?>">Apri</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card onlyoffice-card mt-4">
            <div class="card-header border-0">
                <h2 class="card-title h5 mb-0">Stato integrazione</h2>
            </div>
            <div class="card-body">
                <ul class="onlyoffice-config-list">
                    <li>
                        <span class="text-muted d-block">Document Server</span>
                        <strong><?php echo sanitize_output($documentServer ?: 'non configurato'); ?></strong>
                    </li>
                    <li>
                        <span class="text-muted d-block">JWT</span>
                        <strong><?php echo Modules\Onlyoffice\DOCUMENT_SERVER_USE_JWT ? 'Abilitato' : 'Disabilitato'; ?></strong>
                    </li>
                    <li>
                        <span class="text-muted d-block">Ruolo corrente</span>
                        <strong><?php echo sanitize_output($_SESSION['role'] ?? 'Sconosciuto'); ?></strong>
                    </li>
                    <li>
                        <span class="text-muted d-block">Ultimo accesso editor</span>
                        <strong><?php echo sanitize_output(date('d/m/Y H:i')); ?></strong>
                    </li>
                </ul>
            </div>
        </div>

        <script>
        const createForms = document.querySelectorAll('.onlyoffice-create-form');
        const uploadForm = document.getElementById('onlyoffice-upload-form');

        async function sendForm(url, formData) {
            const response = await fetch(url, {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) {
                throw new Error('Richiesta non riuscita');
            }

            const data = await response.json();
            if (data.error) {
                throw new Error(data.error);
            }

            return data.data;
        }

        createForms.forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const formData = new FormData(form);
                try {
                    const file = await sendForm('filemanager.php?action=create', formData);
                    window.location.href = `editor.php?id=${encodeURIComponent(file.id)}`;
                } catch (error) {
                    alert(error.message);
                }
            });
        });

        if (uploadForm) {
            uploadForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                const formData = new FormData(uploadForm);
                try {
                    const file = await sendForm('filemanager.php?action=upload', formData);
                    window.location.href = `editor.php?id=${encodeURIComponent(file.id)}`;
                } catch (error) {
                    alert(error.message);
                }
            });
        }
        </script>
    </main>
    <?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</div>
