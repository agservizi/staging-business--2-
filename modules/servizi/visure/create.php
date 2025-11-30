<?php

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Nuova visura catastale';
$docUrl = 'https://console.openapi.com/it/apis/catasto/documentation?_gl=1*1bujcfo*_gcl_aw*R0NMLjE3NjE2ODkzMDkuQ2p3S0NBancwNEhJQmhCOEVpd0E4akdOYlNqZ1JyYjQ5R3BaU2xwWC1vQmJVQ1hhX1hteHh2VkhYc1pUZ3lPOWFLTThQSzJHNHVNaUF4b0NZQ2NRQXZEX0J3RQ..*_gcl_au*NDE1MTk4OTI3LjE3NjA2NDM1MDk.*_ga*MjA4NjEzNDgyOC4xNzYwNjQzNTEy*_ga_NWG43T6K5G*czE3NjIxMDcyNjMkbzckZzAkdDE3NjIxMDcyNjMkajYwJGwwJGgw#tag/Visura-Catastale/paths/~1visura_catastale/post';

$apiKeyAvailable = trim((string) (env('OPENAPI_CATASTO_API_KEY') ?? env('OPENAPI_SANDBOX_API_KEY') ?? '')) !== '';
$tokenAvailable = trim((string) (env('OPENAPI_CATASTO_TOKEN') ?? env('OPENAPI_CATASTO_SANDBOX_TOKEN') ?? '')) !== '';
$catastoConfigured = $apiKeyAvailable && $tokenAvailable;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Nuova visura catastale</h1>
                <p class="text-muted mb-0">Invia una nuova richiesta al Catasto o scarica manualmente una pratica già presente su OpenAPI.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="index.php">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna all'elenco
                </a>
            </div>
        </div>
        <?php if (!$catastoConfigured): ?>
        <div class="alert alert-warning" role="alert">
            Completa la configurazione delle credenziali Catasto nel file <code>.env</code> per abilitare l'invio delle richieste e il download manuale.
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xxl-8">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Invia una nuova richiesta</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Compila i dati catastali per generare una nuova pratica tramite l'endpoint <code>POST /visura_catastale</code>. La richiesta verrà salvata e potrai seguirne lo stato dalla dashboard.</p>
                        <form action="store.php" method="post" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                            <fieldset class="row g-3" <?php echo $catastoConfigured ? '' : 'disabled'; ?> data-catasto-fieldset>
                                <div class="col-12">
                                    <label class="form-label d-block">Tipologia richiesta</label>
                                    <div class="btn-group" role="group" aria-label="Tipologia visura">
                                        <input type="radio" class="btn-check" name="request_type" id="request-type-immobile" value="immobile" checked>
                                        <label class="btn btn-outline-primary" for="request-type-immobile">Immobile</label>
                                        <input type="radio" class="btn-check" name="request_type" id="request-type-soggetto" value="soggetto">
                                        <label class="btn btn-outline-primary" for="request-type-soggetto">Soggetto</label>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="codice-fiscale">Codice fiscale / P.IVA</label>
                                    <input type="text" class="form-control text-uppercase" id="codice-fiscale" name="codice_fiscale" placeholder="es. RSSMRA80A01H501Z" maxlength="20" data-required-soggetto-general="true">
                                    <div class="form-text">Obbligatorio per le richieste soggetto; per gli immobili popola automaticamente il richiedente se lasci il campo sottostante vuoto.</div>
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="richiedente">Richiedente</label>
                                    <input type="text" class="form-control" id="richiedente" name="richiedente" placeholder="Codice fiscale o ragione sociale" maxlength="150">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="tipo-visura">Tipo visura</label>
                                    <select class="form-select" id="tipo-visura" name="tipo_visura">
                                        <option value="ordinaria">Ordinaria</option>
                                        <option value="storica">Storica</option>
                                    </select>
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="tipo-catasto">Tipo catasto</label>
                                    <select class="form-select" id="tipo-catasto" name="tipo_catasto" data-required-immobile="true">
                                        <option value="F">Fabbricati (F)</option>
                                        <option value="T">Terreni (T)</option>
                                    </select>
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="provincia">Provincia</label>
                                    <input type="text" class="form-control text-uppercase" id="provincia" name="provincia" placeholder="es. RM" maxlength="2" data-required-immobile="true">
                                </div>
                                <div class="col-md-6" data-section="immobile">
                                    <label class="form-label" for="comune">Comune</label>
                                    <input type="text" class="form-control" id="comune" name="comune" placeholder="es. Roma" maxlength="120" data-required-immobile="true">
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="sezione">Sezione</label>
                                    <input type="text" class="form-control text-uppercase" id="sezione" name="sezione" placeholder="Facoltativa" maxlength="5">
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="sezione-urbana">Sezione urbana</label>
                                    <input type="text" class="form-control text-uppercase" id="sezione-urbana" name="sezione_urbana" placeholder="Facoltativa" maxlength="5">
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="foglio">Foglio</label>
                                    <input type="text" class="form-control" id="foglio" name="foglio" placeholder="es. 123" maxlength="10" data-required-immobile="true">
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="particella">Particella</label>
                                    <input type="text" class="form-control" id="particella" name="particella" placeholder="es. 456" maxlength="10" data-required-immobile="true">
                                </div>
                                <div class="col-md-3" data-section="immobile">
                                    <label class="form-label" for="subalterno">Subalterno</label>
                                    <input type="text" class="form-control" id="subalterno" name="subalterno" placeholder="Facoltativo" maxlength="10">
                                </div>
                                <div class="col-md-3 d-none" data-section="soggetto">
                                    <label class="form-label" for="tipo-soggetto">Tipo soggetto</label>
                                    <select class="form-select" id="tipo-soggetto" name="tipo_soggetto" data-required-soggetto="true">
                                        <option value="persona_fisica">Persona fisica</option>
                                        <option value="persona_giuridica">Persona giuridica</option>
                                    </select>
                                </div>
                                <div class="col-md-3 d-none" data-section="soggetto">
                                    <label class="form-label" for="provincia-soggetto">Provincia</label>
                                    <input type="text" class="form-control text-uppercase" id="provincia-soggetto" name="provincia_soggetto" placeholder="es. RM" maxlength="2" data-required-soggetto="true">
                                </div>
                                <div class="col-md-6 d-none" data-section="soggetto">
                                    <label class="form-label" for="comune-soggetto">Comune</label>
                                    <input type="text" class="form-control" id="comune-soggetto" name="comune_soggetto" placeholder="es. Roma" maxlength="120">
                                </div>
                                <div class="col-md-3 d-none" data-section="soggetto">
                                    <label class="form-label" for="tipo-catasto-soggetto">Tipo catasto</label>
                                    <select class="form-select" id="tipo-catasto-soggetto" name="tipo_catasto_soggetto">
                                        <option value="TF">TF - Terreni e Fabbricati</option>
                                        <option value="T">T - Terreni</option>
                                        <option value="F">F - Fabbricati</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-link p-0" type="button" data-bs-toggle="collapse" data-bs-target="#callback-settings" aria-expanded="false" aria-controls="callback-settings">
                                        <i class="fa-solid fa-plug me-2"></i>Impostazioni callback opzionali
                                    </button>
                                </div>
                                <div class="collapse" id="callback-settings">
                                    <div class="row g-3 pt-1">
                                        <div class="col-lg-6">
                                            <label class="form-label" for="callback-url">Callback URL</label>
                                            <input type="url" class="form-control" id="callback-url" name="callback_url" placeholder="https://example.com/hooks/visure">
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label" for="callback-method">Metodo</label>
                                            <select class="form-select" id="callback-method" name="callback_method">
                                                <option value="POST">POST</option>
                                                <option value="GET">GET</option>
                                                <option value="PUT">PUT</option>
                                                <option value="PATCH">PATCH</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label" for="callback-field">Campo payload</label>
                                            <input type="text" class="form-control" id="callback-field" name="callback_field" placeholder="es. visura" maxlength="50">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label" for="callback-payload">Payload JSON</label>
                                            <textarea class="form-control" id="callback-payload" name="callback_payload" rows="3" placeholder='{"chiave":"valore"}'></textarea>
                                            <div class="form-text">Facoltativo: verrà inviato al callback insieme all'ID della visura.</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-paper-plane me-2"></i>Invia richiesta visura
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Scarica una richiesta esistente</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Inserisci l'ID pratica già generato sul portale OpenAPI per scaricare il PDF della visura e aggiornarne lo stato nel gestionale.</p>
                        <form action="download_document.php" method="post" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="redirect" value="create">
                            <fieldset class="row g-3" <?php echo $catastoConfigured ? '' : 'disabled'; ?>>
                                <div class="col-sm-8">
                                    <label class="form-label" for="request-id">ID richiesta visura</label>
                                    <input type="text" class="form-control" id="request-id" name="request_id" placeholder="es. 123456789" maxlength="150" required>
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label" for="archive-checkbox">Archiviazione</label>
                                    <div class="form-check form-switch pt-2">
                                        <input class="form-check-input" type="checkbox" id="archive-checkbox" name="archive" value="1" checked>
                                        <label class="form-check-label small" for="archive-checkbox">Salva automaticamente nell'area documentale</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-file-arrow-down me-2"></i>Scarica e archivia visura
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-xxl-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Suggerimenti</h2>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small mb-0">
                            <li class="mb-2"><i class="fa-solid fa-circle-info me-2 text-warning"></i>Provincia, comune, foglio e particella sono sempre obbligatori per le richieste immobile.</li>
                            <li class="mb-2"><i class="fa-solid fa-circle-info me-2 text-warning"></i>Per le richieste soggetto sono richiesti codice fiscale/P.IVA e la provincia dell'ufficio catastale.</li>
                            <li class="mb-2"><i class="fa-solid fa-circle-info me-2 text-warning"></i>Il campo richiedente facilita l'associazione della pratica a un cliente nella dashboard.</li>
                            <li class="mb-0"><i class="fa-solid fa-circle-info me-2 text-warning"></i>L'ID pratica è disponibile nella console OpenAPI e nelle notifiche email del servizio.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
                <?php if ($catastoConfigured): ?>
                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var fieldset = document.querySelector('[data-catasto-fieldset]');
                    if (!fieldset || fieldset.disabled) {
                        return;
                    }

                    var radios = fieldset.querySelectorAll('input[name="request_type"]');
                    var sections = fieldset.querySelectorAll('[data-section]');

                    function toggle(type) {
                        sections.forEach(function (section) {
                            var isActive = section.dataset.section === type;
                            section.classList.toggle('d-none', !isActive);
                            section.querySelectorAll('input, select, textarea').forEach(function (input) {
                                var requiresSoggetto = input.dataset.requiredSoggetto === 'true';
                                var requiresImmobile = input.dataset.requiredImmobile === 'true';
                                input.required = (type === 'soggetto' && requiresSoggetto) || (type === 'immobile' && requiresImmobile);
                                input.disabled = !isActive;
                            });
                        });

                        fieldset.querySelectorAll('[data-required-soggetto-general="true"]').forEach(function (input) {
                            input.required = (type === 'soggetto');
                        });
                    }

                    function refresh() {
                        var current = fieldset.querySelector('input[name="request_type"]:checked');
                        toggle(current ? current.value : 'immobile');
                    }

                    radios.forEach(function (radio) {
                        radio.addEventListener('change', refresh);
                    });

                    refresh();
                });
                </script>
                <?php endif; ?>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
