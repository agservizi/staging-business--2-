<?php
require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/env.php';

load_env(__DIR__ . '/../.env');
configure_timezone();

$debugFlag = filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
$displayFlag = filter_var(env('PHP_DISPLAY_ERRORS', false), FILTER_VALIDATE_BOOL);

if ($debugFlag || $displayFlag) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
}
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/app.log';
ini_set('log_errors', '1');
if (@is_writable($logDir) && is_dir($logDir)) {
    ini_set('error_log', $logFile);
}
function redirect_by_role(string $role): void
{
    switch ($role) {
        case 'Admin':
        case 'Operatore':
        case 'Manager':
            header('Location: dashboard.php');
            break;
        case 'Patronato':
            header('Location: modules/servizi/caf-patronato/index.php');
            break;
        case 'Cliente':
            header('Location: dashboard.php?view=cliente');
            break;
        default:
            header('Location: dashboard.php');
    }
}

function sanitize_output(string|int|float|bool|null $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        $value = $value ? '1' : '0';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function format_currency(?float $amount): string
{
    if ($amount === null) {
        return '€ 0,00';
    }
    return '€ ' . number_format($amount, 2, ',', '.');
}

function base_url(string $path = ''): string
{
    static $cached;
    if ($cached === null) {
        $currentHost = $_SERVER['HTTP_HOST'] ?? null;
        $appUrl = env('APP_URL');
        if ($appUrl) {
            $appUrl = rtrim($appUrl, '/');
            $appHost = parse_url($appUrl, PHP_URL_HOST);
            $strictAppUrl = filter_var(env('APP_URL_STRICT', false), FILTER_VALIDATE_BOOL);

            if ($currentHost && $appHost && strcasecmp((string) $appHost, (string) $currentHost) !== 0 && !$strictAppUrl) {
                $appUrl = null;
            }
        }

        if ($appUrl) {
            $cached = $appUrl;
        } else {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $currentHost ?: 'localhost';
            $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
            $projectRoot = realpath(__DIR__ . '/..') ?: '';
            $basePath = '';

            if ($docRoot !== '' && $projectRoot !== '' && strncmp($projectRoot, $docRoot, strlen($docRoot)) === 0) {
                $relative = str_replace('\\', '/', substr($projectRoot, strlen($docRoot)));
                $basePath = '/' . ltrim($relative, '/');
                if ($basePath === '/') {
                    $basePath = '';
                }
            } else {
                $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
                $basePath = $scriptDir && $scriptDir !== '.' ? $scriptDir : '';
            }

            $cached = rtrim($scheme . '://' . $host . $basePath, '/');
        }
    }

    $path = ltrim($path, '/');
    return $cached . ($path !== '' ? '/' . $path : '');
}

function public_path(string $path = ''): string
{
    $base = realpath(__DIR__ . '/..');
    if ($base === false) {
        $base = __DIR__ . '/..';
    }
    return rtrim($base, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

function project_root_path(): string
{
    static $root = null;
    if ($root === null) {
        $resolved = realpath(__DIR__ . '/..');
        $root = $resolved !== false ? $resolved : __DIR__ . '/..';
    }

    return $root;
}

/**
 * @return array{available:array<int,string>,active:array<int,string>,completed:array<int,string>,cancelled:array<int,string>,confirmation:string}
 */
function get_appointment_status_config(\PDO $pdo): array
{
    $cacheKey = '__appointment_status_config';

    if (!array_key_exists($cacheKey, $GLOBALS) || !is_array($GLOBALS[$cacheKey])) {
        $GLOBALS[$cacheKey] = null;
    }

    if ($GLOBALS[$cacheKey] !== null) {
        return $GLOBALS[$cacheKey];
    }

    $default = \App\Services\SettingsService::defaultAppointmentStatuses();

    try {
        $service = new \App\Services\SettingsService($pdo, project_root_path());
        $config = $service->getAppointmentStatuses();
        $GLOBALS[$cacheKey] = $config;
        return $config;
    } catch (\Throwable $exception) {
        error_log('Appointment status config fallback: ' . $exception->getMessage());
        $GLOBALS[$cacheKey] = $default;
        return $default;
    }
}

/**
 * @param array{available:array<int,string>,active:array<int,string>,completed:array<int,string>,cancelled:array<int,string>,confirmation:string}|null $config
 */
function reset_appointment_status_config_cache(?array $config = null): void
{
    $cacheKey = '__appointment_status_config';
    $GLOBALS[$cacheKey] = $config;
}

/**
 * @return array<int, string>
 */
function get_appointment_types(PDO $pdo): array
{
    $cacheKey = '__appointment_types_config';

    if (!array_key_exists($cacheKey, $GLOBALS) || !is_array($GLOBALS[$cacheKey])) {
        $GLOBALS[$cacheKey] = null;
    }

    if ($GLOBALS[$cacheKey] !== null) {
        return $GLOBALS[$cacheKey];
    }

    $default = \App\Services\SettingsService::defaultAppointmentTypes();

    try {
        $service = new \App\Services\SettingsService($pdo, project_root_path());
        $types = $service->getAppointmentTypes();
        $GLOBALS[$cacheKey] = $types;
        return $types;
    } catch (\Throwable $exception) {
        error_log('Appointment types config fallback: ' . $exception->getMessage());
        $GLOBALS[$cacheKey] = $default;
        return $default;
    }
}

/**
 * @param array<int, string>|null $types
 */
function reset_appointment_type_config_cache(?array $types = null): void
{
    $cacheKey = '__appointment_types_config';
    $GLOBALS[$cacheKey] = $types;
}

/**
 * @return array{theme:string}
 */
function get_ui_theme_config(?PDO $pdo = null): array
{
    $cacheKey = '__ui_theme_config';

    if (!array_key_exists($cacheKey, $GLOBALS) || !is_array($GLOBALS[$cacheKey])) {
        $GLOBALS[$cacheKey] = null;
    }

    if ($GLOBALS[$cacheKey] !== null) {
        return $GLOBALS[$cacheKey];
    }

    $default = ['theme' => 'navy'];

    if (!$pdo instanceof PDO) {
        $GLOBALS[$cacheKey] = $default;
        return $default;
    }

    try {
        $service = new \App\Services\SettingsService($pdo, project_root_path());
        $config = $service->getAppearanceSettings();
        $GLOBALS[$cacheKey] = $config;
        return $config;
    } catch (\Throwable $exception) {
        error_log('UI theme config fallback: ' . $exception->getMessage());
        $GLOBALS[$cacheKey] = $default;
        return $default;
    }
}

/**
 * @param array{theme:string}|null $config
 */
function reset_ui_theme_cache(?array $config = null): void
{
    $GLOBALS['__ui_theme_config'] = $config;
}

/**
 * @return array{
 *     sender_name:string,
 *     sender_email:string,
 *     reply_to_email:string,
 *     resend_api_key:string,
 *     unsubscribe_base_url:string,
 *     webhook_secret:string,
 *     test_address:string,
 *     has_resend_api_key:bool,
 *     resend_api_key_hint:string
 * }
 */
function get_email_marketing_config(PDO $pdo): array
{
    $cacheKey = '__email_marketing_config';

    if (!array_key_exists($cacheKey, $GLOBALS) || !is_array($GLOBALS[$cacheKey])) {
        $GLOBALS[$cacheKey] = null;
    }

    if ($GLOBALS[$cacheKey] !== null) {
        return $GLOBALS[$cacheKey];
    }

    try {
        $service = new \App\Services\SettingsService($pdo, project_root_path());
        $config = $service->getEmailMarketingSettings(false);
        $GLOBALS[$cacheKey] = $config;
        return $config;
    } catch (\Throwable $exception) {
        error_log('Email marketing config fallback: ' . $exception->getMessage());
        $fallbackKey = (string) env('RESEND_MARKETING_API_KEY', env('RESEND_API_KEY', ''));
        $fallback = [
            'sender_name' => (string) env('MAIL_MARKETING_NAME', env('MAIL_FROM_NAME', 'Coresuite Business')),
            'sender_email' => (string) env('MAIL_MARKETING_ADDRESS', env('MAIL_FROM_ADDRESS', 'no-reply@example.com')),
            'reply_to_email' => (string) env('MAIL_MARKETING_REPLY_TO', ''),
            'resend_api_key' => $fallbackKey,
            'unsubscribe_base_url' => base_url(),
            'webhook_secret' => '',
            'test_address' => '',
            'has_resend_api_key' => $fallbackKey !== '',
            'resend_api_key_hint' => '',
        ];

        if ($fallback['has_resend_api_key']) {
            $length = strlen($fallbackKey);
            $fallback['resend_api_key_hint'] = $length > 4
                ? str_repeat('*', $length - 4) . substr($fallbackKey, -4)
                : str_repeat('*', $length);
        }

        $GLOBALS[$cacheKey] = $fallback;
        return $fallback;
    }
}

/**
 * @param array<string, mixed>|null $config
 */
function reset_email_marketing_config_cache(?array $config = null): void
{
    $GLOBALS['__email_marketing_config'] = $config;
}

/**
 * @return array<int, string>
 */
function get_appointment_available_statuses(\PDO $pdo): array
{
    return get_appointment_status_config($pdo)['available'];
}

/**
 * @return array<int, string>
 */
function get_appointment_active_statuses(\PDO $pdo): array
{
    $config = get_appointment_status_config($pdo);
    return $config['active'] ?: $config['available'];
}

function get_appointment_confirmation_status(\PDO $pdo): string
{
    return get_appointment_status_config($pdo)['confirmation'];
}

function asset(string $path): string
{
    $relative = ltrim($path, '/');
    $file = public_path($relative);
    $timestamp = is_file($file) ? filemtime($file) : time();
    return base_url($relative) . '?v=' . $timestamp;
}

function ai_assistant_enabled(): bool
{
    static $cached;
    if ($cached !== null) {
        return $cached;
    }

    $flag = filter_var(env('AI_THINKING_ASSISTANT_ENABLED', true), FILTER_VALIDATE_BOOL);
    $key = trim((string) env('OPENROUTER_API_KEY', ''));
    $cached = $flag && $key !== '';

    return $cached;
}

/**
 * @return array{title:string,path:string,section:string,description:string,slug:string}
 */
function ai_assistant_page_context(): array
{
    $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    $path = '/' . ltrim($script, '/');
    $normalized = trim(str_replace('.php', '', $path), '/');
    $segments = $normalized === '' ? [] : explode('/', $normalized);
    $defaultTitle = $GLOBALS['pageTitle'] ?? '';
    $defaultSection = 'Dashboard';
    $defaultDescription = 'Panoramica generale sul controllo operativo di Coresuite Business.';

    $map = [
        'dashboard' => ['Dashboard', 'Monitora KPI, pipeline e anomalie generali.'],
        'modules/clienti' => ['Clienti', 'Gestisci anagrafiche, opportunità e contratti dei clienti.'],
        'modules/servizi' => ['Servizi', 'Coordina erogazioni, logistics e follow-up dei servizi.'],
        'modules/report' => ['Reportistica', 'Analizza trend e scarica report operativi/finanziari.'],
        'modules/ticket' => ['Ticket', 'Gestisci richieste di assistenza e SLA.'],
        'modules/email-marketing' => ['Email marketing', 'Programma e analizza campagne marketing.'],
        'modules/documenti' => ['Documenti', 'Archivia e condividi documentazione ufficiale.'],
        'modules/impostazioni' => ['Impostazioni', 'Configura utenti, permessi e parametri di sistema.'],
        'customer-portal' => ['Customer portal', 'Supporta i clienti finali nelle operazioni self-service.'],
        'tools' => ['Tools', 'Utility amministrative e script di manutenzione.'],
    ];

    $section = $defaultSection;
    $description = $defaultDescription;
    foreach ($map as $needle => $info) {
        $needle = trim($needle, '/');
        if ($needle === '') {
            continue;
        }
        if ($normalized === '' && $needle === 'dashboard') {
            [$section, $description] = $info;
            break;
        }
        if ($needle !== '' && str_starts_with($normalized, $needle)) {
            [$section, $description] = $info;
            break;
        }
    }

    $slug = $normalized !== '' ? str_replace('/', '-', $normalized) : 'dashboard';
    $title = trim((string) $defaultTitle) !== '' ? (string) $defaultTitle : ucfirst(str_replace('-', ' ', $slug));

    return [
        'title' => $title,
        'path' => $path,
        'section' => $section,
        'description' => $description,
        'slug' => $slug,
    ];
}

/**
 * @return array{enabled:bool,endpoint?:string,defaultPeriod?:string,user?:array{name:string,role:string}}
 */
function ai_assistant_frontend_config(): array
{
    $config = ['enabled' => ai_assistant_enabled()];
    if (!$config['enabled']) {
        return $config;
    }

    $config['endpoint'] = base_url('api/ai/advisor.php');
    $config['defaultPeriod'] = 'last30';
    $config['user'] = [
        'name' => current_user_display_name(),
        'role' => (string) ($_SESSION['role'] ?? ''),
    ];
    $config['page'] = ai_assistant_page_context();

    return $config;
}

function format_datetime(?string $value, string $format = 'd/m/Y H:i'): string
{
    if ($value === null || $value === '') {
        return '';
    }

    try {
        $date = new DateTimeImmutable($value);
    } catch (Exception $e) {
        return '';
    }

    return $date->format($format);
}

function format_date_locale(?string $value): string
{
    return format_datetime($value, 'd/m/Y');
}

function format_datetime_locale(?string $value): string
{
    return format_datetime($value, 'd/m/Y H:i');
}

if (!function_exists('format_month_label')) {
    function format_month_label(DateTimeInterface $date): string
    {
        static $shortMonths = [
            '01' => 'Gen',
            '02' => 'Feb',
            '03' => 'Mar',
            '04' => 'Apr',
            '05' => 'Mag',
            '06' => 'Giu',
            '07' => 'Lug',
            '08' => 'Ago',
            '09' => 'Set',
            '10' => 'Ott',
            '11' => 'Nov',
            '12' => 'Dic',
        ];

        $monthKey = $date->format('m');
        $monthName = $shortMonths[$monthKey] ?? $date->format('M');

        return $monthName . ' ' . $date->format('Y');
    }
}

function format_user_display_name(?string $username, ?string $email = null, ?string $firstName = null, ?string $lastName = null): string
{
    $first = trim((string) ($firstName ?? ''));
    $last = trim((string) ($lastName ?? ''));
    if ($first !== '' || $last !== '') {
        $pieces = array_filter([$first, $last], static fn($part) => $part !== '');
        $full = implode(' ', $pieces);
        return mb_convert_case(mb_strtolower($full, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    $candidate = $username ?? '';
    if ($candidate === '' && $email) {
        $candidate = strstr($email, '@', true) ?: $email;
    }
    if ($candidate === '' && $email === null) {
        return 'Utente';
    }
    $candidate = preg_replace('/[._-]+/', ' ', $candidate);
    $candidate = trim($candidate);
    if ($candidate === '') {
        $candidate = $username ?? 'Utente';
    }
    $lower = mb_strtolower($candidate, 'UTF-8');
    return mb_convert_case($lower, MB_CASE_TITLE, 'UTF-8');
}

function current_user_display_name(): string
{
    $username = $_SESSION['username'] ?? '';
    $email = $_SESSION['email'] ?? null;
    $first = $_SESSION['first_name'] ?? null;
    $last = $_SESSION['last_name'] ?? null;
    $display = format_user_display_name($username, $email, $first, $last);
    $_SESSION['display_name'] = $display;
    return $display;
}

function csrf_token(): string
{
    if (!isset($_SESSION['__csrf']) || !is_string($_SESSION['__csrf']) || $_SESSION['__csrf'] === '') {
        $_SESSION['__csrf'] = bin2hex(random_bytes(32));
    }

    $token = $_SESSION['__csrf'];

    $cookieName = 'XSRF-TOKEN';
    $existingCookie = $_COOKIE[$cookieName] ?? null;
    if (!is_string($existingCookie) || $existingCookie !== $token) {
        $cookieParams = session_get_cookie_params();
        $cookieOptions = [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => $cookieParams['samesite'] ?? 'Lax',
        ];
        setcookie($cookieName, $token, $cookieOptions);
        $_COOKIE[$cookieName] = $token;
    }

    return $token;
}

function require_valid_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $providedToken = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_SERVER['HTTP_X_XSRF_TOKEN'] ?? ''));
    if (!is_string($providedToken)) {
        $providedToken = '';
    }

    $sessionToken = $_SESSION['__csrf'] ?? null;
    if (!is_string($sessionToken) || $sessionToken === '') {
        $cookieToken = $_COOKIE['XSRF-TOKEN'] ?? null;
        if (is_string($cookieToken) && $cookieToken !== '') {
            $_SESSION['__csrf'] = $cookieToken;
            $sessionToken = $cookieToken;
        }
    }

    if (!is_string($sessionToken) || $sessionToken === '' || $providedToken === '') {
        http_response_code(419);
        exit('Token CSRF non valido.');
    }

    if (!hash_equals($sessionToken, $providedToken)) {
        http_response_code(419);
        exit('Token CSRF non valido.');
    }
}

function add_flash(string $type, string $message): void
{
    $_SESSION['__flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    if (!array_key_exists('__flash_cache', $GLOBALS) || !is_array($GLOBALS['__flash_cache'])) {
        $GLOBALS['__flash_cache'] = $_SESSION['__flash'] ?? [];
        unset($_SESSION['__flash']);
        if (!is_array($GLOBALS['__flash_cache'])) {
            $GLOBALS['__flash_cache'] = [];
        }
    }

    return $GLOBALS['__flash_cache'];
}

function sanitize_filename(string $filename): string
{
    $clean = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    return $clean ?: bin2hex(random_bytes(8));
}

function current_user_can(string ...$roles): bool
{
    if (!isset($_SESSION['role'])) {
        return false;
    }

    if (!$roles) {
        return true;
    }

    return in_array($_SESSION['role'], $roles, true);
}

function current_user_has_capability(string ...$capabilities): bool
{
    if (!isset($_SESSION['role'])) {
        return false;
    }

    if (!$capabilities) {
        return true;
    }

    $role = $_SESSION['role'];

    return App\Auth\Authorization::roleAllows($role, ...$capabilities);
}

function require_capability(string ...$capabilities): void
{
    if (!current_user_has_capability(...$capabilities)) {
        header('Location: dashboard.php');
        exit;
    }
}

function request_ip(): string
{
    $headers = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];

    foreach ($headers as $header) {
        if (empty($_SERVER[$header])) {
            continue;
        }

        $value = $_SERVER[$header];
        if ($header === 'HTTP_X_FORWARDED_FOR') {
            $parts = explode(',', $value);
            $value = trim($parts[0] ?? '');
        }

        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return $value;
        }
    }

    return '0.0.0.0';
}

function request_user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'), 0, 500);
}

function build_user_session_payload(array $user): array
{
    return [
        'id' => (int) ($user['id'] ?? 0),
        'username' => (string) ($user['username'] ?? ''),
        'ruolo' => (string) ($user['ruolo'] ?? ''),
        'email' => (string) ($user['email'] ?? ''),
        'nome' => (string) ($user['nome'] ?? ''),
        'cognome' => (string) ($user['cognome'] ?? ''),
        'theme_preference' => (string) ($user['theme_preference'] ?? 'dark'),
    ];
}

function complete_user_login(
    \PDO $pdo,
    \App\Security\SecurityAuditLogger $auditLogger,
    array $userData,
    string $ipAddress,
    string $userAgent,
    bool $remember = false,
    ?string $note = null
): void {
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userData['id'];
    $_SESSION['username'] = $userData['username'];
    $_SESSION['role'] = $userData['ruolo'];
    $_SESSION['email'] = $userData['email'];
    $_SESSION['first_name'] = $userData['nome'];
    $_SESSION['last_name'] = $userData['cognome'];
    $_SESSION['display_name'] = format_user_display_name(
        $userData['username'],
        $userData['email'],
        $userData['nome'],
        $userData['cognome']
    );
    $_SESSION['theme_preference'] = $userData['theme_preference'] ?: 'dark';
    $_SESSION['mfa_verified_at'] = time();

    $_SESSION['login_attempts'] = 0;
    unset($_SESSION['login_locked_until'], $_SESSION['mfa_failed_attempts'], $_SESSION['mfa_challenge'], $_SESSION['mfa_setup']);

    if ($remember) {
        issue_remember_me_token($pdo, $userData['id']);
        $_SESSION['remember_me'] = true;
    } else {
        revoke_remember_me_tokens($pdo, $userData['id']);
        unset($_SESSION['remember_me']);
    }

    $stmt = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $userData['id']]);

    $auditLogger->logLoginAttempt(
        $userData['id'],
        $userData['username'],
        true,
        $ipAddress,
        $userAgent,
        $note
    );
}

function remember_cookie_name(): string
{
    return 'coresuite_remember';
}

function remember_cookie_lifetime(): int
{
    return 60 * 60 * 24 * 30;
}

function remember_cookie_options(int $expires): array
{
    $params = session_get_cookie_params();
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    return [
        'expires' => $expires,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ];
}

function purge_expired_remember_tokens(\PDO $pdo): void
{
    try {
        $pdo->exec('DELETE FROM remember_tokens WHERE expires_at < NOW()');
    } catch (Throwable $exception) {
        error_log('Unable to purge remember tokens: ' . $exception->getMessage());
    }
}

function issue_remember_me_token(\PDO $pdo, int $userId): void
{
    purge_expired_remember_tokens($pdo);

    try {
        $delete = $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $delete->execute([':user_id' => $userId]);

        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $hash = hash('sha256', $validator);
        $expiresAt = (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s');

        $insert = $pdo->prepare(
            'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at, created_at, last_used_at)
             VALUES (:user_id, :selector, :token_hash, :expires_at, NOW(), NOW())'
        );
        $insert->execute([
            ':user_id' => $userId,
            ':selector' => $selector,
            ':token_hash' => $hash,
            ':expires_at' => $expiresAt,
        ]);

        $cookieValue = $selector . ':' . $validator;
        $options = remember_cookie_options(time() + remember_cookie_lifetime());
        setcookie(remember_cookie_name(), $cookieValue, $options);
        $_COOKIE[remember_cookie_name()] = $cookieValue;
    } catch (Throwable $exception) {
        error_log('Unable to issue remember token: ' . $exception->getMessage());
        forget_remember_me_cookie();
    }
}

function forget_remember_me_cookie(): void
{
    $options = remember_cookie_options(time() - 3600);
    setcookie(remember_cookie_name(), '', $options);
    unset($_COOKIE[remember_cookie_name()]);
}

function revoke_remember_me_tokens(\PDO $pdo, int $userId): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :user_id');
        $stmt->execute([':user_id' => $userId]);
    } catch (Throwable $exception) {
        error_log('Unable to revoke remember tokens: ' . $exception->getMessage());
    }
    forget_remember_me_cookie();
}

function revoke_current_remember_token(\PDO $pdo): void
{
    $cookie = $_COOKIE[remember_cookie_name()] ?? '';
    if ($cookie === '') {
        return;
    }

    $parts = explode(':', $cookie, 2);
    $selector = $parts[0] ?? '';

    if ($selector !== '') {
        try {
            $stmt = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
            $stmt->execute([':selector' => $selector]);
        } catch (Throwable $exception) {
            error_log('Unable to revoke remember token: ' . $exception->getMessage());
        }
    }

    forget_remember_me_cookie();
}

function attempt_remembered_login(\PDO $pdo, \App\Security\SecurityAuditLogger $auditLogger): bool
{
    if (isset($_SESSION['user_id'])) {
        return true;
    }

    $cookie = $_COOKIE[remember_cookie_name()] ?? '';
    if ($cookie === '') {
        return false;
    }

    $parts = explode(':', $cookie, 2);
    if (count($parts) !== 2) {
        forget_remember_me_cookie();
        return false;
    }

    [$selector, $validator] = $parts;
    if (!ctype_xdigit($selector) || !ctype_xdigit($validator)) {
        forget_remember_me_cookie();
        return false;
    }

    purge_expired_remember_tokens($pdo);

    try {
        $stmt = $pdo->prepare(
            'SELECT rt.user_id, rt.token_hash, rt.expires_at, u.id AS uid, u.username, u.email, u.nome, u.cognome, u.ruolo, u.theme_preference
             FROM remember_tokens rt
             INNER JOIN users u ON u.id = rt.user_id
             WHERE rt.selector = :selector
             LIMIT 1'
        );
        $stmt->execute([':selector' => $selector]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('Unable to validate remember token: ' . $exception->getMessage());
        forget_remember_me_cookie();
        return false;
    }

    if (!$record) {
        forget_remember_me_cookie();
        return false;
    }

    $ip = request_ip();
    $userAgent = request_user_agent();

    if (strtotime($record['expires_at']) < time()) {
        $auditLogger->logLoginAttempt((int) $record['user_id'], $record['username'], false, $ip, $userAgent, 'remember_token_expired');
        revoke_current_remember_token($pdo);
        return false;
    }

    $expectedHash = $record['token_hash'];
    $providedHash = hash('sha256', $validator);

    if (!hash_equals($expectedHash, $providedHash)) {
        $auditLogger->logLoginAttempt((int) $record['user_id'], $record['username'], false, $ip, $userAgent, 'remember_token_mismatch');
        revoke_current_remember_token($pdo);
        return false;
    }

    $delete = $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :selector');
    $delete->execute([':selector' => $selector]);

    $userData = [
        'id' => (int) $record['uid'],
        'username' => (string) $record['username'],
        'email' => (string) $record['email'],
        'nome' => (string) $record['nome'],
        'cognome' => (string) $record['cognome'],
        'ruolo' => (string) $record['ruolo'],
        'theme_preference' => (string) $record['theme_preference'],
    ];

    complete_user_login($pdo, $auditLogger, $userData, $ip, $userAgent, true, 'remember_token');

    return true;
}
