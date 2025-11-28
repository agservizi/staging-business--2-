<?php
declare(strict_types=1);

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    http_response_code(403);
    exit('Accesso non autorizzato.');
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/../../../includes/helpers.php';

const PICKUP_UPLOAD_BASE = __DIR__ . '/../../../uploads/pickup';
const PICKUP_SIGNATURE_DIR = PICKUP_UPLOAD_BASE . '/signatures';
const PICKUP_PHOTO_DIR = PICKUP_UPLOAD_BASE . '/photos';
const PICKUP_RECEIPT_DIR = PICKUP_UPLOAD_BASE . '/receipts';
const PICKUP_QR_DIR = PICKUP_UPLOAD_BASE . '/qr';
const PICKUP_BACKUP_DIR = PICKUP_UPLOAD_BASE . '/backups';

const PICKUP_STATUS_MAP = [
    'in_arrivo' => ['label' => 'In Arrivo'],
    'consegnato' => ['label' => 'Consegnato'],
    'ritirato' => ['label' => 'Ritirato'],
    'in_giacenza' => ['label' => 'In Giacenza'],
    'in_giacenza_scaduto' => ['label' => 'In Giacenza - Scaduto'],
];

const PICKUP_DEFAULT_ARCHIVE_DAYS = 30;
const PICKUP_DEFAULT_STORAGE_GRACE_DAYS = 15;
const PICKUP_STORAGE_WARNING_BEFORE_DAYS = 3;

const PICKUP_EVENT_TEMPLATES = [
    'arrived' => [
        'email_subject' => 'Il tuo pacco {{tracking}} è arrivato',
        'email_body' => "Ciao {{customer_name}},\nIl tuo pacco con tracking {{tracking}} è arrivato presso {{location_name}}.\nCodice OTP: {{otp}}\nTi aspettiamo per il ritiro!",
        'whatsapp_body' => 'Ciao {{customer_name}}, il tuo pacco {{tracking}} è arrivato presso {{location_name}}. Codice OTP: {{otp}}.',
    ],
    'picked_up' => [
        'email_subject' => 'Conferma ritiro pacco {{tracking}}',
        'email_body' => "Ciao {{customer_name}},\nAbbiamo consegnato il pacco {{tracking}} presso {{location_name}}. Grazie per aver utilizzato il servizio Pickup!",
        'whatsapp_body' => 'Ciao {{customer_name}}, confermiamo il ritiro del pacco {{tracking}} presso {{location_name}}. A presto!',
    ],
    'storage_warning' => [
        'email_subject' => 'Avviso giacenza pacco {{tracking}}',
        'email_body' => "Ciao {{customer_name}},\nIl pacco {{tracking}} è in giacenza da {{days_in_storage}} giorni presso {{location_name}}. Ti chiediamo di passare entro {{expiration_date}}.",
        'whatsapp_body' => 'Avviso: il pacco {{tracking}} è in giacenza da {{days_in_storage}} giorni presso {{location_name}}. Ritiralo entro {{expiration_date}}.',
    ],
    'storage_expired' => [
        'email_subject' => 'Giacenza scaduta per il pacco {{tracking}}',
        'email_body' => "Ciao {{customer_name}},\nIl pacco {{tracking}} ha superato il periodo massimo di giacenza e verrà gestito come da policy. Contatta il punto ritiro per maggiori informazioni.",
        'whatsapp_body' => 'Il pacco {{tracking}} ha superato il limite di giacenza. Contatta il punto ritiro.',
    ],
    'otp_generated' => [
        'email_subject' => 'Codice OTP per ritiro pacco {{tracking}}',
        'email_body' => "Ciao {{customer_name}},\nCodice OTP per ritirare il pacco {{tracking}}: {{otp}}. Valido fino al {{expiration_date}}.",
        'whatsapp_body' => 'Codice OTP per il pacco {{tracking}}: {{otp}} (scadenza {{expiration_time}}).',
    ],
];

function pickup_root_path(): string
{
    return dirname(__DIR__, 3);
}

function pickup_ensure_directory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Impossibile creare la directory di lavoro pickup: ' . $directory);
    }
}

function pickup_relative_path(string $absolutePath): string
{
    $root = rtrim(str_replace('\\', '/', pickup_root_path()), '/');
    $normalized = str_replace('\\', '/', $absolutePath);
    if (strpos($normalized, $root) === 0) {
        return ltrim(substr($normalized, strlen($root)), '/');
    }
    return $absolutePath;
}

function pickup_web_base_path(): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $projectRoot = realpath(pickup_root_path()) ?: pickup_root_path();
    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
    $projectRoot = rtrim(str_replace('\\', '/', $projectRoot), '/');

    $basePath = '';
    if ($docRoot !== '' && str_starts_with($projectRoot, $docRoot)) {
        $relative = trim(substr($projectRoot, strlen($docRoot)), '/');
        if ($relative !== '') {
            $basePath = '/' . $relative;
        }
    }

    $cached = $basePath;
    return $cached;
}

function pickup_public_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $normalized = ltrim($path, '/');
    $baseUrl = trim((string) env('APP_URL', ''));
    $basePath = pickup_web_base_path();

    if ($baseUrl !== '') {
        $baseUrl = rtrim($baseUrl, '/');
        $existingPath = parse_url($baseUrl, PHP_URL_PATH) ?: '';
        if ($existingPath === '' && $basePath !== '') {
            $baseUrl .= $basePath;
        }
        return $baseUrl . '/' . $normalized;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim($scheme . '://' . $host . $basePath, '/');

    return $base . '/' . $normalized;
}

function pickup_fetch_qr_image(string $targetUrl): ?string
{
    static $placeholderCache;
    static $placeholderLoaded = false;

    if (!$placeholderLoaded) {
        $placeholderLoaded = true;
        $placeholderValue = trim((string) env('PICKUP_QR_PLACEHOLDER_BASE64', ''));
        if ($placeholderValue !== '') {
            $decoded = base64_decode($placeholderValue, true);
            if ($decoded !== false) {
                $placeholderCache = $decoded;
            } else {
                error_log('Placeholder QR base64 non valido, variabile ignorata.');
                $placeholderCache = null;
            }
        } else {
            $placeholderCache = null;
        }
    }

    if ($placeholderCache !== null) {
        return $placeholderCache;
    }

    $serviceTemplate = trim((string) env('PICKUP_QR_SERVICE_URL', ''));
    if ($serviceTemplate === '') {
        $serviceTemplate = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={{DATA}}';
    }

    if (str_contains($serviceTemplate, '{{DATA}}')) {
        $qrService = str_replace('{{DATA}}', rawurlencode($targetUrl), $serviceTemplate);
    } else {
        $separator = str_contains($serviceTemplate, '?') ? '&' : '?';
        $qrService = $serviceTemplate . $separator . 'data=' . rawurlencode($targetUrl);
    }

    $timeout = (int) env('PICKUP_QR_HTTP_TIMEOUT', 6);
    if ($timeout <= 0) {
        $timeout = 6;
    }

    $contextOptions = [
        'http' => [
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: CoresuitePickup/1.0\r\nAccept: image/png,image/*;q=0.8,*/*;q=0.5\r\n",
        ],
    ];

    $caBundle = pickup_root_path() . '/certs/cacert.pem';
    if (is_file($caBundle)) {
        $contextOptions['ssl'] = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'cafile' => $caBundle,
        ];
    }

    $context = stream_context_create($contextOptions);
    $imageData = @file_get_contents($qrService, false, $context);
    if ($imageData === false) {
        error_log('QR service request failed for target URL: ' . $targetUrl);
        return null;
    }

    if (function_exists('getimagesizefromstring') && @getimagesizefromstring($imageData) === false) {
        error_log('QR service returned invalid payload for target URL: ' . $targetUrl);
        return null;
    }

    return $imageData;
}

function pickup_extract_package_id_from_code(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^#?(\d{1,10})$/', $value, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/[?&](?:id|package_id)=([0-9]+)/', $value, $matches)) {
        return (int) $matches[1];
    }

    if (preg_match('/pickup[^0-9]*([0-9]+)/i', $value, $matches)) {
        return (int) $matches[1];
    }

    $url = $value;
    if (!preg_match('#^https?://#i', $url)) {
        $baseUrl = rtrim(env('APP_URL', ''), '/');
        if ($baseUrl !== '') {
            $url = $baseUrl . '/' . ltrim($url, '/');
        } else {
            $url = 'https://placeholder.local/' . ltrim($url, '/');
        }
    }

    $parsed = parse_url($url);
    if (!$parsed) {
        return null;
    }

    $params = [];
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $params);
        if (isset($params['id'])) {
            return (int) $params['id'];
        }
        if (isset($params['package_id'])) {
            return (int) $params['package_id'];
        }
    }

    if (!empty($parsed['path']) && preg_match('/(\d+)/', $parsed['path'], $matches)) {
        return (int) $matches[1];
    }

    return null;
}

function pickup_random_numeric_code(int $length = 6): string
{
    $length = max(4, min($length, 10));
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= (string) random_int(0, 9);
    }
    return $code;
}

function pickup_storage_reference_datetime(array $package): ?\DateTimeImmutable
{
    foreach (['expected_at', 'updated_at', 'created_at'] as $key) {
        if (empty($package[$key])) {
            continue;
        }

        try {
            return new \DateTimeImmutable((string) $package[$key]);
        } catch (\Throwable $exception) {
            continue;
        }
    }

    return null;
}

function pickup_calculate_days_in_storage(array $package, ?\DateTimeInterface $now = null): int
{
    $referenceDate = pickup_storage_reference_datetime($package);
    if (!$referenceDate) {
        return 0;
    }

    try {
        $current = $now instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable('now');
        $diff = $referenceDate->diff($current);
        return max(0, (int) $diff->days);
    } catch (\Throwable $exception) {
        return 0;
    }
}

function pickup_render_template(string $template, array $data): string
{
    $replacements = [];
    foreach ($data as $key => $value) {
        $replacements['{{' . $key . '}}'] = (string) $value;
    }
    return strtr($template, $replacements);
}

function pickup_statuses(): array
{
    return array_keys(PICKUP_STATUS_MAP);
}

function pickup_status_label(string $status): string
{
    return PICKUP_STATUS_MAP[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
}

function pickup_current_timestamp(): string
{
    return (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function pickup_timestamp_minus_days(int $days): string
{
    $days = max(0, $days);
    return (new \DateTimeImmutable('now'))->modify(sprintf('-%d days', $days))->format('Y-m-d H:i:s');
}

function clean_input(?string $value, int $maxLength = 255): string
{
    if ($value === null) {
        return '';
    }

    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = strip_tags($value);
    $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '';
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        $value = mb_substr($value, 0, $maxLength, 'UTF-8');
    } else {
        $value = substr($value, 0, $maxLength);
    }

    return $value;
}

function ensure_pickup_tables(): void
{
    create_pickup_locations_table();
    create_couriers_table();
    create_packages_table();
    create_notifications_table();
    create_pickup_otps_table();
    create_pickup_history_table();
    create_pickup_customer_portal_tables();
    ensure_pickup_default_records();
}

function ensure_pickup_default_records(): void
{
    ensure_default_pickup_location();
    ensure_default_couriers();
}

function create_pickup_locations_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pickup_locations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    address TEXT NULL,
    contact_phone TEXT NULL,
    contact_email TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_location_name ON pickup_locations (name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_location_created ON pickup_locations (created_at)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_locations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    address VARCHAR(255) NULL,
    contact_phone VARCHAR(50) NULL,
    contact_email VARCHAR(160) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pickup_location_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_couriers_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pickup_couriers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    logo_url TEXT NULL,
    contact_name TEXT NULL,
    support_email TEXT NULL,
    support_phone TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_courier_name ON pickup_couriers (name)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_courier_created ON pickup_couriers (created_at)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_couriers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    logo_url VARCHAR(255) NULL,
    contact_name VARCHAR(120) NULL,
    support_email VARCHAR(160) NULL,
    support_phone VARCHAR(40) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pickup_courier_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_packages_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pickup_packages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tracking TEXT NOT NULL,
    customer_name TEXT NOT NULL,
    customer_phone TEXT NOT NULL,
    customer_email TEXT NULL,
    courier_id INTEGER NULL,
    pickup_location_id INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'in_arrivo',
    expected_at DATETIME NULL,
    notes TEXT NULL,
    signature_path TEXT NULL,
    signature_captured_at DATETIME NULL,
    photo_path TEXT NULL,
    photo_captured_at DATETIME NULL,
    qr_code_path TEXT NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (courier_id) REFERENCES pickup_couriers(id) ON DELETE SET NULL ON UPDATE CASCADE,
    FOREIGN KEY (pickup_location_id) REFERENCES pickup_locations(id) ON DELETE SET NULL ON UPDATE CASCADE
);
SQL);
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_tracking ON pickup_packages (tracking)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_status ON pickup_packages (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_courier ON pickup_packages (courier_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_location ON pickup_packages (pickup_location_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_created ON pickup_packages (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_archived ON pickup_packages (archived_at)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_packages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracking VARCHAR(100) NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_phone VARCHAR(50) NOT NULL,
    customer_email VARCHAR(160) NULL,
    courier_id INT UNSIGNED NULL,
    pickup_location_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'in_arrivo',
    expected_at DATETIME NULL,
    notes TEXT NULL,
    signature_path VARCHAR(255) NULL,
    signature_captured_at DATETIME NULL,
    photo_path VARCHAR(255) NULL,
    photo_captured_at DATETIME NULL,
    qr_code_path VARCHAR(255) NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pickup_tracking (tracking),
    INDEX idx_pickup_status (status),
    INDEX idx_pickup_courier (courier_id),
    INDEX idx_pickup_location (pickup_location_id),
    INDEX idx_pickup_created (created_at),
    INDEX idx_pickup_archived (archived_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);

    pickup_add_column_if_missing('pickup_packages', 'customer_email', 'VARCHAR(160) NULL AFTER customer_phone');
    pickup_add_column_if_missing('pickup_packages', 'pickup_location_id', 'INT UNSIGNED NULL AFTER courier_id');
    pickup_add_column_if_missing('pickup_packages', 'signature_path', 'VARCHAR(255) NULL AFTER notes');
    pickup_add_column_if_missing('pickup_packages', 'signature_captured_at', 'DATETIME NULL AFTER signature_path');
    pickup_add_column_if_missing('pickup_packages', 'photo_path', 'VARCHAR(255) NULL AFTER signature_captured_at');
    pickup_add_column_if_missing('pickup_packages', 'photo_captured_at', 'DATETIME NULL AFTER photo_path');
    pickup_add_column_if_missing('pickup_packages', 'qr_code_path', 'VARCHAR(255) NULL AFTER photo_captured_at');

    if (!pickup_foreign_key_exists('pickup_packages', 'pickup_packages_courier_fk')) {
        $pdo->exec('ALTER TABLE pickup_packages ADD CONSTRAINT pickup_packages_courier_fk FOREIGN KEY (courier_id) REFERENCES pickup_couriers(id) ON DELETE SET NULL ON UPDATE CASCADE');
    }

    if (!pickup_foreign_key_exists('pickup_packages', 'pickup_packages_location_fk')) {
        if (!pickup_column_exists('pickup_packages', 'pickup_location_id')) {
            pickup_add_column_if_missing('pickup_packages', 'pickup_location_id', 'INT UNSIGNED NULL AFTER courier_id');
        }
        $pdo->exec('ALTER TABLE pickup_packages ADD CONSTRAINT pickup_packages_location_fk FOREIGN KEY (pickup_location_id) REFERENCES pickup_locations(id) ON DELETE SET NULL ON UPDATE CASCADE');
    }
}

function create_notifications_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pickup_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id INTEGER NOT NULL,
    channel TEXT NOT NULL,
    status TEXT NOT NULL,
    message TEXT NOT NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES pickup_packages(id) ON DELETE CASCADE
);
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_notification_package ON pickup_notifications (package_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_notification_channel ON pickup_notifications (channel)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id INT UNSIGNED NOT NULL,
    channel VARCHAR(20) NOT NULL,
    status VARCHAR(80) NOT NULL,
    message TEXT NOT NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pickup_notification_package (package_id),
    INDEX idx_pickup_notification_channel (channel),
    CONSTRAINT pickup_notifications_package_fk FOREIGN KEY (package_id) REFERENCES pickup_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_otps_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pickup_otps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id INTEGER NOT NULL,
    code_hash TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL DEFAULT 5,
    channel TEXT NULL,
    created_by INTEGER NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES pickup_packages(id) ON DELETE CASCADE
);
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_otp_package ON pickup_otps (package_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_otp_expires ON pickup_otps (expires_at)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id INT UNSIGNED NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
    channel VARCHAR(20) NULL,
    created_by INT UNSIGNED NULL,
    consumed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pickup_otp_package (package_id),
    INDEX idx_pickup_otp_expires (expires_at),
    CONSTRAINT pickup_otps_package_fk FOREIGN KEY (package_id) REFERENCES pickup_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_history_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS pickup_package_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    package_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    previous_status TEXT NULL,
    new_status TEXT NULL,
    actor_id INTEGER NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (package_id) REFERENCES pickup_packages(id) ON DELETE CASCADE
);
SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_history_package ON pickup_package_history (package_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_history_event ON pickup_package_history (event_type)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_package_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id INT UNSIGNED NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    previous_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NULL,
    actor_id INT UNSIGNED NULL,
    meta TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pickup_history_package (package_id),
    INDEX idx_pickup_history_event (event_type),
    CONSTRAINT pickup_package_history_package_fk FOREIGN KEY (package_id) REFERENCES pickup_packages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_customer_portal_tables(): void
{
    create_pickup_customers_table();
    create_pickup_customer_otps_table();
    create_pickup_customer_sessions_table();
    create_pickup_customer_reports_table();
    create_pickup_customer_notifications_table();
    create_pickup_api_rate_limits_table();
    create_pickup_customer_activity_logs_table();
    create_pickup_customer_preferences_table();
    ensure_pickup_customer_preferences_seed();
    ensure_pickup_customer_summary_view();
}

function create_pickup_customers_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NULL,
    phone TEXT NULL,
    name TEXT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    email_verified INTEGER NOT NULL DEFAULT 0,
    phone_verified INTEGER NOT NULL DEFAULT 0,
    failed_login_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME NULL,
    last_login_attempt DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (status IN ('active','suspended','deleted')),
    CHECK (email IS NOT NULL OR phone IS NOT NULL)
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_email ON pickup_customers (email)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_phone ON pickup_customers (phone)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_status ON pickup_customers (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_created_at ON pickup_customers (created_at)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_customers_email ON pickup_customers (email)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_customers_phone ON pickup_customers (phone)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    name VARCHAR(100) NULL,
    status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    email_verified TINYINT(1) DEFAULT 0,
    phone_verified TINYINT(1) DEFAULT 0,
    failed_login_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME NULL,
    last_login_attempt DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT uq_pickup_customers_email UNIQUE (email),
    CONSTRAINT uq_pickup_customers_phone UNIQUE (phone),
    CONSTRAINT chk_pickup_customer_contact CHECK (email IS NOT NULL OR phone IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_email ON pickup_customers (email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_phone ON pickup_customers (phone)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_status ON pickup_customers (status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customers_created_at ON pickup_customers (created_at)');
}

function create_pickup_customer_otps_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_otps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    otp_code TEXT NOT NULL,
    delivery_method TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    used INTEGER NOT NULL DEFAULT 0,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (delivery_method IN ('email','sms','whatsapp')),
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_otps_customer_id ON pickup_customer_otps (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_otps_expires ON pickup_customer_otps (expires_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_otps_used ON pickup_customer_otps (used)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_otps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    otp_code VARCHAR(255) NOT NULL,
    delivery_method ENUM('email', 'sms', 'whatsapp') NOT NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) DEFAULT 0,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pickup_customer_otps_customer_id (customer_id),
    INDEX idx_pickup_customer_otps_expires (expires_at),
    INDEX idx_pickup_customer_otps_used (used),
    CONSTRAINT pickup_customer_otps_customer_fk FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_customer_sessions_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_sessions (
    id TEXT PRIMARY KEY,
    customer_id INTEGER NOT NULL,
    ip_address TEXT NOT NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_sessions_customer_id ON pickup_customer_sessions (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_sessions_last_activity ON pickup_customer_sessions (last_activity)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_sessions_expires_at ON pickup_customer_sessions (expires_at)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_sessions (
    id VARCHAR(128) PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL,
    last_activity DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    INDEX idx_pickup_customer_sessions_customer_id (customer_id),
    INDEX idx_pickup_customer_sessions_last_activity (last_activity),
    INDEX idx_pickup_customer_sessions_expires_at (expires_at),
    CONSTRAINT pickup_customer_sessions_customer_fk FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_customer_reports_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    tracking_code TEXT NOT NULL,
    courier_name TEXT NULL,
    expected_delivery_date DATE NULL,
    delivery_location TEXT NULL,
    recipient_name TEXT NULL,
    notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'reported',
    pickup_id INTEGER NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (status IN ('reported','confirmed','arrived','cancelled')),
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE,
    FOREIGN KEY (pickup_id) REFERENCES pickup_packages(id) ON DELETE SET NULL
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_reports_customer_id ON pickup_customer_reports (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_reports_tracking_code ON pickup_customer_reports (tracking_code)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_reports_status ON pickup_customer_reports (status)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_reports_created_at ON pickup_customer_reports (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_reports_pickup_id ON pickup_customer_reports (pickup_id)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    tracking_code VARCHAR(100) NOT NULL,
    courier_name VARCHAR(100) NULL,
    expected_delivery_date DATE NULL,
    delivery_location VARCHAR(255) NULL,
    recipient_name VARCHAR(100) NULL,
    notes TEXT NULL,
    status ENUM('reported', 'confirmed', 'arrived', 'cancelled') DEFAULT 'reported',
    pickup_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pickup_customer_reports_customer_id (customer_id),
    INDEX idx_pickup_customer_reports_tracking_code (tracking_code),
    INDEX idx_pickup_customer_reports_status (status),
    INDEX idx_pickup_customer_reports_created_at (created_at),
    INDEX idx_pickup_customer_reports_pickup_id (pickup_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);

    $supportsFk = pickup_table_supports_foreign_keys('pickup_customer_reports');

    if ($supportsFk && pickup_table_supports_foreign_keys('pickup_customers') && !pickup_foreign_key_exists('pickup_customer_reports', 'pickup_customer_reports_customer_fk')) {
        try {
            $pdo->exec('ALTER TABLE pickup_customer_reports ADD CONSTRAINT pickup_customer_reports_customer_fk FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE ON UPDATE CASCADE');
        } catch (Throwable $exception) {
            error_log('Impossibile aggiungere FK pickup_customer_reports_customer_fk: ' . $exception->getMessage());
        }
    }

    if ($supportsFk && pickup_table_supports_foreign_keys('pickup_packages') && !pickup_foreign_key_exists('pickup_customer_reports', 'pickup_customer_reports_pickup_fk')) {
        try {
            $pdo->exec('ALTER TABLE pickup_customer_reports ADD CONSTRAINT pickup_customer_reports_pickup_fk FOREIGN KEY (pickup_id) REFERENCES pickup_packages(id) ON DELETE SET NULL ON UPDATE CASCADE');
        } catch (Throwable $exception) {
            error_log('Impossibile aggiungere FK pickup_customer_reports_pickup_fk: ' . $exception->getMessage());
        }
    }
}

function create_pickup_customer_notifications_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    type TEXT NOT NULL,
    title TEXT NOT NULL,
    message TEXT NOT NULL,
    tracking_code TEXT NULL,
    read_at DATETIME NULL,
    sent_via_email INTEGER NOT NULL DEFAULT 0,
    sent_via_sms INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CHECK (type IN ('package_arrived','package_ready','package_reminder','package_expired','system_message')),
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_notifications_customer_id ON pickup_customer_notifications (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_notifications_type ON pickup_customer_notifications (type)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_notifications_read_at ON pickup_customer_notifications (read_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_notifications_created_at ON pickup_customer_notifications (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_notifications_tracking_code ON pickup_customer_notifications (tracking_code)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    type ENUM('package_arrived', 'package_ready', 'package_reminder', 'package_expired', 'system_message') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    tracking_code VARCHAR(100) NULL,
    read_at DATETIME NULL,
    sent_via_email TINYINT(1) DEFAULT 0,
    sent_via_sms TINYINT(1) DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pickup_customer_notifications_customer_id (customer_id),
    INDEX idx_pickup_customer_notifications_type (type),
    INDEX idx_pickup_customer_notifications_read_at (read_at),
    INDEX idx_pickup_customer_notifications_created_at (created_at),
    INDEX idx_pickup_customer_notifications_tracking_code (tracking_code),
    CONSTRAINT pickup_customer_notifications_customer_fk FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_api_rate_limits_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_api_rate_limits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    identifier TEXT NOT NULL,
    endpoint TEXT NOT NULL,
    request_count INTEGER NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_api_rate_limits_identifier ON pickup_api_rate_limits (identifier)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_api_rate_limits_endpoint ON pickup_api_rate_limits (endpoint)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_api_rate_limits_window_start ON pickup_api_rate_limits (window_start)');
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_api_rate_limits ON pickup_api_rate_limits (identifier, endpoint, window_start)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_api_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    request_count INT NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pickup_api_rate_limits (identifier, endpoint, window_start),
    INDEX idx_pickup_api_rate_limits_identifier (identifier),
    INDEX idx_pickup_api_rate_limits_endpoint (endpoint),
    INDEX idx_pickup_api_rate_limits_window_start (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_customer_activity_logs_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_activity_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NULL,
    action TEXT NOT NULL,
    resource_type TEXT NULL,
    resource_id INTEGER NULL,
    ip_address TEXT NOT NULL,
    user_agent TEXT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE SET NULL
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_activity_logs_customer_id ON pickup_customer_activity_logs (customer_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_activity_logs_action ON pickup_customer_activity_logs (action)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_activity_logs_resource ON pickup_customer_activity_logs (resource_type, resource_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_activity_logs_created_at ON pickup_customer_activity_logs (created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pickup_customer_activity_logs_ip ON pickup_customer_activity_logs (ip_address)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_activity_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50) NULL,
    resource_id INT UNSIGNED NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pickup_customer_activity_logs_customer_id (customer_id),
    INDEX idx_pickup_customer_activity_logs_action (action),
    INDEX idx_pickup_customer_activity_logs_resource (resource_type, resource_id),
    INDEX idx_pickup_customer_activity_logs_created_at (created_at),
    INDEX idx_pickup_customer_activity_logs_ip (ip_address),
    CONSTRAINT pickup_customer_activity_logs_customer_fk FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function create_pickup_customer_preferences_table(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NOT NULL,
    notification_email INTEGER NOT NULL DEFAULT 1,
    notification_sms INTEGER NOT NULL DEFAULT 0,
    notification_whatsapp INTEGER NOT NULL DEFAULT 0,
    language TEXT NOT NULL DEFAULT 'it',
    timezone TEXT NOT NULL DEFAULT 'Europe/Rome',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
);
SQL;
        $pdo->exec($sql);
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_pickup_customer_preferences_customer_id ON pickup_customer_preferences (customer_id)');
        return;
    }

    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS pickup_customer_preferences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    notification_email TINYINT(1) DEFAULT 1,
    notification_sms TINYINT(1) DEFAULT 0,
    notification_whatsapp TINYINT(1) DEFAULT 0,
    language VARCHAR(5) DEFAULT 'it',
    timezone VARCHAR(50) DEFAULT 'Europe/Rome',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pickup_customer_preferences_customer_id (customer_id),
    CONSTRAINT pickup_customer_preferences_customer_fk FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $pdo->exec($sql);
}

function ensure_pickup_customer_preferences_seed(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec('INSERT OR IGNORE INTO pickup_customer_preferences (customer_id, created_at, updated_at)
            SELECT id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM pickup_customers');
        return;
    }

    $pdo->exec('INSERT IGNORE INTO pickup_customer_preferences (customer_id, created_at, updated_at)
        SELECT id, NOW(), NOW() FROM pickup_customers');
}

function ensure_pickup_customer_summary_view(): void
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $pdo->exec('CREATE VIEW IF NOT EXISTS pickup_customer_summary AS SELECT
            c.id,
            c.email,
            c.phone,
            c.name,
            c.status,
            c.email_verified,
            c.phone_verified,
            c.last_login,
            c.created_at,
            p.notification_email,
            p.notification_sms,
            p.notification_whatsapp,
            p.language,
            COUNT(DISTINCT r.id) AS total_reports,
            COUNT(DISTINCT CASE WHEN r.status = "reported" THEN r.id END) AS pending_reports,
            COUNT(DISTINCT n.id) AS total_notifications,
            COUNT(DISTINCT CASE WHEN n.read_at IS NULL THEN n.id END) AS unread_notifications
        FROM pickup_customers c
        LEFT JOIN pickup_customer_preferences p ON c.id = p.customer_id
        LEFT JOIN pickup_customer_reports r ON c.id = r.customer_id
        LEFT JOIN pickup_customer_notifications n ON c.id = n.customer_id
        GROUP BY c.id, c.email, c.phone, c.name, c.status, c.email_verified, c.phone_verified, c.last_login, c.created_at, p.notification_email, p.notification_sms, p.notification_whatsapp, p.language');
        return;
    }

    $pdo->exec('CREATE OR REPLACE VIEW pickup_customer_summary AS
        SELECT 
            c.id,
            c.email,
            c.phone,
            c.name,
            c.status,
            c.email_verified,
            c.phone_verified,
            c.last_login,
            c.created_at,
            p.notification_email,
            p.notification_sms,
            p.notification_whatsapp,
            p.language,
            COUNT(DISTINCT r.id) AS total_reports,
            COUNT(DISTINCT CASE WHEN r.status = "reported" THEN r.id END) AS pending_reports,
            COUNT(DISTINCT n.id) AS total_notifications,
            COUNT(DISTINCT CASE WHEN n.read_at IS NULL THEN n.id END) AS unread_notifications
        FROM pickup_customers c
        LEFT JOIN pickup_customer_preferences p ON c.id = p.customer_id
        LEFT JOIN pickup_customer_reports r ON c.id = r.customer_id
        LEFT JOIN pickup_customer_notifications n ON c.id = n.customer_id
        GROUP BY c.id, c.email, c.phone, c.name, c.status, c.email_verified, c.phone_verified, c.last_login, c.created_at, p.notification_email,
                 p.notification_sms, p.notification_whatsapp, p.language');
}

function ensure_default_pickup_location(): void
{
    $pdo = pickup_db();
    $defaultName = 'AG SERVIZI VIA PLINIO 72';
    $defaultAddress = 'VIA PLINIO IL VECCHIO 72 CASTELLAMMARE DI STABIA (NA) 80053';

    $stmt = $pdo->prepare('SELECT id, address FROM pickup_locations WHERE name = :name LIMIT 1');
    $stmt->execute([':name' => $defaultName]);
    $existing = $stmt->fetch();

    if ($existing) {
        if (empty($existing['address']) || strcasecmp((string) $existing['address'], $defaultAddress) !== 0) {
            $update = $pdo->prepare('UPDATE pickup_locations SET address = :address WHERE id = :id');
            $update->execute([
                ':address' => $defaultAddress,
                ':id' => (int) $existing['id'],
            ]);
        }
        return;
    }

    $insert = $pdo->prepare('INSERT INTO pickup_locations (name, address) VALUES (:name, :address)');
    $insert->execute([
        ':name' => $defaultName,
        ':address' => $defaultAddress,
    ]);
}

function ensure_default_couriers(): void
{
    $pdo = pickup_db();
    $defaults = [
        ['name' => 'Bartolini (BRT)', 'support_email' => null, 'support_phone' => null],
        ['name' => 'DHL Express', 'support_email' => null, 'support_phone' => null],
        ['name' => 'GLS Italy', 'support_email' => null, 'support_phone' => null],
        ['name' => 'SDA Express Courier', 'support_email' => null, 'support_phone' => null],
        ['name' => 'Poste Italiane', 'support_email' => null, 'support_phone' => null],
        ['name' => 'UPS', 'support_email' => null, 'support_phone' => null],
        ['name' => 'FedEx', 'support_email' => null, 'support_phone' => null],
        ['name' => 'TNT', 'support_email' => null, 'support_phone' => null],
        ['name' => 'Nexive', 'support_email' => null, 'support_phone' => null],
        ['name' => 'Amazon Logistics', 'support_email' => null, 'support_phone' => null],
    ];

    $select = $pdo->prepare('SELECT id FROM pickup_couriers WHERE name = :name LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO pickup_couriers (name, support_email, support_phone) VALUES (:name, :support_email, :support_phone)');

    foreach ($defaults as $courier) {
        $select->execute([':name' => $courier['name']]);
        if ($select->fetchColumn()) {
            continue;
        }

        $insert->execute([
            ':name' => $courier['name'],
            ':support_email' => $courier['support_email'],
            ':support_phone' => $courier['support_phone'],
        ]);
    }
}

function pickup_foreign_key_exists(string $table, string $constraint): bool
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        return true;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = :table AND CONSTRAINT_NAME = :constraint');
    $stmt->execute([
        ':table' => $table,
        ':constraint' => $constraint,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function pickup_table_supports_foreign_keys(string $table): bool
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        return true;
    }

    $stmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1');
    $stmt->execute([':table' => $table]);
    $engine = $stmt->fetchColumn();

    if ($engine === false) {
        return false;
    }

    return strtoupper((string) $engine) === 'INNODB';
}

function pickup_column_exists(string $table, string $column): bool
{
    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new InvalidArgumentException('Nome tabella non valido.');
        }

        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return false;
        }

        while ($info = $stmt->fetch()) {
            if (strcasecmp((string) $info['name'], $column) === 0) {
                return true;
            }
        }

        return false;
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column');
    $stmt->execute([
        ':table' => $table,
        ':column' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}

function pickup_add_column_if_missing(string $table, string $column, string $definition): void
{
    if (pickup_column_exists($table, $column)) {
        return;
    }

    $pdo = pickup_db();
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));

    if ($driver === 'sqlite') {
        $cleanDefinition = preg_replace('/\s+AFTER\s+.+$/i', '', $definition) ?: $definition;
        $pdo->exec(sprintf('ALTER TABLE "%s" ADD COLUMN "%s" %s', $table, $column, $cleanDefinition));
        return;
    }

    $pdo->exec(sprintf('ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $column, $definition));
}

function add_package(array $data): int
{
    $pdo = pickup_db();

    $tracking = clean_input($data['tracking'] ?? '', 100);
    $customerName = clean_input($data['customer_name'] ?? '', 150);
    $customerPhone = clean_input($data['customer_phone'] ?? '', 50);
    $customerEmail = clean_input($data['customer_email'] ?? '', 160);
    $status = clean_input($data['status'] ?? 'in_arrivo', 20);
    $courierId = isset($data['courier_id']) ? (int) $data['courier_id'] : null;
    $pickupLocationId = isset($data['pickup_location_id']) ? (int) $data['pickup_location_id'] : null;
    $expectedAt = clean_input($data['expected_at'] ?? '', 32);
    $notes = clean_input($data['notes'] ?? '', 500);

    if ($tracking === '' || $customerName === '' || $customerPhone === '') {
        throw new InvalidArgumentException('Tracking, nome cliente e telefono sono obbligatori.');
    }

    if (!in_array($status, pickup_statuses(), true)) {
        throw new InvalidArgumentException('Stato pacco non valido.');
    }

    if ($courierId !== null && $courierId > 0 && !courier_exists($courierId)) {
        throw new InvalidArgumentException('Corriere selezionato non valido.');
    }

    if ($pickupLocationId !== null && $pickupLocationId > 0 && !pickup_location_exists($pickupLocationId)) {
        throw new InvalidArgumentException('Punto ritiro selezionato non valido.');
    }

    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Email cliente non valida.');
    }

    $stmt = $pdo->prepare('SELECT id FROM pickup_packages WHERE tracking = :tracking LIMIT 1');
    $stmt->execute([':tracking' => $tracking]);
    if ($stmt->fetch()) {
        throw new RuntimeException('Tracking già registrato.');
    }

    $expectedDate = null;
    if ($expectedAt !== '') {
        $expectedDate = \DateTime::createFromFormat('Y-m-d', $expectedAt) ?: \DateTime::createFromFormat('Y-m-d H:i', $expectedAt);
        if (!$expectedDate) {
            throw new InvalidArgumentException('Data prevista non valida.');
        }
        $expectedDate = $expectedDate->format('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare('INSERT INTO pickup_packages (tracking, customer_name, customer_phone, customer_email, courier_id, pickup_location_id, status, expected_at, notes) VALUES (:tracking, :customer_name, :customer_phone, :customer_email, :courier_id, :pickup_location_id, :status, :expected_at, :notes)');
    $stmt->execute([
        ':tracking' => $tracking,
        ':customer_name' => $customerName,
        ':customer_phone' => $customerPhone,
        ':customer_email' => $customerEmail !== '' ? $customerEmail : null,
        ':courier_id' => $courierId ?: null,
        ':pickup_location_id' => $pickupLocationId ?: null,
        ':status' => $status,
        ':expected_at' => $expectedDate,
        ':notes' => $notes !== '' ? $notes : null,
    ]);

    $packageId = (int) $pdo->lastInsertId();

    track_package_history($packageId, 'created', null, $status, [
        'tracking' => $tracking,
        'courier_id' => $courierId,
        'pickup_location_id' => $pickupLocationId,
    ]);

    try {
        generate_package_qr($packageId);
    } catch (Throwable $exception) {
        error_log('Generazione QR fallita per pacco ' . $packageId . ': ' . $exception->getMessage());
    }

    if ($status === 'in_arrivo') {
        try {
            $otp = generate_pickup_otp($packageId);
            notify_customer_event($packageId, 'arrived', [
                'otp' => $otp['code'],
                'expires_at' => $otp['expires_at'],
            ]);
        } catch (Throwable $exception) {
            error_log('Invio OTP fallito per pacco ' . $packageId . ': ' . $exception->getMessage());
        }
    }

    return $packageId;
}

function update_package_status(int $packageId, string $status, array $options = []): array
{
    $status = clean_input($status, 20);
    if (!in_array($status, pickup_statuses(), true)) {
        throw new InvalidArgumentException('Stato pacco non valido.');
    }

    $autoNotify = $options['auto_notify'] ?? true;
    $generateOtp = $options['generate_otp'] ?? true;

    $existing = get_package_details($packageId);
    if (!$existing) {
        throw new RuntimeException('Pacco non trovato.');
    }

    if (($existing['status'] ?? null) === $status) {
        return $existing;
    }

    $pdo = pickup_db();
    $now = pickup_current_timestamp();
    $stmt = $pdo->prepare('UPDATE pickup_packages SET status = :status, updated_at = :now WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':id' => $packageId,
        ':now' => $now,
    ]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Pacco non trovato o stato invariato.');
    }

    $details = get_package_details($packageId);
    if (!$details) {
        throw new RuntimeException('Impossibile recuperare i dettagli del pacco aggiornato.');
    }

    track_package_history($packageId, 'status_change', $existing['status'] ?? null, $details['status'] ?? null, [
        'previous_status' => $existing['status'] ?? null,
        'new_status' => $details['status'] ?? null,
    ]);

    if ($autoNotify) {
        try {
            if ($status === 'in_arrivo') {
                $otpData = $generateOtp ? generate_pickup_otp($packageId) : null;
                notify_customer_event($packageId, 'arrived', [
                    'otp' => $otpData['code'] ?? '',
                    'expires_at' => $otpData['expires_at'] ?? null,
                ]);
            } elseif ($status === 'ritirato') {
                notify_customer_event($packageId, 'picked_up');
            } elseif ($status === 'in_giacenza_scaduto') {
                notify_customer_event($packageId, 'storage_expired');
            }
        } catch (Throwable $exception) {
            error_log('Notifica automatica stato pickup fallita per pacco ' . $packageId . ': ' . $exception->getMessage());
        }
    }

    return $details;
}

function update_package(int $packageId, array $data): bool
{
    $pdo = pickup_db();
    $existing = get_package_details($packageId);
    if (!$existing) {
        throw new RuntimeException('Pacco non trovato.');
    }

    $tracking = clean_input($data['tracking'] ?? '', 100);
    $customerName = clean_input($data['customer_name'] ?? '', 150);
    $customerPhone = clean_input($data['customer_phone'] ?? '', 50);
    $customerEmail = clean_input($data['customer_email'] ?? '', 160);
    $status = clean_input($data['status'] ?? $existing['status'], 20);
    $courierId = isset($data['courier_id']) ? (int) $data['courier_id'] : null;
    $pickupLocationId = null;
    if (array_key_exists('pickup_location_id', $data)) {
        $rawLocation = $data['pickup_location_id'];
        $pickupLocationId = ($rawLocation === '' || $rawLocation === null) ? null : (int) $rawLocation;
    } elseif (isset($existing['pickup_location_id'])) {
        $pickupLocationId = (int) $existing['pickup_location_id'];
        if ($pickupLocationId <= 0) {
            $pickupLocationId = null;
        }
    }
    $expectedAt = clean_input($data['expected_at'] ?? '', 32);
    $notes = clean_input($data['notes'] ?? '', 500);

    if ($tracking === '' || $customerName === '' || $customerPhone === '') {
        throw new InvalidArgumentException('Tracking, nome cliente e telefono sono obbligatori.');
    }

    if (!in_array($status, pickup_statuses(), true)) {
        throw new InvalidArgumentException('Stato pacco non valido.');
    }

    if ($courierId !== null && $courierId > 0 && !courier_exists($courierId)) {
        throw new InvalidArgumentException('Corriere selezionato non valido.');
    }

    if ($pickupLocationId !== null && $pickupLocationId > 0 && !pickup_location_exists($pickupLocationId)) {
        throw new InvalidArgumentException('Punto ritiro selezionato non valido.');
    }

    if ($customerEmail !== '' && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Email cliente non valida.');
    }

    if (strcasecmp($tracking, (string) $existing['tracking']) !== 0) {
        $stmt = $pdo->prepare('SELECT id FROM pickup_packages WHERE tracking = :tracking AND id <> :id LIMIT 1');
        $stmt->execute([
            ':tracking' => $tracking,
            ':id' => $packageId,
        ]);
        if ($stmt->fetch()) {
            throw new RuntimeException('Tracking già registrato su un altro pacco.');
        }
    }

    $expectedDate = null;
    if ($expectedAt !== '') {
        $expectedDate = \DateTime::createFromFormat('Y-m-d', $expectedAt) ?: \DateTime::createFromFormat('Y-m-d H:i', $expectedAt);
        if (!$expectedDate) {
            throw new InvalidArgumentException('Data prevista non valida.');
        }
        $expectedDate = $expectedDate->format('Y-m-d H:i:s');
    }

    $stmt = $pdo->prepare('UPDATE pickup_packages SET tracking = :tracking, customer_name = :customer_name, customer_phone = :customer_phone, customer_email = :customer_email, courier_id = :courier_id, pickup_location_id = :pickup_location_id, status = :status, expected_at = :expected_at, notes = :notes, updated_at = :now WHERE id = :id');
    $stmt->execute([
        ':tracking' => $tracking,
        ':customer_name' => $customerName,
        ':customer_phone' => $customerPhone,
        ':customer_email' => $customerEmail !== '' ? $customerEmail : null,
        ':courier_id' => $courierId ?: null,
        ':pickup_location_id' => $pickupLocationId ?: null,
        ':status' => $status,
        ':expected_at' => $expectedDate,
    ':notes' => $notes !== '' ? $notes : null,
    ':now' => pickup_current_timestamp(),
        ':id' => $packageId,
    ]);

    $changes = [];
    if (strcasecmp((string) $existing['tracking'], $tracking) !== 0) {
        $changes['tracking'] = [$existing['tracking'], $tracking];
    }
    if (strcasecmp((string) $existing['customer_name'], $customerName) !== 0) {
        $changes['customer_name'] = [$existing['customer_name'], $customerName];
    }
    if (strcasecmp((string) $existing['customer_phone'], $customerPhone) !== 0) {
        $changes['customer_phone'] = [$existing['customer_phone'], $customerPhone];
    }
    if ((string) ($existing['customer_email'] ?? '') !== $customerEmail) {
        $changes['customer_email'] = [$existing['customer_email'] ?? '', $customerEmail];
    }
    if ((int) ($existing['courier_id'] ?? 0) !== (int) ($courierId ?: 0)) {
        $changes['courier_id'] = [$existing['courier_id'] ?? null, $courierId];
    }
    if ((int) ($existing['pickup_location_id'] ?? 0) !== (int) ($pickupLocationId ?: 0)) {
        $changes['pickup_location_id'] = [$existing['pickup_location_id'] ?? null, $pickupLocationId];
    }
    if ((string) ($existing['expected_at'] ?? '') !== ($expectedDate ?? '')) {
        $changes['expected_at'] = [$existing['expected_at'] ?? '', $expectedDate];
    }
    if ((string) ($existing['notes'] ?? '') !== $notes) {
        $changes['notes'] = [$existing['notes'] ?? '', $notes];
    }

    if ($changes || (string) $existing['status'] !== $status) {
        track_package_history($packageId, 'updated', $existing['status'], $status, [
            'changes' => $changes,
        ]);
    }

    return $stmt->rowCount() > 0;
}

function delete_package(int $packageId): bool
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('DELETE FROM pickup_packages WHERE id = :id');
    $stmt->execute([':id' => $packageId]);
    return $stmt->rowCount() > 0;
}

function filter_packages(array $params = [], array $options = []): array
{
    $pdo = pickup_db();

    $conditions = ['1 = 1'];
    $bindings = [];

    $archived = array_key_exists('archived', $params) ? (bool) $params['archived'] : false;
    $conditions[] = $archived ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL';

    if (!empty($params['ids']) && is_array($params['ids'])) {
        $ids = array_filter(array_map('intval', $params['ids']), static fn($id) => $id > 0);
        if ($ids) {
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));
            $conditions[] = 'p.id IN (' . $placeholders . ')';
            $bindings = array_merge($bindings, $ids);
        }
    }

    if (!empty($params['status'])) {
        $statuses = is_array($params['status']) ? $params['status'] : [$params['status']];
        $statuses = array_values(array_intersect($statuses, pickup_statuses()));
        if ($statuses) {
            $placeholders = implode(', ', array_fill(0, count($statuses), '?'));
            $conditions[] = 'p.status IN (' . $placeholders . ')';
            $bindings = array_merge($bindings, $statuses);
        }
    }

    if (!empty($params['status_not'])) {
        $statusNot = is_array($params['status_not']) ? $params['status_not'] : [$params['status_not']];
        $statusNot = array_values(array_intersect($statusNot, pickup_statuses()));
        if ($statusNot) {
            foreach ($statusNot as $status) {
                $conditions[] = 'p.status <> ?';
                $bindings[] = $status;
            }
        }
    }

    if (isset($params['courier_id'])) {
        if (is_array($params['courier_id'])) {
            $couriers = array_filter(array_map('intval', $params['courier_id']), static fn($id) => $id > 0);
            if ($couriers) {
                $placeholders = implode(', ', array_fill(0, count($couriers), '?'));
                $conditions[] = 'p.courier_id IN (' . $placeholders . ')';
                $bindings = array_merge($bindings, $couriers);
            }
        } elseif ((int) $params['courier_id'] > 0) {
            $conditions[] = 'p.courier_id = ?';
            $bindings[] = (int) $params['courier_id'];
        }
    }

    if (isset($params['pickup_location_id'])) {
        if (is_array($params['pickup_location_id'])) {
            $locations = array_filter(array_map('intval', $params['pickup_location_id']), static fn($id) => $id > 0);
            if ($locations) {
                $placeholders = implode(', ', array_fill(0, count($locations), '?'));
                $conditions[] = 'p.pickup_location_id IN (' . $placeholders . ')';
                $bindings = array_merge($bindings, $locations);
            }
        } elseif ((int) $params['pickup_location_id'] > 0) {
            $conditions[] = 'p.pickup_location_id = ?';
            $bindings[] = (int) $params['pickup_location_id'];
        }
    }

    if (!empty($params['tracking'])) {
        $conditions[] = 'p.tracking = ?';
        $bindings[] = clean_input($params['tracking'], 100);
    }

    if (!empty($params['tracking_like'])) {
        $conditions[] = 'p.tracking LIKE ?';
        $bindings[] = '%' . clean_input($params['tracking_like'], 100) . '%';
    }

    if (!empty($params['customer_name'])) {
        $conditions[] = 'p.customer_name LIKE ?';
        $bindings[] = '%' . clean_input($params['customer_name'], 150) . '%';
    }

    if (!empty($params['customer_phone'])) {
        $conditions[] = 'p.customer_phone LIKE ?';
        $bindings[] = '%' . clean_input($params['customer_phone'], 50) . '%';
    }

    if (!empty($params['customer_email'])) {
        $conditions[] = 'p.customer_email LIKE ?';
        $bindings[] = '%' . clean_input($params['customer_email'], 160) . '%';
    }

    if (!empty($params['search'])) {
        $search = '%' . clean_input($params['search'], 120) . '%';
        $conditions[] = '(p.tracking LIKE ? OR p.customer_name LIKE ? OR p.customer_phone LIKE ? OR p.customer_email LIKE ?)';
        $bindings[] = $search;
        $bindings[] = $search;
        $bindings[] = $search;
        $bindings[] = $search;
    }

    if (!empty($params['created_from'])) {
        $conditions[] = 'p.created_at >= ?';
        $bindings[] = $params['created_from'] . ' 00:00:00';
    }

    if (!empty($params['created_to'])) {
        $conditions[] = 'p.created_at <= ?';
        $bindings[] = $params['created_to'] . ' 23:59:59';
    }

    if (!empty($params['expected_from'])) {
        $conditions[] = 'p.expected_at IS NOT NULL AND p.expected_at >= ?';
        $bindings[] = $params['expected_from'] . ' 00:00:00';
    }

    if (!empty($params['expected_to'])) {
        $conditions[] = 'p.expected_at IS NOT NULL AND p.expected_at <= ?';
        $bindings[] = $params['expected_to'] . ' 23:59:59';
    }

    if (!empty($params['updated_from'])) {
        $conditions[] = 'p.updated_at >= ?';
        $bindings[] = $params['updated_from'] . ' 00:00:00';
    }

    if (!empty($params['updated_to'])) {
        $conditions[] = 'p.updated_at <= ?';
        $bindings[] = $params['updated_to'] . ' 23:59:59';
    }

    if (!empty($params['archived_from'])) {
        $conditions[] = 'p.archived_at IS NOT NULL AND p.archived_at >= ?';
        $bindings[] = $params['archived_from'] . ' 00:00:00';
    }

    if (!empty($params['archived_to'])) {
        $conditions[] = 'p.archived_at IS NOT NULL AND p.archived_at <= ?';
        $bindings[] = $params['archived_to'] . ' 23:59:59';
    }

    if (!empty($params['has_signature'])) {
        $conditions[] = $params['has_signature'] ? 'p.signature_path IS NOT NULL' : 'p.signature_path IS NULL';
    }

    if (!empty($params['has_photo'])) {
        $conditions[] = $params['has_photo'] ? 'p.photo_path IS NOT NULL' : 'p.photo_path IS NULL';
    }

    if (!empty($params['event_since'])) {
        $conditions[] = 'p.updated_at >= ?';
        $bindings[] = $params['event_since'];
    }

    $allowedOrderColumns = [
        'created_at' => 'p.created_at',
        'updated_at' => 'p.updated_at',
        'expected_at' => 'p.expected_at',
        'status' => 'p.status',
        'customer_name' => 'p.customer_name',
    ];

    $orderKey = $options['order_by'] ?? 'created_at';
    $orderColumn = $allowedOrderColumns[$orderKey] ?? 'p.created_at';
    $orderDirection = strtoupper($options['order_dir'] ?? 'DESC');
    if (!in_array($orderDirection, ['ASC', 'DESC'], true)) {
        $orderDirection = 'DESC';
    }

    $limit = isset($options['limit']) ? (int) $options['limit'] : 0;
    $offset = isset($options['offset']) ? max(0, (int) $options['offset']) : 0;

    $sql = sprintf(
        'SELECT p.*, c.name AS courier_name, c.logo_url, c.support_email, c.support_phone,
                l.name AS location_name, l.address AS location_address
         FROM pickup_packages p
         LEFT JOIN pickup_couriers c ON c.id = p.courier_id
         LEFT JOIN pickup_locations l ON l.id = p.pickup_location_id
         WHERE %s
         ORDER BY %s %s',
        implode(' AND ', $conditions),
        $orderColumn,
        $orderDirection
    );

    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;
        if ($offset > 0) {
            $sql .= ' OFFSET ' . $offset;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bindings);

    return $stmt->fetchAll();
}

function get_all_packages(array $filters = []): array
{
    return filter_packages($filters);
}

function get_packages_by_status(string $status): array
{
    return filter_packages(['status' => $status]);
}

function get_packages_by_location(int $locationId, array $params = [], array $options = []): array
{
    $params['pickup_location_id'] = $locationId;
    return filter_packages($params, $options);
}

function search_packages(string $query, int $limit = 25): array
{
    $pdo = pickup_db();
    $query = clean_input($query, 120);
    if ($query === '') {
        return [];
    }

    $stmt = $pdo->prepare('SELECT p.*, c.name AS courier_name FROM pickup_packages p LEFT JOIN pickup_couriers c ON c.id = p.courier_id WHERE p.archived_at IS NULL AND (p.tracking LIKE :query OR p.customer_name LIKE :query) ORDER BY p.created_at DESC LIMIT :limit');
    $stmt->bindValue(':query', '%' . $query . '%', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_package_details(int $packageId): ?array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT p.*, c.name AS courier_name, c.support_email, c.support_phone,
        l.name AS location_name, l.address AS location_address, l.contact_phone AS location_phone, l.contact_email AS location_email
        FROM pickup_packages p
        LEFT JOIN pickup_couriers c ON c.id = p.courier_id
        LEFT JOIN pickup_locations l ON l.id = p.pickup_location_id
        WHERE p.id = :id');
    $stmt->execute([':id' => $packageId]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function courier_exists(int $courierId): bool
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT 1 FROM pickup_couriers WHERE id = :id');
    $stmt->execute([':id' => $courierId]);
    return (bool) $stmt->fetchColumn();
}

function add_courier(array $data): int
{
    $pdo = pickup_db();
    $name = clean_input($data['name'] ?? '', 120);
    if ($name === '') {
        throw new InvalidArgumentException('Il nome del corriere è obbligatorio.');
    }

    $logoUrl = clean_input($data['logo_url'] ?? '', 255);
    $contactName = clean_input($data['contact_name'] ?? '', 120);
    $supportEmail = clean_input($data['support_email'] ?? '', 160);
    $supportPhone = clean_input($data['support_phone'] ?? '', 40);

    if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email assistenza non valida.');
    }

    $stmt = $pdo->prepare('INSERT INTO pickup_couriers (name, logo_url, contact_name, support_email, support_phone) VALUES (:name, :logo_url, :contact_name, :support_email, :support_phone)');
    $stmt->execute([
        ':name' => $name,
        ':logo_url' => $logoUrl !== '' ? $logoUrl : null,
        ':contact_name' => $contactName !== '' ? $contactName : null,
        ':support_email' => $supportEmail !== '' ? $supportEmail : null,
        ':support_phone' => $supportPhone !== '' ? $supportPhone : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function update_courier(int $courierId, array $data): bool
{
    if (!courier_exists($courierId)) {
        throw new InvalidArgumentException('Corriere non trovato.');
    }

    $pdo = pickup_db();
    $name = clean_input($data['name'] ?? '', 120);
    if ($name === '') {
        throw new InvalidArgumentException('Il nome del corriere è obbligatorio.');
    }

    $logoUrl = clean_input($data['logo_url'] ?? '', 255);
    $contactName = clean_input($data['contact_name'] ?? '', 120);
    $supportEmail = clean_input($data['support_email'] ?? '', 160);
    $supportPhone = clean_input($data['support_phone'] ?? '', 40);

    if ($supportEmail !== '' && !filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email assistenza non valida.');
    }

    $stmt = $pdo->prepare('UPDATE pickup_couriers SET name = :name, logo_url = :logo_url, contact_name = :contact_name, support_email = :support_email, support_phone = :support_phone WHERE id = :id');
    $stmt->execute([
        ':name' => $name,
        ':logo_url' => $logoUrl !== '' ? $logoUrl : null,
        ':contact_name' => $contactName !== '' ? $contactName : null,
        ':support_email' => $supportEmail !== '' ? $supportEmail : null,
        ':support_phone' => $supportPhone !== '' ? $supportPhone : null,
        ':id' => $courierId,
    ]);

    return $stmt->rowCount() > 0;
}

function delete_courier(int $courierId): bool
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('UPDATE pickup_packages SET courier_id = NULL WHERE courier_id = :id');
    $stmt->execute([':id' => $courierId]);

    $stmt = $pdo->prepare('DELETE FROM pickup_couriers WHERE id = :id');
    $stmt->execute([':id' => $courierId]);
    return $stmt->rowCount() > 0;
}

function get_all_couriers(): array
{
    $pdo = pickup_db();
    $stmt = $pdo->query('SELECT * FROM pickup_couriers ORDER BY name ASC');
    return $stmt->fetchAll();
}

function get_pickup_locations(): array
{
    $pdo = pickup_db();
    $stmt = $pdo->query('SELECT * FROM pickup_locations ORDER BY name ASC');
    return $stmt->fetchAll();
}

function get_pickup_location(int $locationId): ?array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT * FROM pickup_locations WHERE id = :id');
    $stmt->execute([':id' => $locationId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function pickup_location_exists(int $locationId): bool
{
    if ($locationId <= 0) {
        return false;
    }

    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT 1 FROM pickup_locations WHERE id = :id');
    $stmt->execute([':id' => $locationId]);
    return (bool) $stmt->fetchColumn();
}

function log_notification(int $packageId, string $type, string $status, string $message = '', ?array $meta = null): int
{
    $pdo = pickup_db();
    $metaValue = null;
    if ($meta) {
        try {
            $metaValue = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            error_log('Serializzazione log notifiche fallita: ' . $exception->getMessage());
        }
    }

    $stmt = $pdo->prepare('INSERT INTO pickup_notifications (package_id, channel, status, message, meta) VALUES (:package_id, :channel, :status, :message, :meta)');
    $stmt->execute([
        ':package_id' => $packageId,
        ':channel' => clean_input($type, 20),
        ':status' => clean_input($status, 80),
        ':message' => $message,
        ':meta' => $metaValue,
    ]);

    return (int) $pdo->lastInsertId();
}

function track_package_history(int $packageId, string $eventType, ?string $previousStatus = null, ?string $newStatus = null, array $meta = []): int
{
    $pdo = pickup_db();

    $actorId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $metaJson = null;
    if ($meta) {
        try {
            $metaJson = json_encode($meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            error_log('Serializzazione storico pickup fallita: ' . $exception->getMessage());
        }
    }

    $stmt = $pdo->prepare('INSERT INTO pickup_package_history (package_id, event_type, previous_status, new_status, actor_id, meta) VALUES (:package_id, :event_type, :previous_status, :new_status, :actor_id, :meta)');
    $stmt->execute([
        ':package_id' => $packageId,
        ':event_type' => clean_input($eventType, 40),
        ':previous_status' => $previousStatus !== null ? clean_input($previousStatus, 20) : null,
        ':new_status' => $newStatus !== null ? clean_input($newStatus, 20) : null,
        ':actor_id' => $actorId,
        ':meta' => $metaJson,
    ]);

    return (int) $pdo->lastInsertId();
}

function get_package_history(int $packageId, int $limit = 50): array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT * FROM pickup_package_history WHERE package_id = :package_id ORDER BY created_at DESC LIMIT :limit');
    $stmt->bindValue(':package_id', $packageId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function notify_customer_event(int $packageId, string $eventType, array $context = []): array
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato per la notifica evento.');
    }

    if (empty($package['qr_code_path'])) {
        try {
            $qrPath = generate_package_qr($packageId);
            if ($qrPath) {
                $package['qr_code_path'] = $qrPath;
            }
        } catch (Throwable $exception) {
            error_log('Generazione QR in notifica fallita per pacco ' . $packageId . ': ' . $exception->getMessage());
        }
    }

    $templates = PICKUP_EVENT_TEMPLATES[$eventType] ?? null;
    if ($templates === null) {
        throw new InvalidArgumentException('Evento notifica non supportato: ' . $eventType);
    }

    $expiresAt = $context['expires_at'] ?? null;
    if ($expiresAt instanceof \DateTimeInterface) {
        $expiresAt = $expiresAt->format('Y-m-d H:i:s');
    }

    $data = array_merge([
        'customer_name' => $package['customer_name'] ?? 'Cliente',
        'tracking' => $package['tracking'] ?? '',
        'location_name' => $package['location_name'] ?? 'il punto ritiro',
        'location_address' => $package['location_address'] ?? '',
        'otp' => $context['otp'] ?? '',
        'expiration_date' => $expiresAt ? format_datetime_locale($expiresAt) : '',
        'expiration_time' => $expiresAt ? format_datetime_locale($expiresAt) : '',
        'days_in_storage' => $context['days_in_storage'] ?? null,
        'qr_url' => pickup_public_url($package['qr_code_path'] ?? ''),
    ], $context);

    if ($data['days_in_storage'] === null) {
        $data['days_in_storage'] = pickup_calculate_days_in_storage($package);
    }

    $channels = $context['channels'] ?? ['email', 'whatsapp'];
    $results = [];

    if (in_array('email', $channels, true) && !empty($package['customer_email'])) {
        $subject = pickup_render_template($templates['email_subject'], $data);
        $message = pickup_render_template($templates['email_body'], $data);
        $success = send_notification_email($package['customer_email'], $subject, $message, [
            'qr_url' => $data['qr_url'] ?? '',
        ]);
        $results['email'] = [
            'success' => $success,
            'recipient' => $package['customer_email'],
        ];
        log_notification($packageId, 'email', $success ? 'inviata' : 'errore', $message, [
            'recipient' => $package['customer_email'],
            'subject' => $subject,
            'event' => $eventType,
        ]);
    }

    if (in_array('whatsapp', $channels, true) && !empty($package['customer_phone'])) {
        $message = pickup_render_template($templates['whatsapp_body'], $data);
        $normalizedPhone = preg_replace('/[^0-9+]/', '', $package['customer_phone']);
        $sent = false;
        $fallback = null;

        $apiUrl = env('WHATSAPP_API_URL', '');
        $apiToken = env('WHATSAPP_API_TOKEN', '');
        if ($apiUrl !== '' && $apiToken !== '') {
            $sent = send_notification_whatsapp($normalizedPhone, $message);
        }

        if (!$sent) {
            $waNumber = ltrim($normalizedPhone, '+');
            $fallback = 'https://wa.me/' . rawurlencode($waNumber) . '?text=' . rawurlencode($message);
            $sent = true;
        }

        $results['whatsapp'] = [
            'success' => $sent,
            'recipient' => $normalizedPhone,
            'fallback_url' => $fallback,
        ];

        log_notification($packageId, 'whatsapp', $fallback ? 'manuale' : ($sent ? 'inviata' : 'errore'), $message, [
            'recipient' => $normalizedPhone,
            'event' => $eventType,
            'fallback_url' => $fallback,
        ]);
    }

    track_package_history($packageId, 'notify_' . $eventType, null, null, [
        'channels' => array_keys($results),
        'results' => $results,
    ]);

    return $results;
}

function get_active_pickup_otp(int $packageId): ?array
{
    $pdo = pickup_db();
    $now = pickup_current_timestamp();

    $stmt = $pdo->prepare('SELECT * FROM pickup_otps WHERE package_id = :package_id AND consumed_at IS NULL AND expires_at >= :now ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([
        ':package_id' => $packageId,
        ':now' => $now,
    ]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function generate_pickup_otp(int $packageId, array $options = []): array
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    $length = isset($options['length']) ? (int) $options['length'] : 6;
    $validMinutes = isset($options['valid_minutes']) ? (int) $options['valid_minutes'] : 1440;
    $maxAttempts = isset($options['max_attempts']) ? (int) $options['max_attempts'] : 5;
    $channel = isset($options['channel']) ? clean_input((string) $options['channel'], 20) : null;

    $length = $length > 0 ? $length : 6;
    $validMinutes = $validMinutes > 0 ? $validMinutes : 1440;
    $maxAttempts = $maxAttempts > 0 ? $maxAttempts : 5;

    $expiresAt = (new \DateTimeImmutable('now'))->modify('+' . $validMinutes . ' minutes');
    $code = pickup_random_numeric_code($length);
    $hash = password_hash($code, PASSWORD_DEFAULT);

    $pdo = pickup_db();
    $now = pickup_current_timestamp();
    $updateActiveOtp = $pdo->prepare('UPDATE pickup_otps SET expires_at = :now WHERE package_id = :package_id AND consumed_at IS NULL AND expires_at > :now');
    $updateActiveOtp->bindValue(':package_id', $packageId, PDO::PARAM_INT);
    $updateActiveOtp->bindValue(':now', $now, PDO::PARAM_STR);
    $updateActiveOtp->execute();

    $stmt = $pdo->prepare('INSERT INTO pickup_otps (package_id, code_hash, expires_at, max_attempts, channel, created_by) VALUES (:package_id, :code_hash, :expires_at, :max_attempts, :channel, :created_by)');
    $stmt->bindValue(':package_id', $packageId, PDO::PARAM_INT);
    $stmt->bindValue(':code_hash', $hash, PDO::PARAM_STR);
    $stmt->bindValue(':expires_at', $expiresAt->format('Y-m-d H:i:s'), PDO::PARAM_STR);
    $stmt->bindValue(':max_attempts', $maxAttempts, PDO::PARAM_INT);

    if ($channel === null || $channel === '') {
        $stmt->bindValue(':channel', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':channel', $channel, PDO::PARAM_STR);
    }

    $createdBy = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($createdBy === null) {
        $stmt->bindValue(':created_by', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':created_by', $createdBy, PDO::PARAM_INT);
    }
    $stmt->execute();

    $otpId = (int) $pdo->lastInsertId();

    track_package_history($packageId, 'otp_generated', $package['status'] ?? null, $package['status'] ?? null, [
        'otp_id' => $otpId,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        'channel' => $channel,
    ]);

    notify_customer_event($packageId, 'otp_generated', [
        'otp' => $code,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
    ]);

    return [
        'otp_id' => $otpId,
        'code' => $code,
        'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        'max_attempts' => $maxAttempts,
    ];
}

function confirm_pickup_with_otp(int $packageId, string $otp): array
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    $otpRow = get_active_pickup_otp($packageId);
    if (!$otpRow) {
        throw new RuntimeException('Non è presente un OTP valido per questo pacco.');
    }

    $attempts = (int) $otpRow['attempts'];
    $maxAttempts = (int) $otpRow['max_attempts'];
    if ($attempts >= $maxAttempts) {
        throw new RuntimeException('Numero massimo di tentativi OTP superato.');
    }

    $pdo = pickup_db();

    if (!password_verify($otp, $otpRow['code_hash'])) {
        $pdo->prepare('UPDATE pickup_otps SET attempts = attempts + 1 WHERE id = :id')->execute([':id' => $otpRow['id']]);
        throw new InvalidArgumentException('Codice OTP non valido o scaduto.');
    }

    $pdo->prepare('UPDATE pickup_otps SET consumed_at = :now, attempts = attempts + 1 WHERE id = :id')
        ->execute([
            ':id' => $otpRow['id'],
            ':now' => pickup_current_timestamp(),
        ]);

    $previousStatus = $package['status'] ?? null;
    $statusDetails = update_package_status($packageId, 'ritirato', [
        'auto_notify' => false,
    ]);

    track_package_history($packageId, 'otp_confirmed', $previousStatus, $statusDetails['status'] ?? 'ritirato', [
        'otp_id' => $otpRow['id'],
    ]);

    notify_customer_event($packageId, 'picked_up');

    return [
        'status' => $statusDetails,
        'otp_id' => $otpRow['id'],
    ];
}

function confirm_pickup_with_qr(int $packageId): array
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    $previousStatus = $package['status'] ?? null;
    $statusDetails = update_package_status($packageId, 'ritirato', [
        'auto_notify' => false,
    ]);

    track_package_history($packageId, 'qr_confirmed', $previousStatus, $statusDetails['status'] ?? 'ritirato', []);

    notify_customer_event($packageId, 'picked_up');

    return [
        'status' => $statusDetails,
    ];
}

function check_storage_expiration(int $graceDays = PICKUP_DEFAULT_STORAGE_GRACE_DAYS, array $options = []): array
{
    $graceDays = $graceDays > 0 ? $graceDays : PICKUP_DEFAULT_STORAGE_GRACE_DAYS;
    $warningDays = isset($options['warning_days']) ? max(0, (int) $options['warning_days']) : PICKUP_STORAGE_WARNING_BEFORE_DAYS;
    if ($warningDays > $graceDays) {
        $warningDays = $graceDays;
    }

    $now = new \DateTimeImmutable('now');
    $expirationThreshold = $now->modify('-' . $graceDays . ' days');
    $warningOffset = max(0, $graceDays - $warningDays);
    $warningThreshold = $warningOffset === 0 ? $expirationThreshold : $now->modify('-' . $warningOffset . ' days');

    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT * FROM pickup_packages WHERE status = :status AND archived_at IS NULL AND COALESCE(expected_at, updated_at, created_at) <= :threshold');
    $stmt->execute([
        ':status' => 'in_giacenza',
        ':threshold' => $warningThreshold->format('Y-m-d H:i:s'),
    ]);
    $packages = $stmt->fetchAll();

    $warnCheckStmt = $pdo->prepare('SELECT COUNT(*) FROM pickup_package_history WHERE package_id = :package_id AND event_type = :event_type');

    $processed = 0;
    $warned = 0;
    $expired = 0;

    foreach ($packages as $package) {
        $packageId = (int) $package['id'];
        $processed++;

        $referenceDate = pickup_storage_reference_datetime($package);
        if (!$referenceDate) {
            continue;
        }

        $expirationDate = $referenceDate->modify('+' . $graceDays . ' days');
        $warningDate = $warningDays > 0 ? $expirationDate->modify('-' . $warningDays . ' days') : $expirationDate;
        $daysInStorage = pickup_calculate_days_in_storage($package, $now);

        if ($now >= $expirationDate) {
            $statusDetails = update_package_status($packageId, 'in_giacenza_scaduto', [
                'auto_notify' => false,
                'generate_otp' => false,
            ]);
            $expired++;

            track_package_history($packageId, 'storage_expired', $package['status'], $statusDetails['status'] ?? 'in_giacenza_scaduto', [
                'days_in_storage' => $daysInStorage,
                'grace_days' => $graceDays,
                'expired_at' => $expirationDate->format('Y-m-d H:i:s'),
            ]);

            notify_customer_event($packageId, 'storage_expired', [
                'days_in_storage' => $daysInStorage,
                'expiration_date' => format_datetime_locale($expirationDate->format('Y-m-d H:i:s')),
            ]);
            continue;
        }

        if ($now < $warningDate) {
            continue;
        }

        $warnCheckStmt->execute([
            ':package_id' => $packageId,
            ':event_type' => 'notify_storage_warning',
        ]);
        $alreadyWarned = (int) $warnCheckStmt->fetchColumn() > 0;

        if ($alreadyWarned) {
            continue;
        }

        notify_customer_event($packageId, 'storage_warning', [
            'days_in_storage' => $daysInStorage,
            'expiration_date' => format_datetime_locale($expirationDate->format('Y-m-d H:i:s')),
        ]);
        $warned++;
    }

    return [
        'processed' => $processed,
        'warned' => $warned,
        'expired' => $expired,
        'warning_days' => $warningDays,
    ];
}

function save_digital_signature(int $packageId, string $signatureData): string
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    if (strpos($signatureData, 'base64,') !== false) {
        [, $signatureData] = explode('base64,', $signatureData, 2);
    }

    $binary = base64_decode($signatureData, true);
    if ($binary === false) {
        throw new InvalidArgumentException('Firma digitale non valida.');
    }

    pickup_ensure_directory(PICKUP_SIGNATURE_DIR);

    if (!empty($package['signature_path'])) {
        $existing = pickup_root_path() . '/' . ltrim($package['signature_path'], '/');
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    $fileName = 'signature_' . $packageId . '_' . time() . '.png';
    $fullPath = PICKUP_SIGNATURE_DIR . '/' . $fileName;

    if (file_put_contents($fullPath, $binary) === false) {
        throw new RuntimeException('Impossibile salvare la firma digitale.');
    }

    $relative = pickup_relative_path($fullPath);

    $pdo = pickup_db();
    $pdo->prepare('UPDATE pickup_packages SET signature_path = :path, signature_captured_at = :now WHERE id = :id')
        ->execute([
            ':path' => $relative,
            ':id' => $packageId,
            ':now' => pickup_current_timestamp(),
        ]);

    track_package_history($packageId, 'signature_saved', $package['status'], $package['status'], [
        'signature_path' => $relative,
    ]);

    return $relative;
}

function upload_package_photo(int $packageId, array $photo): string
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    if (!isset($photo['error'], $photo['tmp_name']) || (int) $photo['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Caricamento foto non riuscito.');
    }

    $tmpPath = $photo['tmp_name'];
    if (!is_uploaded_file($tmpPath)) {
        throw new RuntimeException('File foto non valido.');
    }

    $size = isset($photo['size']) ? (int) $photo['size'] : filesize($tmpPath);
    if ($size > 5 * 1024 * 1024) { // 5 MB
        throw new RuntimeException('La foto supera il limite di 5MB.');
    }

    $mime = mime_content_type($tmpPath) ?: '';
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Formato foto non supportato.');
    }

    pickup_ensure_directory(PICKUP_PHOTO_DIR);

    if (!empty($package['photo_path'])) {
        $existing = pickup_root_path() . '/' . ltrim($package['photo_path'], '/');
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    $fileName = 'photo_' . $packageId . '_' . time() . '.' . $allowed[$mime];
    $destPath = PICKUP_PHOTO_DIR . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $destPath)) {
        throw new RuntimeException('Impossibile salvare la foto del pacco.');
    }

    $relative = pickup_relative_path($destPath);

    $pdo = pickup_db();
    $pdo->prepare('UPDATE pickup_packages SET photo_path = :path, photo_captured_at = :now WHERE id = :id')
        ->execute([
            ':path' => $relative,
            ':id' => $packageId,
            ':now' => pickup_current_timestamp(),
        ]);

    track_package_history($packageId, 'photo_uploaded', $package['status'], $package['status'], [
        'photo_path' => $relative,
        'mime' => $mime,
    ]);

    return $relative;
}

function generate_package_qr(int $packageId, ?string $targetUrl = null): ?string
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    $baseUrl = $targetUrl ?? rtrim(env('APP_URL', ''), '/');
    if ($baseUrl === '') {
        $baseUrl = '/modules/servizi/logistici/view.php?id=' . $packageId;
    } elseif (!str_contains($baseUrl, (string) $packageId)) {
        $baseUrl .= '/modules/servizi/logistici/view.php?id=' . $packageId;
    }

    pickup_ensure_directory(PICKUP_QR_DIR);

    if (!empty($package['qr_code_path'])) {
        $existing = pickup_root_path() . '/' . ltrim($package['qr_code_path'], '/');
        if (is_file($existing)) {
            @unlink($existing);
        }
    }

    $fileName = 'qr_' . $packageId . '_' . time() . '.png';
    $destPath = PICKUP_QR_DIR . '/' . $fileName;

    $imageData = pickup_fetch_qr_image($baseUrl);
    if ($imageData === null) {
        error_log('Generazione QR fallita per il pacco ' . $packageId);
        return null;
    }

    if (file_put_contents($destPath, $imageData) === false) {
        throw new RuntimeException('Impossibile salvare il QR code.');
    }

    $relative = pickup_relative_path($destPath);

    $pdo = pickup_db();
    $pdo->prepare('UPDATE pickup_packages SET qr_code_path = :path WHERE id = :id')
        ->execute([
            ':path' => $relative,
            ':id' => $packageId,
        ]);

    track_package_history($packageId, 'qr_generated', $package['status'], $package['status'], [
        'qr_path' => $relative,
        'target' => $baseUrl,
    ]);

    return $relative;
}

function generate_package_receipt(int $packageId): string
{
    $package = get_package_details($packageId);
    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    require_once pickup_root_path() . '/lib/fpdf/fpdf.php';
    if (!class_exists('FPDF')) {
        throw new RuntimeException('Libreria FPDF non disponibile.');
    }

    pickup_ensure_directory(PICKUP_RECEIPT_DIR);

    $pdfClass = 'FPDF';
    /** @var object $pdf */
    $pdf = new $pdfClass();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    $pdf->Cell(0, 10, 'Ricevuta ritiro pacco', 0, 1, 'C');

    $pdf->SetFont('Arial', '', 12);
    $pdf->Ln(4);
    $pdf->Cell(0, 8, 'Tracking: ' . ($package['tracking'] ?? ''), 0, 1);
    $pdf->Cell(0, 8, 'Cliente: ' . ($package['customer_name'] ?? ''), 0, 1);
    $pdf->Cell(0, 8, 'Telefono: ' . ($package['customer_phone'] ?? ''), 0, 1);
    if (!empty($package['customer_email'])) {
        $pdf->Cell(0, 8, 'Email: ' . $package['customer_email'], 0, 1);
    }
    $pdf->Cell(0, 8, 'Corriere: ' . ($package['courier_name'] ?? 'N/D'), 0, 1);
    $pdf->Cell(0, 8, 'Stato: ' . pickup_status_label($package['status'] ?? ''), 0, 1);
    $pdf->Cell(0, 8, 'Punto ritiro: ' . ($package['location_name'] ?? 'N/D'), 0, 1);
    $pdf->Cell(0, 8, 'Data: ' . format_datetime_locale(date('Y-m-d H:i:s')), 0, 1);

    if (!empty($package['qr_code_path'])) {
        $qrPath = pickup_root_path() . '/' . ltrim($package['qr_code_path'], '/');
        if (is_file($qrPath)) {
            $pdf->Ln(4);
            $pdf->Image($qrPath, $pdf->GetX(), $pdf->GetY(), 30, 30);
        }
    }

    if (!empty($package['signature_path'])) {
        $signaturePath = pickup_root_path() . '/' . ltrim($package['signature_path'], '/');
        if (is_file($signaturePath)) {
            $pdf->Ln(36);
            $pdf->SetFont('Arial', 'I', 10);
            $pdf->Cell(0, 6, 'Firma digitale cliente:', 0, 1);
            $pdf->Image($signaturePath, $pdf->GetX(), $pdf->GetY(), 50, 20);
        }
    }

    $fileName = 'receipt_' . $packageId . '_' . time() . '.pdf';
    $destPath = PICKUP_RECEIPT_DIR . '/' . $fileName;
    $pdf->Output('F', $destPath);

    $relative = pickup_relative_path($destPath);

    track_package_history($packageId, 'receipt_generated', $package['status'], $package['status'], [
        'receipt_path' => $relative,
    ]);

    return $relative;
}

function generate_pickup_stats(string $range = 'today', array $options = []): array
{
    $range = strtolower($range);
    $locationId = isset($options['location_id']) ? (int) $options['location_id'] : null;

    $now = new \DateTimeImmutable('now');
    $start = $now->setTime(0, 0);
    $end = $now->setTime(23, 59, 59);

    if ($range === 'week') {
        $start = $now->modify('monday this week')->setTime(0, 0);
        $end = $now->modify('sunday this week')->setTime(23, 59, 59);
    } elseif ($range === 'month') {
        $start = $now->modify('first day of this month')->setTime(0, 0);
        $end = $now->modify('last day of this month')->setTime(23, 59, 59);
    } elseif ($range === 'custom' && !empty($options['from']) && !empty($options['to'])) {
        try {
            $start = new \DateTimeImmutable($options['from'] . ' 00:00:00');
            $end = new \DateTimeImmutable($options['to'] . ' 23:59:59');
        } catch (\Exception $exception) {
            $start = $now->setTime(0, 0);
            $end = $now->setTime(23, 59, 59);
        }
    }

    $pdo = pickup_db();
    $params = [
        ':start' => $start->format('Y-m-d H:i:s'),
        ':end' => $end->format('Y-m-d H:i:s'),
    ];
    $condition = '';
    if ($locationId) {
        $condition = ' AND pickup_location_id = :location_id';
        $params[':location_id'] = $locationId;
    }

    $sql = 'SELECT
        COUNT(*) AS total_received,
        SUM(CASE WHEN status = "ritirato" THEN 1 ELSE 0 END) AS total_picked,
        SUM(CASE WHEN status = "in_giacenza" THEN 1 ELSE 0 END) AS in_storage,
        SUM(CASE WHEN status = "in_giacenza_scaduto" THEN 1 ELSE 0 END) AS storage_expired
        FROM pickup_packages
        WHERE created_at BETWEEN :start AND :end' . $condition;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $totals = $stmt->fetch() ?: [];

    $pickedStmt = $pdo->prepare('SELECT COUNT(*) FROM pickup_packages WHERE status = "ritirato" AND updated_at BETWEEN :start AND :end' . $condition);
    $pickedStmt->execute($params);
    $pickedRange = (int) $pickedStmt->fetchColumn();

    return [
        'range' => [
            'start' => $params[':start'],
            'end' => $params[':end'],
            'label' => $range,
        ],
        'received' => (int) ($totals['total_received'] ?? 0),
        'picked' => (int) ($totals['total_picked'] ?? 0),
        'picked_in_range' => $pickedRange,
        'in_storage' => (int) ($totals['in_storage'] ?? 0),
        'storage_expired' => (int) ($totals['storage_expired'] ?? 0),
    ];
}

function get_dashboard_counters(?int $locationId = null): array
{
    $pdo = pickup_db();
    $params = [];
    $condition = 'archived_at IS NULL';
    if ($locationId) {
        $condition .= ' AND pickup_location_id = :location_id';
        $params[':location_id'] = $locationId;
    }

    $countQuery = static function (string $status) use ($pdo, $condition, $params): int {
        $sql = 'SELECT COUNT(*) FROM pickup_packages WHERE ' . $condition . ' AND status = :status';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [':status' => $status]));
        return (int) $stmt->fetchColumn();
    };

    $incoming = $countQuery('in_arrivo');
    $inStorage = $countQuery('in_giacenza');
    $expired = $countQuery('in_giacenza_scaduto');

    $todayParams = $params;
    $todayCondition = $condition . ' AND status = :status AND DATE(updated_at) = CURDATE()';
    $sqlToday = 'SELECT COUNT(*) FROM pickup_packages WHERE ' . $todayCondition;
    $stmtToday = $pdo->prepare($sqlToday);
    $stmtToday->execute(array_merge($todayParams, [':status' => 'ritirato']));
    $pickedToday = (int) $stmtToday->fetchColumn();

    return [
        'incoming' => $incoming,
        'storage' => $inStorage,
        'expired' => $expired,
        'picked_today' => $pickedToday,
    ];
}

function api_get_package(string $tracking): array
{
    $tracking = clean_input($tracking, 100);
    if ($tracking === '') {
        throw new InvalidArgumentException('Tracking non valido.');
    }

    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT p.*, c.name AS courier_name, l.name AS location_name FROM pickup_packages p
        LEFT JOIN pickup_couriers c ON c.id = p.courier_id
        LEFT JOIN pickup_locations l ON l.id = p.pickup_location_id
        WHERE p.tracking = :tracking LIMIT 1');
    $stmt->execute([':tracking' => $tracking]);
    $package = $stmt->fetch();

    if (!$package) {
        throw new RuntimeException('Pacco non trovato.');
    }

    $package['history'] = get_package_history((int) $package['id'], 20);
    $package['notifications'] = get_notifications_for_package((int) $package['id'], 10);

    return $package;
}

function export_database_backup(): string
{
    pickup_ensure_directory(PICKUP_BACKUP_DIR);

    $pdo = pickup_db();
    $backup = [
        'generated_at' => date('c'),
        'packages' => $pdo->query('SELECT * FROM pickup_packages')->fetchAll(),
        'couriers' => $pdo->query('SELECT * FROM pickup_couriers')->fetchAll(),
        'locations' => $pdo->query('SELECT * FROM pickup_locations')->fetchAll(),
        'history' => $pdo->query('SELECT * FROM pickup_package_history')->fetchAll(),
        'notifications' => $pdo->query('SELECT * FROM pickup_notifications')->fetchAll(),
    ];

    $fileName = 'pickup_backup_' . date('Ymd_His') . '.json';
    $path = PICKUP_BACKUP_DIR . '/' . $fileName;

    $json = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('Impossibile serializzare il backup pickup.');
    }

    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException('Impossibile salvare il backup pickup.');
    }

    return pickup_relative_path($path);
}

function get_operator_actions(int $userId, int $limit = 50): array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT h.*, p.tracking FROM pickup_package_history h
        LEFT JOIN pickup_packages p ON p.id = h.package_id
        WHERE h.actor_id = :user_id
        ORDER BY h.created_at DESC
        LIMIT :limit');
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function generate_qr_checkin(?int $locationId = null, ?string $callbackUrl = null): ?string
{
    $baseUrl = $callbackUrl ?? rtrim(env('APP_URL', ''), '/');
    if ($baseUrl === '') {
        $baseUrl = '/modules/servizi/logistici/index.php';
    }

    if ($locationId) {
        $baseUrl .= (str_contains($baseUrl, '?') ? '&' : '?') . 'location=' . (int) $locationId;
    }

    pickup_ensure_directory(PICKUP_QR_DIR);

    $fileName = 'checkin_' . ($locationId ?: 'global') . '_' . time() . '.png';
    $destPath = PICKUP_QR_DIR . '/' . $fileName;

    $imageData = pickup_fetch_qr_image($baseUrl);
    if ($imageData === null) {
        error_log('Generazione QR check-in fallita per URL ' . $baseUrl);
        return null;
    }

    if (@file_put_contents($destPath, $imageData) === false) {
        throw new RuntimeException('Impossibile salvare il QR di check-in.');
    }

    return pickup_relative_path($destPath);
}

function send_notification_email(string $email, string $subject, string $message, array $options = []): bool
{
    $email = filter_var($email, FILTER_VALIDATE_EMAIL);
    if ($email === false) {
        throw new InvalidArgumentException('Indirizzo email non valido.');
    }

    $subject = clean_input($subject, 160);
    if ($subject === '') {
        $subject = 'Aggiornamento pacco pickup';
    }

    $qrUrl = trim((string) ($options['qr_url'] ?? ''));
    $qrHtml = '';
    if ($qrUrl !== '') {
        $qrEscaped = htmlspecialchars($qrUrl, ENT_QUOTES, 'UTF-8');
        $qrHtml = '<div style="margin-top:24px;text-align:center;">'
            . '<img src="' . $qrEscaped . '" alt="QR code pickup" style="max-width:200px;height:auto;border:1px solid #f0f0f0;padding:8px;border-radius:8px;" />'
            . '<p style="font-size:13px;color:#555;margin-top:8px;">Mostra questo QR al punto ritiro per completare il ritiro.</p>'
            . '</div>';
    }

    $bodyContent = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
    $htmlSections = [];
    $htmlSections[] = '<div style="font-size:15px;line-height:1.6;color:#212529;">' . $bodyContent . '</div>';
    if ($qrHtml !== '') {
        $htmlSections[] = $qrHtml;
    }

    $htmlBody = render_mail_template($subject, implode('', $htmlSections));

    return send_system_mail($email, $subject, $htmlBody);
}

function send_notification_whatsapp(string $phone, string $message): bool
{
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if ($phone === '') {
        throw new InvalidArgumentException('Numero di telefono non valido.');
    }

    $endpoint = env('WHATSAPP_API_URL', '');
    $token = env('WHATSAPP_API_TOKEN', '');

    if ($endpoint === '' || $token === '') {
        error_log('WhatsApp API non configurata.');
        return false;
    }

    if (!function_exists('curl_init')) {
        error_log('cURL non disponibile per le notifiche WhatsApp.');
        return false;
    }

    $payload = [
        'to' => $phone,
        'message' => $message,
    ];

    try {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (\JsonException $exception) {
        error_log('Serializzazione WhatsApp fallita: ' . $exception->getMessage());
        return false;
    }

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $json,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $error !== '') {
        error_log('Invio WhatsApp fallito: ' . ($error !== '' ? $error : 'risposta vuota'));
        return false;
    }

    if ($status >= 200 && $status < 300) {
        return true;
    }

    error_log('WhatsApp API status: ' . $status . ' body: ' . $response);
    return false;
}

function generate_statistics(array $filters = []): array
{
    $pdo = pickup_db();
    $conditions = [];
    $params = [];

    $conditions[] = 'p.archived_at IS NULL';

    if (!empty($filters['from'])) {
        $conditions[] = 'p.created_at >= :from';
        $params[':from'] = $filters['from'] . ' 00:00:00';
    }

    if (!empty($filters['to'])) {
        $conditions[] = 'p.created_at <= :to';
        $params[':to'] = $filters['to'] . ' 23:59:59';
    }

    if (!empty($filters['pickup_location_id'])) {
        $locationFilter = $filters['pickup_location_id'];
        if (is_array($locationFilter)) {
            $locationIds = array_filter(array_map('intval', $locationFilter), static fn($id) => $id > 0);
            if ($locationIds) {
                $placeholders = [];
                foreach ($locationIds as $index => $locationId) {
                    $placeholder = ':location_' . $index;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $locationId;
                }
                $conditions[] = 'p.pickup_location_id IN (' . implode(', ', $placeholders) . ')';
            }
        } elseif ((int) $locationFilter > 0) {
            $conditions[] = 'p.pickup_location_id = :location_id';
            $params[':location_id'] = (int) $locationFilter;
        }
    }

    $sql = 'SELECT p.status, COUNT(*) AS total FROM pickup_packages p WHERE ' . implode(' AND ', $conditions) . ' GROUP BY p.status';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $data = array_fill_keys(pickup_statuses(), 0);
    foreach ($stmt->fetchAll() as $row) {
        $status = $row['status'];
        $data[$status] = (int) $row['total'];
    }

    $data['totale'] = array_sum($data);
    return $data;
}

function export_packages_csv(array $filters = []): void
{
    $rows = get_all_packages($filters);
    $filename = 'pickup_packages_' . date('Ymd_His') . '.csv';

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Tracking', 'Cliente', 'Telefono', 'Email', 'Corriere', 'Punto ritiro', 'Indirizzo ritiro', 'Stato', 'Creato il', 'Aggiornato il']);

    foreach ($rows as $row) {
        fputcsv($output, [
            $row['id'],
            $row['tracking'],
            $row['customer_name'],
            $row['customer_phone'],
            $row['customer_email'] ?? '',
            $row['courier_name'] ?? 'N/D',
            $row['location_name'] ?? 'N/D',
            $row['location_address'] ?? '',
            pickup_status_label($row['status']),
            $row['created_at'],
            $row['updated_at'],
        ]);
    }

    fclose($output);
    exit;
}

function export_packages_pdf(array $filters = []): void
{
    $rows = get_all_packages($filters);
    $filename = 'pickup_packages_' . date('Ymd_His') . '.pdf';

    $lines = [];
    $lines[] = 'Report pacchi pickup - generato il ' . date('d/m/Y H:i');
    $lines[] = str_repeat('-', 128);

    $pad = static function (string $value, int $length): string {
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, $length, 'UTF-8');
        } else {
            $value = substr($value, 0, $length);
        }
        return str_pad($value, $length, ' ');
    };

    $lines[] = $pad('ID', 6) . $pad('Tracking', 16) . $pad('Cliente', 24) . $pad('Telefono', 14) . $pad('Email', 24) . $pad('Corriere', 16) . $pad('Punto ritiro', 20) . $pad('Stato', 12) . 'Aggiornato';

    foreach ($rows as $row) {
        $lines[] = $pad((string) $row['id'], 6)
            . $pad($row['tracking'], 16)
            . $pad($row['customer_name'], 24)
            . $pad($row['customer_phone'], 14)
            . $pad($row['customer_email'] ?? 'N/D', 24)
            . $pad($row['courier_name'] ?? 'N/D', 16)
            . $pad($row['location_name'] ?? 'N/D', 20)
            . $pad(pickup_status_label($row['status']), 12)
            . substr($row['updated_at'], 0, 19);
    }

    if (!$rows) {
        $lines[] = 'Nessun pacco disponibile per i filtri selezionati.';
    }

    $pages = array_chunk($lines, 42);
    $pdfBinary = pickup_build_pdf($pages);

    if (ob_get_length()) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $pdfBinary;
    exit;
}

function pickup_build_pdf(array $pages): string
{
    if (!$pages) {
        $pages = [['Report vuoto']];
    }

    $objects = [''];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '';
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $kids = [];
    foreach ($pages as $pageLines) {
        $stream = "BT\n/F1 11 Tf\n14 TL\n40 780 Td\n";
        foreach (array_values($pageLines) as $index => $line) {
            $escaped = pickup_escape_pdf_text($line);
            if ($index === 0) {
                $stream .= '(' . $escaped . ") Tj\n";
            } else {
                $stream .= "T*\n(" . $escaped . ") Tj\n";
            }
        }
        $stream .= "ET\n";

        $contentObject = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($stream), $stream);
        $objects[] = $contentObject;
        $contentId = array_key_last($objects);

        $pageObject = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>', $contentId);
        $objects[] = $pageObject;
        $pageId = array_key_last($objects);
        $kids[] = $pageId . ' 0 R';
    }

    $objects[2] = sprintf('<< /Type /Pages /Count %d /Kids [%s] >>', count($kids), implode(' ', $kids));

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    $maxId = array_key_last($objects);

    for ($i = 1; $i <= $maxId; $i++) {
        if (!isset($objects[$i]) || $objects[$i] === '') {
            continue;
        }
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xrefStart = strlen($pdf);
    $pdf .= 'xref\n0 ' . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ' . "\n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefStart . "\n%%EOF";

    return $pdf;
}

function pickup_escape_pdf_text(string $text): string
{
    $text = str_replace(["\\", "(", ")"], ['\\\\', '\\(', '\\)'], $text);
    $text = preg_replace('/[\r\n\t]+/', ' ', $text) ?? $text;
    return $text;
}

function pickup_email_subject_template(array $package): string
{
    $tracking = trim((string) ($package['tracking'] ?? ''));
    $status = $package['status'] ?? 'in_arrivo';
    $base = 'Aggiornamento pickup';
    if ($tracking !== '') {
        $base .= ' #' . $tracking;
    }

    switch ($status) {
        case 'consegnato':
            return $base . ' disponibile al ritiro';
        case 'ritirato':
            return $base . ' completato';
        case 'in_giacenza':
            return $base . ' in giacenza';
        case 'in_arrivo':
            return $base . ' in arrivo';
        default:
            return $base;
    }
}

function pickup_email_message_template(array $package): string
{
    $name = trim((string) ($package['customer_name'] ?? ''));
    $recipientName = $name !== '' ? $name : 'Cliente';
    $tracking = trim((string) ($package['tracking'] ?? ''));
    $trackingInfo = $tracking !== '' ? ' (tracking ' . $tracking . ')' : '';
    $status = $package['status'] ?? 'in_arrivo';
    $expectedAt = $package['expected_at'] ?? null;
    $expectedText = '';
    $locationName = trim((string) ($package['location_name'] ?? ''));
    $locationAddress = trim((string) ($package['location_address'] ?? ''));
    $qrUrl = pickup_public_url($package['qr_code_path'] ?? '');

    if ($expectedAt) {
        $formatted = format_datetime_locale($expectedAt);
        if ($formatted !== '') {
            $expectedText = $formatted;
        }
    }

    $body = '';
    switch ($status) {
        case 'consegnato':
            $body = 'siamo lieti di informarti che il tuo pacco' . $trackingInfo . ' è arrivato presso il nostro punto pickup ed è pronto per il ritiro. Ti aspettiamo in sede con il codice di riferimento.';
            break;
        case 'ritirato':
            $body = 'ti confermiamo che il pacco' . $trackingInfo . ' è stato ritirato correttamente. Grazie per aver scelto il servizio pickup.';
            break;
        case 'in_giacenza':
            $body = 'il tuo pacco' . $trackingInfo . ' è in giacenza presso il nostro punto pickup. Ti invitiamo a passare a ritirarlo entro 48 ore.';
            break;
        case 'in_arrivo':
            $body = 'il tuo pacco' . $trackingInfo . ' è in arrivo' . ($expectedText !== '' ? ' con consegna prevista il ' . $expectedText : '') . '. Ti avviseremo non appena sarà disponibile al ritiro.';
            break;
        default:
            $body = 'abbiamo registrato un aggiornamento relativo al tuo pacco' . $trackingInfo . '. Ti contatteremo con ulteriori dettagli a breve.';
            break;
    }

    $details = [];
    if ($locationName !== '') {
        $details[] = 'Punto ritiro: ' . $locationName;
    }
    if ($locationAddress !== '') {
        $details[] = 'Indirizzo: ' . $locationAddress;
    }
    if ($qrUrl !== '') {
        $details[] = 'Mostra il QR in fondo a questa email al punto ritiro.';
    }

    $message = 'Gentile ' . $recipientName . ', ' . $body;
    if ($details) {
        $message .= "\n\n" . implode("\n", $details);
    }

    $message .= "\n\nCordiali saluti,\nTeam Ag Servizi";
    return trim($message);
}

function pickup_whatsapp_message_template(array $package): string
{
    $name = trim((string) ($package['customer_name'] ?? ''));
    $recipientName = $name !== '' ? $name : 'cliente';
    $tracking = trim((string) ($package['tracking'] ?? ''));
    $trackingInfo = $tracking !== '' ? ' (tracking ' . $tracking . ')' : '';
    $status = $package['status'] ?? 'in_arrivo';
    $expectedAt = $package['expected_at'] ?? null;
    $expectedText = '';
    $locationName = trim((string) ($package['location_name'] ?? ''));
    $locationAddress = trim((string) ($package['location_address'] ?? ''));
    $qrUrl = pickup_public_url($package['qr_code_path'] ?? '');

    if ($expectedAt) {
        $formatted = format_datetime_locale($expectedAt);
        if ($formatted !== '') {
            $expectedText = $formatted;
        }
    }

    $body = '';
    switch ($status) {
        case 'consegnato':
            $body = 'il tuo pacco' . $trackingInfo . ' è arrivato presso il nostro punto pickup ed è pronto per il ritiro. Presentati con il codice per completare il ritiro.';
            break;
        case 'ritirato':
            $body = 'il tuo pacco' . $trackingInfo . ' risulta ritirato. Grazie per aver utilizzato il servizio Coresuite Pickup!';
            break;
        case 'in_giacenza':
            $body = 'il tuo pacco' . $trackingInfo . ' ti aspetta al punto pickup. Ti chiediamo di passare a ritirarlo entro 48 ore.';
            break;
        case 'in_arrivo':
            $body = 'il tuo pacco' . $trackingInfo . ' è in arrivo' . ($expectedText !== '' ? ' e previsto per il ' . $expectedText : '') . '. Ti avviseremo non appena sarà disponibile al ritiro.';
            break;
        default:
            $body = 'abbiamo un aggiornamento sul tuo pacco' . $trackingInfo . '. Ti contatteremo a breve con maggiori dettagli.';
            break;
    }

    $details = [];
    if ($locationName !== '') {
        $details[] = 'Punto ritiro: ' . $locationName;
    }
    if ($locationAddress !== '') {
        $details[] = 'Indirizzo: ' . $locationAddress;
    }
    if ($qrUrl !== '') {
        $details[] = 'QR Code: ' . $qrUrl;
    }

    $message = 'Ciao ' . $recipientName . ', ' . $body;
    if ($details) {
        $message .= "\n" . implode("\n", $details);
    }

    $message .= "\n\nGrazie,\nTeam Ag Servizi";
    return trim($message);
}

function pickup_customer_report_statuses(): array
{
    return ['reported', 'confirmed', 'arrived', 'cancelled'];
}

function pickup_customer_report_status_meta(string $status): array
{
    $map = [
        'reported' => ['label' => 'Segnalato', 'badge' => 'bg-warning-subtle text-warning'],
        'confirmed' => ['label' => 'Confermato', 'badge' => 'bg-info-subtle text-info'],
        'arrived' => ['label' => 'Arrivato', 'badge' => 'bg-success-subtle text-success'],
        'cancelled' => ['label' => 'Annullato', 'badge' => 'bg-secondary-subtle text-secondary'],
    ];

    return $map[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'badge' => 'bg-secondary-subtle text-secondary'];
}

function get_customer_report_statistics(): array
{
    $pdo = pickup_db();
    $statuses = pickup_customer_report_statuses();
    $stats = array_fill_keys($statuses, 0);

    $stmt = $pdo->query('SELECT status, COUNT(*) AS total FROM pickup_customer_reports GROUP BY status');
    foreach ($stmt->fetchAll() as $row) {
        $key = $row['status'] ?? '';
        if (isset($stats[$key])) {
            $stats[$key] = (int) $row['total'];
        }
    }

    $pendingStmt = $pdo->query('SELECT COUNT(*) FROM pickup_customer_reports WHERE status = "reported" AND pickup_id IS NULL');
    $pending = $pendingStmt ? (int) $pendingStmt->fetchColumn() : 0;

    $stats['totale'] = array_sum($stats);
    $stats['pending_unlinked'] = $pending;

    return $stats;
}

function get_customer_reports(array $filters = [], array $options = []): array
{
    $pdo = pickup_db();

    $conditions = ['1 = 1'];
    $params = [];

    if (!empty($filters['id'])) {
        $conditions[] = 'r.id = :report_id';
        $params[':report_id'] = (int) $filters['id'];
    }

    if (!empty($filters['customer_id'])) {
        $conditions[] = 'r.customer_id = :customer_id';
        $params[':customer_id'] = (int) $filters['customer_id'];
    }

    if (!empty($filters['pickup_id'])) {
        $conditions[] = 'r.pickup_id = :pickup_id';
        $params[':pickup_id'] = (int) $filters['pickup_id'];
    }

    if (!empty($filters['status'])) {
        $allowed = pickup_customer_report_statuses();
        if (is_array($filters['status'])) {
            $selected = array_values(array_intersect($allowed, array_map('strval', $filters['status'])));
            if ($selected) {
                $placeholders = [];
                foreach ($selected as $idx => $status) {
                    $placeholder = ':status_' . $idx;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $status;
                }
                $conditions[] = 'r.status IN (' . implode(', ', $placeholders) . ')';
            }
        } else {
            $status = (string) $filters['status'];
            if (in_array($status, $allowed, true)) {
                $conditions[] = 'r.status = :status';
                $params[':status'] = $status;
            }
        }
    }

    if (!empty($filters['only_unlinked'])) {
        $conditions[] = 'r.pickup_id IS NULL';
    }

    if (array_key_exists('linked', $filters)) {
        if ($filters['linked'] === true) {
            $conditions[] = 'r.pickup_id IS NOT NULL';
        }
        if ($filters['linked'] === false) {
            $conditions[] = 'r.pickup_id IS NULL';
        }
    }

    if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])) {
        $conditions[] = 'r.created_at >= :from_date';
        $params[':from_date'] = $filters['from'] . ' 00:00:00';
    }

    if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])) {
        $conditions[] = 'r.created_at <= :to_date';
        $params[':to_date'] = $filters['to'] . ' 23:59:59';
    }

    if (!empty($filters['search'])) {
        $search = clean_input((string) $filters['search'], 120);
        if ($search !== '') {
            $conditions[] = '(r.tracking_code LIKE :search OR COALESCE(r.courier_name, "") LIKE :search OR COALESCE(r.recipient_name, "") LIKE :search OR COALESCE(r.delivery_location, "") LIKE :search OR COALESCE(c.name, "") LIKE :search OR COALESCE(c.email, "") LIKE :search OR COALESCE(c.phone, "") LIKE :search OR COALESCE(r.notes, "") LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }
    }

    $orderBy = 'created_at';
    $orderDirection = 'DESC';

    if (!empty($options['order_by'])) {
        $candidate = strtolower((string) $options['order_by']);
        if (in_array($candidate, ['created_at', 'updated_at'], true)) {
            $orderBy = $candidate;
        }
    }

    if (!empty($options['order_direction'])) {
        $candidate = strtoupper((string) $options['order_direction']);
        if (in_array($candidate, ['ASC', 'DESC'], true)) {
            $orderDirection = $candidate;
        }
    }

    $limit = isset($options['limit']) ? (int) $options['limit'] : 50;
    $limit = max(1, min($limit, 200));
    $offset = isset($options['offset']) ? (int) $options['offset'] : 0;
    $offset = max(0, $offset);

    $sql = 'SELECT r.*, c.name AS customer_name, c.email AS customer_email, c.phone AS customer_phone '
        . 'FROM pickup_customer_reports r '
        . 'LEFT JOIN pickup_customers c ON c.id = r.customer_id '
        . 'WHERE ' . implode(' AND ', $conditions)
        . ' ORDER BY r.' . $orderBy . ' ' . $orderDirection . ' LIMIT :limit OFFSET :offset';

    $stmt = $pdo->prepare($sql);

    foreach ($params as $placeholder => $value) {
        $stmt->bindValue($placeholder, $value);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll();
}

function get_customer_report(int $reportId): ?array
{
    $reports = get_customer_reports(['id' => $reportId], ['limit' => 1]);
    return $reports[0] ?? null;
}

function get_customer_report_for_pickup(int $pickupId): ?array
{
    $reports = get_customer_reports(['pickup_id' => $pickupId], ['limit' => 1]);
    return $reports[0] ?? null;
}

function update_customer_report_status(int $reportId, string $status): bool
{
    if (!in_array($status, pickup_customer_report_statuses(), true)) {
        throw new InvalidArgumentException('Stato segnalazione non valido.');
    }

    $pdo = pickup_db();
    $stmt = $pdo->prepare('UPDATE pickup_customer_reports SET status = :status, updated_at = :now WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':id' => $reportId,
        ':now' => pickup_current_timestamp(),
    ]);

    return $stmt->rowCount() > 0;
}

function link_customer_report_to_pickup(int $reportId, int $pickupId, string $status = 'confirmed'): bool
{
    $allowed = pickup_customer_report_statuses();
    if (!in_array($status, $allowed, true)) {
        $status = 'confirmed';
    }

    $pdo = pickup_db();
    $stmt = $pdo->prepare('UPDATE pickup_customer_reports SET pickup_id = :pickup_id, status = :status, updated_at = :now WHERE id = :id');
    $stmt->execute([
        ':pickup_id' => $pickupId,
        ':status' => $status,
        ':id' => $reportId,
        ':now' => pickup_current_timestamp(),
    ]);

    return $stmt->rowCount() > 0;
}

function unlink_customer_report(int $reportId): bool
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('UPDATE pickup_customer_reports SET pickup_id = NULL, status = "reported", updated_at = :now WHERE id = :id');
    $stmt->execute([
        ':id' => $reportId,
        ':now' => pickup_current_timestamp(),
    ]);
    return $stmt->rowCount() > 0;
}

function get_package_by_tracking(string $tracking): ?array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT * FROM pickup_packages WHERE tracking = :tracking LIMIT 1');
    $stmt->execute([':tracking' => trim($tracking)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function archive_old_packages(int $days = PICKUP_DEFAULT_ARCHIVE_DAYS): int
{
    $pdo = pickup_db();
    $now = pickup_current_timestamp();
    $threshold = pickup_timestamp_minus_days($days);
    $stmt = $pdo->prepare('UPDATE pickup_packages SET archived_at = :now WHERE status = :status AND archived_at IS NULL AND updated_at < :threshold');
    $stmt->bindValue(':now', $now, PDO::PARAM_STR);
    $stmt->bindValue(':status', 'ritirato', PDO::PARAM_STR);
    $stmt->bindValue(':threshold', $threshold, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->rowCount();
}

function get_recent_notifications(int $limit = 10): array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT n.*, p.tracking FROM pickup_notifications n INNER JOIN pickup_packages p ON p.id = n.package_id ORDER BY n.created_at DESC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function get_notifications_for_package(int $packageId, int $limit = 20): array
{
    $pdo = pickup_db();
    $stmt = $pdo->prepare('SELECT * FROM pickup_notifications WHERE package_id = :package_id ORDER BY created_at DESC LIMIT :limit');
    $stmt->bindValue(':package_id', $packageId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}
