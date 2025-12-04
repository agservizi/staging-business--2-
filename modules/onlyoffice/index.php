<?php

declare(strict_types=1);

use Modules\Onlyoffice as OnlyOffice;

require __DIR__ . '/config.php';

$user = OnlyOffice\requireUser();
$documents = OnlyOffice\listDocuments();
$documentServer = rtrim(Modules\Onlyoffice\DOCUMENT_SERVER_URL, '/');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>ONLYOFFICE - Modulo Coresuite</title>
    <link rel="stylesheet" href="assets/css/editor.css?v=1">
</head>
<body class="oo-layout">
<header class="oo-header">
    <div>
        <h1>ONLYOFFICE Document Hub</h1>
        <p>Gestisci e modifica i documenti Word / Excel / PowerPoint direttamente da Coresuite.</p>
    </div>
    <div class="oo-user-card">
        <span class="oo-user-name"><?= htmlspecialchars($user['name'] ?? 'Utente', ENT_QUOTES) ?></span>
        <span class="oo-user-role"><?= htmlspecialchars(strtoupper($user['role'] ?? 'user'), ENT_QUOTES) ?></span>
    </div>
</header>

<section class="oo-panels">
    <div class="oo-panel">
        <h2>Crea un documento</h2>
        <form class="oo-create-form" data-type="docx">
            <label>
                Nome documento
                <input type="text" name="title" placeholder="Nuovo documento" required>
            </label>
            <input type="hidden" name="type" value="docx">
            <button type="submit">Nuovo DOCX</button>
        </form>
        <form class="oo-create-form" data-type="xlsx">
            <label>
                Nome foglio
                <input type="text" name="title" placeholder="Nuovo foglio" required>
            </label>
            <input type="hidden" name="type" value="xlsx">
            <button type="submit">Nuovo XLSX</button>
        </form>
        <form class="oo-create-form" data-type="pptx">
            <label>
                Nome presentazione
                <input type="text" name="title" placeholder="Nuova presentazione" required>
            </label>
            <input type="hidden" name="type" value="pptx">
            <button type="submit">Nuovo PPTX</button>
        </form>
    </div>

    <div class="oo-panel">
        <h2>Carica un file esistente</h2>
        <form id="oo-upload-form" enctype="multipart/form-data">
            <input type="file" name="file" accept=".docx,.xlsx,.pptx" required>
            <button type="submit">Carica e apri</button>
        </form>
    </div>
</section>

<section class="oo-panel">
    <h2>Documenti disponibili</h2>
    <?php if (empty($documents)): ?>
        <p>Nessun documento presente. Creane uno nuovo oppure carica un file.</p>
    <?php else: ?>
    <table class="oo-table">
        <thead>
            <tr>
                <th>Nome</th>
                <th>Tipo</th>
                <th>Ultimo aggiornamento</th>
                <th>Dimensione</th>
                <th>Azione</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($documents as $doc): ?>
            <tr>
                <td><?= htmlspecialchars($doc['name'], ENT_QUOTES) ?></td>
                <td><?= strtoupper(htmlspecialchars($doc['extension'], ENT_QUOTES)) ?></td>
                <td><?= date('d/m/Y H:i', $doc['updatedAt']) ?></td>
                <td><?= number_format(($doc['size'] ?? 0) / 1024, 1) ?> KB</td>
                <td>
                    <a class="oo-button" href="editor.php?id=<?= urlencode($doc['id']) ?>">Apri</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</section>

<section class="oo-panel">
    <h2>Configurazione</h2>
    <ul class="oo-inline-list">
        <li><strong>Document Server:</strong> <?= htmlspecialchars($documentServer, ENT_QUOTES) ?></li>
        <li><strong>JWT:</strong> <?= Modules\Onlyoffice\DOCUMENT_SERVER_USE_JWT ? 'Attivo' : 'Disattivo' ?></li>
        <li><strong>Ruolo corrente:</strong> <?= htmlspecialchars($user['role'] ?? 'user', ENT_QUOTES) ?></li>
    </ul>
</section>

<script>
const createForms = document.querySelectorAll('.oo-create-form');
const uploadForm = document.getElementById('oo-upload-form');

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
</script>
</body>
</html>
