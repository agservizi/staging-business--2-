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
            SUM(CASE WHEN stato = 'completato' THEN 1 ELSE 0 END) as completate,
            SUM(CASE WHEN stato = 'in_elaborazione' THEN 1 ELSE 0 END) as in_elaborazione,
            SUM(CASE WHEN stato = 'rifiutato' THEN 1 ELSE 0 END) as rifiutate
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
                        <i class="fa-solid fa-list-check me-2"></i>Gestione Richieste
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (count($richieste) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Utente</th>
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
                                            <td><?php echo htmlspecialchars($richiesta['categoria'] . ' - ' . ($richiesta['tipo'] ?? 'N/A')); ?></td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo match($richiesta['stato']) {
                                                        'completato' => 'success',
                                                        'in_elaborazione' => 'warning',
                                                        'rifiutato' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php
                                                    echo match($richiesta['stato']) {
                                                        'completato' => 'Completato',
                                                        'in_elaborazione' => 'In elaborazione',
                                                        'rifiutato' => 'Rifiutato',
                                                        'pending' => 'In attesa',
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
                                                    <button class="btn btn-outline-success" onclick="approvaRichiesta(<?php echo $richiesta['id']; ?>)">
                                                        <i class="fa-solid fa-check"></i>
                                                    </button>
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
                        <p class="text-muted mb-0">Nessuna richiesta trovata.</p>
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
                                                                'completato' => 'success',
                                                                'in_elaborazione' => 'warning',
                                                                'rifiutato' => 'danger',
                                                                default => 'secondary'
                                                            };
                                                        ?>">
                                                            <?php
                                                            echo match($richiesta['stato']) {
                                                                'completato' => 'Completato',
                                                                'in_elaborazione' => 'In elaborazione',
                                                                'rifiutato' => 'Rifiutato',
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
    alert('Gestione richiesta #' + richiestaId + ' - Funzionalità in sviluppo');
}

function approvaRichiesta(richiestaId) {
    if (confirm('Sei sicuro di voler approvare questa richiesta?')) {
        alert('Richiesta #' + richiestaId + ' approvata');
        // Qui andrebbe la logica per aggiornare lo stato
    }
}

function rifiutaRichiesta(richiestaId) {
    if (confirm('Sei sicuro di voler rifiutare questa richiesta?')) {
        alert('Richiesta #' + richiestaId + ' rifiutata');
        // Qui andrebbe la logica per aggiornare lo stato
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
