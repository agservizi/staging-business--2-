<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Certificati Comunali';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';

// Carica richieste recenti
$richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? ORDER BY cr.created_at DESC LIMIT 50');
$richieste->execute([$_SESSION['user_id']]);
$richieste = $richieste->fetchAll();
?>

<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once '../../../includes/topbar.php'; ?>

    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certificati Comunali</h1>
                <p class="text-muted mb-0">Richiesta certificati anagrafici, residenza e stato civile</p>
            </div>
            <div class="toolbar-actions">
                <a href="/modules/servizi/certi3/index.php" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna ai Servizi
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">
                            <i class="fa-solid fa-plus-circle text-primary me-2"></i>Nuova Richiesta
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="certificatoForm" class="needs-validation" novalidate>
                            <input type="hidden" name="categoria" value="comunali">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="comunale_tipo" class="form-label fw-semibold">
                                        <i class="fa-solid fa-certificate text-primary me-1"></i>Tipo Certificato *
                                    </label>
                                    <select class="form-select" id="comunale_tipo" name="tipo" required>
                                        <option value="">Caricamento tipi...</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="codice_fiscale" class="form-label fw-semibold">
                                        <i class="fa-solid fa-id-card text-primary me-1"></i>Codice Fiscale *
                                    </label>
                                    <input type="text" class="form-control" id="codice_fiscale" name="codice_fiscale" required
                                           pattern="[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]"
                                           placeholder="RSSMRA85T10A562S">
                                </div>

                                <div class="col-md-6">
                                    <label for="nome" class="form-label fw-semibold">
                                        <i class="fa-solid fa-user text-primary me-1"></i>Nome *
                                    </label>
                                    <input type="text" class="form-control" id="nome" name="nome" required placeholder="Mario">
                                </div>

                                <div class="col-md-6">
                                    <label for="cognome" class="form-label fw-semibold">
                                        <i class="fa-solid fa-user text-primary me-1"></i>Cognome *
                                    </label>
                                    <input type="text" class="form-control" id="cognome" name="cognome" required placeholder="Rossi">
                                </div>

                                <div class="col-md-6">
                                    <label for="data_nascita" class="form-label fw-semibold">
                                        <i class="fa-solid fa-calendar text-primary me-1"></i>Data di Nascita *
                                    </label>
                                    <input type="date" class="form-control" id="data_nascita" name="data_nascita" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="luogo_nascita" class="form-label fw-semibold">
                                        <i class="fa-solid fa-map-marker-alt text-primary me-1"></i>Luogo di Nascita *
                                    </label>
                                    <input type="text" class="form-control" id="luogo_nascita" name="luogo_nascita" required placeholder="Milano">
                                </div>

                                <div class="col-md-6">
                                    <label for="comune" class="form-label fw-semibold">
                                        <i class="fa-solid fa-home text-primary me-1"></i>Comune di Residenza *
                                    </label>
                                    <input type="text" class="form-control" id="comune" name="comune" required placeholder="Milano">
                                </div>

                                <div class="col-md-6">
                                    <label for="indirizzo" class="form-label fw-semibold">
                                        <i class="fa-solid fa-road text-primary me-1"></i>Indirizzo (opzionale)
                                    </label>
                                    <input type="text" class="form-control" id="indirizzo" name="indirizzo" placeholder="Via Roma 123">
                                </div>

                                <div class="col-12">
                                    <label for="note" class="form-label fw-semibold">
                                        <i class="fa-solid fa-sticky-note text-primary me-1"></i>Note aggiuntive (opzionale)
                                    </label>
                                    <textarea class="form-control" id="note" name="note" rows="3" placeholder="Eventuali note o richieste specifiche"></textarea>
                                </div>

                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="urgente" name="urgente">
                                        <label class="form-check-label fw-semibold" for="urgente">
                                            <i class="fa-solid fa-exclamation-triangle text-warning me-1"></i>Richiesta urgente
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia Richiesta
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fa-solid fa-rotate-left me-2"></i>Reimposta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">
                            <i class="fa-solid fa-info-circle text-info me-2"></i>Informazioni
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6><i class="fa-solid fa-clock me-2"></i>Tempi di consegna</h6>
                            <p class="mb-0 small">I certificati vengono solitamente consegnati entro 24-48 ore dalla richiesta.</p>
                        </div>

                        <div class="alert alert-warning">
                            <h6><i class="fa-solid fa-euro-sign me-2"></i>Costi</h6>
                            <p class="mb-0 small">Verifica i costi specifici nel portale DocuEngine prima della richiesta.</p>
                        </div>

                        <div class="alert alert-success">
                            <h6><i class="fa-solid fa-check-circle me-2"></i>Documenti supportati</h6>
                            <ul class="mb-0 small">
                                <li>Certificato di residenza</li>
                                <li>Stato di famiglia</li>
                                <li>Certificato anagrafico</li>
                                <li>Altri documenti comunali</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($richieste)): ?>
        <div class="card ag-card mt-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">
                    <i class="fa-solid fa-history text-secondary me-2"></i>Richieste Recenti
                </h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tipo</th>
                                <th>Data Richiesta</th>
                                <th>Stato</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($richieste as $richiesta): ?>
                            <tr>
                                <td>#<?php echo $richiesta['id']; ?></td>
                                <td><?php echo htmlspecialchars($richiesta['tipo_certificato'] ?? 'N/A'); ?></td>
                                <td><?php echo date('d/m/Y H:i', strtotime($richiesta['created_at'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo match($richiesta['stato']) {
                                            'completato' => 'success',
                                            'in_elaborazione' => 'warning',
                                            'rifiutato' => 'danger',
                                            default => 'secondary'
                                        };
                                    ?>">
                                        <?php echo htmlspecialchars($richiesta['stato'] ?? 'sconosciuto'); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewRequest(<?php echo $richiesta['id']; ?>)">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>

<!-- Modal per messaggi -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="messageModalTitle">Notifica</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="messageModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script>
// Carica tipi di certificato disponibili
document.addEventListener('DOMContentLoaded', function() {
    fetch('api/comunali.php?action=get_tipi')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('comunale_tipo');
            select.innerHTML = '<option value="">Seleziona tipo...</option>';

            if (data.success && data.tipi) {
                data.tipi.forEach(tipo => {
                    const option = document.createElement('option');
                    option.value = tipo.id;
                    option.textContent = tipo.nome;
                    select.appendChild(option);
                });
            }
        })
        .catch(error => {
            console.error('Errore caricamento tipi:', error);
            document.getElementById('comunale_tipo').innerHTML = '<option value="">Errore caricamento</option>';
        });
});

// Gestione form AJAX
document.getElementById('certificatoForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Invio in corso...';

    const formData = new FormData(this);

    fetch('api/comunali.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const modal = new bootstrap.Modal(document.getElementById('messageModal'));
        const modalTitle = document.getElementById('messageModalTitle');
        const modalBody = document.getElementById('messageModalBody');

        if (data.success) {
            modalTitle.textContent = 'Richiesta Inviata';
            modalBody.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-check-circle me-2"></i>Richiesta certificato inviata con successo! Verrai notificato quando sarà pronta.</div>';
            this.reset();
        } else {
            modalTitle.textContent = 'Errore';
            modalBody.innerHTML = '<div class="alert alert-danger"><i class="fa-solid fa-exclamation-triangle me-2"></i>' + (data.error || 'Errore sconosciuto') + '</div>';
        }

        modal.show();
    })
    .catch(error => {
        console.error('Errore:', error);
        const modal = new bootstrap.Modal(document.getElementById('messageModal'));
        document.getElementById('messageModalTitle').textContent = 'Errore';
        document.getElementById('messageModalBody').innerHTML = '<div class="alert alert-danger"><i class="fa-solid fa-exclamation-triangle me-2"></i>Errore di connessione. Riprova più tardi.</div>';
        modal.show();
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});

function viewRequest(id) {
    // Implementa visualizzazione richiesta
    alert('Visualizzazione richiesta #' + id + ' - Funzionalità da implementare');
}
</script>

<?php require_once '../../../includes/footer.php'; ?>
