<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Nuovo telegramma';
$csrfToken = csrf_token();

$tokenValue = env('UFFICIO_POSTALE_TOKEN') ?? env('UFFICIO_POSTALE_SANDBOX_TOKEN') ?? '';
$tokenConfigured = trim((string) $tokenValue) !== '';
$baseUri = (string) (env('UFFICIO_POSTALE_BASE_URI', 'https://ws.ufficiopostale.com') ?: 'https://ws.ufficiopostale.com');

$clientsStmt = $pdo->query('SELECT id, ragione_sociale, nome, cognome, email FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$defaultMittente = [
    'nome' => 'Mario Rossi',
    'indirizzo' => [
        'via' => 'Via Roma 1',
        'cap' => '00100',
        'citta' => 'Roma',
        'provincia' => 'RM',
        'complemento' => '',
    ],
    'telefono' => '',
    'email' => '',
];

$defaultDestinatari = [
    [
        'nome' => 'Ufficio Anagrafe',
        'indirizzo' => [
            'via' => 'Piazza Municipio 10',
            'cap' => '00100',
            'citta' => 'Roma',
            'provincia' => 'RM',
            'complemento' => '',
        ],
        'telefono' => '',
        'email' => '',
    ],
];

$pricingSummary = null;
$pricingError = null;

if ($tokenConfigured) {
    $formatEuro = static function (float $value): string {
        return 'EUR ' . number_format($value, 2, ',', '.');
    };

    $buildSummary = static function (array $payload) use ($formatEuro): ?array {
        $tiers = [];
        if (isset($payload['tariffe']) && is_array($payload['tariffe'])) {
            foreach ($payload['tariffe'] as $tier) {
                if (!is_array($tier)) {
                    continue;
                }

                $postage = isset($tier['tariffa_postale']) ? (float) $tier['tariffa_postale'] : 0.0;
                $print = isset($tier['stampa']) ? (float) $tier['stampa'] : 0.0;
                $envelope = isset($tier['imbustamento']) ? (float) $tier['imbustamento'] : 0.0;
                $total = $postage + $print + $envelope;

                if ($total <= 0.0) {
                    continue;
                }

                $tiers[] = [
                    'from' => isset($tier['da']) ? (int) $tier['da'] : 0,
                    'to' => isset($tier['a']) ? (int) $tier['a'] : 0,
                    'total' => $total,
                    'postage' => $postage,
                    'print' => $print,
                    'envelope' => $envelope,
                ];
            }
        }

        $tierLines = [];
        if ($tiers) {
            usort($tiers, static fn (array $left, array $right): int => $left['from'] <=> $right['from']);

            foreach (array_slice($tiers, 0, 3) as $tier) {
                if ($tier['from'] <= 0) {
                    $rangeLabel = 'Fino a ' . $tier['to'] . ' parole';
                } elseif ($tier['to'] > 0) {
                    $rangeLabel = $tier['from'] . '-' . $tier['to'] . ' parole';
                } else {
                    $rangeLabel = 'Oltre ' . $tier['from'] . ' parole';
                }

                $tierLines[] = sprintf(
                    '%s: %s (posta %s + stampa %s + imbustamento %s)',
                    $rangeLabel,
                    $formatEuro($tier['total']),
                    $formatEuro($tier['postage']),
                    $formatEuro($tier['print']),
                    $formatEuro($tier['envelope'])
                );
            }
        }

        $optionLines = [];
        if (isset($payload['options']) && is_array($payload['options'])) {
            foreach ($payload['options'] as $option) {
                if (!is_array($option) || empty($option['attivo'])) {
                    continue;
                }

                $name = trim((string) ($option['nome_option'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $price = isset($option['prezzo_option']) ? (float) $option['prezzo_option'] : 0.0;
                $optionLines[] = $price > 0
                    ? $name . ': +' . $formatEuro($price)
                    : $name . ': inclusa';
            }
        }

        if ($tierLines === [] && $optionLines === []) {
            return null;
        }

        return [
            'tiers' => $tierLines,
            'options' => $optionLines,
        ];
    };

    try {
        $client = new \App\Services\ServiziWeb\UfficioPostaleClient();
        $response = $client->getPricing('telegrammi');
        $pricingData = $response['data']['data'] ?? null;

        if (is_array($pricingData)) {
            $pricingSummary = $buildSummary($pricingData);
        }

        if ($pricingSummary === null) {
            $pricingError = 'Listino telegrammi non disponibile.';
        }
    } catch (\Throwable $exception) {
        $pricingError = $exception->getMessage();
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Nuovo telegramma</h1>
                <p class="text-muted mb-0">Prepara il payload da inviare al servizio Ufficio Postale e salva la pratica nel gestionale.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="index.php">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna all'elenco
                </a>
            </div>
        </div>
        <?php if (!$tokenConfigured): ?>
        <div class="alert alert-warning" role="alert">
            Configura <code>UFFICIO_POSTALE_TOKEN</code> (o il token sandbox) nel file <code>.env</code> per poter inviare telegrammi. Endpoint corrente: <span class="fw-semibold"><?php echo sanitize_output($baseUri); ?></span>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xxl-8">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Dati invio</h2>
                    </div>
                    <div class="card-body">
                        <form action="store.php" method="post" class="row g-3" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <fieldset <?php echo $tokenConfigured ? '' : 'disabled'; ?>>
                                <div class="col-md-4">
                                    <label class="form-label" for="cliente-id">Cliente (facoltativo)</label>
                                    <select class="form-select" id="cliente-id" name="cliente_id">
                                        <option value="">Nessun cliente</option>
                                        <?php foreach ($clients as $client): ?>
                                            <?php
                                                $labelParts = array_filter([
                                                    $client['ragione_sociale'] ?? null,
                                                    trim((string) (($client['nome'] ?? '') . ' ' . ($client['cognome'] ?? ''))),
                                                    $client['email'] ?? null,
                                                ]);
                                                $label = $labelParts ? implode(' • ', $labelParts) : ('Cliente #' . $client['id']);
                                            ?>
                                            <option value="<?php echo (int) $client['id']; ?>"><?php echo sanitize_output($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="riferimento">Riferimento interno</label>
                                    <input type="text" class="form-control" id="riferimento" name="riferimento" placeholder="es. Pratica 2025-01" maxlength="160">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="prodotto">Prodotto</label>
                                    <input type="text" class="form-control" id="prodotto" name="prodotto" value="telegramma" maxlength="80" required>
                                    <div class="form-text">Valore restituito dal catalogo Ufficio Postale (es. <code>telegramma</code>, <code>telegramma_estero</code>).</div>
                                </div>

                                <div class="col-12">
                                    <div class="bg-light rounded-3 p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h2 class="h6 mb-0">Mittente</h2>
                                            <span class="badge bg-secondary">Obbligatorio</span>
                                        </div>
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label" for="mittente-nome">Nome / Ragione sociale</label>
                                                <input type="text" class="form-control" id="mittente-nome" name="mittente[nome]" value="<?php echo sanitize_output($defaultMittente['nome']); ?>" maxlength="160" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label" for="mittente-email">Email (facoltativa)</label>
                                                <input type="email" class="form-control" id="mittente-email" name="mittente[email]" value="<?php echo sanitize_output($defaultMittente['email']); ?>" maxlength="160" placeholder="mittente@example.com">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="mittente-telefono">Telefono (facoltativo)</label>
                                                <input type="text" class="form-control" id="mittente-telefono" name="mittente[telefono]" value="<?php echo sanitize_output($defaultMittente['telefono']); ?>" maxlength="40" placeholder="es. +39 06 1234567">
                                            </div>
                                            <div class="col-md-8">
                                                <label class="form-label" for="mittente-indirizzo-via">Indirizzo</label>
                                                <input type="text" class="form-control" id="mittente-indirizzo-via" name="mittente[indirizzo][via]" value="<?php echo sanitize_output($defaultMittente['indirizzo']['via']); ?>" maxlength="180" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="mittente-indirizzo-complemento">Complemento (facoltativo)</label>
                                                <input type="text" class="form-control" id="mittente-indirizzo-complemento" name="mittente[indirizzo][complemento]" value="<?php echo sanitize_output($defaultMittente['indirizzo']['complemento']); ?>" maxlength="120" placeholder="Scala, interno, c/o...">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label" for="mittente-indirizzo-cap">CAP</label>
                                                <input type="text" class="form-control" id="mittente-indirizzo-cap" name="mittente[indirizzo][cap]" value="<?php echo sanitize_output($defaultMittente['indirizzo']['cap']); ?>" maxlength="10" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label" for="mittente-indirizzo-citta">Città</label>
                                                <input type="text" class="form-control" id="mittente-indirizzo-citta" name="mittente[indirizzo][citta]" value="<?php echo sanitize_output($defaultMittente['indirizzo']['citta']); ?>" maxlength="120" required>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label" for="mittente-indirizzo-provincia">Provincia</label>
                                                <input type="text" class="form-control" id="mittente-indirizzo-provincia" name="mittente[indirizzo][provincia]" value="<?php echo sanitize_output($defaultMittente['indirizzo']['provincia']); ?>" maxlength="2" required>
                                                <div class="form-text">Formato sigla (es. RM).</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="bg-light rounded-3 p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-3">
                                            <h2 class="h6 mb-0">Destinatari</h2>
                                            <button class="btn btn-outline-primary btn-sm" type="button" id="add-destinatario">
                                                <i class="fa-solid fa-user-plus me-1"></i>Aggiungi destinatario
                                            </button>
                                        </div>
                                        <div id="destinatari-container">
                                            <?php foreach ($defaultDestinatari as $index => $destinatario): ?>
                                                <div class="destinatario-entry border rounded-3 p-3 mb-3" data-index="<?php echo (int) $index; ?>">
                                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                                        <h3 class="h6 mb-0">Destinatario <span class="destinatario-label"><?php echo (int) ($index + 1); ?></span></h3>
                                                        <?php if ($index > 0): ?>
                                                            <button type="button" class="btn btn-outline-danger btn-sm remove-destinatario">
                                                                <i class="fa-solid fa-user-minus me-1"></i>Rimuovi
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="row g-3">
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-nome" data-id-base="destinatari-nome">Nome / Ragione sociale</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-nome" name="destinatari[<?php echo (int) $index; ?>][nome]" data-id-base="destinatari-nome" data-field-path="nome" value="<?php echo sanitize_output($destinatario['nome']); ?>" maxlength="160" required>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-email" data-id-base="destinatari-email">Email (facoltativa)</label>
                                                            <input type="email" class="form-control" id="destinatari-<?php echo (int) $index; ?>-email" name="destinatari[<?php echo (int) $index; ?>][email]" data-id-base="destinatari-email" data-field-path="email" value="<?php echo sanitize_output($destinatario['email']); ?>" maxlength="160" placeholder="destinatario@example.com">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-telefono" data-id-base="destinatari-telefono">Telefono (facoltativo)</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-telefono" name="destinatari[<?php echo (int) $index; ?>][telefono]" data-id-base="destinatari-telefono" data-field-path="telefono" value="<?php echo sanitize_output($destinatario['telefono']); ?>" maxlength="40" placeholder="es. +39 06 1234567">
                                                        </div>
                                                        <div class="col-md-8">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-indirizzo-via" data-id-base="destinatari-indirizzo-via">Indirizzo</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-indirizzo-via" name="destinatari[<?php echo (int) $index; ?>][indirizzo][via]" data-id-base="destinatari-indirizzo-via" data-field-path="indirizzo.via" value="<?php echo sanitize_output($destinatario['indirizzo']['via']); ?>" maxlength="180" required>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-indirizzo-complemento" data-id-base="destinatari-indirizzo-complemento">Complemento (facoltativo)</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-indirizzo-complemento" name="destinatari[<?php echo (int) $index; ?>][indirizzo][complemento]" data-id-base="destinatari-indirizzo-complemento" data-field-path="indirizzo.complemento" value="<?php echo sanitize_output($destinatario['indirizzo']['complemento']); ?>" maxlength="120" placeholder="Scala, interno, c/o...">
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-indirizzo-cap" data-id-base="destinatari-indirizzo-cap">CAP</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-indirizzo-cap" name="destinatari[<?php echo (int) $index; ?>][indirizzo][cap]" data-id-base="destinatari-indirizzo-cap" data-field-path="indirizzo.cap" value="<?php echo sanitize_output($destinatario['indirizzo']['cap']); ?>" maxlength="10" required>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-indirizzo-citta" data-id-base="destinatari-indirizzo-citta">Città</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-indirizzo-citta" name="destinatari[<?php echo (int) $index; ?>][indirizzo][citta]" data-id-base="destinatari-indirizzo-citta" data-field-path="indirizzo.citta" value="<?php echo sanitize_output($destinatario['indirizzo']['citta']); ?>" maxlength="120" required>
                                                        </div>
                                                        <div class="col-md-2">
                                                            <label class="form-label" for="destinatari-<?php echo (int) $index; ?>-indirizzo-provincia" data-id-base="destinatari-indirizzo-provincia">Provincia</label>
                                                            <input type="text" class="form-control" id="destinatari-<?php echo (int) $index; ?>-indirizzo-provincia" name="destinatari[<?php echo (int) $index; ?>][indirizzo][provincia]" data-id-base="destinatari-indirizzo-provincia" data-field-path="indirizzo.provincia" value="<?php echo sanitize_output($destinatario['indirizzo']['provincia']); ?>" maxlength="2" required>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <template id="destinatario-template">
                                            <div class="destinatario-entry border rounded-3 p-3 mb-3" data-index="__INDEX__">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <h3 class="h6 mb-0">Destinatario <span class="destinatario-label">__LABEL__</span></h3>
                                                    <button type="button" class="btn btn-outline-danger btn-sm remove-destinatario">
                                                        <i class="fa-solid fa-user-minus me-1"></i>Rimuovi
                                                    </button>
                                                </div>
                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label" data-id-base="destinatari-nome">Nome / Ragione sociale</label>
                                                        <input type="text" class="form-control" data-field-path="nome" data-id-base="destinatari-nome" maxlength="160" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label" data-id-base="destinatari-email">Email (facoltativa)</label>
                                                        <input type="email" class="form-control" data-field-path="email" data-id-base="destinatari-email" maxlength="160" placeholder="destinatario@example.com">
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label" data-id-base="destinatari-telefono">Telefono (facoltativo)</label>
                                                        <input type="text" class="form-control" data-field-path="telefono" data-id-base="destinatari-telefono" maxlength="40" placeholder="es. +39 06 1234567">
                                                    </div>
                                                    <div class="col-md-8">
                                                        <label class="form-label" data-id-base="destinatari-indirizzo-via">Indirizzo</label>
                                                        <input type="text" class="form-control" data-field-path="indirizzo.via" data-id-base="destinatari-indirizzo-via" maxlength="180" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label" data-id-base="destinatari-indirizzo-complemento">Complemento (facoltativo)</label>
                                                        <input type="text" class="form-control" data-field-path="indirizzo.complemento" data-id-base="destinatari-indirizzo-complemento" maxlength="120" placeholder="Scala, interno, c/o...">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label" data-id-base="destinatari-indirizzo-cap">CAP</label>
                                                        <input type="text" class="form-control" data-field-path="indirizzo.cap" data-id-base="destinatari-indirizzo-cap" maxlength="10" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label" data-id-base="destinatari-indirizzo-citta">Città</label>
                                                        <input type="text" class="form-control" data-field-path="indirizzo.citta" data-id-base="destinatari-indirizzo-citta" maxlength="120" required>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label" data-id-base="destinatari-indirizzo-provincia">Provincia</label>
                                                        <input type="text" class="form-control" data-field-path="indirizzo.provincia" data-id-base="destinatari-indirizzo-provincia" maxlength="2" required>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                        <template id="kv-row-template">
                                            <div class="kv-row row g-2 align-items-end mb-2" data-role="kv-row">
                                                <div class="col-sm-6 col-lg-4">
                                                    <input type="text" class="form-control" data-role="key" placeholder="Chiave (es. ritiro)" maxlength="120">
                                                </div>
                                                <div class="col-sm-6 col-lg-4">
                                                    <input type="text" class="form-control" data-role="value" placeholder="Valore">
                                                </div>
                                                <div class="col-sm-6 col-lg-3">
                                                    <select class="form-select" data-role="type" aria-label="Tipo valore">
                                                        <option value="string">Stringa</option>
                                                        <option value="number">Numero</option>
                                                        <option value="boolean">Boolean</option>
                                                        <option value="json">JSON</option>
                                                    </select>
                                                </div>
                                                <div class="col-sm-6 col-lg-1 text-sm-end">
                                                    <button type="button" class="btn btn-outline-danger" data-action="remove-row" title="Rimuovi riga">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                        <template id="kv-header-template">
                                            <div class="kv-header row g-2 align-items-end mb-2" data-role="header-row">
                                                <div class="col-sm-6 col-lg-5">
                                                    <input type="text" class="form-control" data-role="key" placeholder="Chiave (es. X-Signature)" maxlength="120">
                                                </div>
                                                <div class="col-sm-6 col-lg-5">
                                                    <input type="text" class="form-control" data-role="value" placeholder="Valore" maxlength="255">
                                                </div>
                                                <div class="col-sm-6 col-lg-2 text-sm-end">
                                                    <button type="button" class="btn btn-outline-danger" data-action="remove-row" title="Rimuovi header">
                                                        <i class="fa-solid fa-xmark"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                        <p class="small text-muted mb-0">Aggiungi un destinatario per ogni telegramma che vuoi inoltrare in questa pratica. Tutti riceveranno lo stesso testo.</p>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="documento">Testo del telegramma</label>
                                    <textarea class="form-control" id="documento" name="documento" rows="6" placeholder="Gentile destinatario, ..." required></textarea>
                                    <div class="form-text">Ogni riga vuota separa i paragrafi nel telegramma.</div>
                                </div>

                                <div class="col-12">
                                    <div class="accordion" id="telegrammi-advanced">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="telegrammi-advanced-heading">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#telegrammi-advanced-body" aria-expanded="false" aria-controls="telegrammi-advanced-body">
                                                    Opzioni avanzate
                                                </button>
                                            </h2>
                                            <div id="telegrammi-advanced-body" class="accordion-collapse collapse" aria-labelledby="telegrammi-advanced-heading" data-bs-parent="#telegrammi-advanced">
                                                <div class="accordion-body">
                                                    <div class="mb-4" data-advanced-section="opzioni" data-mode="form">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <div>
                                                                <h3 class="h6 mb-1">Opzioni aggiuntive</h3>
                                                                <p class="small text-muted mb-0">Configura parametri accessori come ritiro, consegna programmata o servizi supplementari.</p>
                                                            </div>
                                                            <div class="btn-group btn-group-sm" role="group" aria-label="Gestione opzioni">
                                                                <button type="button" class="btn btn-outline-primary" data-action="add-row">
                                                                    <i class="fa-solid fa-plus me-1"></i>Voce
                                                                </button>
                                                                <button type="button" class="btn btn-outline-secondary" data-action="toggle-mode">
                                                                    <i class="fa-solid fa-code me-1"></i>Editor JSON
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="border rounded-3 p-3 mb-3" data-role="form">
                                                            <div class="mb-3">
                                                                <div class="small fw-semibold text-muted mb-2">Suggerimenti rapidi</div>
                                                                <div class="d-flex flex-wrap gap-2" data-role="presets">
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="ritiro" data-template-type="boolean" data-template-value="true">Ritiro a domicilio</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="ritiro_note" data-template-type="string" data-template-value="Indicazioni citofono">Note per l'addetto</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="consegna_fascia" data-template-type="string" data-template-value="09:00-12:00">Fascia consegna 09-12</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="notifica_email" data-template-type="boolean" data-template-value="true">Notifica email</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="notifica_sms" data-template-type="boolean" data-template-value="true">Notifica SMS</button>
                                                                </div>
                                                                <p class="small text-muted mb-0">Personalizza i valori dopo averli aggiunti oppure usa "Voce" per inserire campi liberi.</p>
                                                            </div>
                                                            <div class="kv-rows" data-role="rows"></div>
                                                            <p class="small text-muted" data-role="empty-state">Nessuna opzione configurata. Aggiungi un campo per estendere il comportamento del servizio.</p>
                                                            <p class="small text-muted mb-0">Lascia vuoto se non servono opzioni accessorie.</p>
                                                        </div>
                                                        <div class="d-none" data-role="json">
                                                            <textarea class="form-control font-monospace" id="opzioni-json-editor" data-role="json-editor" rows="6" placeholder="{&#10;    &quot;ritiro&quot;: true&#10;}"></textarea>
                                                            <div class="form-text">Inserisci un oggetto JSON valido. Il contenuto sovrascrive l'editor strutturato.</div>
                                                        </div>
                                                        <input type="hidden" name="opzioni_mode" value="form" data-role="mode-input">
                                                        <input type="hidden" name="opzioni_json" id="opzioni-json" data-role="hidden-input">
                                                    </div>

                                                    <div class="mb-4" data-advanced-section="callback" data-mode="form">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <div>
                                                                <h3 class="h6 mb-1">Callback</h3>
                                                                <p class="small text-muted mb-0">Ricevi notifiche automatiche quando cambia lo stato del telegramma.</p>
                                                            </div>
                                                            <button type="button" class="btn btn-outline-secondary btn-sm" data-action="toggle-mode">
                                                                <i class="fa-solid fa-code me-1"></i>Editor JSON
                                                            </button>
                                                        </div>
                                                        <div class="border rounded-3 p-3 mb-3" data-role="form">
                                                            <div class="row g-3">
                                                                <div class="col-md-8">
                                                                    <label class="form-label" for="callback-url">URL di callback</label>
                                                                    <input type="url" class="form-control" id="callback-url" data-role="callback-url" placeholder="https://example.com/hooks/telegrammi">
                                                                </div>
                                                                <div class="col-md-4">
                                                                    <label class="form-label" for="callback-method">Metodo HTTP</label>
                                                                    <select class="form-select" id="callback-method" data-role="callback-method">
                                                                        <option value="">Predefinito (POST)</option>
                                                                        <option value="POST">POST</option>
                                                                        <option value="PUT">PUT</option>
                                                                        <option value="PATCH">PATCH</option>
                                                                        <option value="GET">GET</option>
                                                                    </select>
                                                                </div>
                                                                <div class="col-12">
                                                                    <label class="form-label d-flex justify-content-between align-items-center" for="callback-headers">
                                                                        Header HTTP opzionali
                                                                        <button type="button" class="btn btn-outline-primary btn-sm" data-action="add-header">
                                                                            <i class="fa-solid fa-plus me-1"></i>Header
                                                                        </button>
                                                                    </label>
                                                                    <div class="kv-headers" data-role="headers-rows"></div>
                                                                    <p class="small text-muted mb-0">Aggiungi chiavi/valori per firmare o autenticare le notifiche.</p>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="d-none" data-role="json">
                                                            <textarea class="form-control font-monospace" id="callback-json-editor" data-role="json-editor" rows="6" placeholder="{&#10;    &quot;url&quot;: &quot;https://example.com/hooks/telegrammi&quot;,&#10;    &quot;method&quot;: &quot;POST&quot;&#10;}"></textarea>
                                                            <div class="form-text">Inserisci un oggetto JSON valido conforme all'API.</div>
                                                        </div>
                                                        <input type="hidden" name="callback_mode" value="form" data-role="mode-input">
                                                        <input type="hidden" name="callback_json" id="callback-json" data-role="hidden-input">
                                                    </div>

                                                    <div class="mb-0" data-advanced-section="extra" data-mode="form">
                                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                                            <div>
                                                                <h3 class="h6 mb-1">Payload aggiuntivo</h3>
                                                                <p class="small text-muted mb-0">Includi eventuali campi personalizzati che non hanno un campo dedicato.</p>
                                                            </div>
                                                            <div class="btn-group btn-group-sm" role="group" aria-label="Gestione payload aggiuntivo">
                                                                <button type="button" class="btn btn-outline-primary" data-action="add-row">
                                                                    <i class="fa-solid fa-plus me-1"></i>Campo
                                                                </button>
                                                                <button type="button" class="btn btn-outline-secondary" data-action="toggle-mode">
                                                                    <i class="fa-solid fa-code me-1"></i>Editor JSON
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="border rounded-3 p-3 mb-3" data-role="form">
                                                            <div class="mb-3">
                                                                <div class="small fw-semibold text-muted mb-2">Suggerimenti rapidi</div>
                                                                <div class="d-flex flex-wrap gap-2" data-role="presets">
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="riferimento_esterno" data-template-type="string" data-template-value="CRM-12345">Riferimento esterno</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="metadata" data-template-type="json" data-template-value="{&quot;origine&quot;:&quot;crm&quot;}">Metadata JSON</button>
                                                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-template-key="priorita" data-template-type="number" data-template-value="1">Priorità</button>
                                                                </div>
                                                                <p class="small text-muted mb-0">Usa i template per partire da esempi comuni oppure aggiungi campi manualmente.</p>
                                                            </div>
                                                            <div class="kv-rows" data-role="rows"></div>
                                                            <p class="small text-muted" data-role="empty-state">Nessun payload personalizzato impostato. Aggiungi solo ciò che serve davvero.</p>
                                                            <p class="small text-muted mb-0">Verrà unito al payload finale. Chiavi già definite verranno sovrascritte.</p>
                                                        </div>
                                                        <div class="d-none" data-role="json">
                                                            <textarea class="form-control font-monospace" id="extra-json-editor" data-role="json-editor" rows="6" placeholder="{&#10;    &quot;etag&quot;: &quot;custom&quot;&#10;}"></textarea>
                                                            <div class="form-text">Inserisci un oggetto JSON valido per popolare il payload personalizzato.</div>
                                                        </div>
                                                        <input type="hidden" name="extra_mode" value="form" data-role="mode-input">
                                                        <input type="hidden" name="extra_json" id="extra-json" data-role="hidden-input">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="note">Note interne</label>
                                    <textarea class="form-control" id="note" name="note" rows="3" maxlength="2000"></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fa-solid fa-paper-plane me-2"></i>Invia telegramma
                                    </button>
                                </div>
                            </fieldset>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-xxl-4">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Suggerimenti di compilazione</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($pricingSummary !== null): ?>
                        <div class="mb-3">
                            <div class="small fw-semibold text-muted mb-1">Costi indicativi (catalogo Ufficio Postale)</div>
                            <?php if (!empty($pricingSummary['tiers'])): ?>
                            <ul class="small text-muted ps-3 mb-2">
                                <?php foreach ($pricingSummary['tiers'] as $line): ?>
                                <li><?php echo sanitize_output($line); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                            <?php if (!empty($pricingSummary['options'])): ?>
                            <div class="small text-muted">
                                <span class="fw-semibold">Opzioni:</span>
                                <?php echo sanitize_output(implode('; ', $pricingSummary['options'])); ?>
                            </div>
                            <?php endif; ?>
                            <p class="small text-muted mb-0">Valori IVA esclusa, calcolati in base al listino corrente.</p>
                        </div>
                        <?php elseif ($tokenConfigured && $pricingError !== null): ?>
                        <div class="alert alert-warning small mb-3" role="alert">
                            Impossibile recuperare il listino Ufficio Postale (<?php echo sanitize_output($pricingError); ?>).
                        </div>
                        <?php endif; ?>
                        <ul class="small text-muted mb-0 ps-3">
                            <li>Recupera i prodotti disponibili tramite l'endpoint <code>GET /pricing</code> per valorizzare correttamente il campo "Prodotto".</li>
                            <li>Il campo "documento" accetta testo semplice; ogni riga vuota separa i paragrafi nel telegramma.</li>
                            <li>Includi eventuali preferenze di recapito o servizi accessori dentro "Opzioni" secondo la documentazione Ufficio Postale.</li>
                            <li>Le note interne restano visibili solo nel gestionale e non vengono inviate all'API.</li>
                        </ul>
                    </div>
                </div>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Verifiche preliminari</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-6 text-muted">Token</dt>
                            <dd class="col-6 text-<?php echo $tokenConfigured ? 'success' : 'danger'; ?> fw-semibold"><?php echo $tokenConfigured ? 'Configurato' : 'Assente'; ?></dd>
                            <dt class="col-6 text-muted">Endpoint</dt>
                            <dd class="col-6"><code class="text-break"><?php echo sanitize_output($baseUri); ?></code></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
(function () {
    const form = document.querySelector('form[action="store.php"]');
    if (!form) {
        return;
    }

    (function initDestinatari() {
        const container = form.querySelector('#destinatari-container');
        const template = document.getElementById('destinatario-template');
        const addButton = document.getElementById('add-destinatario');

        if (!container || !template || !addButton) {
            return;
        }

        function buildFieldName(index, fieldPath) {
            const parts = fieldPath.split('.');
            let name = 'destinatari[' + index + ']';
            for (const part of parts) {
                if (part) {
                    name += '[' + part + ']';
                }
            }
            return name;
        }

        function assignNames(entry, index, total) {
            entry.setAttribute('data-index', String(index));

            const label = entry.querySelector('.destinatario-label');
            if (label) {
                label.textContent = String(index + 1);
            }

            entry.querySelectorAll('[data-field-path]').forEach(function (input) {
                const fieldPath = input.getAttribute('data-field-path');
                if (!fieldPath) {
                    return;
                }
                input.name = buildFieldName(index, fieldPath);

                const idBase = input.getAttribute('data-id-base');
                if (idBase) {
                    const newId = idBase + '-' + index;
                    input.id = newId;
                    const associatedLabel = entry.querySelector('label[data-id-base="' + idBase + '"]');
                    if (associatedLabel) {
                        associatedLabel.setAttribute('for', newId);
                    }
                }
            });

            const removeButton = entry.querySelector('.remove-destinatario');
            if (removeButton) {
                if (total > 1) {
                    removeButton.classList.remove('d-none');
                } else {
                    removeButton.classList.add('d-none');
                }
            }
        }

        function refreshIndices() {
            const entries = container.querySelectorAll('.destinatario-entry');
            const count = entries.length;
            entries.forEach(function (entry, index) {
                assignNames(entry, index, count);
            });
        }

        addButton.addEventListener('click', function (event) {
            event.preventDefault();
            const clone = template.content.cloneNode(true);
            const entry = clone.querySelector('.destinatario-entry');
            if (!entry) {
                return;
            }
            container.appendChild(entry);
            refreshIndices();
            const firstField = entry.querySelector('input');
            if (firstField) {
                firstField.focus();
            }
        });

        container.addEventListener('click', function (event) {
            const target = event.target instanceof HTMLElement ? event.target : null;
            if (!target) {
                return;
            }
            const button = target.closest('.remove-destinatario');
            if (!button) {
                return;
            }
            event.preventDefault();
            const entry = button.closest('.destinatario-entry');
            if (!entry) {
                return;
            }
            if (container.querySelectorAll('.destinatario-entry').length <= 1) {
                return;
            }
            entry.remove();
            refreshIndices();
        });

        refreshIndices();
    })();
    const kvRowTemplate = document.getElementById('kv-row-template');
    const kvHeaderTemplate = document.getElementById('kv-header-template');

    function applyTypePlaceholder(input, type) {
        if (!input) {
            return;
        }
        input.setAttribute('inputmode', 'text');
        switch (type) {
            case 'number':
                input.placeholder = 'Numero (es. 12.50)';
                input.setAttribute('inputmode', 'decimal');
                break;
            case 'boolean':
                input.placeholder = 'true | false';
                break;
            case 'json':
                input.placeholder = 'JSON valido';
                break;
            default:
                input.placeholder = 'Valore';
        }
    }

    function setupKeyValueSection(section, displayName) {
        if (!section || !kvRowTemplate) {
            return null;
        }

    const rowsContainer = section.querySelector('[data-role="rows"]');
    const addButton = section.querySelector('[data-action="add-row"]');
    const toggleButton = section.querySelector('[data-action="toggle-mode"]');
    const formContainer = section.querySelector('[data-role="form"]');
    const jsonContainer = section.querySelector('[data-role="json"]');
    const jsonEditor = section.querySelector('[data-role="json-editor"]');
    const modeInput = section.querySelector('[data-role="mode-input"]');
    const hiddenInput = section.querySelector('[data-role="hidden-input"]');
    const emptyState = section.querySelector('[data-role="empty-state"]');
    const presetsContainer = section.querySelector('[data-role="presets"]');

        if (!rowsContainer || !formContainer || !jsonContainer || !jsonEditor || !modeInput || !hiddenInput) {
            return null;
        }

        const toggleLabelJson = '<i class="fa-solid fa-code me-1"></i>Editor JSON';
        const toggleLabelForm = '<i class="fa-solid fa-pen me-1"></i>Editor visuale';
        const originalLabel = toggleButton ? toggleButton.innerHTML : toggleLabelJson;

        function addRow(initial) {
            const fragment = kvRowTemplate.content.cloneNode(true);
            const row = fragment.querySelector('[data-role="kv-row"]');
            if (!row) {
                return null;
            }
            const keyInput = row.querySelector('[data-role="key"]');
            const valueInput = row.querySelector('[data-role="value"]');
            const typeSelect = row.querySelector('[data-role="type"]');
            if (keyInput) {
                keyInput.value = initial && typeof initial.key === 'string' ? initial.key : '';
            }
            if (valueInput) {
                const rawValue = initial && typeof initial.value !== 'undefined' ? String(initial.value) : '';
                valueInput.value = rawValue;
            }
            if (typeSelect) {
                const targetType = initial && typeof initial.type === 'string' ? initial.type : 'string';
                if (Array.prototype.some.call(typeSelect.options, function (option) { return option.value === targetType; })) {
                    typeSelect.value = targetType;
                } else {
                    typeSelect.value = 'string';
                }
                applyTypePlaceholder(valueInput, typeSelect.value);
            }
            rowsContainer.appendChild(row);
            updateRemoveButtons();
            return row;
        }

        function updateEmptyState() {
            if (!emptyState) {
                return;
            }
            const hasRows = rowsContainer.querySelector('[data-role="kv-row"]') !== null;
            if (hasRows) {
                emptyState.classList.add('d-none');
            } else {
                emptyState.classList.remove('d-none');
            }
        }

        function updateRemoveButtons() {
            const rows = rowsContainer.querySelectorAll('[data-role="kv-row"]');
            rows.forEach(function (row) {
                const removeButton = row.querySelector('[data-action="remove-row"]');
                if (!removeButton) {
                    return;
                }
                if (rows.length <= 1) {
                    removeButton.setAttribute('disabled', 'disabled');
                } else {
                    removeButton.removeAttribute('disabled');
                }
            });
            updateEmptyState();
        }

        function coercePreviewValue(type, value) {
            const normalized = value.trim();
            if (type === 'number') {
                const candidate = Number(normalized.replace(',', '.'));
                return Number.isFinite(candidate) ? candidate : value;
            }
            if (type === 'boolean') {
                const lowered = normalized.toLowerCase();
                if (['true', '1', 'yes', 'y'].includes(lowered)) {
                    return true;
                }
                if (['false', '0', 'no', 'n'].includes(lowered)) {
                    return false;
                }
                return value;
            }
            if (type === 'json') {
                if (normalized === '') {
                    return value;
                }
                try {
                    return JSON.parse(value);
                } catch (error) {
                    return value;
                }
            }
            return value;
        }

        function coerceStrictValue(type, value) {
            const normalized = value.trim();
            if (type === 'number') {
                if (normalized === '') {
                    return { ok: false, message: 'inserisci un numero valido alla riga %row%.' };
                }
                const candidate = Number(normalized.replace(',', '.'));
                if (!Number.isFinite(candidate)) {
                    return { ok: false, message: 'il valore alla riga %row% non è un numero valido.' };
                }
                return { ok: true, value: candidate };
            }
            if (type === 'boolean') {
                if (normalized === '') {
                    return { ok: false, message: 'scegli true o false alla riga %row%.' };
                }
                const lowered = normalized.toLowerCase();
                if (['true', '1', 'yes', 'y'].includes(lowered)) {
                    return { ok: true, value: true };
                }
                if (['false', '0', 'no', 'n'].includes(lowered)) {
                    return { ok: true, value: false };
                }
                return { ok: false, message: 'il valore booleano alla riga %row% deve essere true/false.' };
            }
            if (type === 'json') {
                if (normalized === '') {
                    return { ok: false, message: 'inserisci un JSON valido alla riga %row%.' };
                }
                try {
                    return { ok: true, value: JSON.parse(value) };
                } catch (error) {
                    return { ok: false, message: 'JSON non valido alla riga %row%: ' + error.message };
                }
            }
            return { ok: true, value: value };
        }

        function collect(validate) {
            const result = {};
            const errors = [];
            const rows = rowsContainer.querySelectorAll('[data-role="kv-row"]');
            rows.forEach(function (row, index) {
                const keyInput = row.querySelector('[data-role="key"]');
                const valueInput = row.querySelector('[data-role="value"]');
                const typeSelect = row.querySelector('[data-role="type"]');
                const key = keyInput ? keyInput.value.trim() : '';
                const rawValue = valueInput ? valueInput.value : '';
                const trimmedValue = rawValue.trim();
                const type = typeSelect ? typeSelect.value : 'string';

                if (key === '' && trimmedValue === '') {
                    return;
                }

                if (key === '') {
                    errors.push(displayName + ': specifica la chiave alla riga ' + (index + 1) + '.');
                    return;
                }

                if (!validate) {
                    result[key] = coercePreviewValue(type, rawValue);
                    return;
                }

                const conversion = coerceStrictValue(type, rawValue);
                if (!conversion.ok) {
                    errors.push(displayName + ': ' + conversion.message.replace('%row%', String(index + 1)));
                    return;
                }
                result[key] = conversion.value;
            });

            return { errors: errors, value: result };
        }

        function setMode(mode) {
            section.setAttribute('data-mode', mode);
            modeInput.value = mode;
            if (mode === 'json') {
                formContainer.classList.add('d-none');
                jsonContainer.classList.remove('d-none');
                if (toggleButton) {
                    toggleButton.innerHTML = toggleLabelForm;
                }
            } else {
                formContainer.classList.remove('d-none');
                jsonContainer.classList.add('d-none');
                if (toggleButton) {
                    toggleButton.innerHTML = originalLabel || toggleLabelJson;
                }
            }
        }

        if (addButton) {
            addButton.addEventListener('click', function (event) {
                event.preventDefault();
                addRow();
            });
        }

        rowsContainer.addEventListener('click', function (event) {
            const target = event.target instanceof HTMLElement ? event.target : null;
            if (!target) {
                return;
            }
            const button = target.closest('[data-action="remove-row"]');
            if (!button) {
                return;
            }
            event.preventDefault();
            const row = button.closest('[data-role="kv-row"]');
            if (!row) {
                return;
            }
            row.remove();
            updateRemoveButtons();
        });

        rowsContainer.addEventListener('change', function (event) {
            const select = event.target instanceof HTMLSelectElement ? event.target : null;
            if (!select || !select.matches('select[data-role="type"]')) {
                return;
            }
            const row = select.closest('[data-role="kv-row"]');
            if (!row) {
                return;
            }
            const valueInput = row.querySelector('[data-role="value"]');
            applyTypePlaceholder(valueInput, select.value);
        });

        updateRemoveButtons();

        if (presetsContainer) {
            presetsContainer.addEventListener('click', function (event) {
                const target = event.target instanceof HTMLElement ? event.target : null;
                if (!target) {
                    return;
                }
                const button = target.closest('button[data-template-key]');
                if (!button) {
                    return;
                }
                event.preventDefault();
                const key = (button.getAttribute('data-template-key') || '').trim();
                if (key === '') {
                    return;
                }
                const type = (button.getAttribute('data-template-type') || 'string').trim() || 'string';
                const rawValueAttr = button.getAttribute('data-template-value');
                const initial = { key: key, type: type };
                if (rawValueAttr !== null) {
                    initial.value = rawValueAttr;
                }

                let targetRow = null;
                rowsContainer.querySelectorAll('[data-role="kv-row"]').forEach(function (row) {
                    if (targetRow) {
                        return;
                    }
                    const keyInput = row.querySelector('[data-role="key"]');
                    if (!keyInput) {
                        return;
                    }
                    if (keyInput.value.trim().toLowerCase() === key.toLowerCase()) {
                        targetRow = row;
                    }
                });

                if (!targetRow) {
                    targetRow = addRow(initial);
                    if (!targetRow) {
                        return;
                    }
                    const keyInput = targetRow.querySelector('[data-role="key"]');
                    if (keyInput && keyInput.value.trim() === '') {
                        keyInput.value = key;
                    }
                } else {
                    const keyInput = targetRow.querySelector('[data-role="key"]');
                    if (keyInput && keyInput.value.trim() === '') {
                        keyInput.value = key;
                    }
                }

                const typeSelect = targetRow.querySelector('[data-role="type"]');
                if (typeSelect && typeSelect.value !== type) {
                    const optionExists = Array.prototype.some.call(typeSelect.options, function (option) {
                        return option.value === type;
                    });
                    if (optionExists) {
                        typeSelect.value = type;
                        const valueInputForType = targetRow.querySelector('[data-role="value"]');
                        applyTypePlaceholder(valueInputForType, type);
                    }
                }

                if (rawValueAttr !== null) {
                    const valueInput = targetRow.querySelector('[data-role="value"]');
                    if (valueInput && valueInput.value.trim() === '') {
                        valueInput.value = rawValueAttr;
                    }
                }

                const valueInput = targetRow.querySelector('[data-role="value"]');
                if (valueInput && typeof valueInput.focus === 'function') {
                    valueInput.focus();
                    valueInput.select();
                }
            });
        }

        if (toggleButton) {
            toggleButton.addEventListener('click', function (event) {
                event.preventDefault();
                const currentMode = section.getAttribute('data-mode') || 'form';
                if (currentMode === 'form') {
                    const collected = collect(false);
                    jsonEditor.value = Object.keys(collected.value).length ? JSON.stringify(collected.value, null, 2) : '';
                    setMode('json');
                } else {
                    const raw = jsonEditor.value.trim();
                    if (raw !== '') {
                        try {
                            const parsed = JSON.parse(raw);
                            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                                rowsContainer.innerHTML = '';
                                let inserted = false;
                                Object.keys(parsed).forEach(function (key) {
                                    const value = parsed[key];
                                    let type = 'string';
                                    let fieldValue = '';
                                    if (typeof value === 'number') {
                                        type = 'number';
                                        fieldValue = String(value);
                                    } else if (typeof value === 'boolean') {
                                        type = 'boolean';
                                        fieldValue = value ? 'true' : 'false';
                                    } else if (value !== null && typeof value === 'object') {
                                        type = 'json';
                                        fieldValue = JSON.stringify(value);
                                    } else if (value === null) {
                                        type = 'json';
                                        fieldValue = 'null';
                                    } else {
                                        fieldValue = String(value);
                                    }
                                    addRow({ key: key, value: fieldValue, type: type });
                                    inserted = true;
                                });
                                if (!inserted) {
                                    updateRemoveButtons();
                                }
                            } else {
                                rowsContainer.innerHTML = '';
                                updateRemoveButtons();
                            }
                        } catch (error) {
                            window.alert('JSON non valido: ' + error.message);
                            return;
                        }
                    } else {
                        rowsContainer.innerHTML = '';
                        updateRemoveButtons();
                    }
                    setMode('form');
                }
            });
        }

    updateEmptyState();
    setMode(section.getAttribute('data-mode') || 'form');

        return {
            prepare: function () {
                const mode = section.getAttribute('data-mode') || 'form';
                if (mode === 'json') {
                    const raw = jsonEditor.value.trim();
                    if (raw === '') {
                        hiddenInput.value = '';
                        return { ok: true };
                    }
                    try {
                        const parsed = JSON.parse(raw);
                        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                            return { ok: false, message: displayName + ': il JSON deve rappresentare un oggetto.', focus: jsonEditor };
                        }
                        hiddenInput.value = JSON.stringify(parsed);
                        return { ok: true };
                    } catch (error) {
                        return { ok: false, message: displayName + ': JSON non valido (' + error.message + ').', focus: jsonEditor };
                    }
                }
                const collected = collect(true);
                if (collected.errors.length) {
                    return { ok: false, message: collected.errors[0] };
                }
                hiddenInput.value = Object.keys(collected.value).length ? JSON.stringify(collected.value) : '';
                return { ok: true };
            }
        };
    }

    function setupCallbackSection(section) {
        if (!section || !kvHeaderTemplate) {
            return null;
        }

        const toggleButton = section.querySelector('[data-action="toggle-mode"]');
        const formContainer = section.querySelector('[data-role="form"]');
        const jsonContainer = section.querySelector('[data-role="json"]');
        const jsonEditor = section.querySelector('[data-role="json-editor"]');
        const modeInput = section.querySelector('[data-role="mode-input"]');
        const hiddenInput = section.querySelector('[data-role="hidden-input"]');
        const urlInput = section.querySelector('[data-role="callback-url"]');
        const methodSelect = section.querySelector('[data-role="callback-method"]');
        const headersContainer = section.querySelector('[data-role="headers-rows"]');
        const addHeaderButton = section.querySelector('[data-action="add-header"]');

        if (!formContainer || !jsonContainer || !jsonEditor || !modeInput || !hiddenInput || !headersContainer) {
            return null;
        }

        const toggleLabelJson = '<i class="fa-solid fa-code me-1"></i>Editor JSON';
        const toggleLabelForm = '<i class="fa-solid fa-pen me-1"></i>Editor visuale';
        const originalLabel = toggleButton ? toggleButton.innerHTML : toggleLabelJson;

        function addHeader(initial) {
            const fragment = kvHeaderTemplate.content.cloneNode(true);
            const row = fragment.querySelector('[data-role="header-row"]');
            if (!row) {
                return null;
            }
            const keyInput = row.querySelector('[data-role="key"]');
            const valueInput = row.querySelector('[data-role="value"]');
            if (keyInput) {
                keyInput.value = initial && typeof initial.key === 'string' ? initial.key : '';
            }
            if (valueInput) {
                const rawValue = initial && typeof initial.value !== 'undefined' ? String(initial.value) : '';
                valueInput.value = rawValue;
            }
            headersContainer.appendChild(row);
            updateRemoveButtons();
            return row;
        }

        function updateRemoveButtons() {
            const rows = headersContainer.querySelectorAll('[data-role="header-row"]');
            rows.forEach(function (row) {
                const removeButton = row.querySelector('[data-action="remove-row"]');
                if (!removeButton) {
                    return;
                }
                if (rows.length <= 1) {
                    removeButton.setAttribute('disabled', 'disabled');
                } else {
                    removeButton.removeAttribute('disabled');
                }
            });
        }

        function collectHeaders(validate) {
            const errors = [];
            const values = {};
            let count = 0;
            const rows = headersContainer.querySelectorAll('[data-role="header-row"]');
            rows.forEach(function (row, index) {
                const keyInput = row.querySelector('[data-role="key"]');
                const valueInput = row.querySelector('[data-role="value"]');
                const key = keyInput ? keyInput.value.trim() : '';
                const value = valueInput ? valueInput.value : '';
                if (key === '' && value.trim() === '') {
                    return;
                }
                if (key === '') {
                    errors.push('Callback: specifica la chiave dell\'header alla riga ' + (index + 1) + '.');
                    return;
                }
                values[key] = value;
                count += 1;
            });
            return { errors: validate ? errors : [], value: values, count: count };
        }

        function buildPayload(validate) {
            const url = urlInput ? urlInput.value.trim() : '';
            const methodRaw = methodSelect ? methodSelect.value.trim() : '';
            const method = methodRaw === '' ? '' : methodRaw.toUpperCase();
            const headers = collectHeaders(validate);
            if (validate && headers.errors.length) {
                return { errors: headers.errors };
            }

            if (url === '') {
                if (validate && (method !== '' || headers.count > 0)) {
                    return { errors: ['Callback: specifica l\'URL o lascia vuoti gli altri campi.'] };
                }
                return { payload: null };
            }

            const payload = { url: url };
            if (method !== '') {
                payload.method = method;
            }
            if (headers.count > 0) {
                payload.headers = headers.value;
            }
            return { payload: payload };
        }

        function setMode(mode) {
            section.setAttribute('data-mode', mode);
            modeInput.value = mode;
            if (mode === 'json') {
                formContainer.classList.add('d-none');
                jsonContainer.classList.remove('d-none');
                if (toggleButton) {
                    toggleButton.innerHTML = toggleLabelForm;
                }
            } else {
                formContainer.classList.remove('d-none');
                jsonContainer.classList.add('d-none');
                if (toggleButton) {
                    toggleButton.innerHTML = originalLabel || toggleLabelJson;
                }
            }
        }

        if (addHeaderButton) {
            addHeaderButton.addEventListener('click', function (event) {
                event.preventDefault();
                addHeader();
            });
        }

        headersContainer.addEventListener('click', function (event) {
            const target = event.target instanceof HTMLElement ? event.target : null;
            if (!target) {
                return;
            }
            const button = target.closest('[data-action="remove-row"]');
            if (!button) {
                return;
            }
            event.preventDefault();
            const row = button.closest('[data-role="header-row"]');
            if (!row) {
                return;
            }
            row.remove();
            updateRemoveButtons();
        });

        updateRemoveButtons();

        if (toggleButton) {
            toggleButton.addEventListener('click', function (event) {
                event.preventDefault();
                const currentMode = section.getAttribute('data-mode') || 'form';
                if (currentMode === 'form') {
                    const payload = buildPayload(false).payload;
                    jsonEditor.value = payload ? JSON.stringify(payload, null, 2) : '';
                    setMode('json');
                } else {
                    const raw = jsonEditor.value.trim();
                    if (raw === '') {
                        if (urlInput) {
                            urlInput.value = '';
                        }
                        if (methodSelect) {
                            methodSelect.value = '';
                        }
                        headersContainer.innerHTML = '';
                        updateRemoveButtons();
                        setMode('form');
                        return;
                    }
                    try {
                        const parsed = JSON.parse(raw);
                        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                            window.alert('Il JSON della callback deve rappresentare un oggetto.');
                            return;
                        }
                        if (urlInput) {
                            urlInput.value = typeof parsed.url === 'string' ? parsed.url : '';
                        }
                        if (methodSelect) {
                            const methodValue = typeof parsed.method === 'string' ? parsed.method.toUpperCase() : '';
                            const available = Array.prototype.some.call(methodSelect.options, function (option) {
                                return option.value === methodValue || (option.value === '' && methodValue === '');
                            });
                            methodSelect.value = available ? methodValue : '';
                        }
                        headersContainer.innerHTML = '';
                        if (parsed.headers && typeof parsed.headers === 'object' && !Array.isArray(parsed.headers)) {
                            Object.keys(parsed.headers).forEach(function (key) {
                                const value = parsed.headers[key];
                                addHeader({ key: key, value: value });
                            });
                        }
                        updateRemoveButtons();
                        setMode('form');
                    } catch (error) {
                        window.alert('JSON non valido per la callback: ' + error.message);
                    }
                }
            });
        }

        setMode(section.getAttribute('data-mode') || 'form');

        return {
            prepare: function () {
                const mode = section.getAttribute('data-mode') || 'form';
                if (mode === 'json') {
                    const raw = jsonEditor.value.trim();
                    if (raw === '') {
                        hiddenInput.value = '';
                        return { ok: true };
                    }
                    try {
                        const parsed = JSON.parse(raw);
                        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                            return { ok: false, message: 'Callback: il JSON deve rappresentare un oggetto.', focus: jsonEditor };
                        }
                        hiddenInput.value = JSON.stringify(parsed);
                        return { ok: true };
                    } catch (error) {
                        return { ok: false, message: 'Callback: JSON non valido (' + error.message + ').', focus: jsonEditor };
                    }
                }
                const payloadResult = buildPayload(true);
                if (payloadResult.errors && payloadResult.errors.length) {
                    return { ok: false, message: payloadResult.errors[0] };
                }
                if (!payloadResult.payload) {
                    hiddenInput.value = '';
                } else {
                    hiddenInput.value = JSON.stringify(payloadResult.payload);
                }
                return { ok: true };
            }
        };
    }

    const opzioniSection = setupKeyValueSection(form.querySelector('[data-advanced-section="opzioni"]'), 'Opzioni');
    const extraSection = setupKeyValueSection(form.querySelector('[data-advanced-section="extra"]'), 'Payload aggiuntivo');
    const callbackSection = setupCallbackSection(form.querySelector('[data-advanced-section="callback"]'));

    form.addEventListener('submit', function (event) {
        const handlers = [opzioniSection, extraSection, callbackSection];
        for (const handler of handlers) {
            if (!handler) {
                continue;
            }
            const outcome = handler.prepare();
            if (!outcome.ok) {
                event.preventDefault();
                window.alert(outcome.message);
                if (outcome.focus && typeof outcome.focus.focus === 'function') {
                    outcome.focus.focus();
                }
                return;
            }
        }
    });
})();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
