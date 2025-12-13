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
                            <li class="list-group-item"><strong>Cerca/recupera cliente</strong> · Inserisci CF e usa “Recupera”.</li>
                            <li class="list-group-item"><strong>Compila dati anagrafici</strong> · Nome, contatti, indirizzo, documento.</li>
                            <li class="list-group-item"><strong>Scegli categoria/gestore</strong> · Telefonia, Luce, Gas, offerta.</li>
                            <li class="list-group-item"><strong>Pagamento</strong> · IBAN (validazione automatica) o bollettino.</li>
                            <li class="list-group-item"><strong>Allega documenti</strong> · Documento, moduli gestore, altri allegati.</li>
                            <li class="list-group-item"><strong>Invia</strong> · Salva, controlla stato in “Elenco OP”.</li>
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
                                            <li>Apri “Nuova OP” o duplica da elenco.</li>
                                            <li>Recupera cliente tramite CF per precompilare dati e pagamento.</li>
                                            <li>Telefonia: scegli “Migrazione” o “Nuova attivazione”; per migrazione servono operatore, numero, codice migrazione.</li>
                                            <li>Luce/Gas: inserisci POD/PDR e dati fornitura.</li>
                                            <li>Pagamento: inserisci IBAN (validazione automatica) o bollettino.</li>
                                            <li>Allegati: carica documento identità e moduli richiesti dal gestore.</li>
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
                                            <li>Allega subito o dalla scheda OP; formati accettati: PDF/JPG/PNG.</li>
                                            <li>Usa “Note” per commenti interni; “Sollecito” per richiamare l'attenzione.</li>
                                            <li>I file restano collegati e visibili a admin/manager.</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="accordion-item">
                                <h2 class="accordion-header" id="flowDraftHeading">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#flowDraft" aria-expanded="false" aria-controls="flowDraft">
                                        Bozze e duplicazioni
                                    </button>
                                </h2>
                                <div id="flowDraft" class="accordion-collapse collapse" aria-labelledby="flowDraftHeading" data-bs-parent="#guideFlows">
                                    <div class="accordion-body">
                                        <ul class="mb-0">
                                            <li>Il salvataggio bozza è automatico: riaprendo la pagina trovi i dati.</li>
                                            <li>Puoi duplicare un'OP dall'elenco per creare una nuova pratica simile.</li>
                                            <li>Pulisci i campi sensibili (documento, pagamento) prima di inviare.</li>
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
                                <p class="text-muted mb-0 small">La validazione IBAN avviene in salvataggio se hai compilato IBAN e intestatario. In dettaglio OP compare badge “Validato” con banca/cifre se esito positivo.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Quando servono operatore e numero?</p>
                                <p class="text-muted mb-0 small">Solo per telefonia in modalità “Migrazione”. Per “Nuova attivazione” i campi restano nascosti.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Posso recuperare un cliente esistente?</p>
                                <p class="text-muted mb-0 small">Sì: inserisci CF e premi “Recupera” per autocompilare dati, documento e pagamento.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Come vedo le mie provvigioni?</p>
                                <p class="text-muted mb-0 small">Sezione “Provvigioni”: timeline mensile, dettagli mese, stato delle OP conteggiate.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Cosa significa stato “in verifica”?</p>
                                <p class="text-muted mb-0 small">La pratica è in revisione admin. Se riaperta puoi modificarla; a chiusura diventa “attivata” o “annullata”.</p>
                            </div>
                            <div>
                                <p class="fw-semibold mb-1">Mancano allegati o campi obbligatori?</p>
                                <p class="text-muted mb-0 small">Controlla gli alert rossi nel form. Senza documento e dati pagamento il salvataggio può fallire.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Suggerimenti operativi</h2>
                        <ul class="mb-0">
                            <li>Completa i campi obbligatori prima di caricare file per evitare errori.</li>
                            <li>Nomina i file in modo uniforme: cliente-cf-documento.pdf.</li>
                            <li>Usa la rubrica “Clienti” per recuperare storico e allegati.</li>
                            <li>Verifica Promemoria e Solleciti per seguire le attività pendenti.</li>
                            <li>Per POD/PDR o codice migrazione, usa i dati presenti in bolletta.</li>
                        </ul>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Storni: cosa sapere</h2>
                        <ul class="mb-0">
                            <li>Tempistiche: tutti i gestori prevedono possibili storni tra 3 e 6 mesi.</li>
                            <li>Mancato pagamento prima fattura: storno 100% della OP.</li>
                            <li>Uscita prima di 3 mesi: il gestore può riconoscere un gettone ridotto; prima di 6 mesi può riconoscere un gettone maggiore.</li>
                            <li>Beneficiario ≠ IBAN: se intestatari diversi e non dichiarati, il contratto può essere stornato. Indica sempre il reale intestatario.</li>
                            <li>Trattativa: chiarisci vincoli e possibili penali/uscite per proteggere il compenso.</li>
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
