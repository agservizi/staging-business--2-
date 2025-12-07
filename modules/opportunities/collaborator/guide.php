<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$pageTitle = 'Guida Opportunity';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Guida</p>
                <h1 class="h4 mb-0">Come usare il portale Opportunity</h1>
                <p class="text-muted mb-0">Istruzioni operative, flussi passo-passo e FAQ per collaboratori.</p>
            </div>
            <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                <i class="fa-solid fa-plus me-2"></i>Nuova OP
            </a>
        </div>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Percorso rapido</h2>
                        <ol class="list-group list-group-numbered mb-4">
                            <li class="list-group-item">
                                <strong>Prepara i dati cliente</strong> · CF, documento, contatti, indirizzo.
                            </li>
                            <li class="list-group-item">
                                <strong>Seleziona categoria e gestore</strong> · Telefonia/Luce/Gas, offerta disponibile.
                            </li>
                            <li class="list-group-item">
                                <strong>Compila pagamento</strong> · IBAN (validazione Stripe) o bollettino se previsto.
                            </li>
                            <li class="list-group-item">
                                <strong>Allega documenti</strong> · Carta identità, modulo, eventuali allegati gestore.
                            </li>
                            <li class="list-group-item">
                                <strong>Invia</strong> · Salva e invia; segui stato in “Elenco OP”.
                            </li>
                        </ol>

                        <h2 class="h6 text-uppercase text-muted mb-3">Flussi principali</h2>
                        <div class="accordion" id="guideFlows">
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="flowCreateHeading">
                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#flowCreate" aria-expanded="true" aria-controls="flowCreate">
                                        Creazione di una nuova opportunity
                                    </button>
                                </h2>
                                <div id="flowCreate" class="accordion-collapse collapse show" aria-labelledby="flowCreateHeading" data-bs-parent="#guideFlows">
                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            <li>Usa “Nuova OP” o duplica da elenco (icona copia).</li>
                                            <li>Ricerca cliente via codice fiscale per autocompilare dati e pagamento.</li>
                                            <li>Telefonia: scegli tipologia contratto (Migrazione vs Nuova attivazione); migrazione richiede operatore, numero, codice migrazione.</li>
                                            <li>Luce/Gas: inserisci POD/PDR.</li>
                                            <li>Pagamento IBAN: valida via Stripe (badge verde in dettaglio se riuscita).</li>
                                            <li>Carica allegati richiesti dal gestore; verifica requisiti dimensione/formato.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="flowReopenHeading">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#flowReopen" aria-expanded="false" aria-controls="flowReopen">
                                        Riaperture e rettifiche
                                    </button>
                                </h2>
                                <div id="flowReopen" class="accordion-collapse collapse" aria-labelledby="flowReopenHeading" data-bs-parent="#guideFlows">
                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            <li>L'admin può riaprire una pratica in stato “in verifica”.</li>
                                            <li>Accedi a “Elenco OP” e clicca “Modifica” per correggere.</li>
                                            <li>Salva: la pratica torna in verifica e lo stato viene aggiornato.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="flowFilesHeading">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#flowFiles" aria-expanded="false" aria-controls="#flowFiles">
                                        Allegati e note
                                    </button>
                                </h2>
                                <div id="flowFiles" class="accordion-collapse collapse" aria-labelledby="flowFilesHeading" data-bs-parent="#guideFlows">
                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            <li>Allega documenti al momento dell'invio oppure successivamente dalla scheda OP.</li>
                                            <li>Usa “Note” per comunicazioni interne e “Sollecito” per richiamare l'attenzione.</li>
                                            <li>I file restano collegati e visibili ad admin/manager.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-5 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">FAQ rapide</h2>
                        <div class="d-flex flex-column gap-3">
                            <div>
                                <p class="fw-semibold mb-1">Perché non vedo IBAN validato?</p>
                                <p class="text-muted mb-0 small">La validazione IBAN avviene automaticamente in salvataggio quando compili il campo IBAN e i dati intestatario. In dettaglio comparirà il badge “Validato” con banca/cifre se l'esito Stripe è positivo.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Quando servono operatore e numero?</p>
                                <p class="text-muted mb-0 small">Solo per telefonia in modalità “Migrazione”. Per “Nuova attivazione” i campi restano nascosti.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Posso recuperare un cliente esistente?</p>
                                <p class="text-muted mb-0 small">Sì, inserisci CF e usa “Recupera”: la scheda cliente si autocompila (dati, documento, pagamento).</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Come vedo le mie provvigioni?</p>
                                <p class="text-muted mb-0 small">Vai su “Provvigioni”: timeline mensile, dettagli mese, stato delle OP conteggiate.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Cosa significa stato “in_verifica”?</p>
                                <p class="text-muted mb-0 small">La pratica è in revisione admin. Se riaperta, puoi modificarla; quando chiusa passa ad “attivata” o “annullata”. L'etichetta può apparire come “in verifica”.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Suggerimenti operativi</h2>
                        <ul class="mb-0">
                            <li>Compila tutti i campi obbligatori prima di caricare i file.</li>
                            <li>Uniforma i nomi file: cliente-cf-documento.pdf.</li>
                            <li>Usa la rubrica “Clienti” per riaprire rapidamente schede e storico.</li>
                            <li>Controlla la sezione Promemoria per solleciti automatici.</li>
                            <li>Per dubbi su POD/PDR o codici migrazione, verifica il documento bolletta cliente.</li>
                        </ul>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Supporto</h2>
                        <p class="mb-2">Se hai problemi:</p>
                        <ul class="mb-3">
                            <li>Apri un ticket da “Ticket”.</li>
                            <li>Lascia una nota su una OP in verifica.</li>
                            <li>Contatta il manager di riferimento.</li>
                        </ul>
                        <p class="text-muted small mb-0">Questa guida viene aggiornata periodicamente. Segnala mancanze o errori.</p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
