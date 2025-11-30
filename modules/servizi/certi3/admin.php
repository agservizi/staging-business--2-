<?php
require_once '../../../includes/auth.php';
require_role('Admin');
$pageTitle = 'Certi³ - Impostazioni Admin';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';

// Carica impostazioni attuali
$settings = $pdo->query("SELECT chiave, valore FROM configurazioni WHERE chiave LIKE 'certi3_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

// Gestione salvataggio impostazioni
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Verifica CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $message = 'Token CSRF non valido';
        $messageType = 'danger';
    } else {
        try {
            // Impostazioni da salvare
            $settingsToSave = [
                'certi3_api_timeout' => $_POST['api_timeout'] ?? '30',
                'certi3_max_file_size' => $_POST['max_file_size'] ?? '10',
                'certi3_allowed_extensions' => $_POST['allowed_extensions'] ?? 'pdf,doc,docx,jpg,jpeg,png',
                'certi3_email_notifications' => isset($_POST['email_notifications']) ? '1' : '0',
                'certi3_auto_cleanup_days' => $_POST['auto_cleanup_days'] ?? '90',
                'certi3_debug_mode' => isset($_POST['debug_mode']) ? '1' : '0'
            ];

            // Salva ogni impostazione
            $stmt = $pdo->prepare("INSERT INTO configurazioni (chiave, valore) VALUES (?, ?) ON DUPLICATE KEY UPDATE valore = VALUES(valore)");

            foreach ($settingsToSave as $key => $value) {
                $stmt->execute([$key, $value]);
            }

            $message = 'Impostazioni salvate con successo!';
            $messageType = 'success';

            // Ricarica impostazioni
            $settings = $pdo->query("SELECT chiave, valore FROM configurazioni WHERE chiave LIKE 'certi3_%'")->fetchAll(PDO::FETCH_KEY_PAIR);

        } catch (Exception $e) {
            $message = 'Errore nel salvataggio: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}
?>

<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once '../../../includes/topbar.php'; ?>

    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Impostazioni Certi³</h1>
                <p class="text-muted mb-0">Configurazione sistema certificati</p>
            </div>
            <div class="toolbar-actions">
                <a href="./" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna al Pannello
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
                <i class="fa-solid fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-triangle'; ?> me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-lg-8">
                <form method="POST" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                    <!-- API Settings -->
                    <div class="card ag-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fa-solid fa-globe text-primary me-2"></i>Impostazioni API
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="api_timeout" class="form-label fw-semibold">
                                        <i class="fa-solid fa-clock text-primary me-1"></i>Timeout API (secondi)
                                    </label>
                                    <input type="number" class="form-control" id="api_timeout" name="api_timeout"
                                           value="<?php echo htmlspecialchars($settings['certi3_api_timeout'] ?? '30'); ?>"
                                           min="5" max="300" required>
                                    <div class="form-text">Tempo massimo di attesa per le chiamate API esterne</div>
                                </div>

                                <div class="col-md-6">
                                    <label for="max_file_size" class="form-label fw-semibold">
                                        <i class="fa-solid fa-file text-primary me-1"></i>Dimensione Max File (MB)
                                    </label>
                                    <input type="number" class="form-control" id="max_file_size" name="max_file_size"
                                           value="<?php echo htmlspecialchars($settings['certi3_max_file_size'] ?? '10'); ?>"
                                           min="1" max="100" required>
                                    <div class="form-text">Dimensione massima per gli allegati</div>
                                </div>

                                <div class="col-12">
                                    <label for="allowed_extensions" class="form-label fw-semibold">
                                        <i class="fa-solid fa-file-code text-primary me-1"></i>Estensioni Consentite
                                    </label>
                                    <input type="text" class="form-control" id="allowed_extensions" name="allowed_extensions"
                                           value="<?php echo htmlspecialchars($settings['certi3_allowed_extensions'] ?? 'pdf,doc,docx,jpg,jpeg,png'); ?>"
                                           required>
                                    <div class="form-text">Elenco separato da virgola delle estensioni file permesse</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notification Settings -->
                    <div class="card ag-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fa-solid fa-bell text-warning me-2"></i>Notifiche
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="email_notifications" name="email_notifications"
                                       <?php echo ($settings['certi3_email_notifications'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label fw-semibold" for="email_notifications">
                                    <i class="fa-solid fa-envelope text-warning me-1"></i>Abilita notifiche email
                                </label>
                            </div>
                            <div class="form-text">Invia email di notifica per nuove richieste e completamenti</div>
                        </div>
                    </div>

                    <!-- Maintenance Settings -->
                    <div class="card ag-card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fa-solid fa-wrench text-danger me-2"></i>Manutenzione
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="auto_cleanup_days" class="form-label fw-semibold">
                                        <i class="fa-solid fa-calendar-times text-danger me-1"></i>Pulizia Automatica (giorni)
                                    </label>
                                    <input type="number" class="form-control" id="auto_cleanup_days" name="auto_cleanup_days"
                                           value="<?php echo htmlspecialchars($settings['certi3_auto_cleanup_days'] ?? '90'); ?>"
                                           min="7" max="365" required>
                                    <div class="form-text">Elimina automaticamente richieste completate dopo X giorni</div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        <i class="fa-solid fa-bug text-danger me-1"></i>Modalità Debug
                                    </label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode"
                                               <?php echo ($settings['certi3_debug_mode'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="debug_mode">
                                            Abilita debug
                                        </label>
                                    </div>
                                    <div class="form-text">Mostra informazioni di debug nelle risposte API</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="d-flex gap-2">
                        <button type="submit" name="save_settings" class="btn btn-success">
                            <i class="fa-solid fa-save me-2"></i>Salva Impostazioni
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-rotate-left me-2"></i>Reimposta
                        </button>
                    </div>
                </form>
            </div>

            <div class="col-lg-4">
                <!-- System Info -->
                <div class="card ag-card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fa-solid fa-info-circle text-info me-2"></i>Informazioni Sistema
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php
                        // Statistiche sistema
                        $stats = $pdo->query("
                            SELECT
                                COUNT(*) as total_richieste,
                                SUM(CASE WHEN stato = 'pending' THEN 1 ELSE 0 END) as pending,
                                SUM(CASE WHEN stato = 'done' THEN 1 ELSE 0 END) as completed,
                                SUM(CASE WHEN documenti IS NOT NULL AND documenti != '[]' THEN 1 ELSE 0 END) as with_attachments
                            FROM certificati_richieste
                        ")->fetch();
                        ?>

                        <div class="row g-3">
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h4 mb-1 text-primary"><?php echo $stats['total_richieste'] ?? 0; ?></div>
                                    <small class="text-muted">Totale Richieste</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h4 mb-1 text-warning"><?php echo $stats['pending'] ?? 0; ?></div>
                                    <small class="text-muted">In Attesa</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h4 mb-1 text-success"><?php echo $stats['completed'] ?? 0; ?></div>
                                    <small class="text-muted">Completate</small>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="text-center">
                                    <div class="h4 mb-1 text-info"><?php echo $stats['with_attachments'] ?? 0; ?></div>
                                    <small class="text-muted">Con Allegati</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card ag-card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fa-solid fa-bolt text-success me-2"></i>Azioni Rapide
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="exportData()">
                                <i class="fa-solid fa-download me-1"></i>Esporta Dati
                            </button>
                            <button type="button" class="btn btn-outline-warning btn-sm" onclick="clearOldRequests()">
                                <i class="fa-solid fa-broom me-1"></i>Pulisci Vecchie
                            </button>
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="viewLogs()">
                                <i class="fa-solid fa-list me-1"></i>Visualizza Log
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Funzioni per azioni rapide
function exportData() {
    alert('Funzionalità esportazione dati in sviluppo');
}

function clearOldRequests() {
    if (confirm('Vuoi eliminare le richieste completate più vecchie di 90 giorni?')) {
        alert('Funzionalità pulizia automatica in sviluppo');
    }
}

function viewLogs() {
    alert('Funzionalità visualizzazione log in sviluppo');
}

// Validazione form
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.needs-validation');
    form.addEventListener('submit', function(event) {
        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        form.classList.add('was-validated');
    });
});
</script>

<?php require_once '../../../includes/footer.php'; ?>