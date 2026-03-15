<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$opportunityId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

if ($opportunityId <= 0 || $collaboratorId <= 0) {
    add_flash('warning', 'Opportunity non trovata.');
    header('Location: ' . opportunities_collaborator_url('list'));
    exit;
}

$opportunity = $opportunityService->findById($opportunityId);
if ($opportunity === null || (int) ($opportunity['collaborator_id'] ?? 0) !== $collaboratorId) {
    add_flash('warning', 'Non hai accesso a questa opportunity.');
    header('Location: ' . opportunities_collaborator_url('list'));
    exit;
}

$errors = [];
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $noteBody = isset($_POST['note_body']) ? trim((string) $_POST['note_body']) : '';
        $updatedNotes = $opportunityService->appendCollaboratorNote(
            $opportunityId,
            $collaboratorId,
            $noteBody,
            current_user_display_name()
        );
        $opportunity['additional_notes'] = $updatedNotes;
        add_flash('success', 'Nota aggiunta correttamente.');
        header('Location: ' . opportunities_collaborator_url('notes', ['id' => $opportunityId]));
        exit;
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$viewUrl = opportunities_collaborator_url('view', ['id' => $opportunityId]);
$listUrl = opportunities_collaborator_url('list');

$notesContent = trim((string) ($opportunity['additional_notes'] ?? ''));
$notesSegments = $notesContent !== '' ? preg_split('/\n{1,2}-+\n{1,2}/', $notesContent) : [];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity #<?php echo (int) $opportunity['id']; ?></p>
                <h1 class="h4 mb-1">Note collaboratore</h1>
                <p class="text-muted mb-0">Aggiungi aggiornamenti operativi visibili al team interno.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($viewUrl); ?>">
                    <i class="fa-solid fa-eye me-2"></i>Dettaglio opportunity
                </a>
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($listUrl); ?>">
                    <i class="fa-solid fa-table-list me-2"></i>Vista tabellare
                </a>
            </div>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h6 text-uppercase text-muted mb-3">Timeline note</h2>
                        <?php if (!$notesSegments): ?>
                            <p class="text-muted mb-0">Ancora nessuna nota salvata.</p>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3 overflow-auto" style="max-height: 480px;">
                                <?php foreach ($notesSegments as $segment): ?>
                                    <div class="p-3 rounded border bg-light">
                                        <?php echo nl2br(sanitize_output(trim($segment)), false); ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h6 text-uppercase text-muted mb-3">Nuova nota</h2>
                        <form method="post" class="d-flex flex-column gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $opportunityId; ?>">
                            <div>
                                <label class="form-label text-uppercase small text-muted">Messaggio</label>
                                <textarea class="form-control" name="note_body" rows="8" maxlength="2000" placeholder="Es. Ho ricontattato il cliente per completare la firma."></textarea>
                                <p class="text-muted small mb-0 mt-2">Il testo viene salvato con data e autore. Max 2000 caratteri.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-success" type="submit">
                                    <i class="fa-solid fa-plus me-2"></i>Salva nota
                                </button>
                                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($viewUrl); ?>">Annulla</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
