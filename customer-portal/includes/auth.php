<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mailer.php';

/**
 * Classe per la gestione dell'autenticazione dei clienti del portale
 */
class CustomerAuth {
    
    /**
     * Registra o aggiorna un cliente
     */
    public static function registerOrUpdateCustomer(array $data): array {
        $email = trim(strtolower($data['email'] ?? ''));
        $phone = trim($data['phone'] ?? '');
        $name = trim($data['name'] ?? '');
        
        if (empty($email) && empty($phone)) {
            throw new Exception('Email o telefono richiesti');
        }
        
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email non valida');
        }
        
        if (!empty($phone) && !preg_match('/^\+?[1-9]\d{1,14}$/', $phone)) {
            throw new Exception('Numero di telefono non valido');
        }
        
        $existingCustomer = null;
        
        // Cerca cliente esistente per email o telefono
        if (!empty($email)) {
            $existingCustomer = portal_fetch_one(
                'SELECT * FROM pickup_customers WHERE email = ?',
                [$email]
            );
        }
        
        if (!$existingCustomer && !empty($phone)) {
            $existingCustomer = portal_fetch_one(
                'SELECT * FROM pickup_customers WHERE phone = ?',
                [$phone]
            );
        }
        
        $now = date('Y-m-d H:i:s');
        
        if ($existingCustomer) {
            // Aggiorna cliente esistente
            $updateData = [
                'last_login_attempt' => $now,
                'updated_at' => $now
            ];
            
            if (!empty($email) && $existingCustomer['email'] !== $email) {
                $updateData['email'] = $email;
            }
            
            if (!empty($phone) && $existingCustomer['phone'] !== $phone) {
                $updateData['phone'] = $phone;
            }
            
            if (!empty($name) && $existingCustomer['name'] !== $name) {
                $updateData['name'] = $name;
            }
            
            portal_update('pickup_customers', $updateData, ['id' => $existingCustomer['id']]);
            
            $customerId = $existingCustomer['id'];
        } else {
            // Crea nuovo cliente
            $customerData = [
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'name' => $name ?: null,
                'status' => 'active',
                'last_login_attempt' => $now,
                'created_at' => $now,
                'updated_at' => $now
            ];
            
            $customerId = portal_insert('pickup_customers', $customerData);
        }
        
        return portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);
    }
    
    /**
     * Genera e invia OTP per l'autenticazione
     */
    public static function generateAndSendOtp(int $customerId, string $method = 'email'): array {
        $customer = portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);
        
        if (!$customer) {
            throw new Exception('Cliente non trovato');
        }
        
        $requestedMethod = strtolower($method);
        if ($requestedMethod !== 'email') {
            portal_debug_log('Unsupported OTP delivery method requested. Falling back to email.', [
                'customer_id' => $customerId,
                'requested_method' => $requestedMethod,
            ]);
        }

        $method = 'email';
        
        // Controlla tentativi di login
        if (self::isCustomerLocked($customerId)) {
            throw new Exception('Account temporaneamente bloccato per troppi tentativi falliti');
        }
        
        // Genera OTP
        $otp = str_pad((string) random_int(0, 999999), portal_config('otp_length'), '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + portal_config('otp_validity'));
        
        // Salva OTP nel database
        $otpData = [
            'customer_id' => $customerId,
            'otp_code' => self::hashOtp($otp),
            'delivery_method' => $method,
            'expires_at' => $expiresAt,
            'used' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        portal_insert('pickup_customer_otps', $otpData);
        
        // Invia OTP
        $sent = false;
        $destination = '';
        
        try {
            if (!empty($customer['email'])) {
                $sent = self::sendOtpByEmail($customer['email'], $customer['name'] ?? '', $otp);
                $destination = $customer['email'];
            }
            
            if (!$sent) {
                throw new Exception('Impossibile inviare OTP');
            }
            
            portal_info_log('OTP generated and sent', [
                'customer_id' => $customerId,
                'method' => $method,
                'destination' => self::maskDestination($destination, $method)
            ]);
            
            return [
                'success' => true,
                'method' => $method,
                'destination' => self::maskDestination($destination, $method),
                'expires_in' => portal_config('otp_validity')
            ];
            
        } catch (Exception $e) {
            portal_error_log('Failed to send OTP: ' . $e->getMessage(), [
                'customer_id' => $customerId,
                'method' => $method
            ]);
            throw new Exception('Errore durante l\'invio dell\'OTP');
        }
    }
    
    /**
     * Verifica OTP e effettua il login
     */
    public static function verifyOtpAndLogin(int $customerId, string $otp, bool $rememberLogin = false): array {
        if (self::isCustomerLocked($customerId)) {
            throw new Exception('Account temporaneamente bloccato');
        }
        
        // Trova OTP valido non utilizzato
        $validOtp = portal_fetch_one(
            'SELECT * FROM pickup_customer_otps 
             WHERE customer_id = ? AND used = 0 AND expires_at > NOW() 
             ORDER BY created_at DESC LIMIT 1',
            [$customerId]
        );
        
        if (!$validOtp) {
            self::recordFailedAttempt($customerId);
            throw new Exception('OTP non valido o scaduto');
        }
        
        // Verifica OTP
        if (!password_verify($otp, $validOtp['otp_code'])) {
            self::recordFailedAttempt($customerId);
            throw new Exception('OTP non corretto');
        }
        
        // Marca OTP come utilizzato
        portal_update('pickup_customer_otps', 
            ['used' => 1, 'used_at' => date('Y-m-d H:i:s')],
            ['id' => $validOtp['id']]
        );
        
        // Reset tentativi falliti
        portal_update('pickup_customers',
            ['failed_login_attempts' => 0, 'last_login' => date('Y-m-d H:i:s')],
            ['id' => $customerId]
        );
        
        // Crea sessione
    $sessionData = self::createSession($customerId, $rememberLogin);
        
        portal_info_log('Customer logged in successfully', ['customer_id' => $customerId]);
        
        return $sessionData;
    }
    
    /**
     * Crea sessione per il cliente
     */
    private static function createSession(int $customerId, bool $rememberLogin = false, ?string $existingTokenHash = null, ?string $rawToken = null): array {
        $customer = portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);

        if (!$customer) {
            throw new Exception('Cliente non trovato');
        }

        session_regenerate_id(true);

        $_SESSION['customer_authenticated'] = true;
        $_SESSION['customer_id'] = $customerId;
        $_SESSION['customer_email'] = $customer['email'];
        $_SESSION['customer_name'] = $customer['name'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['remember_login'] = $rememberLogin;

        if ($rememberLogin) {
            $tokenHash = self::ensureRememberSession($customerId, $existingTokenHash, $rawToken);
            $_SESSION['remember_token_hash'] = $tokenHash;
        } else {
            unset($_SESSION['remember_token_hash']);
            self::clearRememberCookie($existingTokenHash);
        }

        return [
            'customer_id' => $customerId,
            'email' => $customer['email'],
            'name' => $customer['name'],
            'phone' => $customer['phone']
        ];
    }
    
    /**
     * Verifica se il cliente è autenticato
     */
    public static function isAuthenticated(): bool {
        if (!isset($_SESSION['customer_authenticated']) || !$_SESSION['customer_authenticated']) {
            return self::attemptRememberLogin();
        }

        $_SESSION['last_activity'] = time();
        return true;
    }
    
    /**
     * Ottiene i dati del cliente autenticato
     */
    public static function getAuthenticatedCustomer(): ?array {
        if (!self::isAuthenticated()) {
            return null;
        }
        
        $customerId = $_SESSION['customer_id'] ?? 0;
        return portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);
    }
    
    /**
     * Effettua il logout
     */
    public static function logout(): void {
        $customerId = $_SESSION['customer_id'] ?? null;
        $rememberTokenHash = $_SESSION['remember_token_hash'] ?? null;
        
        session_unset();
        session_destroy();

        self::clearRememberCookie($rememberTokenHash);
        
        if ($customerId) {
            portal_info_log('Customer logged out', ['customer_id' => $customerId]);
        }
    }

    /**
     * Prova a ripristinare l'autenticazione da cookie persistente
     */
    private static function attemptRememberLogin(): bool {
        $cookieName = self::rememberCookieName();
        $cookieValue = $_COOKIE[$cookieName] ?? null;

        if ($cookieValue === null) {
            return false;
        }

        $decoded = self::decodeRememberCookie($cookieValue);
        if ($decoded === null) {
            self::clearRememberCookie();
            return false;
        }

        $customerId = (int) $decoded['customer_id'];
        $token = $decoded['token'];
        $tokenHash = hash('sha256', $token);

        try {
            $session = portal_fetch_one(
                'SELECT * FROM pickup_customer_sessions WHERE id = ? AND expires_at > ?',
                [$tokenHash, date('Y-m-d H:i:s')]
            );
        } catch (Exception $e) {
            portal_error_log('Persistent session lookup failed', [
                'token_hash' => $tokenHash,
                'error' => $e->getMessage()
            ]);
            self::clearRememberCookie($tokenHash);
            return false;
        }

        if (!$session || (int) $session['customer_id'] !== $customerId) {
            self::clearRememberCookie($tokenHash);
            return false;
        }

        try {
            self::createSession($customerId, true, $tokenHash, $token);
            portal_update('pickup_customers', [
                'last_login' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $customerId]);
        } catch (Exception $e) {
            portal_error_log('Failed to restore persistent session', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            self::clearRememberCookie($tokenHash);
            return false;
        }

        return true;
    }

    private static function ensureRememberSession(int $customerId, ?string $existingTokenHash, ?string $rawToken): string {
        if ($existingTokenHash !== null) {
            self::refreshRememberSession($existingTokenHash, $customerId, $rawToken);
            return $existingTokenHash;
        }

        return self::createRememberSession($customerId);
    }

    private static function createRememberSession(int $customerId): string {
        $lifetime = self::getRememberLifetime();
        $now = date('Y-m-d H:i:s');
        $expiresTs = time() + $lifetime;
        $expiresAt = date('Y-m-d H:i:s', $expiresTs);

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            portal_error_log('Failed to generate remember-me token', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Impossibile mantenere la sessione attiva in questo momento. Riprovare più tardi.');
        }
        $tokenHash = hash('sha256', $token);

        try {
            portal_query(
                'INSERT INTO pickup_customer_sessions (id, customer_id, ip_address, user_agent, created_at, last_activity, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $tokenHash,
                    $customerId,
                    self::resolveClientIp(),
                    self::resolveUserAgent(),
                    $now,
                    $now,
                    $expiresAt
                ]
            );
        } catch (Exception $e) {
            portal_error_log('Failed to persist remember-me session', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            throw new Exception('Impossibile mantenere la sessione attiva in questo momento. Riprovare più tardi.');
        }

        self::setRememberCookie($customerId, $token, $expiresTs);

        return $tokenHash;
    }

    private static function refreshRememberSession(string $tokenHash, int $customerId, ?string $rawToken): void {
        $lifetime = self::getRememberLifetime();
        $expiresTs = time() + $lifetime;
        $expiresAt = date('Y-m-d H:i:s', $expiresTs);
        $now = date('Y-m-d H:i:s');

        try {
            portal_query(
                'UPDATE pickup_customer_sessions SET last_activity = ?, expires_at = ?, ip_address = ?, user_agent = ? WHERE id = ?',
                [
                    $now,
                    $expiresAt,
                    self::resolveClientIp(),
                    self::resolveUserAgent(),
                    $tokenHash
                ]
            );
        } catch (Exception $e) {
            portal_error_log('Failed to refresh persistent session', [
                'token_hash' => $tokenHash,
                'error' => $e->getMessage()
            ]);
        }

        if ($rawToken !== null) {
            self::setRememberCookie($customerId, $rawToken, $expiresTs);
        }
    }

    private static function clearRememberCookie(?string $tokenHash = null): void {
        $cookieName = self::rememberCookieName();
        $cookieValue = $_COOKIE[$cookieName] ?? null;

        if ($tokenHash === null && $cookieValue !== null) {
            $decoded = self::decodeRememberCookie($cookieValue);
            if ($decoded !== null) {
                $tokenHash = hash('sha256', $decoded['token']);
            }
        }

        if ($tokenHash !== null) {
            try {
                portal_query('DELETE FROM pickup_customer_sessions WHERE id = ?', [$tokenHash]);
            } catch (Exception $e) {
                portal_error_log('Failed to delete persistent session', [
                    'token_hash' => $tokenHash,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if ($cookieValue !== null) {
            setcookie($cookieName, '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => self::isSecureCookie(),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            unset($_COOKIE[$cookieName]);
        }
    }

    private static function setRememberCookie(int $customerId, string $token, int $expiresTs): void {
        $cookieName = self::rememberCookieName();
        $value = base64_encode($customerId . ':' . $token);

        setcookie($cookieName, $value, [
            'expires' => $expiresTs,
            'path' => '/',
            'secure' => self::isSecureCookie(),
            'httponly' => true,
            'samesite' => 'Strict'
        ]);

        $_COOKIE[$cookieName] = $value;
    }

    private static function rememberCookieName(): string {
        return portal_config('remember_cookie_name', REMEMBER_ME_COOKIE_NAME);
    }

    private static function decodeRememberCookie(string $value): ?array {
        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return null;
        }

        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return null;
        }

        $customerId = filter_var($parts[0], FILTER_VALIDATE_INT);
        if ($customerId === false) {
            return null;
        }

        return [
            'customer_id' => (int) $customerId,
            'token' => $parts[1]
        ];
    }

    private static function getRememberLifetime(): int {
        $lifetime = (int) portal_config('remember_me_lifetime', REMEMBER_ME_LIFETIME);
        return $lifetime > 0 ? $lifetime : REMEMBER_ME_LIFETIME;
    }

    private static function isSecureCookie(): bool {
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

        return $httpsOn;
    }

    private static function resolveClientIp(): string {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    private static function resolveUserAgent(): string {
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        return substr($userAgent, 0, 500);
    }
    
    /**
     * Controlla se il cliente è bloccato per troppi tentativi
     */
    private static function isCustomerLocked(int $customerId): bool {
        $customer = portal_fetch_one(
            'SELECT failed_login_attempts, locked_until FROM pickup_customers WHERE id = ?',
            [$customerId]
        );
        
        if (!$customer) {
            return false;
        }
        
        if ($customer['locked_until'] && strtotime($customer['locked_until']) > time()) {
            return true;
        }
        
        return $customer['failed_login_attempts'] >= portal_config('max_login_attempts');
    }
    
    /**
     * Registra tentativo di login fallito
     */
    private static function recordFailedAttempt(int $customerId): void {
        $attempts = portal_fetch_value(
            'SELECT failed_login_attempts FROM pickup_customers WHERE id = ?',
            [$customerId]
        ) ?: 0;
        
        $attempts++;
        $updateData = ['failed_login_attempts' => $attempts];
        
        // Se raggiunto il limite, blocca account
        if ($attempts >= portal_config('max_login_attempts')) {
            $lockUntil = date('Y-m-d H:i:s', time() + portal_config('lockout_time'));
            $updateData['locked_until'] = $lockUntil;
        }
        
        portal_update('pickup_customers', $updateData, ['id' => $customerId]);
    }

    /**
     * Genera hash sicuro per l'OTP con fallback automatico
     */
    private static function hashOtp(string $otp): string {
        $availableAlgorithms = function_exists('password_algos') ? password_algos() : [];
        $preferred = [];

        if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', $availableAlgorithms, true)) {
            $preferred[] = PASSWORD_ARGON2ID;
        }

        if (defined('PASSWORD_ARGON2I') && in_array('argon2i', $availableAlgorithms, true)) {
            $preferred[] = PASSWORD_ARGON2I;
        }

        $preferred[] = PASSWORD_DEFAULT;

        foreach ($preferred as $index => $algorithm) {
            try {
                $hash = password_hash($otp, $algorithm);
                if ($hash !== false) {
                    if ($index > 0) {
                        portal_info_log('OTP hashing fallback in use', [
                            'algorithm' => self::describePasswordAlgo($algorithm)
                        ]);
                    }
                    return $hash;
                }
            } catch (\Throwable $exception) {
                portal_error_log('OTP hashing failed', [
                    'algorithm' => self::describePasswordAlgo($algorithm),
                    'error' => $exception->getMessage()
                ]);
            }
        }

        throw new Exception('Impossibile generare il codice di sicurezza. Riprovare più tardi.');
    }

    private static function describePasswordAlgo($algorithm): string {
        if (defined('PASSWORD_ARGON2ID') && $algorithm === PASSWORD_ARGON2ID) {
            return 'argon2id';
        }

        if (defined('PASSWORD_ARGON2I') && $algorithm === PASSWORD_ARGON2I) {
            return 'argon2i';
        }

        if ($algorithm === PASSWORD_DEFAULT) {
            return 'default';
        }

        return is_string($algorithm) ? $algorithm : (string) $algorithm;
    }
    
    /**
     * Invia OTP via email
     */
    private static function sendOtpByEmail(string $email, string $name, string $otp): bool {
        if (!portal_config('enable_email')) {
            return false;
        }
        
        $subject = 'Codice di accesso - ' . portal_config('portal_name');
        $body = "Ciao " . ($name ?: 'Cliente') . ",\n\n";
        $body .= "Il tuo codice di accesso è: {$otp}\n\n";
        $body .= "Il codice è valido per " . (portal_config('otp_validity') / 60) . " minuti.\n\n";
        $body .= "Se non hai richiesto questo codice, ignora questa email.\n\n";
        $body .= "Grazie,\nIl team di " . portal_config('portal_name');
        
        try {
            return send_system_mail($email, $subject, $body);
        } catch (Exception $e) {
            portal_error_log('Email OTP sending failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Maschera la destinazione per privacy
     */
    private static function maskDestination(string $destination, string $method): string {
        if ($method === 'email') {
            $parts = explode('@', $destination);
            if (count($parts) === 2) {
                $localPart = $parts[0];
                $domain = $parts[1];
                $maskedLocal = substr($localPart, 0, 2) . str_repeat('*', max(0, strlen($localPart) - 4)) . substr($localPart, -2);
                return $maskedLocal . '@' . $domain;
            }
        }
        
        return $destination;
    }
    
    /**
     * Pulizia OTP scaduti
     */
    public static function cleanupExpiredOtps(): int {
        $stmt = portal_query('DELETE FROM pickup_customer_otps WHERE expires_at < NOW()');
        $deletedCount = $stmt->rowCount();
        
        if ($deletedCount > 0) {
            portal_info_log('Cleaned up expired OTPs', ['count' => $deletedCount]);
        }
        
        return $deletedCount;
    }
}