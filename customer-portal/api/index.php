<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

// Router API semplice
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$pathInfo = $_SERVER['PATH_INFO'] ?? '';

// Estrai il percorso API gestendo eventuali sottocartelle (es. /customer-portal/api/...)
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '';
$apiPosition = strpos($requestPath, '/api/');
$apiPath = $apiPosition !== false
    ? substr($requestPath, $apiPosition + strlen('/api/'))
    : ltrim($pathInfo, '/');
$apiPath = ltrim($apiPath, '/');

// Headers di risposta JSON
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS per sviluppo (rimuovere in produzione)
if (env('APP_DEBUG', false)) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
}

// Gestisci preflight OPTIONS
if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Rate limiting semplice
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
$rateLimitKey = 'rate_limit_' . md5($clientIp . date('Y-m-d-H-i'));

try {
    require_once __DIR__ . '/../includes/database.php';
    
    // Controlla rate limit
    $currentRequests = (int) portal_fetch_value(
        'SELECT request_count FROM pickup_api_rate_limits 
         WHERE identifier = ? AND window_start >= ?',
        [$clientIp, date('Y-m-d H:i:s', time() - portal_config('api_rate_window'))]
    );
    
    if ($currentRequests >= portal_config('api_rate_limit')) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded']);
        exit;
    }
    
    // Aggiorna contatore rate limit
    $windowStart = date('Y-m-d H:i:s', (int) (floor(time() / portal_config('api_rate_window')) * portal_config('api_rate_window')));
    
    portal_query(
        'INSERT INTO pickup_api_rate_limits (identifier, endpoint, request_count, window_start, created_at) 
         VALUES (?, ?, 1, ?, NOW()) 
         ON DUPLICATE KEY UPDATE request_count = request_count + 1',
        [$clientIp, $apiPath, $windowStart]
    );
    
} catch (Exception $e) {
    // In caso di errore con rate limiting, continua comunque
    portal_error_log('Rate limiting error: ' . $e->getMessage());
}

// Routing delle API
try {
    switch ($apiPath) {
        case 'auth/login.php':
        case 'auth/login':
            require_once __DIR__ . '/auth/login.php';
            break;
            
        case 'auth/verify-otp.php':
        case 'auth/verify-otp':
            require_once __DIR__ . '/auth/verify-otp.php';
            break;
            
        case 'auth/resend-otp.php':
        case 'auth/resend-otp':
            require_once __DIR__ . '/auth/resend-otp.php';
            break;
            
        case 'stats.php':
        case 'stats':
            require_once __DIR__ . '/stats.php';
            break;
            
        case 'notifications.php':
        case 'notifications':
            require_once __DIR__ . '/notifications.php';
            break;
            
        case 'packages.php':
        case 'packages':
            require_once __DIR__ . '/packages.php';
            break;
            
        case 'reports.php':
        case 'reports':
            require_once __DIR__ . '/reports.php';
            break;
            
        case 'brt/shipments.php':
        case 'brt/shipments':
            require_once __DIR__ . '/brt/shipments.php';
            break;

        case 'brt/shipment.php':
        case 'brt/shipment':
            require_once __DIR__ . '/brt/shipment.php';
            break;

        case 'brt/label.php':
        case 'brt/label':
            require_once __DIR__ . '/brt/label.php';
            break;

        case 'brt/pudos.php':
        case 'brt/pudos':
            require_once __DIR__ . '/brt/pudos.php';
            break;

        case 'payments/status.php':
        case 'payments/status':
            require_once __DIR__ . '/payments/status.php';
            break;

        case 'payments/stripe-webhook.php':
        case 'payments/stripe-webhook':
            require_once __DIR__ . '/payments/stripe-webhook.php';
            break;

        default:
            http_response_code(404);
            echo json_encode([
                'error' => 'Endpoint non trovato',
                'available_endpoints' => [
                    'auth/login',
                    'auth/verify-otp',
                    'auth/resend-otp',
                    'stats',
                    'notifications',
                    'packages',
                    'reports',
                    'brt/shipments',
                    'brt/shipment',
                    'brt/label',
                    'brt/pudos',
                    'payments/status',
                    'payments/stripe-webhook'
                ]
            ]);
            break;
    }
    
} catch (Exception $e) {
    portal_error_log('API Error: ' . $e->getMessage(), [
        'endpoint' => $apiPath,
        'method' => $requestMethod,
        'ip' => $clientIp
    ]);
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Errore interno del server',
        'message' => env('APP_DEBUG', false) ? $e->getMessage() : 'Si è verificato un errore'
    ]);
}

/**
 * Funzioni helper per le API
 */
function api_response(bool $success, $data = null, string $message = '', int $httpCode = 200): void {
    http_response_code($httpCode);
    
    $response = ['success' => $success];
    
    if ($message) {
        $response['message'] = $message;
    }
    
    if ($data !== null) {
        if (is_array($data)) {
            $response = array_merge($response, $data);
        } else {
            $response['data'] = $data;
        }
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $httpCode = 400, array $details = []): void {
    $response = [
        'success' => false,
        'error' => $message
    ];
    
    if (!empty($details)) {
        $response['details'] = $details;
    }
    
    api_response(false, $response, '', $httpCode);
}

function api_success($data = null, string $message = ''): void {
    api_response(true, $data, $message);
}

function require_authentication(): array {
    require_once __DIR__ . '/../includes/auth.php';
    
    if (!CustomerAuth::isAuthenticated()) {
        api_error('Autenticazione richiesta', 401);
    }
    
    $customer = CustomerAuth::getAuthenticatedCustomer();
    if (!$customer) {
        api_error('Sessione non valida', 401);
    }
    
    return $customer;
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($method)) {
        api_error('Metodo non consentito', 405);
    }
}

function get_json_input(): array {
    $input = file_get_contents('php://input');
    
    if (empty($input)) {
        return [];
    }
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        api_error('JSON non valido: ' . json_last_error_msg(), 400);
    }
    
    return $data ?? [];
}

function validate_csrf_token(array $data): void {
    if (!isset($data['csrf_token']) || !verify_csrf_token($data['csrf_token'])) {
        api_error('Token CSRF non valido', 403);
    }
}