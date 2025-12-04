<?php

declare(strict_types=1);

use Modules\Onlyoffice as OnlyOffice;

require __DIR__ . '/config.php';

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {
        case 'list':
            respondJson(['data' => OnlyOffice\listDocuments()]);
            break;
        case 'create':
            ensurePost();
            $user = OnlyOffice\requireUser();
            $type = strtolower($_POST['type'] ?? 'docx');
            $title = trim($_POST['title'] ?? 'Nuovo documento');
            $file = OnlyOffice\createDocumentFromTemplate($type, $title, $user);
            respondJson(['data' => $file]);
            break;
        case 'upload':
            ensurePost();
            $user = OnlyOffice\requireUser();
            if (empty($_FILES['file']['tmp_name'])) {
                throw new RuntimeException('File non trovato');
            }

            if ((int) $_FILES['file']['size'] > Modules\Onlyoffice\MAX_UPLOAD_SIZE) {
                throw new RuntimeException('File troppo grande');
            }

            $originalName = $_FILES['file']['name'] ?? 'documento';
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, Modules\Onlyoffice\ALLOWED_EXTENSIONS, true)) {
                throw new RuntimeException('Estensione non supportata');
            }

            $binary = file_get_contents($_FILES['file']['tmp_name']);
            if ($binary === false) {
                throw new RuntimeException('Impossibile leggere il file caricato');
            }

            $file = OnlyOffice\persistBinaryDocument($extension, $originalName, $binary, $user);
            respondJson(['data' => $file]);
            break;
        case 'download':
            $id = $_GET['id'] ?? '';
            if ($id === '') {
                throw new RuntimeException('ID mancante');
            }

            $file = OnlyOffice\getFileInfo($id);
            $binary = OnlyOffice\readDecryptedBinary($file['storagePath']);
            header('Content-Type: ' . Modules\Onlyoffice\mimeType($file['extension']));
            header('Content-Disposition: inline; filename="' . basename($file['name']) . '"');
            header('Content-Length: ' . strlen($binary));
            echo $binary;
            exit;
        case 'info':
            $id = $_GET['id'] ?? '';
            if ($id === '') {
                throw new RuntimeException('ID mancante');
            }

            $file = OnlyOffice\getFileInfo($id);
            unset($file['storagePath']);
            respondJson(['data' => $file]);
            break;
        default:
            throw new RuntimeException('Azione non supportata');
    }
} catch (Throwable $exception) {
    http_response_code(400);
    respondJson(['error' => $exception->getMessage()]);
}

function ensurePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Metodo non consentito');
    }
}

function respondJson(array $payload): void
{
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
