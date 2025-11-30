<?php
require_once '../../../includes/db_connect.php';
require_once '../../../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

// Verifica che sia admin
if (!isset($_SESSION['ruolo']) || $_SESSION['ruolo'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Accesso negato']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'get_details':
            // Ottieni dettagli di una richiesta
            if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
                throw new Exception('ID richiesta non valido');
            }

            $stmt = $pdo->prepare('
                SELECT cr.*, u.nome, u.cognome
                FROM certificati_richieste cr
                JOIN users u ON cr.user_id = u.id
                WHERE cr.id = ?
            ');
            $stmt->execute([$_GET['id']]);
            $richiesta = $stmt->fetch();

            if (!$richiesta) {
                throw new Exception('Richiesta non trovata');
            }

            echo json_encode([
                'success' => true,
                'richiesta' => $richiesta
            ]);
            break;

        case 'upload_attachment':
            // Carica allegati per una richiesta
            if (!isset($_POST['richiesta_id']) || !is_numeric($_POST['richiesta_id'])) {
                throw new Exception('ID richiesta non valido');
            }

            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF non valido');
            }

            $richiesta_id = $_POST['richiesta_id'];

            // Verifica che la richiesta esista
            $stmt = $pdo->prepare('SELECT id, documenti FROM certificati_richieste WHERE id = ?');
            $stmt->execute([$richiesta_id]);
            $richiesta = $stmt->fetch();

            if (!$richiesta) {
                throw new Exception('Richiesta non trovata');
            }

            $documenti = json_decode($richiesta['documenti'] ?? '[]', true) ?: [];

            // Directory per gli upload
            $uploadDir = '../../../uploads/certificati/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $uploadedFiles = [];

            if (isset($_FILES['files'])) {
                $files = $_FILES['files'];

                // Se è un singolo file, convertilo in array
                if (!is_array($files['name'])) {
                    $files = [
                        'name' => [$files['name']],
                        'type' => [$files['type']],
                        'tmp_name' => [$files['tmp_name']],
                        'error' => [$files['error']],
                        'size' => [$files['size']]
                    ];
                }

                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] === UPLOAD_ERR_OK) {
                        $filename = $files['name'][$i];
                        $tmpName = $files['tmp_name'][$i];

                        // Genera nome file sicuro
                        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
                        $uniqueFilename = time() . '_' . $richiesta_id . '_' . $safeFilename;
                        $destination = $uploadDir . $uniqueFilename;

                        if (move_uploaded_file($tmpName, $destination)) {
                            $documenti[] = [
                                'nome' => $filename,
                                'filename' => $uniqueFilename,
                                'url' => 'uploads/certificati/' . $uniqueFilename,
                                'uploaded_at' => date('Y-m-d H:i:s'),
                                'uploaded_by' => $_SESSION['user_id']
                            ];
                            $uploadedFiles[] = $filename;
                        }
                    }
                }
            }

            if (empty($uploadedFiles)) {
                throw new Exception('Nessun file caricato');
            }

            // Aggiorna il database
            $stmt = $pdo->prepare('UPDATE certificati_richieste SET documenti = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([json_encode($documenti), $richiesta_id]);

            echo json_encode([
                'success' => true,
                'message' => 'Allegati caricati con successo',
                'uploaded_files' => $uploadedFiles
            ]);
            break;

        case 'complete':
            // Completa una richiesta
            if (!isset($_POST['richiesta_id']) || !is_numeric($_POST['richiesta_id'])) {
                throw new Exception('ID richiesta non valido');
            }

            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF non valido');
            }

            $richiesta_id = $_POST['richiesta_id'];

            // Verifica che la richiesta esista e sia pending
            $stmt = $pdo->prepare('SELECT id, stato, user_id FROM certificati_richieste WHERE id = ?');
            $stmt->execute([$richiesta_id]);
            $richiesta = $stmt->fetch();

            if (!$richiesta) {
                throw new Exception('Richiesta non trovata');
            }

            if ($richiesta['stato'] === 'done') {
                throw new Exception('Richiesta già completata');
            }

            // Aggiorna lo stato
            $stmt = $pdo->prepare('UPDATE certificati_richieste SET stato = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute(['done', $richiesta_id]);

            // TODO: Invia notifica all'operatore
            // send_notification($richiesta['user_id'], 'Richiesta completata', "La tua richiesta di certificato #$richiesta_id è stata completata.");

            echo json_encode([
                'success' => true,
                'message' => 'Richiesta completata con successo'
            ]);
            break;

        case 'reject':
            // Rifiuta una richiesta
            if (!isset($_POST['richiesta_id']) || !is_numeric($_POST['richiesta_id'])) {
                throw new Exception('ID richiesta non valido');
            }

            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF non valido');
            }

            $richiesta_id = $_POST['richiesta_id'];
            $motivo = trim($_POST['motivo'] ?? '');

            // Verifica che la richiesta esista
            $stmt = $pdo->prepare('SELECT id, stato, user_id FROM certificati_richieste WHERE id = ?');
            $stmt->execute([$richiesta_id]);
            $richiesta = $stmt->fetch();

            if (!$richiesta) {
                throw new Exception('Richiesta non trovata');
            }

            if ($richiesta['stato'] === 'error') {
                throw new Exception('Richiesta già rifiutata');
            }

            // Aggiorna lo stato e aggiungi errore
            $errore = $motivo ? "Rifiutato: $motivo" : 'Richiesta rifiutata dall\'amministratore';
            $stmt = $pdo->prepare('UPDATE certificati_richieste SET stato = ?, errore = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute(['error', $errore, $richiesta_id]);

            // TODO: Invia notifica all'operatore
            // send_notification($richiesta['user_id'], 'Richiesta rifiutata', "La tua richiesta di certificato #$richiesta_id è stata rifiutata. Motivo: $motivo");

            echo json_encode([
                'success' => true,
                'message' => 'Richiesta rifiutata'
            ]);
            break;

        default:
            throw new Exception('Azione non valida');
    }

} catch (Exception $e) {
    error_log("Errore admin_actions: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>