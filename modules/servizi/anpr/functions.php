<?php
declare(strict_types=1);

use Mpdf\Mpdf;
use Mpdf\MpdfException;
use Mpdf\Output\Destination;
const ANPR_MODULE_LOG = 'Servizi/ANPR';
const ANPR_ALLOWED_STATUSES = [
    'In lavorazione',
    'Completato',
    'Annullato',
];

const ANPR_SIGNATURE_STATUSES = [
    'non_inviata',
    'otp_inviato',
    'firmata',
    'scaduta',
];

const ANPR_SIGNATURE_OTP_TTL = 900; // 15 minuti
const ANPR_SIGNATURE_MAX_ATTEMPTS = 5;

const ANPR_DELEGA_AUTO_TYPES = [
    'Certificato di residenza',
    'Certificato di nascita',
    'Certificato di cittadinanza',
    'Certificato di stato di famiglia',
    'Certificato cumulativo',
    'Certificato contestuale',
    'Certificato di matrimonio',
    'Certificato di morte',
    'Cambio residenza assistito',
    'Delega / autocertificazione',
    'Altra certificazione',
];

const ANPR_ATTACHMENT_RULES = [
    'certificato' => [
        'dir' => 'uploads/anpr/certificati',
        'allowed_mimes' => ['application/pdf'],
        'max_size' => 15728640, // 15 MB
        'columns' => [
            'path' => 'certificato_path',
            'hash' => 'certificato_hash',
            'uploaded_at' => 'certificato_caricato_at',
        ],
    ],
    'delega' => [
        'dir' => 'uploads/anpr/deleghe',
        'allowed_mimes' => ['application/pdf'],
        'max_size' => 10485760, // 10 MB
        'columns' => [
            'path' => 'delega_path',
            'hash' => 'delega_hash',
            'uploaded_at' => 'delega_caricato_at',
        ],
    ],
    'documento' => [
        'dir' => 'uploads/anpr/documenti',
        'allowed_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
        'max_size' => 10485760, // 10 MB
        'columns' => [
            'path' => 'documento_path',
            'hash' => 'documento_hash',
            'uploaded_at' => 'documento_caricato_at',
        ],
    ],
];

function anpr_practice_types(): array
{
    return [
        'Certificato di residenza',
        'Certificato di nascita',
        'Certificato di cittadinanza',
        'Certificato di stato di famiglia',
        'Certificato cumulativo',
        'Certificato contestuale',
        'Certificato di matrimonio',
        'Certificato di morte',
        'Cambio residenza assistito',
        'Delega / autocertificazione',
        'Altra certificazione',
    ];
}

function anpr_service_catalog(): array
{
    return [
        [
            'servizio' => 'Certificato di residenza',
            'prezzo' => '€3–5',
            'note' => 'Rilascio in pochi minuti',
        ],
        [
            'servizio' => 'Certificato di nascita',
            'prezzo' => '€3–5',
            'note' => '',
        ],
        [
            'servizio' => 'Stato di famiglia',
            'prezzo' => '€3–6',
            'note' => '',
        ],
        [
            'servizio' => 'Certificato cumulativo',
            'prezzo' => '€5–8',
            'note' => '',
        ],
        [
            'servizio' => 'Cambio residenza assistito',
            'prezzo' => '€15–25',
            'note' => 'Con caricamento moduli e PEC',
        ],
        [
            'servizio' => 'Delega / autocertificazione',
            'prezzo' => '€2–3',
            'note' => 'Generata dal gestionale',
        ],
    ];
}

function anpr_fetch_pratiche(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT ap.*, c.nome, c.cognome, c.ragione_sociale, c.email AS cliente_email,
            u.username AS operatore_username, u.nome AS operatore_nome, u.cognome AS operatore_cognome,
            us.username AS spid_operatore_username
        FROM anpr_pratiche ap
        LEFT JOIN clienti c ON ap.cliente_id = c.id
        LEFT JOIN users u ON ap.operatore_id = u.id
        LEFT JOIN users us ON ap.spid_operatore_id = us.id';

    $where = [];
    $params = [];

    if (!empty($filters['stato'])) {
        $where[] = 'ap.stato = :stato';
        $params[':stato'] = $filters['stato'];
    }

    if (!empty($filters['tipo_pratica'])) {
        $where[] = 'ap.tipo_pratica = :tipo';
        $params[':tipo'] = $filters['tipo_pratica'];
    }

    if (!empty($filters['query'])) {
        $where[] = '(ap.pratica_code LIKE :search OR c.nome LIKE :search OR c.cognome LIKE :search OR c.ragione_sociale LIKE :search)';
        $params[':search'] = '%' . $filters['query'] . '%';
    }

    if (!empty($filters['cliente_id'])) {
        $where[] = 'ap.cliente_id = :cliente_id';
        $params[':cliente_id'] = (int) $filters['cliente_id'];
    }

    if (!empty($filters['has_certificate'])) {
        $where[] = 'ap.certificato_path IS NOT NULL';
    }

    if (!empty($filters['created_from'])) {
        $where[] = 'ap.created_at >= :created_from';
        $params[':created_from'] = $filters['created_from'] . ' 00:00:00';
    }

    if (!empty($filters['created_to'])) {
        $where[] = 'ap.created_at <= :created_to';
        $params[':created_to'] = $filters['created_to'] . ' 23:59:59';
    }

    if (!empty($filters['certificate_from'])) {
        $where[] = 'ap.certificato_caricato_at >= :cert_from';
        $params[':cert_from'] = $filters['certificate_from'] . ' 00:00:00';
    }

    if (!empty($filters['certificate_to'])) {
        $where[] = 'ap.certificato_caricato_at <= :cert_to';
        $params[':cert_to'] = $filters['certificate_to'] . ' 23:59:59';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $orderBy = 'ap.created_at';
    $orderDir = 'DESC';

    if (!empty($filters['order_by'])) {
        $allowed = ['ap.created_at', 'ap.certificato_caricato_at', 'ap.pratica_code'];
        if (in_array($filters['order_by'], $allowed, true)) {
            $orderBy = $filters['order_by'];
        }
    }

    if (!empty($filters['order_dir']) && strtoupper($filters['order_dir']) === 'ASC') {
        $orderDir = 'ASC';
    }

    $sql .= ' ORDER BY ' . $orderBy . ' ' . $orderDir;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

function anpr_fetch_pratica(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT ap.*, c.nome, c.cognome, c.ragione_sociale, c.cf_piva AS cliente_cf_piva, c.email AS cliente_email,
        c.telefono AS cliente_telefono,
        u.username AS operatore_username, u.nome AS operatore_nome, u.cognome AS operatore_cognome,
        us.username AS spid_operatore_username
        FROM anpr_pratiche ap
        LEFT JOIN clienti c ON ap.cliente_id = c.id
        LEFT JOIN users u ON ap.operatore_id = u.id
        LEFT JOIN users us ON ap.spid_operatore_id = us.id
        WHERE ap.id = :id');
    $stmt->execute([':id' => $id]);
    $pratica = $stmt->fetch();

    return $pratica ?: null;
}

function anpr_generate_pratica_code(PDO $pdo): string
{
    $year = (new DateTimeImmutable('now'))->format('Y');
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM anpr_pratiche WHERE YEAR(created_at) = :year');
    $stmt->execute([':year' => $year]);
    $count = (int) $stmt->fetchColumn();
    $next = $count + 1;

    return sprintf('ANPR-%s-%05d', $year, $next);
}

function anpr_attachment_storage_path(int $praticaId, string $type): string
{
    $type = strtolower($type);
    $rules = ANPR_ATTACHMENT_RULES[$type] ?? null;
    if (!$rules) {
        throw new InvalidArgumentException('Tipo allegato non supportato.');
    }

    $relative = rtrim($rules['dir'], '/') . '/' . $praticaId;
    return public_path($relative);
}

function anpr_log_action(PDO $pdo, string $action, string $details): void
{
    try {
        $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
        $stmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $stmt->execute([
            ':user_id' => $userId ?: null,
            ':modulo' => ANPR_MODULE_LOG,
            ':azione' => $action,
            ':dettagli' => $details,
        ]);
    } catch (Throwable $exception) {
        error_log('ANPR log error: ' . $exception->getMessage());
    }
}

function anpr_fetch_clienti(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, ragione_sociale, nome, cognome FROM clienti ORDER BY ragione_sociale, cognome, nome');
    return $stmt->fetchAll() ?: [];
}

function anpr_set_spid_status(PDO $pdo, int $praticaId, ?int $operatoreId): void
{
    if ($operatoreId) {
        $stmt = $pdo->prepare('UPDATE anpr_pratiche
            SET spid_verificato_at = NOW(), spid_operatore_id = :operatore_id, updated_at = NOW()
            WHERE id = :id');
        $stmt->execute([
            ':operatore_id' => $operatoreId,
            ':id' => $praticaId,
        ]);
    } else {
        $stmt = $pdo->prepare('UPDATE anpr_pratiche
            SET spid_verificato_at = NULL, spid_operatore_id = NULL, updated_at = NOW()
            WHERE id = :id');
        $stmt->execute([':id' => $praticaId]);
    }
}

function anpr_record_certificate_delivery(PDO $pdo, int $praticaId, string $channel, string $recipient): void
{
    $stmt = $pdo->prepare('UPDATE anpr_pratiche
        SET certificato_inviato_at = NOW(),
            certificato_inviato_via = :via,
            certificato_inviato_destinatario = :recipient,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([
        ':via' => $channel,
        ':recipient' => $recipient,
        ':id' => $praticaId,
    ]);
}

function anpr_get_attachment_rules(string $type): array
{
    $type = strtolower($type);
    $rules = ANPR_ATTACHMENT_RULES[$type] ?? null;
    if (!$rules) {
        throw new InvalidArgumentException('Tipo allegato non supportato.');
    }

    return $rules;
}

function anpr_store_attachment(array $file, int $praticaId, string $type): array
{
    $rules = anpr_get_attachment_rules($type);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Errore durante il caricamento del file.');
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload non valido.');
    }

    $maxSize = (int) ($rules['max_size'] ?? 0);
    if ($maxSize > 0 && (int) ($file['size'] ?? 0) > $maxSize) {
        throw new RuntimeException('Il file supera la dimensione massima consentita.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : null;
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = $rules['allowed_mimes'] ?? [];
    if ($mime === false || !in_array((string) $mime, $allowed, true)) {
        throw new RuntimeException('Tipo di file non supportato per questo allegato.');
    }

    $storageDir = anpr_attachment_storage_path($praticaId, $type);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Impossibile creare la cartella di archiviazione.');
    }

    $sanitizedName = sanitize_filename($file['name']);
    $random = bin2hex(random_bytes(4));
    $fileName = sprintf('%s_%s_%s_%s', strtolower($type), date('YmdHis'), $random, $sanitizedName);
    $destination = $storageDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Impossibile salvare il file caricato.');
    }

    $relative = rtrim($rules['dir'], '/') . '/' . $praticaId . '/' . $fileName;

    return [
        'path' => $relative,
        'hash' => hash_file('sha256', $destination),
    ];
}

function anpr_delete_attachment(?string $relativePath): void
{
    if (!$relativePath) {
        return;
    }

    $absolute = public_path($relativePath);
    if (is_file($absolute)) {
        @unlink($absolute);
    }
}

function anpr_store_certificate(array $file, int $praticaId): array
{
    return anpr_store_attachment($file, $praticaId, 'certificato');
}

function anpr_delete_certificate(?string $relativePath): void
{
    anpr_delete_attachment($relativePath);
}

function anpr_store_delega(array $file, int $praticaId): array
{
    return anpr_store_attachment($file, $praticaId, 'delega');
}

function anpr_set_delega_metadata(PDO $pdo, int $praticaId, array $stored, bool $autoGenerated): void
{
    $stmt = $pdo->prepare('UPDATE anpr_pratiche
        SET delega_path = :path,
            delega_hash = :hash,
            delega_caricato_at = NOW(),
            delega_generata_auto = :auto,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([
        ':path' => $stored['path'],
        ':hash' => $stored['hash'],
        ':auto' => $autoGenerated ? 1 : 0,
        ':id' => $praticaId,
    ]);
}

function anpr_delete_delega(?string $relativePath): void
{
    anpr_delete_attachment($relativePath);
}

function anpr_store_documento(array $file, int $praticaId): array
{
    return anpr_store_attachment($file, $praticaId, 'documento');
}

function anpr_delete_documento(?string $relativePath): void
{
    anpr_delete_attachment($relativePath);
}

function anpr_signature_generate_otp(): array
{
    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $salt = bin2hex(random_bytes(4));
    $hash = hash('sha256', $salt . $otp);

    return [
        'otp' => $otp,
        'salt' => $salt,
        'hash' => $hash,
    ];
}

function anpr_signature_clear(PDO $pdo, int $praticaId): void
{
    $stmt = $pdo->prepare('UPDATE anpr_pratiche
        SET delega_firma_status = "non_inviata",
            delega_firma_hash = NULL,
            delega_firma_otp_salt = NULL,
            delega_firma_inviata_il = NULL,
            delega_firma_verificata_il = NULL,
            delega_firma_recipient = NULL,
            delega_firma_channel = NULL,
            delega_firma_attempts = 0,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([':id' => $praticaId]);
}

function anpr_signature_store_send(PDO $pdo, int $praticaId, string $hash, string $salt, string $channel, string $recipient): void
{
    $stmt = $pdo->prepare('UPDATE anpr_pratiche
        SET delega_firma_status = "otp_inviato",
            delega_firma_hash = :hash,
            delega_firma_otp_salt = :salt,
            delega_firma_inviata_il = NOW(),
            delega_firma_verificata_il = NULL,
            delega_firma_channel = :channel,
            delega_firma_recipient = :recipient,
            delega_firma_attempts = 0,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([
        ':hash' => $hash,
        ':salt' => $salt,
        ':channel' => $channel,
        ':recipient' => $recipient,
        ':id' => $praticaId,
    ]);
}

function anpr_signature_mark_verified(PDO $pdo, int $praticaId): void
{
    $stmt = $pdo->prepare('UPDATE anpr_pratiche
        SET delega_firma_status = "firmata",
            delega_firma_verificata_il = NOW(),
            delega_firma_hash = NULL,
            delega_firma_otp_salt = NULL,
            delega_firma_attempts = 0,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([':id' => $praticaId]);
}

function anpr_signature_increment_attempt(PDO $pdo, int $praticaId, bool $expire = false): void
{
    if ($expire) {
        $stmt = $pdo->prepare('UPDATE anpr_pratiche
            SET delega_firma_status = "scaduta",
                delega_firma_hash = NULL,
                delega_firma_otp_salt = NULL,
                delega_firma_attempts = delega_firma_attempts + 1,
                updated_at = NOW()
            WHERE id = :id');
        $stmt->execute([':id' => $praticaId]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE anpr_pratiche
        SET delega_firma_attempts = delega_firma_attempts + 1,
            updated_at = NOW()
        WHERE id = :id');
    $stmt->execute([':id' => $praticaId]);
}

function anpr_signature_is_expired(array $pratica): bool
{
    if (empty($pratica['delega_firma_inviata_il'])) {
        return false;
    }

    try {
        $sentAt = new DateTimeImmutable($pratica['delega_firma_inviata_il']);
    } catch (Throwable $exception) {
        return false;
    }

    return $sentAt->getTimestamp() + ANPR_SIGNATURE_OTP_TTL < time();
}

function anpr_is_delega_auto_supported(?string $tipoPratica): bool
{
    if ($tipoPratica === null || $tipoPratica === '') {
        return false;
    }

    return in_array($tipoPratica, ANPR_DELEGA_AUTO_TYPES, true);
}

function anpr_should_generate_delega(string $tipoPratica, bool $userRequested): bool
{
    return $userRequested && anpr_is_delega_auto_supported($tipoPratica);
}

function anpr_normalize_delega_data(array $pratica): array
{
    $clienteNome = trim((string) ($pratica['nome'] ?? ''));
    $clienteCognome = trim((string) ($pratica['cognome'] ?? ''));
    $ragioneSociale = trim((string) ($pratica['ragione_sociale'] ?? ''));
    $clienteDisplay = $ragioneSociale !== '' ? $ragioneSociale : trim($clienteNome . ' ' . $clienteCognome);
    if ($clienteDisplay === '') {
        $clienteDisplay = 'Cliente';
    }

    $praticaCode = (string) ($pratica['pratica_code'] ?? '');
    $tipoPratica = (string) ($pratica['tipo_pratica'] ?? '');
    $operatore = trim((string) ($pratica['operatore_username'] ?? ''));
    $operatoreNome = trim((string) ($pratica['operatore_nome'] ?? ''));
    $operatoreCognome = trim((string) ($pratica['operatore_cognome'] ?? ''));

    $operatoreDisplay = $operatore;
    $operatorFull = trim($operatoreNome . ' ' . $operatoreCognome);
    if ($operatorFull !== '') {
        $operatoreDisplay = $operatorFull;
    }

    if (!function_exists('env')) {
        require_once __DIR__ . '/../../../includes/env.php';
    }

    $companyName = trim((string) (env('ANPR_DELEGA_DELEGATO', env('COMPANY_LEGAL_NAME', env('APP_NAME', 'AG Servizi - Coresuite Business'))) ?? ''));
    if ($companyName === '') {
        $companyName = 'AG Servizi - Coresuite Business';
    }

    $companyAddress = trim((string) (env('ANPR_DELEGA_DELEGATO_INDIRIZZO', env('COMPANY_ADDRESS', '')) ?? ''));

    $createdAt = (string) ($pratica['created_at'] ?? '');
    $createdAtFormatted = format_datetime_locale($createdAt ?: date('Y-m-d H:i:s'));
    $today = format_date_locale(date('Y-m-d'));

    $firmaStatus = trim((string) ($pratica['delega_firma_status'] ?? ''));
    $firmaChannel = trim((string) ($pratica['delega_firma_channel'] ?? ''));
    $firmaRecipient = trim((string) ($pratica['delega_firma_recipient'] ?? ''));
    $firmaVerifiedAtRaw = (string) ($pratica['delega_firma_verificata_il'] ?? '');
    $firmaVerifiedAt = '';
    if ($firmaVerifiedAtRaw !== '') {
        $firmaVerifiedAt = format_datetime_locale($firmaVerifiedAtRaw);
    }

    return [
        'cliente_display' => $clienteDisplay,
        'cliente_nome' => $clienteNome,
        'cliente_cognome' => $clienteCognome,
        'cliente_cf_piva' => trim((string) ($pratica['cliente_cf_piva'] ?? '')),
        'cliente_email' => trim((string) ($pratica['cliente_email'] ?? '')),
        'cliente_telefono' => trim((string) ($pratica['cliente_telefono'] ?? '')),
        'pratica_code' => $praticaCode,
        'tipo_pratica' => $tipoPratica,
        'operatore_username' => $operatoreDisplay,
        'company_name' => $companyName,
        'company_address' => $companyAddress,
        'created_at' => $createdAtFormatted,
        'data_oggi' => $today,
        'delega_firma_status' => $firmaStatus,
        'delega_firma_channel' => $firmaChannel,
        'delega_firma_recipient' => $firmaRecipient,
        'delega_firma_verificata_il' => $firmaVerifiedAt,
    ];
}

function anpr_delega_html_escape(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function anpr_generate_delega_pdf(array $pratica): array
{
    if (empty($pratica['id'])) {
        throw new InvalidArgumentException('Pratica non valida per la generazione della delega.');
    }

    $data = anpr_normalize_delega_data($pratica);

    $storageDir = anpr_attachment_storage_path((int) $pratica['id'], 'delega');
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        throw new RuntimeException('Impossibile creare la cartella deleghe.');
    }

    $fileName = sprintf('delega_auto_%s.pdf', date('YmdHis'));
    $destination = $storageDir . DIRECTORY_SEPARATOR . $fileName;

    try {
        $mpdf = new Mpdf([
            'format' => 'A4',
            'margin_left' => 20,
            'margin_right' => 20,
            'margin_top' => 25,
            'margin_bottom' => 25,
        ]);
    } catch (MpdfException $exception) {
        throw new RuntimeException('Impossibile inizializzare il motore PDF. ' . $exception->getMessage());
    }

    $mpdf->SetTitle('Delega pratica ' . $data['pratica_code']);
    $mpdf->SetAuthor($data['company_name']);

    $clienteDisplay = anpr_delega_html_escape($data['cliente_display']);
    $clienteCodiceFiscale = anpr_delega_html_escape($data['cliente_cf_piva'] ?? '');
    $companyName = anpr_delega_html_escape($data['company_name']);
    $companyAddress = anpr_delega_html_escape($data['company_address']);
    $tipoPratica = anpr_delega_html_escape($data['tipo_pratica'] !== '' ? $data['tipo_pratica'] : 'Documento anagrafico');
    $praticaCode = anpr_delega_html_escape($data['pratica_code']);
    $operatore = anpr_delega_html_escape($data['operatore_username']);
    $clienteEmail = anpr_delega_html_escape($data['cliente_email']);
    $clienteTelefono = anpr_delega_html_escape($data['cliente_telefono']);
    $createdAt = anpr_delega_html_escape($data['created_at']);
    $dataOggi = anpr_delega_html_escape($data['data_oggi']);
    $firmaStatus = anpr_delega_html_escape($data['delega_firma_status'] ?? '');
    $firmaChannel = anpr_delega_html_escape($data['delega_firma_channel'] ?? '');
    $firmaRecipient = anpr_delega_html_escape($data['delega_firma_recipient'] ?? '');
    $firmaVerifiedAt = anpr_delega_html_escape($data['delega_firma_verificata_il'] ?? '');

    $contatti = '';
    if ($clienteEmail !== '') {
        $contatti .= '<li>Email delegante: <strong>' . $clienteEmail . '</strong></li>';
    }
    if ($clienteTelefono !== '') {
        $contatti .= '<li>Telefono delegante: <strong>' . $clienteTelefono . '</strong></li>';
    }
    if ($companyAddress !== '') {
        $contatti .= '<li>Delegato operativo presso: <strong>' . $companyAddress . '</strong></li>';
    }

    $praticaDetails = '';
    if ($praticaCode !== '') {
        $praticaDetails .= '<li>Codice pratica interno: <strong>' . $praticaCode . '</strong></li>';
    }
    if ($operatore !== '') {
        $praticaDetails .= '<li>Operatore incaricato: <strong>' . $operatore . '</strong></li>';
    }

    $html = '<style>
        body { font-family: "DejaVu Sans", Arial, sans-serif; color: #1c2534; font-size: 11pt; }
        h1 { text-transform: uppercase; text-align: center; font-size: 16pt; margin-bottom: 12pt; letter-spacing: 1px; }
        p { line-height: 1.5; margin: 0 0 10pt; }
        ul { margin: 0 0 10pt 18pt; padding: 0; }
        li { margin-bottom: 6pt; }
        .section { margin-bottom: 14pt; }
        .meta { font-size: 10pt; color: #4a566b; }
        .signatures { display: flex; justify-content: space-between; margin-top: 40pt; }
        .signature-block { width: 45%; text-align: center; }
        .signature-line { margin-top: 40pt; border-top: 1px solid #000; padding-top: 4pt; font-size: 10pt; }
        .footer-note { font-size: 9pt; color: #6c7d93; margin-top: 32pt; text-align: center; }
    </style>';

    $deleganteInfo = trim($clienteDisplay);
    if ($clienteCodiceFiscale !== '') {
        $deleganteInfo = trim($deleganteInfo . ($deleganteInfo !== '' ? ', ' : '') . 'C.F. ' . $clienteCodiceFiscale);
    }

    $deleganteIntro = 'Il/La sottoscritto/a ';
    if ($deleganteInfo !== '') {
        $deleganteIntro .= '(<strong>' . $deleganteInfo . '</strong>) ';
    }

    $html .= '<h1>Delega per richiesta documenti ANPR</h1>';
    $html .= '<div class="section">'
        . '<p>' . $deleganteIntro . 'autorizza l&#39;agenzia <strong>AG Servizi Via Plinio 72</strong> '
        . 'a operare in qualità di delegato per le richieste presso l&#39;Anagrafe Nazionale della Popolazione Residente (ANPR).</p>'
        . '<p>La delega è valida ai fini dell&#39;ottenimento del seguente documento:</p>'
        . '<p><strong>' . $tipoPratica . '</strong></p>'
        . '</div>';

    if ($praticaDetails !== '') {
        $html .= '<div class="section"><p class="meta">Riferimenti pratica</p><ul>' . $praticaDetails . '</ul></div>';
    }

    if ($contatti !== '') {
        $html .= '<div class="section"><p class="meta">Contatti utili</p><ul>' . $contatti . '</ul></div>';
    }

    $html .= '<div class="section"><p>La delega comprende la gestione di eventuale documentazione integrativa, '
        . 'la trasmissione di richieste agli uffici comunali competenti e l&#39;utilizzo dei dati personali '
        . 'strettamente necessari al completamento della pratica nel rispetto del Regolamento UE 2016/679 (GDPR).</p></div>';

    $html .= '<div class="section meta"><p>Data richiesta: <strong>' . $createdAt . '</strong></p>'
        . '<p>Data delega: <strong>' . $dataOggi . '</strong></p></div>';

    $firmaInfo = '';
    if ($firmaStatus === 'firmata' && $firmaVerifiedAt !== '') {
        $parts = [];
        $parts[] = 'Firmato digitalmente il ' . $firmaVerifiedAt;
        if ($firmaChannel !== '') {
            $parts[] = 'Metodo: ' . strtoupper($firmaChannel);
        }
        if ($firmaRecipient !== '') {
            $parts[] = 'OTP: ' . $firmaRecipient;
        }
        $firmaInfo = implode(' • ', $parts);
    }

    $html .= '<div class="signatures">'
        . '<div class="signature-block">'
        . ($firmaInfo !== '' ? '<div class="meta mb-2">' . $firmaInfo . '</div>' : '')
        . '<div class="signature-line">Firma delegante</div>'
        . '</div>'
    . '<div class="signature-block">'
        . '<div class="signature-line">Firma delegato</div>'
        . '</div>'
        . '</div>';

    $html .= '<div class="footer-note">Documento generato automaticamente dal gestionale Coresuite Business.</div>';

    $mpdf->WriteHTML($html);

    try {
        $mpdf->Output($destination, Destination::FILE);
    } catch (MpdfException $exception) {
        throw new RuntimeException('Impossibile scrivere il PDF generato. ' . $exception->getMessage());
    }

    $relative = rtrim(ANPR_ATTACHMENT_RULES['delega']['dir'], '/') . '/' . $pratica['id'] . '/' . $fileName;

    return [
        'path' => $relative,
        'hash' => hash_file('sha256', $destination),
    ];
}

function anpr_auto_generate_delega(PDO $pdo, int $praticaId, ?array $pratica = null): array
{
    $pratica = $pratica ?? anpr_fetch_pratica($pdo, $praticaId);
    if (!$pratica) {
        throw new RuntimeException('Pratica non trovata per la generazione automatica della delega.');
    }

    if (!anpr_is_delega_auto_supported($pratica['tipo_pratica'] ?? '')) {
        throw new RuntimeException('La tipologia selezionata non supporta la generazione automatica della delega.');
    }

    if (!empty($pratica['delega_path'])) {
        anpr_delete_delega($pratica['delega_path']);
    }

    $generated = anpr_generate_delega_pdf($pratica);
    anpr_set_delega_metadata($pdo, $praticaId, $generated, true);
    anpr_signature_clear($pdo, $praticaId);

    anpr_log_action($pdo, 'Delega generata', 'Delega auto-generata per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));

    return $generated;
}

function anpr_can_generate_delega(array $pratica): bool
{
    return anpr_is_delega_auto_supported($pratica['tipo_pratica'] ?? '');
}
