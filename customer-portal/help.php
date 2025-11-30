<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pickup_service.php';
require_once __DIR__ . '/includes/mailer.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pickupService = new PickupService();

$alerts = [];
$errors = [];

$summary = $pickupService->getCustomerSummary((int) $customer['id']);
$statusCounts = $pickupService->getPackageStatusCounts((int) $customer['id']);

$supportEmail = env('CUSTOMER_SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'assistenza@coresuite.it'));
$supportPhone = env('CUSTOMER_SUPPORT_PHONE', env('SUPPORT_PHONE', '+39 0810584542'));
$supportHours = env('CUSTOMER_SUPPORT_HOURS', 'Lun-Ven 09:00-18:00');

$faqItems = [
    [
        'question' => 'Come posso segnalare un nuovo pacco in arrivo?',
        'answer' => 'Accedi alla pagina "Pacchi" e clicca il pulsante "Segnala spedizione". Compila i dati di tracking e seleziona il corriere: riceverai un avviso quando il pacco sarà disponibile al ritiro.',
        'keywords' => 'segnalare pacco, nuova spedizione, tracking'
    ],
    [
        'question' => 'Dove trovo lo storico dei miei ritiri?',
        'answer' => 'Nella pagina "Pacchi" puoi filtrare lo storico per stato. Per un riepilogo rapido consulta il box "Attività recenti" nella dashboard.',
        'keywords' => 'storico, ritiri, pacchi consegnati'
    ],
    [
        'question' => 'Come modifico le notifiche e la lingua del portale?',
        'answer' => 'Apri la pagina "Impostazioni" e attiva i canali notifica desiderati. Dalla stessa schermata puoi impostare lingua e fuso orario utilizzati per gli avvisi automatici.',
        'keywords' => 'notifiche, lingua, impostazioni'
    ],
    [
        'question' => 'È possibile ricevere un riepilogo giornaliero via email?',
        'answer' => 'Sì. Nella pagina "Impostazioni" attiva le notifiche email: ogni sera riceverai un riepilogo dei pacchi in giacenza e delle nuove segnalazioni.',
        'keywords' => 'riepilogo email, giacenza, promemoria'
    ],
    [
        'question' => 'Non ricevo più aggiornamenti via SMS: cosa posso fare?',
        'answer' => 'Verifica che il numero di telefono nel profilo sia corretto e che gli SMS siano abilitati nelle impostazioni. Se il problema persiste contattaci tramite questo form allegando l’ora dell’ultimo aggiornamento ricevuto.',
        'keywords' => 'sms, notifiche, problemi'
    ],
];

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$filteredFaqs = $faqItems;
if ($searchQuery !== '') {
    $toLower = static function (string $value): string {
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    };

    $filteredFaqs = array_filter($faqItems, static function (array $faq) use ($searchQuery, $toLower): bool {
        $needle = $toLower($searchQuery);
        $haystack = $toLower($faq['question'] . ' ' . $faq['answer'] . ' ' . $faq['keywords']);
        if (function_exists('mb_strpos')) {
            return mb_strpos($haystack, $needle, 0, 'UTF-8') !== false;
        }
        return strpos($haystack, $needle) !== false;
    });
}

$supportForm = [
    'subject' => '',
    'category' => 'assistenza',
    'message' => '',
    'urgency' => 'standard',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'support-request') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Token di sicurezza non valido. Riprova a inviare la richiesta.';
    } else {
        $supportForm['subject'] = trim((string) ($_POST['subject'] ?? ''));
        $supportForm['category'] = trim((string) ($_POST['category'] ?? 'assistenza'));
        $supportForm['message'] = trim((string) ($_POST['message'] ?? ''));
        $supportForm['urgency'] = trim((string) ($_POST['urgency'] ?? 'standard'));

        if ($supportForm['subject'] === '') {
            $errors[] = 'Inserisci un oggetto per la richiesta.';
        }
        if ($supportForm['message'] === '') {
            $errors[] = 'Descrivi il problema o la domanda che vuoi inviarci.';
        }

        if (empty($errors)) {
            $subject = sprintf('[Pickup Portal] %s - %s', $supportForm['category'], $supportForm['subject']);
            $intro = sprintf('Il cliente #%d %s ha inviato una nuova richiesta dal portale clienti.', (int) $customer['id'], htmlspecialchars((string) ($customer['name'] ?? 'Cliente sconosciuto')));
            $content = '<p>' . $intro . '</p>'
                . '<ul>'
                . '<li><strong>Email:</strong> ' . htmlspecialchars((string) ($customer['email'] ?? 'n/a')) . '</li>'
                . '<li><strong>Telefono:</strong> ' . htmlspecialchars((string) ($customer['phone'] ?? 'n/a')) . '</li>'
                . '<li><strong>Categoria:</strong> ' . htmlspecialchars($supportForm['category']) . '</li>'
                . '<li><strong>Priorità:</strong> ' . htmlspecialchars($supportForm['urgency']) . '</li>'
                . '</ul>'
                . '<h3>Messaggio</h3>'
                . '<p style="white-space: pre-line;">' . htmlspecialchars($supportForm['message']) . '</p>';

            $htmlBody = render_mail_template('Nuova richiesta di supporto', $content);

            if ($supportEmail === null || $supportEmail === '') {
                portal_error_log('Support email non configurata per il portale clienti.');
                $errors[] = 'Impossibile inviare la richiesta perché il canale email non è configurato. Contatta l’assistenza telefonica.';
            } else {
                $sent = send_system_mail($supportEmail, $subject, $htmlBody);
                if ($sent) {
                    $alerts[] = 'Richiesta inviata correttamente. Ti risponderemo al più presto.';
                    $pickupService->logCustomerActivity((int) $customer['id'], 'support_request_sent', 'support_request', null, [
                        'category' => $supportForm['category'],
                        'urgency' => $supportForm['urgency'],
                    ]);
                    $supportForm = [
                        'subject' => '',
                        'category' => 'assistenza',
                        'message' => '',
                        'urgency' => 'standard',
                    ];
                } else {
                    $errors[] = 'Si è verificato un problema durante l’invio. Riprova più tardi o utilizza i contatti alternativi.';
                }
            }
        }
    }
}

$quickLinks = [
    [
        'label' => 'Vai ai pacchi',
        'href' => 'packages.php',
        'icon' => 'fa-boxes-stacked',
        'description' => 'Controlla lo stato delle spedizioni e segnala nuovi arrivi.'
    ],
    [
        'label' => 'Centro notifiche',
        'href' => 'notifications.php',
        'icon' => 'fa-bell',
        'description' => 'Gestisci gli avvisi e marca come letti gli aggiornamenti.'
    ],
    [
        'label' => 'Impostazioni account',
        'href' => 'settings.php',
        'icon' => 'fa-sliders',
        'description' => 'Configura notifiche, lingua e recapiti di contatto.'
    ],
];

$pageTitle = 'Supporto';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2"><i class="fa-solid fa-life-ring text-primary"></i>Supporto e assistenza</h1>
                <p class="text-muted-soft mb-0">Consulta la documentazione rapida oppure contatta il nostro team dedicato al servizio pickup.</p>
            </div>
            <a class="btn topbar-btn" href="dashboard.php">
                <i class="fa-solid fa-arrow-left"></i>
                <span class="topbar-btn-label">Torna alla dashboard</span>
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($message) ?>
            </div>
        <?php endforeach; ?>

        <?php foreach ($alerts as $message): ?>
            <div class="alert alert-success" role="alert">
                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($message) ?>
            </div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-12 col-lg-7">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h5 mb-1">Cerca tra le guide</h2>
                        <p class="text-muted-soft mb-0">Digita una parola chiave per trovare rapidamente la risposta.</p>
                    </div>
                    <div class="card-body">
                        <form class="input-group input-group-lg" method="GET" action="help.php">
                            <input class="form-control" name="q" type="search" placeholder="Es. tracking pacco" value="<?= htmlspecialchars($searchQuery) ?>">
                            <?php if ($searchQuery !== ''): ?>
                                <a class="btn btn-outline-secondary" href="help.php"><i class="fa-solid fa-xmark"></i></a>
                            <?php endif; ?>
                            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Cerca</button>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header border-0 pb-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Domande frequenti</h2>
                        <span class="text-muted small"><?= count($filteredFaqs) ?> risultati</span>
                    </div>
                    <div class="card-body">
                        <?php if (empty($filteredFaqs)): ?>
                            <p class="text-muted-soft mb-0">Nessun contenuto corrisponde alla ricerca. Prova con termini più generici oppure contattaci.</p>
                        <?php else: ?>
                            <div class="accordion" id="faq-accordion">
                                <?php $index = 0; ?>
                                <?php foreach ($filteredFaqs as $faq): ?>
                                    <?php $index++; ?>
                                    <div class="accordion-item">
                                        <h2 class="accordion-header" id="faq-heading-<?= $index ?>">
                                            <button class="accordion-button <?= $index === 1 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq-collapse-<?= $index ?>" aria-expanded="<?= $index === 1 ? 'true' : 'false' ?>" aria-controls="faq-collapse-<?= $index ?>">
                                                <?= htmlspecialchars($faq['question']) ?>
                                            </button>
                                        </h2>
                                        <div id="faq-collapse-<?= $index ?>" class="accordion-collapse collapse <?= $index === 1 ? 'show' : '' ?>" aria-labelledby="faq-heading-<?= $index ?>" data-bs-parent="#faq-accordion">
                                            <div class="accordion-body">
                                                <?= nl2br(htmlspecialchars($faq['answer'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h5 mb-1">Invia una richiesta al supporto</h2>
                        <p class="text-muted-soft mb-0">Compila il modulo: ti rispondiamo all’indirizzo email impostato nel profilo.</p>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="help.php" class="vstack gap-3">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(get_csrf_token()) ?>">
                            <input type="hidden" name="action" value="support-request">

                            <div>
                                <label class="form-label" for="support-subject">Oggetto</label>
                                <input class="form-control form-control-lg" id="support-subject" name="subject" value="<?= htmlspecialchars($supportForm['subject']) ?>" placeholder="Es. Mancano notifiche via SMS">
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="support-category">Categoria</label>
                                    <select class="form-select form-select-lg" id="support-category" name="category">
                                        <option value="assistenza" <?= $supportForm['category'] === 'assistenza' ? 'selected' : '' ?>>Assistenza tecnica</option>
                                        <option value="fatturazione" <?= $supportForm['category'] === 'fatturazione' ? 'selected' : '' ?>>Fatturazione e contratti</option>
                                        <option value="servizio" <?= $supportForm['category'] === 'servizio' ? 'selected' : '' ?>>Informazioni sul servizio</option>
                                        <option value="altro" <?= $supportForm['category'] === 'altro' ? 'selected' : '' ?>>Altro</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="support-urgency">Priorità</label>
                                    <select class="form-select form-select-lg" id="support-urgency" name="urgency">
                                        <option value="standard" <?= $supportForm['urgency'] === 'standard' ? 'selected' : '' ?>>Standard (entro 1 giorno)</option>
                                        <option value="alta" <?= $supportForm['urgency'] === 'alta' ? 'selected' : '' ?>>Alta (entro 4 ore)</option>
                                        <option value="critica" <?= $supportForm['urgency'] === 'critica' ? 'selected' : '' ?>>Critica (risposta immediata)</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="form-label" for="support-message">Messaggio</label>
                                <textarea class="form-control" id="support-message" name="message" rows="5" placeholder="Descrivi il problema, includendo eventuali numeri di tracking o orari."><?= htmlspecialchars($supportForm['message']) ?></textarea>
                                <small class="text-muted">Alleghiamo automaticamente ID cliente, email e numero di telefono presenti nel profilo.</small>
                            </div>

                            <div class="d-flex justify-content-between align-items-center pt-2">
                                <small class="text-muted">Riceverai conferma all’indirizzo <?= htmlspecialchars($customer['email'] ?? 'non impostato') ?>.</small>
                                <button class="btn btn-primary btn-lg" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>Invia richiesta</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-5">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h6 mb-1">Canali di contatto</h2>
                        <p class="text-muted-soft mb-0">Scegli il metodo più rapido in base all’urgenza.</p>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-0 small">
                            <li class="mb-3">
                                <div class="fw-semibold"><i class="fa-solid fa-headset text-primary me-2"></i>Telefono</div>
                                <div><?= htmlspecialchars($supportPhone) ?></div>
                                <div class="text-muted">Disponibile <?= htmlspecialchars($supportHours) ?></div>
                            </li>
                            <li class="mb-3">
                                <div class="fw-semibold"><i class="fa-solid fa-envelope text-primary me-2"></i>Email</div>
                                <a href="mailto:<?= htmlspecialchars($supportEmail) ?>"><?= htmlspecialchars($supportEmail) ?></a>
                                <div class="text-muted">Risposta entro 1 giorno lavorativo</div>
                            </li>
                            <li>
                                <div class="fw-semibold"><i class="fa-solid fa-comments text-primary me-2"></i>Chat operatore</div>
                                <div class="text-muted">Disponibile dal portale principale (sezione ticket).</div>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h6 mb-1">Stato account</h2>
                        <p class="text-muted-soft mb-0">Panoramica dei tuoi dati utili al supporto.</p>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-6 text-muted">ID cliente</dt>
                            <dd class="col-6">#<?= (int) $customer['id'] ?></dd>
                            <dt class="col-6 text-muted">Pacchi in giacenza</dt>
                            <dd class="col-6"><?= (int) ($statusCounts['in_giacenza'] ?? 0) ?></dd>
                            <dt class="col-6 text-muted">Pacchi consegnati</dt>
                            <dd class="col-6"><?= (int) ($statusCounts['consegnato'] ?? 0) ?></dd>
                            <dt class="col-6 text-muted">Notifiche non lette</dt>
                            <dd class="col-6"><?= (int) ($summary['unread_notifications'] ?? 0) ?></dd>
                            <dt class="col-6 text-muted">Ultima attività</dt>
                            <dd class="col-6"><?= !empty($summary['last_login']) ? date('d/m/Y H:i', strtotime($summary['last_login'])) : 'N/D' ?></dd>
                        </dl>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0">
                        <h2 class="h6 mb-1">Link rapidi</h2>
                        <p class="text-muted-soft mb-0">Prosegui verso le sezioni più utilizzate del portale.</p>
                    </div>
                    <div class="card-body">
                        <div class="vstack gap-3">
                            <?php foreach ($quickLinks as $link): ?>
                                <a class="d-flex align-items-start text-decoration-none" href="<?= htmlspecialchars($link['href']) ?>">
                                    <span class="badge rounded-circle bg-primary-subtle text-primary me-3"><i class="fa-solid <?= htmlspecialchars($link['icon']) ?>"></i></span>
                                    <span>
                                        <span class="d-block fw-semibold text-dark"><?= htmlspecialchars($link['label']) ?></span>
                                        <span class="text-muted small"><?= htmlspecialchars($link['description']) ?></span>
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
