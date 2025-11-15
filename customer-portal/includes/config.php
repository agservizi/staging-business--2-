<?php
declare(strict_types=1);

// Configurazione per il portale cliente pickup
const PORTAL_VERSION = '1.0.0';
const PORTAL_NAME = 'Pickup Portal';

// Percorsi
const PORTAL_ROOT = __DIR__;
const PORTAL_BASE_PATH = __DIR__ . '/..';
const UPLOADS_DIR = PORTAL_BASE_PATH . '/uploads';

// Sicurezza
const SESSION_TIMEOUT = 1800; // 30 minuti di inattività standard
const MAX_LOGIN_ATTEMPTS = 5;
const LOGIN_LOCKOUT_TIME = 900; // 15 minuti
const OTP_VALIDITY_TIME = 300; // 5 minuti
const OTP_LENGTH = 6;
const REMEMBER_ME_LIFETIME = 2592000; // 30 giorni
const REMEMBER_ME_COOKIE_NAME = 'pickup_portal_remember';

// Impostazioni notifiche
const ENABLE_EMAIL_NOTIFICATIONS = true;
const ENABLE_WHATSAPP_NOTIFICATIONS = false;

// Rate limiting
const API_RATE_LIMIT_REQUESTS = 60;
const API_RATE_LIMIT_WINDOW = 60; // secondi

// Cache
const CACHE_ENABLED = true;
const CACHE_TTL = 300; // 5 minuti

// Validazione
const MIN_TRACKING_LENGTH = 5;
const MAX_TRACKING_LENGTH = 50;
const ALLOWED_FILE_TYPES = ['jpg', 'jpeg', 'png', 'pdf'];
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

// Configurazione paginazione
const DEFAULT_PAGE_SIZE = 20;
const MAX_PAGE_SIZE = 100;

// Timezone
date_default_timezone_set('Europe/Rome');

// Funzioni di utility
function portal_config(string $key, $default = null) {
    $config = [
        'portal_name' => PORTAL_NAME,
        'portal_version' => PORTAL_VERSION,
    'session_timeout' => SESSION_TIMEOUT,
    'remember_me_lifetime' => REMEMBER_ME_LIFETIME,
    'remember_cookie_name' => REMEMBER_ME_COOKIE_NAME,
        'otp_length' => OTP_LENGTH,
        'otp_validity' => OTP_VALIDITY_TIME,
        'max_login_attempts' => MAX_LOGIN_ATTEMPTS,
        'lockout_time' => LOGIN_LOCKOUT_TIME,
        'api_rate_limit' => API_RATE_LIMIT_REQUESTS,
        'api_rate_window' => API_RATE_LIMIT_WINDOW,
        'cache_enabled' => CACHE_ENABLED,
        'cache_ttl' => CACHE_TTL,
    'enable_email' => ENABLE_EMAIL_NOTIFICATIONS,
    'enable_whatsapp' => ENABLE_WHATSAPP_NOTIFICATIONS,
        'min_tracking_length' => MIN_TRACKING_LENGTH,
        'max_tracking_length' => MAX_TRACKING_LENGTH,
        'allowed_file_types' => ALLOWED_FILE_TYPES,
        'max_file_size' => MAX_FILE_SIZE,
        'default_page_size' => DEFAULT_PAGE_SIZE,
        'max_page_size' => MAX_PAGE_SIZE,
    ];
    
    return $config[$key] ?? $default;
}

// Inizializzazione sessione sicura
if (session_status() === PHP_SESSION_NONE) {
    $httpsOn = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );

    $forcedSecure = getenv('PORTAL_FORCE_SECURE_COOKIE');
    if ($forcedSecure !== false) {
        $normalized = strtolower((string) $forcedSecure);
        $httpsOn = in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }

    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $httpsOn ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string) max(SESSION_TIMEOUT, REMEMBER_ME_LIFETIME));
    
    session_start();
}

// Rigenerare session ID periodicamente
if (!isset($_SESSION['last_regeneration'])) {
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 300) { // 5 minuti
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// CSRF Protection
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function get_csrf_token(): string {
    return $_SESSION['csrf_token'] ?? '';
}

function verify_csrf_token(string $token): bool {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// Carica configurazione ambiente principale + override del portale
require_once __DIR__ . '/env.php';

// Funzioni di logging specifiche per il portale
function portal_log(string $message, string $level = 'INFO', array $context = []): void {
    $logFile = PORTAL_ROOT . '/logs/portal.log';
    $logDir = dirname($logFile);
    
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $contextStr = empty($context) ? '' : ' ' . json_encode($context);
    $logMessage = "[{$timestamp}] [{$level}] {$message}{$contextStr}" . PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

function portal_error_log(string $message, array $context = []): void {
    portal_log($message, 'ERROR', $context);
}

function portal_info_log(string $message, array $context = []): void {
    portal_log($message, 'INFO', $context);
}

function portal_debug_log(string $message, array $context = []): void {
    if (env('APP_DEBUG', false)) {
        portal_log($message, 'DEBUG', $context);
    }
}