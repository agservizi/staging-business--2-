<?php

declare(strict_types=1);

use Modules\Onlyoffice as OnlyOffice;

require __DIR__ . '/config.php';

$id = $_GET['id'] ?? '';
if ($id === '') {
    http_response_code(400);
    echo 'Missing document identifier.';
    exit;
}

try {
    $user = OnlyOffice\requireUser();
    $file = OnlyOffice\getFileInfo($id);
    $config = OnlyOffice\buildDocumentConfig($file, $user);
    $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $documentServer = rtrim(Modules\Onlyoffice\DOCUMENT_SERVER_URL, '/');
} catch (Throwable $exception) {
    http_response_code(500);
    echo 'Errore: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Editor ONLYOFFICE - <?= htmlspecialchars($file['name'], ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="assets/css/editor.css?v=1">
    <script src="<?= $documentServer ?>/web-apps/apps/api/documents/api.js"></script>
</head>
<body class="oo-layout editor">
<header class="oo-header">
    <div>
        <h1><?= htmlspecialchars($file['name'], ENT_QUOTES) ?></h1>
        <p>Tipo file: <?= strtoupper(htmlspecialchars($file['extension'], ENT_QUOTES)) ?> · Ultimo aggiornamento: <?= date('d/m/Y H:i', $file['updatedAt']) ?></p>
    </div>
    <div class="oo-editor-actions">
        <button id="oo-btn-save" type="button">Salva</button>
        <button id="oo-btn-close" type="button">Chiudi editor</button>
    </div>
</header>
<div id="onlyoffice-editor" class="onlyoffice-editor"></div>
<script>
window.onlyofficeConfig = <?= $configJson ?>;
window.onlyofficeDocId = <?= json_encode($file['id']) ?>;
</script>
<script src="assets/js/editor.js?v=1"></script>
</body>
</html>
