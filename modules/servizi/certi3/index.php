<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Gestione Certificati';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';

// Carica richieste recenti
$richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? ORDER BY cr.created_at DESC LIMIT 50');
$richieste->execute([$_SESSION['user_id']]);
$richieste = $richieste->fetchAll();

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
                <p class="text-muted mb-0">Gestione certificati comunali, catastali e camerali</p>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#nuovaRichiestaModal">
                    <i class="fa-solid fa-plus me-2"></i>Nuova Richiesta
                </button>
            </div>
        </div>
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

        <!-- Richieste recenti -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card ag-card">
                    <div class="card-header">
                        <h5 class="mb-0">Richieste Recenti</h5>
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
                                                <td><?php echo htmlspecialchars($richiesta['tipo_certificato']); ?></td>
                                                <td>
                                                    <span class="badge bg-<?php 
                                                        echo match($richiesta['stato']) {
                                                            'completato' => 'success',
                                                            'in_elaborazione' => 'warning',
                                                            'errore' => 'danger',
                                                            default => 'secondary'
                                                        };
                                                    ?>">
                                                        <?php echo htmlspecialchars($richiesta['stato']); ?>
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
    </main>
</div>

<?php require_once '../../../includes/footer.php'; ?>

<script>
// Funzione per mostrare i dettagli della richiesta
function mostraDettagli(richiestaId) {
    // Per ora mostriamo un alert semplice, in futuro possiamo implementare un modal
    alert('Funzionalità dettagli richiesta in sviluppo. ID richiesta: ' + richiestaId);
}
</script>
