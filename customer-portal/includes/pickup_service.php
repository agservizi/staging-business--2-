<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

/**
 * Servizio per la gestione dei pacchi e integrazione con il sistema pickup
 */
class PickupService {
    private array $tableExistsCache = [];
    private ?array $pickupTableConfig = null;
    private bool $orphanCleanupRan = false;
    
    private function hasTable(string $tableName): bool {
        if (isset($this->tableExistsCache[$tableName])) {
            return $this->tableExistsCache[$tableName];
        }
        try {
            $exists = (int) portal_fetch_value(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$tableName]
            ) > 0;
            $this->tableExistsCache[$tableName] = $exists;
            return $exists;
        } catch (Exception $exception) {
            portal_error_log('Unable to determine table existence', [
                'table' => $tableName,
                'error' => $exception->getMessage()
            ]);
            $this->tableExistsCache[$tableName] = false;
            return false;
        }
    }

    private function getPickupTableConfig(): ?array {
        if ($this->pickupTableConfig !== null) {
            return $this->pickupTableConfig;
        }

        if ($this->hasTable('pickup_packages')) {
            $this->pickupTableConfig = [
                'name' => 'pickup_packages',
                'tracking_column' => 'tracking',
                'note_column' => 'notes',
                'created_column' => 'created_at',
                'updated_column' => 'updated_at',
                'delivered_expression' => "CASE WHEN p.status = 'ritirato' THEN p.updated_at ELSE NULL END",
                'supports_media' => true,
                'supports_otp_column' => false,
            ];
        } elseif ($this->hasTable('pickup')) {
            $this->pickupTableConfig = [
                'name' => 'pickup',
                'tracking_column' => 'tracking_number',
                'note_column' => 'customer_note',
                'created_column' => 'created_at',
                'updated_column' => 'updated_at',
                'delivered_expression' => 'p.delivered_at',
                'supports_media' => false,
                'supports_otp_column' => true,
            ];
        } else {
            $this->pickupTableConfig = null;
        }

        return $this->pickupTableConfig;
    }
    
    /**
     * Ottiene le statistiche del cliente
     */
    public function getCustomerStats(int $customerId): array {
        $stats = [];
        
        // Pacchi in attesa (segnalati ma non ancora arrivati)
        $stats['pending_packages'] = portal_count(
            'SELECT COUNT(*) FROM pickup_customer_reports WHERE customer_id = ? AND status = ?',
            [$customerId, 'reported']
        );
        
        $pickupConfig = $this->getPickupTableConfig();

        // Pacchi pronti per il ritiro (arrivati)
        if ($pickupConfig) {
            $table = $pickupConfig['name'];
            $stats['ready_packages'] = portal_count(
                "SELECT COUNT(*) FROM pickup_customer_reports r 
                 LEFT JOIN {$table} p ON r.pickup_id = p.id 
                 WHERE r.customer_id = ? AND (p.status = ? OR p.status = ?)",
                [$customerId, 'consegnato', 'in_giacenza']
            );
        } else {
            $stats['ready_packages'] = 0;
        }

        // Pacchi ritirati questo mese
        if ($pickupConfig) {
            $table = $pickupConfig['name'];
            $updatedColumn = $pickupConfig['updated_column'];
            $stats['monthly_delivered'] = portal_count(
                "SELECT COUNT(*) FROM pickup_customer_reports r 
                 LEFT JOIN {$table} p ON r.pickup_id = p.id 
                 WHERE r.customer_id = ? AND p.status = ? AND p.{$updatedColumn} >= ?",
                [$customerId, 'ritirato', date('Y-m-01')]
            );
        } else {
            $stats['monthly_delivered'] = 0;
        }
        
        // Totale pacchi
        $stats['total_packages'] = portal_count(
            'SELECT COUNT(*) FROM pickup_customer_reports WHERE customer_id = ?',
            [$customerId]
        );
        
        return $stats;
    }

    public function getPackageStatusCounts(int $customerId): array {
        $counts = [
            'all' => 0,
            'reported' => 0,
            'confirmed' => 0,
            'arrived' => 0,
            'cancelled' => 0,
            'in_arrivo' => 0,
            'consegnato' => 0,
            'in_giacenza' => 0,
            'in_giacenza_scaduto' => 0,
            'ritirato' => 0,
            'ready' => 0,
        ];

        $pickupConfig = $this->getPickupTableConfig();

        if ($pickupConfig) {
            $table = $pickupConfig['name'];
            $row = portal_fetch_one(
                <<<SQL
SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN r.status = 'reported' THEN 1 ELSE 0 END) AS reported,
    SUM(CASE WHEN r.status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
    SUM(CASE WHEN r.status = 'arrived' THEN 1 ELSE 0 END) AS arrived,
    SUM(CASE WHEN r.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
    SUM(CASE WHEN p.status = 'in_arrivo' THEN 1 ELSE 0 END) AS in_arrivo,
    SUM(CASE WHEN p.status = 'consegnato' THEN 1 ELSE 0 END) AS consegnato,
    SUM(CASE WHEN p.status = 'in_giacenza' THEN 1 ELSE 0 END) AS in_giacenza,
    SUM(CASE WHEN p.status = 'in_giacenza_scaduto' THEN 1 ELSE 0 END) AS in_giacenza_scaduto,
    SUM(CASE WHEN p.status = 'ritirato' THEN 1 ELSE 0 END) AS ritirato
FROM pickup_customer_reports r
LEFT JOIN {$table} p ON r.pickup_id = p.id
WHERE r.customer_id = ?
SQL,
                [$customerId]
            );

            if ($row) {
                foreach ($counts as $key => $value) {
                    if (isset($row[$key])) {
                        $counts[$key] = (int) $row[$key];
                    }
                }

                $counts['all'] = (int) ($row['total'] ?? 0);
            }
        } else {
            $row = portal_fetch_one(
                <<<SQL
SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'reported' THEN 1 ELSE 0 END) AS reported,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
    SUM(CASE WHEN status = 'arrived' THEN 1 ELSE 0 END) AS arrived,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled
FROM pickup_customer_reports
WHERE customer_id = ?
SQL,
                [$customerId]
            );

            if ($row) {
                foreach (['reported', 'confirmed', 'arrived', 'cancelled'] as $key) {
                    $counts[$key] = (int) ($row[$key] ?? 0);
                }
                $counts['all'] = (int) ($row['total'] ?? 0);
            }
        }

        $counts['ready'] = $counts['consegnato'] + $counts['in_giacenza'];

        return $counts;
    }

    public function getCustomerSummary(int $customerId): array {
        $summary = portal_fetch_one(
            'SELECT * FROM pickup_customer_summary WHERE id = ?',
            [$customerId]
        );

        if (!$summary) {
            $customer = portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);
            if (!$customer) {
                throw new Exception('Cliente non trovato');
            }

            $preferences = $this->getCustomerPreferences($customerId);

            $summary = array_merge($customer, [
                'notification_email' => $preferences['notification_email'],
                'notification_sms' => $preferences['notification_sms'],
                'notification_whatsapp' => $preferences['notification_whatsapp'],
                'language' => $preferences['language'],
                'timezone' => $preferences['timezone'],
                'total_reports' => portal_count(
                    'SELECT COUNT(*) FROM pickup_customer_reports WHERE customer_id = ?',
                    [$customerId]
                ),
                'pending_reports' => portal_count(
                    "SELECT COUNT(*) FROM pickup_customer_reports WHERE customer_id = ? AND status = 'reported'",
                    [$customerId]
                ),
                'total_notifications' => portal_count(
                    'SELECT COUNT(*) FROM pickup_customer_notifications WHERE customer_id = ?',
                    [$customerId]
                ),
                'unread_notifications' => portal_count(
                    'SELECT COUNT(*) FROM pickup_customer_notifications WHERE customer_id = ? AND read_at IS NULL',
                    [$customerId]
                )
            ]);
        }

        return $summary;
    }

    public function getCustomerPreferences(int $customerId): array {
        $preferences = portal_fetch_one(
            'SELECT * FROM pickup_customer_preferences WHERE customer_id = ?',
            [$customerId]
        );

        if (!$preferences) {
            $now = date('Y-m-d H:i:s');
            $defaults = [
                'customer_id' => $customerId,
                'notification_email' => 1,
                'notification_sms' => 0,
                'notification_whatsapp' => 0,
                'language' => 'it',
                'timezone' => 'Europe/Rome',
                'created_at' => $now,
                'updated_at' => $now
            ];

            try {
                portal_insert('pickup_customer_preferences', $defaults);
                $preferences = $defaults;
            } catch (Exception $exception) {
                portal_error_log('Unable to create default customer preferences', [
                    'customer_id' => $customerId,
                    'error' => $exception->getMessage()
                ]);
                $preferences = portal_fetch_one(
                    'SELECT * FROM pickup_customer_preferences WHERE customer_id = ?',
                    [$customerId]
                ) ?: $defaults;
            }
        }

        return $this->normalizePreferences($preferences);
    }

    public function updateCustomerPreferences(int $customerId, array $data): array {
        $this->getCustomerPreferences($customerId);

        $language = strtolower(trim((string) ($data['language'] ?? '')));
        $allowedLanguages = ['it', 'en', 'de', 'fr', 'es'];
        if (!in_array($language, $allowedLanguages, true)) {
            $language = 'it';
        }

        $timezone = trim((string) ($data['timezone'] ?? 'Europe/Rome'));
        try {
            new DateTimeZone($timezone);
        } catch (Exception $exception) {
            portal_error_log('Invalid timezone provided by customer', [
                'customer_id' => $customerId,
                'timezone' => $timezone,
                'error' => $exception->getMessage()
            ]);
            $timezone = 'Europe/Rome';
        }

        $updateData = [
            'notification_email' => !empty($data['notification_email']) ? 1 : 0,
            'notification_sms' => 0,
            'notification_whatsapp' => !empty($data['notification_whatsapp']) ? 1 : 0,
            'language' => $language,
            'timezone' => $timezone,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        if (!portal_config('enable_whatsapp')) {
            $updateData['notification_whatsapp'] = 0;
        }

        portal_update('pickup_customer_preferences', $updateData, ['customer_id' => $customerId]);

        $this->logCustomerActivity($customerId, 'preferences_updated', 'preferences', null, [
            'email' => (bool) $updateData['notification_email'],
            'sms' => false,
            'whatsapp' => (bool) $updateData['notification_whatsapp'],
            'language' => $language,
            'timezone' => $timezone
        ]);

        return $this->getCustomerPreferences($customerId);
    }

    public function updateCustomerProfile(int $customerId, array $data): array {
        $customer = portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);
        if (!$customer) {
            throw new Exception('Cliente non trovato');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));

        $email = $email !== '' ? $email : null;
        $phone = $phone !== '' ? $phone : null;

        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Email non valida');
        }

        if ($phone !== null && !preg_match('/^\+?[1-9]\d{1,14}$/', $phone)) {
            throw new Exception('Numero di telefono non valido');
        }

        if ($email === null && $phone === null && empty($customer['email']) && empty($customer['phone'])) {
            throw new Exception('Inserisci almeno un recapito tra email e telefono');
        }

        if ($email !== null && strtolower((string) ($customer['email'] ?? '')) !== $email) {
            $exists = portal_fetch_one(
                'SELECT id FROM pickup_customers WHERE email = ? AND id <> ?',
                [$email, $customerId]
            );
            if ($exists) {
                throw new Exception('Email già registrata per un altro account');
            }
        } else {
            $email = $email ?? $customer['email'];
        }

        if ($phone !== null && $customer['phone'] !== $phone) {
            $exists = portal_fetch_one(
                'SELECT id FROM pickup_customers WHERE phone = ? AND id <> ?',
                [$phone, $customerId]
            );
            if ($exists) {
                throw new Exception('Numero di telefono già associato a un altro account');
            }
        } else {
            $phone = $phone ?? $customer['phone'];
        }

        $updates = [];

        if ($name !== ($customer['name'] ?? '')) {
            $updates['name'] = $name !== '' ? $name : null;
        }

        if ($email !== ($customer['email'] ?? null)) {
            $updates['email'] = $email;
            $updates['email_verified'] = $email && $email === ($customer['email'] ?? null) ? $customer['email_verified'] : 0;
        }

        if ($phone !== ($customer['phone'] ?? null)) {
            $updates['phone'] = $phone;
            $updates['phone_verified'] = $phone && $phone === ($customer['phone'] ?? null) ? $customer['phone_verified'] : 0;
        }

        if (empty($updates)) {
            return $customer;
        }

        $updates['updated_at'] = date('Y-m-d H:i:s');

        portal_update('pickup_customers', $updates, ['id' => $customerId]);

        $updatedCustomer = portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);

        $this->logCustomerActivity($customerId, 'profile_updated', 'customer', $customerId, [
            'name_changed' => array_key_exists('name', $updates),
            'email_changed' => array_key_exists('email', $updates),
            'phone_changed' => array_key_exists('phone', $updates)
        ]);

        return $updatedCustomer ?? $customer;
    }

    public function getCustomerActivity(int $customerId, int $limit = 10): array {
        $limit = max(1, min($limit, 50));
        $sql = 'SELECT * FROM pickup_customer_activity_logs WHERE customer_id = ? ORDER BY created_at DESC LIMIT ' . $limit;
        return portal_fetch_all($sql, [$customerId]);
    }

    public function markAllNotificationsAsRead(int $customerId): int {
        $now = date('Y-m-d H:i:s');
        $stmt = portal_query(
            'UPDATE pickup_customer_notifications SET read_at = ? WHERE customer_id = ? AND read_at IS NULL',
            [$now, $customerId]
        );

        if ($stmt->rowCount() > 0) {
            $this->logCustomerActivity($customerId, 'notifications_cleared', 'notification', null, [
                'count' => $stmt->rowCount()
            ]);
        }

        return $stmt->rowCount();
    }
    
    /**
     * Ottiene i pacchi del cliente
     */
    public function getCustomerPackages(int $customerId, array $options = []): array {
        $this->cleanupOrphanedReports();

        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;
        $status = $options['status'] ?? null;
        $search = trim((string) ($options['search'] ?? ''));
        
        $pickupConfig = $this->getPickupTableConfig();

        if ($pickupConfig) {
            $pickupTable = $pickupConfig['name'];
            $signatureColumns = $pickupConfig['supports_media']
                ? 'p.signature_path AS signature_path, p.photo_path AS photo_path, p.qr_code_path AS qr_code_path'
                : 'NULL AS signature_path, NULL AS photo_path, NULL AS qr_code_path';
            $deliveredExpression = $pickupConfig['delivered_expression'];
            $createdColumn = 'p.' . $pickupConfig['created_column'];
            $updatedColumn = 'p.' . $pickupConfig['updated_column'];

            $sql = "SELECT r.*, p.status AS pickup_status, p.courier_id, p.pickup_location_id,
                           {$deliveredExpression} AS delivered_at,
                           {$createdColumn} AS pickup_created_at,
                           {$updatedColumn} AS pickup_updated_at,
                           {$signatureColumns},
                           COALESCE(c.name, r.courier_name) AS courier_name, l.name AS location_name
                    FROM pickup_customer_reports r
                    LEFT JOIN {$pickupTable} p ON r.pickup_id = p.id
                    LEFT JOIN pickup_couriers c ON p.courier_id = c.id
                    LEFT JOIN pickup_locations l ON p.pickup_location_id = l.id
                    WHERE r.customer_id = ?";

            $params = [$customerId];

            if ($status) {
                $sql .= ' AND r.status = ?';
                $params[] = $status;
            }

            if ($search !== '') {
                $sql .= " AND (
                    r.tracking_code LIKE ? OR
                    r.recipient_name LIKE ? OR
                    COALESCE(c.name, r.courier_name, '') LIKE ? OR
                    COALESCE(l.name, '') LIKE ? OR
                    COALESCE(r.delivery_location, '') LIKE ?
                )";

                $like = '%' . $search . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }

            $orderBy = 'r.created_at';
            if (!empty($pickupConfig['updated_column'])) {
                $orderBy = 'p.' . $pickupConfig['updated_column'];
            }
            $sql .= ' ORDER BY ' . $orderBy . ' DESC, r.created_at DESC LIMIT ? OFFSET ?';
            $params[] = $limit;
            $params[] = $offset;

            return portal_fetch_all($sql, $params);
        }

        $sql = 'SELECT * FROM pickup_customer_reports WHERE customer_id = ?';
        $params = [$customerId];

        if ($status) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }

        if ($search !== '') {
            $sql .= " AND (
                tracking_code LIKE ? OR
                COALESCE(courier_name, '') LIKE ? OR
                COALESCE(recipient_name, '') LIKE ? OR
                COALESCE(delivery_location, '') LIKE ?
            )";

        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql .= ' ORDER BY updated_at DESC, created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;

        $reports = portal_fetch_all($sql, $params);
        return array_map(static function (array $report): array {
            $report['pickup_status'] = null;
            $report['courier_id'] = null;
            $report['pickup_location_id'] = null;
            $report['delivered_at'] = null;
            $report['pickup_created_at'] = null;
            $report['courier_name'] = null;
            $report['location_name'] = null;
            return $report;
        }, $reports);
    }
    
    /**
     * Ottiene un singolo pacco del cliente
     */
    public function getCustomerPackage(int $customerId, int $packageId): ?array {
        $this->cleanupOrphanedReports();

        $pickupConfig = $this->getPickupTableConfig();

        if ($pickupConfig) {
            $pickupTable = $pickupConfig['name'];
            $signatureColumns = $pickupConfig['supports_media']
                ? 'p.signature_path AS signature_path, p.photo_path AS photo_path, p.qr_code_path AS qr_code_path'
                : 'NULL AS signature_path, NULL AS photo_path, NULL AS qr_code_path';
            $deliveredExpression = $pickupConfig['delivered_expression'];
            $createdColumn = 'p.' . $pickupConfig['created_column'];
            $updatedColumn = 'p.' . $pickupConfig['updated_column'];
            $otpColumn = $pickupConfig['supports_otp_column']
                ? 'p.otp_code AS otp_code'
                : 'NULL AS otp_code';
            $trackingColumn = 'p.' . $pickupConfig['tracking_column'] . ' AS pickup_tracking';
            $noteColumn = $pickupConfig['note_column']
                ? 'p.' . $pickupConfig['note_column'] . ' AS pickup_note'
                : 'NULL AS pickup_note';

            $sql = "SELECT r.*, p.status AS pickup_status, p.courier_id, p.pickup_location_id,
                           {$deliveredExpression} AS delivered_at,
                           {$createdColumn} AS pickup_created_at,
                           {$updatedColumn} AS pickup_updated_at,
                           {$otpColumn},
                           {$signatureColumns},
                           {$trackingColumn},
                           {$noteColumn},
                           COALESCE(c.name, r.courier_name) AS courier_name,
                           l.name AS location_name,
                           l.address AS location_address
                    FROM pickup_customer_reports r
                    LEFT JOIN {$pickupTable} p ON r.pickup_id = p.id
                    LEFT JOIN pickup_couriers c ON p.courier_id = c.id
                    LEFT JOIN pickup_locations l ON p.pickup_location_id = l.id
                    WHERE r.customer_id = ? AND r.id = ?";

            return portal_fetch_one($sql, [$customerId, $packageId]);
        }

        return portal_fetch_one(
            'SELECT * FROM pickup_customer_reports WHERE customer_id = ? AND id = ?',
            [$customerId, $packageId]
        );
    }
    
    private function cleanupOrphanedReports(): void {
        if ($this->orphanCleanupRan) {
            return;
        }

        $this->orphanCleanupRan = true;

        $pickupConfig = $this->getPickupTableConfig();
        if (!$pickupConfig) {
            return;
        }

        $pickupTable = $pickupConfig['name'];

        try {
            portal_query(
                "DELETE r FROM pickup_customer_reports r LEFT JOIN {$pickupTable} p ON p.id = r.pickup_id WHERE r.pickup_id IS NOT NULL AND p.id IS NULL"
            );
        } catch (Exception $exception) {
            portal_error_log('Unable to cleanup orphaned pickup reports', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Segnala un nuovo pacco
     */
    public function reportPackage(int $customerId, array $data): array {
        $trackingCode = trim($data['tracking_code'] ?? '');
        $courierName = trim($data['courier_name'] ?? '');
        $recipientName = trim($data['recipient_name'] ?? '');
        $expectedDeliveryDate = $data['expected_delivery_date'] ?? null;
        $notes = trim($data['notes'] ?? '');
        $deliveryLocation = trim($data['delivery_location'] ?? '');
        
        if (empty($trackingCode)) {
            throw new Exception('Codice tracking richiesto');
        }
        
        if (strlen($trackingCode) < portal_config('min_tracking_length') || 
            strlen($trackingCode) > portal_config('max_tracking_length')) {
            throw new Exception('Codice tracking non valido');
        }
        
        // Verifica se il tracking è già stato segnalato dal cliente
        $existing = portal_fetch_one(
            'SELECT id FROM pickup_customer_reports WHERE customer_id = ? AND tracking_code = ?',
            [$customerId, $trackingCode]
        );
        
        if ($existing) {
            throw new Exception('Hai già segnalato un pacco con questo codice tracking');
        }
        
        $now = date('Y-m-d H:i:s');
        
        $reportData = [
            'customer_id' => $customerId,
            'tracking_code' => $trackingCode,
            'courier_name' => $courierName ?: null,
            'recipient_name' => $recipientName ?: null,
            'expected_delivery_date' => $expectedDeliveryDate ?: null,
            'delivery_location' => $deliveryLocation ?: null,
            'notes' => $notes ?: null,
            'status' => 'reported',
            'created_at' => $now,
            'updated_at' => $now
        ];
        
        $reportId = portal_insert('pickup_customer_reports', $reportData);
        
        // Log attività
        $this->logCustomerActivity($customerId, 'package_reported', 'package', $reportId, [
            'tracking_code' => $trackingCode,
            'courier_name' => $courierName
        ]);
        
        // Invia notifica di conferma
        $this->createNotification($customerId, 'system_message', 
            'Pacco segnalato', 
            "Il pacco con tracking {$trackingCode} è stato segnalato correttamente.",
            $trackingCode
        );
        
        portal_info_log('Package reported by customer', [
            'customer_id' => $customerId,
            'report_id' => $reportId,
            'tracking_code' => $trackingCode
        ]);
        
        return portal_fetch_one('SELECT * FROM pickup_customer_reports WHERE id = ?', [$reportId]);
    }
    
    /**
     * Ottiene le segnalazioni del cliente
     */
    public function getCustomerReports(int $customerId, array $options = []): array {
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;
        $status = $options['status'] ?? null;
        
        $sql = 'SELECT * FROM pickup_customer_reports WHERE customer_id = ?';
        $params = [$customerId];
        
        if ($status) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        
    $sql .= ' ORDER BY updated_at DESC, created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        
        return portal_fetch_all($sql, $params);
    }
    
    /**
     * Ottiene le notifiche del cliente
     */
    public function getCustomerNotifications(int $customerId, array $options = []): array {
        $limit = $options['limit'] ?? 50;
        $offset = $options['offset'] ?? 0;
        $unreadOnly = $options['unread_only'] ?? false;
        
        $sql = 'SELECT * FROM pickup_customer_notifications WHERE customer_id = ?';
        $params = [$customerId];
        
        if ($unreadOnly) {
            $sql .= ' AND read_at IS NULL';
        }
        
        $sql .= ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
        
        return portal_fetch_all($sql, $params);
    }
    
    /**
     * Crea una notifica per il cliente
     */
    public function createNotification(int $customerId, string $type, string $title, string $message, ?string $trackingCode = null): int {
        $notificationData = [
            'customer_id' => $customerId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'tracking_code' => $trackingCode,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        return portal_insert('pickup_customer_notifications', $notificationData);
    }
    
    /**
     * Marca una notifica come letta
     */
    public function markNotificationAsRead(int $customerId, int $notificationId): bool {
        $updated = portal_update(
            'pickup_customer_notifications',
            ['read_at' => date('Y-m-d H:i:s')],
            ['id' => $notificationId, 'customer_id' => $customerId]
        );
        
        return $updated > 0;
    }

    /**
     * Elimina una singola notifica del cliente
     */
    public function deleteNotification(int $customerId, int $notificationId): bool {
        $deleted = portal_delete(
            'pickup_customer_notifications',
            ['id' => $notificationId, 'customer_id' => $customerId]
        );

        return $deleted > 0;
    }

    public function deleteAllNotifications(int $customerId): int {
        $stmt = portal_query(
            'DELETE FROM pickup_customer_notifications WHERE customer_id = ?',
            [$customerId]
        );

        $deletedCount = $stmt->rowCount();

        if ($deletedCount > 0) {
            $this->logCustomerActivity($customerId, 'notifications_deleted_all', 'notification', null, [
                'count' => $deletedCount,
            ]);
        }

        return $deletedCount;
    }

    public function deleteCustomerAccount(int $customerId): void {
        $connection = portal_db();
        $startedTransaction = !$connection->inTransaction();

        if ($startedTransaction) {
            $connection->beginTransaction();
        }

        try {
            portal_query(
                'DELETE FROM pickup_customer_activity_logs WHERE customer_id = ?',
                [$customerId]
            );

            $customerDeletion = portal_query(
                'DELETE FROM pickup_customers WHERE id = ?',
                [$customerId]
            );

            if ($customerDeletion->rowCount() === 0) {
                throw new Exception('Account non trovato.');
            }

            if ($startedTransaction && $connection->inTransaction()) {
                $connection->commit();
            }

            portal_info_log('Portal customer account deleted', [
                'customer_id' => $customerId,
            ]);
        } catch (Exception $exception) {
            if ($startedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }

            portal_error_log('Unable to delete portal customer account', [
                'customer_id' => $customerId,
                'error' => $exception->getMessage(),
            ]);

            throw new Exception('Impossibile completare la richiesta di eliminazione account. Riprovare più tardi.');
        }
    }
    
    /**
     * Collega una segnalazione a un pacco del sistema pickup
     */
    public function linkReportToPickup(int $reportId, int $pickupId): bool {
        $updated = portal_update(
            'pickup_customer_reports',
            ['pickup_id' => $pickupId, 'status' => 'confirmed', 'updated_at' => date('Y-m-d H:i:s')],
            ['id' => $reportId]
        );
        
        if ($updated > 0) {
            // Ottieni i dati del report per notificare il cliente
            $report = portal_fetch_one('SELECT * FROM pickup_customer_reports WHERE id = ?', [$reportId]);
            if ($report) {
                $this->createNotification(
                    $report['customer_id'],
                    'package_arrived',
                    'Pacco arrivato!',
                    "Il tuo pacco {$report['tracking_code']} è arrivato ed è pronto per il ritiro.",
                    $report['tracking_code']
                );
            }
        }
        
        return $updated > 0;
    }
    
    /**
     * Log attività del cliente
     */
    public function logCustomerActivity(int $customerId, string $action, ?string $resourceType = null, ?int $resourceId = null, array $details = []): void {
        $logData = [
            'customer_id' => $customerId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => json_encode($details),
            'created_at' => date('Y-m-d H:i:s')
        ];
        
        portal_insert('pickup_customer_activity_logs', $logData);
    }
    
    /**
     * Ottiene il badge HTML per lo stato
     */
    public function getStatusBadge(string $status): string {
        $badges = [
            'reported' => '<span class="badge bg-warning">Segnalato</span>',
            'confirmed' => '<span class="badge bg-info">Confermato</span>',
            'arrived' => '<span class="badge bg-success">Arrivato</span>',
            'cancelled' => '<span class="badge bg-secondary">Annullato</span>',
            'in_arrivo' => '<span class="badge bg-primary">In Arrivo</span>',
            'consegnato' => '<span class="badge bg-success">Consegnato</span>',
            'ritirato' => '<span class="badge bg-dark">Ritirato</span>',
            'in_giacenza' => '<span class="badge bg-warning">In Giacenza</span>',
            'in_giacenza_scaduto' => '<span class="badge bg-danger">Giacenza Scaduta</span>',
        ];
        
        return $badges[$status] ?? '<span class="badge bg-secondary">' . htmlspecialchars(ucfirst($status)) . '</span>';
    }
    
    /**
     * Ottiene l'icona per il tipo di notifica
     */
    public function getNotificationIcon(string $type): string {
        $icons = [
            'package_arrived' => 'box',
            'package_ready' => 'check-circle',
            'package_reminder' => 'clock',
            'package_expired' => 'exclamation-triangle',
            'system_message' => 'info-circle'
        ];
        
        return $icons[$type] ?? 'bell';
    }
    
    /**
     * Cerca pacchi nel sistema pickup per tracking code
     */
    public function findPickupByTracking(string $trackingCode): ?array {
        $pickupConfig = $this->getPickupTableConfig();

        if (!$pickupConfig) {
            return null;
        }

        $pickupTable = $pickupConfig['name'];
        $trackingColumn = 'p.' . $pickupConfig['tracking_column'];
        $conditions = ["{$trackingColumn} = ?"];
        $params = [$trackingCode];

        if (!empty($pickupConfig['note_column'])) {
            $noteColumn = 'p.' . $pickupConfig['note_column'];
            $conditions[] = "{$noteColumn} LIKE ?";
            $params[] = '%' . $trackingCode . '%';
        }

        $whereClause = implode(' OR ', $conditions);

        try {
            return portal_fetch_one(
                "SELECT * FROM {$pickupTable} p WHERE {$whereClause} LIMIT 1",
                $params
            );
        } catch (Exception $e) {
            portal_error_log('Error finding pickup by tracking: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Sincronizza le segnalazioni con il sistema pickup
     */
    public function syncReportsWithPickup(): array {
        $results = ['linked' => 0, 'errors' => 0];
        
        // Trova segnalazioni non ancora collegate
        $unlinkedReports = portal_fetch_all(
            'SELECT * FROM pickup_customer_reports WHERE pickup_id IS NULL AND status = ?',
            ['reported']
        );
        
        foreach ($unlinkedReports as $report) {
            try {
                $pickup = $this->findPickupByTracking($report['tracking_code']);
                
                if ($pickup) {
                    $this->linkReportToPickup($report['id'], $pickup['id']);
                    $results['linked']++;
                    
                    portal_info_log('Report linked to pickup', [
                        'report_id' => $report['id'],
                        'pickup_id' => $pickup['id'],
                        'tracking_code' => $report['tracking_code']
                    ]);
                }
            } catch (Exception $e) {
                $results['errors']++;
                portal_error_log('Error syncing report: ' . $e->getMessage(), [
                    'report_id' => $report['id']
                ]);
            }
        }
        
        return $results;
    }
    
    /**
     * Pulisce i dati vecchi
     */
    public function cleanup(): array {
        $results = ['notifications' => 0, 'logs' => 0, 'sessions' => 0];
        
        // Pulisci notifiche vecchie (oltre 90 giorni)
        $stmt = portal_query(
            'DELETE FROM pickup_customer_notifications WHERE created_at < ?',
            [date('Y-m-d H:i:s', strtotime('-90 days'))]
        );
        $results['notifications'] = $stmt->rowCount();
        
        // Pulisci log attività vecchi (oltre 180 giorni)
        $stmt = portal_query(
            'DELETE FROM pickup_customer_activity_logs WHERE created_at < ?',
            [date('Y-m-d H:i:s', strtotime('-180 days'))]
        );
        $results['logs'] = $stmt->rowCount();
        
        // Pulisci sessioni scadute
        $stmt = portal_query(
            'DELETE FROM pickup_customer_sessions WHERE expires_at < ?',
            [date('Y-m-d H:i:s')]
        );
        $results['sessions'] = $stmt->rowCount();
        
        return $results;
    }

    private function normalizePreferences(array $preferences): array {
        $preferences['notification_email'] = (bool) ($preferences['notification_email'] ?? false);
        $preferences['notification_sms'] = (bool) ($preferences['notification_sms'] ?? false);
        $preferences['notification_whatsapp'] = (bool) ($preferences['notification_whatsapp'] ?? false);
        $preferences['language'] = $preferences['language'] ?? 'it';
        $preferences['timezone'] = $preferences['timezone'] ?? 'Europe/Rome';

        return $preferences;
    }
}