<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Gestione Certificati';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';

// Determina il ruolo dell'utente
$userRole = $_SESSION['ruolo'] ?? '';

// Controlla se è admin
$isAdmin = ($userRole === 'Admin');

// Carica dati diversi a seconda del ruolo
if ($isAdmin) {
    // Per admin: carica tutte le richieste recenti di tutti gli utenti
    $richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id ORDER BY cr.created_at DESC LIMIT 100');
    $richieste->execute();
    $richieste = $richieste->fetchAll();

    // Statistiche per admin
    $stats = $pdo->query("
        SELECT
            COUNT(*) as total_richieste,
            SUM(CASE WHEN stato = 'done' THEN 1 ELSE 0 END) as completate,
            SUM(CASE WHEN stato = 'processing' THEN 1 ELSE 0 END) as in_elaborazione,
            SUM(CASE WHEN stato = 'error' THEN 1 ELSE 0 END) as rifiutate
        FROM certificati_richieste
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetch();
} else {
    // Per operatori: carica solo le proprie richieste
    $richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? ORDER BY cr.created_at DESC LIMIT 50');
    $richieste->execute([$_SESSION['user_id']]);
    $richieste = $richieste->fetchAll();
}

// Gestione richieste POST
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categoria'])) {
    require_once 'api/' . $_POST['categoria'] . '.php';
    // La logica è gestita nel file API specifico
    exit; // Le API restituiscono JSON direttamente
}
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once '../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certi³</h1>
                <p class="text-muted mb-0">
                    <?php if ($isAdmin): ?>
                        Pannello di gestione certificati
                    <?php else: ?>
                        Gestione certificati comunali, catastali e camerali
                    <?php endif; ?>
                </p>
            </div>
            <div class="toolbar-actions">
                <?php if ($isAdmin): ?>
                    <a href="admin.php" class="btn btn-warning">
                        <i class="fa-solid fa-cog me-2"></i>Impostazioni
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
            <!-- Pannello Admin -->
            <div class="row g-4 mb-4">
                <!-- Statistiche -->
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card ag-card text-center">
                        <div class="card-body">
                            <div class="display-4 text-primary mb-2"><?php echo $stats['total_richieste'] ?? 0; ?></div>
                            <h6 class="text-muted">Richieste Totali (30gg)</h6>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card ag-card text-center">
                        <div class="card-body">
                            <div class="display-4 text-success mb-2"><?php echo $stats['completate'] ?? 0; ?></div>
                            <h6 class="text-muted">Completate</h6>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card ag-card text-center">
                        <div class="card-body">
                            <div class="display-4 text-warning mb-2"><?php echo $stats['in_elaborazione'] ?? 0; ?></div>
                            <h6 class="text-muted">In Elaborazione</h6>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                    <div class="card ag-card text-center">
                        <div class="card-body">
                            <div class="display-4 text-danger mb-2"><?php echo $stats['rifiutate'] ?? 0; ?></div>
                            <h6 class="text-muted">Rifiutate</h6>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Gestione Richieste -->
            <div class="card ag-card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fa-solid fa-list-check me-2"></i>Gestione Richieste Operatori
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($richieste) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Operatore</th>
                                        <th>Categoria</th>
                                        <th>Tipo</th>
                                        <th>Stato</th>
                                        <th>Data</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($richieste as $richiesta): ?>
                                        <tr>
                                            <td>#<?php echo htmlspecialchars($richiesta['id']); ?></td>
                                            <td><?php echo htmlspecialchars($richiesta['nome'] . ' ' . $richiesta['cognome']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo match($richiesta['categoria']) {
                                                        'comunali' => 'primary',
                                                        'catastali' => 'success',
                                                        'camerali' => 'warning',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo htmlspecialchars(ucfirst($richiesta['categoria'])); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($richiesta['tipo'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo match($richiesta['stato']) {
                                                        'done' => 'success',
                                                        'processing' => 'warning',
                                                        'error' => 'danger',
                                                        'pending' => 'info',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php
                                                    echo match($richiesta['stato']) {
                                                        'done' => 'Completato',
                                                        'processing' => 'In elaborazione',
                                                        'error' => 'Rifiutato',
                                                        'pending' => 'Da gestire',
                                                        default => htmlspecialchars($richiesta['stato'] ?? 'Sconosciuto')
                                                    };
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($richiesta['created_at']))); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button class="btn btn-outline-primary" onclick="gestisciRichiesta(<?php echo $richiesta['id']; ?>)">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                    <?php if ($richiesta['categoria'] !== 'comunali'): ?>
                                                        <button class="btn btn-outline-success" onclick="caricaAllegato(<?php echo $richiesta['id']; ?>)">
                                                            <i class="fa-solid fa-upload"></i>
                                                        </button>
                                                        <button class="btn btn-outline-success" onclick="completaRichiesta(<?php echo $richiesta['id']; ?>)">
                                                            <i class="fa-solid fa-check"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-outline-danger" onclick="rifiutaRichiesta(<?php echo $richiesta['id']; ?>)">
                                                        <i class="fa-solid fa-times"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Nessuna richiesta da gestire.</p>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- Pannello Operatore -->
            <div class="row g-4">
                <!-- Certificati Comunali -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card ag-card h-100 border-primary">
                        <div class="card-body text-center d-flex flex-column">
                            <div class="mb-3">
                                <i class="fa-solid fa-building fa-3x text-primary"></i>
                            </div>
                            <h5 class="card-title">Certificati Comunali</h5>
                            <p class="card-text text-muted small">Anagrafici, residenza, stato civile e altri documenti comunali</p>
                            <div class="mt-auto">
                                <a href="comunali.php" class="btn btn-primary btn-lg w-100">
                                    <i class="fa-solid fa-plus me-2"></i>Richiedi Certificato
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Certificati Catastali -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card ag-card h-100 border-success">
                        <div class="card-body text-center d-flex flex-column">
                            <div class="mb-3">
                                <i class="fa-solid fa-map fa-3x text-success"></i>
                            </div>
                            <h5 class="card-title">Certificati Catastali</h5>
                            <p class="card-text text-muted small">Visure catastali, planimetrie e certificati di proprietà</p>
                            <div class="mt-auto">
                                <a href="catastali.php" class="btn btn-success btn-lg w-100">
                                    <i class="fa-solid fa-plus me-2"></i>Richiedi Certificato
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Certificati Camerali -->
                <div class="col-12 col-md-6 col-lg-4">
                    <div class="card ag-card h-100 border-warning">
                        <div class="card-body text-center d-flex flex-column">
                            <div class="mb-3">
                                <i class="fa-solid fa-industry fa-3x text-warning"></i>
                            </div>
                            <h5 class="card-title">Certificati Camerali</h5>
                            <p class="card-text text-muted small">Bilanci, certificati camerali e informazioni societarie</p>
                            <div class="mt-auto">
                                <a href="camerali.php" class="btn btn-warning btn-lg w-100">
                                    <i class="fa-solid fa-plus me-2"></i>Richiedi Certificato
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Richieste personali -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card ag-card">
                        <div class="card-header">
                            <h5 class="mb-0">Le Mie Richieste</h5>
                        </div>
                        <div class="card-body">
                            <?php if (count($richieste) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Tipo</th>
                                                <th>Stato</th>
                                                <th>Data Richiesta</th>
                                                <th>Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($richieste as $richiesta): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($richiesta['id']); ?></td>
                                                    <td><?php echo htmlspecialchars($richiesta['categoria'] . ' - ' . ($richiesta['tipo'] ?? 'N/A')); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php
                                                            echo match($richiesta['stato']) {
                                                                'done' => 'success',
                                                                'processing' => 'warning',
                                                                'error' => 'danger',
                                                                default => 'secondary'
                                                            };
                                                        ?>">
                                                            <?php
                                                            echo match($richiesta['stato']) {
                                                                'done' => 'Completato',
                                                                'processing' => 'In elaborazione',
                                                                'error' => 'Rifiutato',
                                                                'pending' => 'In attesa',
                                                                default => htmlspecialchars($richiesta['stato'] ?? 'Sconosciuto')
                                                            };
                                                            ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($richiesta['created_at']))); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-primary" onclick="mostraDettagli(<?php echo $richiesta['id']; ?>)">
                                                            <i class="fa-solid fa-eye"></i> Dettagli
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Nessuna richiesta recente trovata.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php require_once '../../../includes/footer.php'; ?>

<script>
// Funzione per mostrare i dettagli della richiesta
function mostraDettagli(richiestaId) {
    // Per ora mostriamo un alert semplice, in futuro possiamo implementare un modal
    alert('Funzionalità dettagli richiesta in sviluppo. ID richiesta: ' + richiestaId);
}

// Funzioni per admin
function gestisciRichiesta(richiestaId) {
    // Carica i dettagli della richiesta via AJAX
    fetch('api/admin_actions.php?action=get_details&id=' + richiestaId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostraModalDettagli(data.richiesta);
            } else {
                alert('Errore nel caricamento dei dettagli: ' + (data.error || 'Errore sconosciuto'));
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore di connessione');
        });
}

function caricaAllegato(richiestaId) {
    // Crea un input file nascosto e triggeralo
    const input = document.createElement('input');
    input.type = 'file';
    input.multiple = true;
    input.accept = '.pdf,.doc,.docx,.jpg,.jpeg,.png';
    input.onchange = function(e) {
        const files = e.target.files;
        if (files.length > 0) {
            caricaFile(richiestaId, files);
        }
    };
    input.click();
}

function caricaFile(richiestaId, files) {
    const formData = new FormData();
    formData.append('action', 'upload_attachment');
    formData.append('richiesta_id', richiestaId);
    formData.append('csrf_token', '<?php echo $csrfToken; ?>');

    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    fetch('api/admin_actions.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Allegati caricati con successo!');
            location.reload(); // Ricarica la pagina per aggiornare la tabella
        } else {
            alert('Errore nel caricamento: ' + (data.error || 'Errore sconosciuto'));
        }
    })
    .catch(error => {
        console.error('Errore:', error);
        alert('Errore di connessione');
    });
}

function completaRichiesta(richiestaId) {
    if (confirm('Sei sicuro di voler completare questa richiesta? L\'operatore riceverà una notifica.')) {
        fetch('api/admin_actions.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=complete&richiesta_id=' + richiestaId + '&csrf_token=<?php echo $csrfToken; ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Richiesta completata con successo!');
                location.reload(); // Ricarica la pagina per aggiornare la tabella
            } else {
                alert('Errore nel completamento: ' + (data.error || 'Errore sconosciuto'));
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore di connessione');
        });
    }
}

function rifiutaRichiesta(richiestaId) {
    const motivo = prompt('Inserisci il motivo del rifiuto (opzionale):');
    if (motivo !== null) { // L'utente non ha annullato
        fetch('api/admin_actions.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=reject&richiesta_id=' + richiestaId + '&motivo=' + encodeURIComponent(motivo) + '&csrf_token=<?php echo $csrfToken; ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Richiesta rifiutata.');
                location.reload(); // Ricarica la pagina per aggiornare la tabella
            } else {
                alert('Errore nel rifiuto: ' + (data.error || 'Errore sconosciuto'));
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore di connessione');
        });
    }
}

function mostraModalDettagli(richiesta) {
    // Crea un modal per mostrare i dettagli
    const modalHtml = `
        <div class="modal fade" id="dettagliModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Dettagli Richiesta #${richiesta.id}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>Informazioni Richiesta</h6>
                                <p><strong>Categoria:</strong> ${richiesta.categoria}</p>
                                <p><strong>Tipo:</strong> ${richiesta.tipo || 'N/A'}</p>
                                <p><strong>Stato:</strong> ${richiesta.stato}</p>
                                <p><strong>Data:</strong> ${new Date(richiesta.created_at).toLocaleString('it-IT')}</p>
                            </div>
                            <div class="col-md-6">
                                <h6>Dati Richiesta</h6>
                                <div id="datiRichiesta">
                                    ${formattaDatiRichiesta(richiesta.dati_richiesta)}
                                </div>
                            </div>
                        </div>
                        ${richiesta.documenti ? `
                        <div class="row mt-3">
                            <div class="col-12">
                                <h6>Documenti Allegati</h6>
                                <div id="documentiAllegati">
                                    ${formattaDocumenti(richiesta.documenti)}
                                </div>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                    </div>
                </div>
            </div>
        </div>
    `;

    // Rimuovi modal precedente se esiste
    const existingModal = document.getElementById('dettagliModal');
    if (existingModal) {
        existingModal.remove();
    }

    // Aggiungi il modal al body
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Mostra il modal
    const modal = new bootstrap.Modal(document.getElementById('dettagliModal'));
    modal.show();
}

function formattaDatiRichiesta(datiJson) {
    try {
        const dati = JSON.parse(datiJson);
        let html = '<dl class="row">';
        for (const [key, value] of Object.entries(dati)) {
            if (value !== null && value !== '') {
                const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                html += `<dt class="col-sm-4">${label}:</dt><dd class="col-sm-8">${value}</dd>`;
            }
        }
        html += '</dl>';
        return html;
    } catch (e) {
        return '<p class="text-muted">Errore nel caricamento dei dati</p>';
    }
}

function formattaDocumenti(documentiJson) {
    try {
        const documenti = JSON.parse(documentiJson);
        if (!Array.isArray(documenti) || documenti.length === 0) {
            return '<p class="text-muted">Nessun documento allegato</p>';
        }

        let html = '<ul class="list-group">';
        documenti.forEach(doc => {
            html += `
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    ${doc.nome || doc.filename}
                    <a href="${doc.url || '#'}" target="_blank" class="btn btn-sm btn-outline-primary">
                        <i class="fa-solid fa-download"></i> Scarica
                    </a>
                </li>
            `;
        });
        html += '</ul>';
        return html;
    } catch (e) {
        return '<p class="text-muted">Errore nel caricamento dei documenti</p>';
    }
}

// Prevenzione errori Bootstrap per modali non esistenti
document.addEventListener('DOMContentLoaded', function() {
    // Rimuovi eventuali event listeners che potrebbero causare errori
    const modalTriggers = document.querySelectorAll('[data-bs-toggle="modal"]');
    modalTriggers.forEach(trigger => {
        const target = trigger.getAttribute('data-bs-target');
        if (target && !document.querySelector(target)) {
            // Rimuovi l'attributo problematico se il modal non esiste
            trigger.removeAttribute('data-bs-toggle');
            trigger.removeAttribute('data-bs-target');
        }
    });
});
</script>
