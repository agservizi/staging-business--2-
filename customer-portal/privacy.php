<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$pageTitle = 'Informativa Privacy';

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2"><i class="fa-solid fa-shield-halved text-primary"></i>Informativa sulla Privacy</h1>
                <p class="text-muted-soft mb-0">Comprendi come gestiamo i tuoi dati personali all'interno del portale clienti Coresuite.</p>
            </div>
            <a class="btn topbar-btn" href="dashboard.php">
                <i class="fa-solid fa-arrow-left"></i>
                <span class="topbar-btn-label">Torna alla dashboard</span>
            </a>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <section class="mb-5">
                    <h2 class="h5 mb-3">1. Titolare del trattamento</h2>
                    <p class="mb-2">Il titolare del trattamento è <strong>AG Servizi Via Plinio 72</strong>, con sede operativa in <strong>Via Plinio il Vecchio 72, 80053 Castellammare di Stabia (NA)</strong> e P.IVA <strong>08442881218</strong> (REA NA-985288).</p>
                    <p class="mb-0">Per ogni chiarimento puoi consultare la <a href="https://www.agenziaplinio.it/privacy-policy" target="_blank" rel="noopener">Privacy Policy ufficiale di AG Servizi</a> oppure contattarci tramite i riferimenti indicati al paragrafo 11.</p>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">2. Tipologie di dati trattati</h2>
                    <ul class="mb-0">
                        <li><strong>Dati di identificazione e contatto</strong>: nome, cognome, email, telefono e azienda di appartenenza comunicati durante la registrazione al portale clienti pickup.coresuite.it.</li>
                        <li><strong>Dati operativi delle spedizioni</strong>: codici di tracking, corriere selezionato (inclusi i servizi BRT), destinatario, note operative, punto di ritiro, documenti di trasporto e stato del pacco.</li>
                        <li><strong>Dati di log e sicurezza</strong>: indirizzo IP, user agent, timestamp di accesso, codici OTP e informazioni relative a tentativi di login o blocco dell'account.</li>
                        <li><strong>Metadati di supporto</strong>: richieste inviate tramite form di assistenza, preferenze di notifica, lingua e fuso orario dell'utente.</li>
                    </ul>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">3. Finalità del trattamento</h2>
                    <p>Le informazioni vengono elaborate per le seguenti finalità:</p>
                    <ul>
                        <li>gestire l'accesso autenticato al portale clienti e garantire la sicurezza dell'area riservata;</li>
                        <li>monitorare l'avanzamento delle spedizioni, inviare notifiche di stato e fornire documenti correlati ai servizi di logistica pickup;</li>
                        <li>consentire la creazione, il pagamento, la stampa delle etichette e il tracking delle spedizioni gestite tramite l'integrazione BRT dedicata ai clienti business;</li>
                        <li>fornire assistenza tecnica e commerciale sui servizi offerti da AG Servizi (pagamenti, spedizioni, attivazioni digitali e altri servizi elencati su agenziaplinio.it);</li>
                        <li>adempiere agli obblighi legali e contabili connessi alla gestione delle pratiche e dei rapporti contrattuali con i clienti business;</li>
                        <li>migliorare i processi interni attraverso l'analisi anonimizzata dei log di utilizzo.</li>
                    </ul>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">4. Funzionalità disponibili nel portale</h2>
                    <p>All'interno di pickup.coresuite.it gli utenti autorizzati possono:</p>
                    <ul>
                        <li>prenotare e pagare le spedizioni BRT con scelta di servizio, peso/volume, opzioni di contrassegno e stampa immediata dell'etichetta PDF;</li>
                        <li>monitorare lo stato delle spedizioni pickup e BRT, scaricare documenti correlati e consultare lo storico dei movimenti;</li>
                        <li>caricare o aggiornare i dati del punto di ritiro, visualizzare la mappa di consegna e verificare gli orari operativi comunicati ad AG Servizi;</li>
                        <li>aprire segnalazioni operative (es. pacchi smarriti, giacenze, anomalie di consegna) e seguirne la risoluzione con il team AG Servizi;</li>
                        <li>gestire le notifiche via email, consultare i messaggi ricevuti nel portale e configurare le preferenze personali;</li>
                        <li>scaricare manifest, ricevute di pagamento e documentazione fiscale disponibile per le spedizioni lavorate;</li>
                        <li>richiedere supporto direttamente dall'area clienti con canali prioritari dedicati.</li>
                    </ul>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">5. Basi giuridiche</h2>
                    <p class="mb-0">Il trattamento si fonda sulle condizioni previste dall'art. 6 del GDPR: esecuzione di un contratto o di misure precontrattuali richieste dall'interessato, adempimento di obblighi legali, legittimo interesse del titolare a mantenere sicuro e funzionante il portale, eventuale consenso espresso per canali di comunicazione facoltativi.</p>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">6. Periodo di conservazione</h2>
                    <p class="mb-0">I dati del profilo cliente e delle spedizioni vengono conservati per tutta la durata del servizio e, successivamente, per il tempo necessario a garantire la tracciabilità amministrativa e fiscale (fino a 10 anni, ove richiesto). I log di sicurezza e le sessioni applicative hanno una conservazione più breve, definita dalle policy interne (da 30 a 180 giorni a seconda della tipologia).</p>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">7. Destinatari e trasferimenti</h2>
                    <p>Le informazioni sono trattate dal personale autorizzato di AG Servizi e da fornitori esterni nominati responsabili del trattamento, ad esempio:</p>
                    <ul>
                        <li>provider di hosting e infrastruttura cloud che ospitano il portale e i database;</li>
                        <li>servizi di invio comunicazioni (email, SMS, WhatsApp) dedicati alle notifiche operative;</li>
                        <li>partner logistici e corrieri nazionali/internazionali coinvolti nella gestione delle spedizioni, inclusa BRT S.p.A.;</li>
                        <li>consulenti amministrativi e tecnici che supportano AG Servizi nei propri processi.</li>
                    </ul>
                    <p class="mb-0">I dati non vengono trasferiti al di fuori dell'Unione Europea senza adeguate garanzie di conformità al GDPR.</p>
                </section>

                <section class="mb-5">
                    <h2 id="cookies" class="h5 mb-3">8. Cookie e strumenti di tracciamento</h2>
                    <p>Il portale utilizza esclusivamente cookie tecnici necessari per il funzionamento della piattaforma:</p>
                    <ul>
                        <li><strong>Cookie di sessione PHP</strong> (es. <code>PHPSESSID</code>): mantengono la sessione autenticata attiva per l'utente loggato.</li>
                        <li><strong>Cookie "Mantieni l'accesso"</strong> (<code><?= htmlspecialchars(REMEMBER_ME_COOKIE_NAME) ?></code>): viene creato solo su richiesta dell'utente per restare autenticato sul dispositivo utilizzato.</li>
                        <li><strong>Cookie di consenso</strong> (<code>portalCookieConsent</code>): memorizza la scelta dell'utente rispetto al banner informativo mostrato al primo accesso.</li>
                    </ul>
                    <p class="mb-0">Non vengono installati cookie di profilazione o strumenti di analisi di terze parti. Il banner informativo consente di accettare i cookie tecnici; la navigazione dell'area autenticata richiede comunque l'utilizzo dei cookie strettamente necessari.</p>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">9. Diritti dell'interessato</h2>
                    <p>Puoi sempre esercitare i diritti previsti dagli articoli 15-22 del GDPR:</p>
                    <ul>
                        <li>accesso, rettifica e aggiornamento dei dati personali;</li>
                        <li>cancellazione (diritto all'oblio) e limitazione del trattamento nei casi previsti;</li>
                        <li>opposizione al trattamento basato su legittimo interesse o finalità di marketing;</li>
                        <li>portabilità dei dati verso altro titolare su richiesta espressa;</li>
                        <li>avviare in autonomia la chiusura definitiva dell'account dalla sezione <em>Impostazioni &gt; Chiusura account</em> del portale, con eliminazione immediata dei dati;</li>
                        <li>presentazione di un reclamo al <a href="https://www.garanteprivacy.it/" target="_blank" rel="noopener">Garante per la protezione dei dati personali</a>.</li>
                    </ul>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">10. Misure di sicurezza</h2>
                    <p class="mb-0">Il portale adotta sessioni HTTPS, politiche di session hardening, rotazione periodica degli ID di sessione, audit log e tracciamento degli accessi. Gli OTP di accesso vengono cifrati e scadono automaticamente; gli operatori AG Servizi seguono procedure interne di autenticazione forte e gestione dei privilegi.</p>
                </section>

                <section class="mb-5">
                    <h2 class="h5 mb-3">11. Contatti dedicati alla privacy</h2>
                    <p class="mb-1">Per informazioni o per esercitare i tuoi diritti puoi contattare:</p>
                    <ul class="mb-0">
                        <li>Email dedicata: <a href="mailto:privacy@coresuite.it">privacy@coresuite.it</a></li>
                        <li>Assistenza clienti: <a href="mailto:assistenza@coresuite.it">assistenza@coresuite.it</a> · <a href="mailto:info@agenziaplinio.it">info@agenziaplinio.it</a></li>
                        <li>Telefono sede: <a href="tel:+390810584542">+39 081 058 4542</a></li>
                        <li>Sede operativa: Via Plinio il Vecchio 72, Castellammare di Stabia (NA)</li>
                    </ul>
                </section>

                <section>
                    <h2 class="h5 mb-3">12. Aggiornamenti dell'informativa</h2>
                    <p class="mb-0">Questo documento è aggiornato alla data <?= date('d/m/Y') ?>. Eventuali modifiche sostanziali verranno comunicate tramite il portale o via email. Ti invitiamo a consultare periodicamente questa pagina e la Privacy Policy pubblicata su agenziaplinio.it.</p>
                </section>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
