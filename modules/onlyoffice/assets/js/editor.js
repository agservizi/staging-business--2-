(function () {
    if (typeof DocsAPI === 'undefined') {
        console.error('ONLYOFFICE SDK non disponibile.');
        return;
    }

    const config = window.onlyofficeConfig;
    if (!config) {
        console.error('Config dell\'editor mancante.');
        return;
    }

    const editorElementId = 'onlyoffice-editor';
    const editorInstance = new DocsAPI.DocEditor(editorElementId, config);
    window.onlyofficeInstance = editorInstance;

    const closeButton = document.getElementById('oo-btn-close');
    const saveButton = document.getElementById('oo-btn-save');

    function showToast(message) {
        console.log(message);
    }

    editorInstance.events.on('onDocumentReady', function () {
        showToast('Documento pronto.');
    });

    editorInstance.events.on('onError', function (error) {
        alert('Errore editor: ' + (error && error.data ? error.data : 'sconosciuto'));
    });

    if (saveButton) {
        saveButton.addEventListener('click', function () {
            editorInstance.save();
        });
    }

    if (closeButton) {
        closeButton.addEventListener('click', function () {
            window.location.href = 'index.php';
        });
    }
})();
