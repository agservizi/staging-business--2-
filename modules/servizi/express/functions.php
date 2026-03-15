<?php
declare(strict_types=1);

use App\Services\SettingsService;

require_once __DIR__ . '/../../../includes/notifications.php';

function express_module_safe_exec(PDO $pdo, string $statement): void
{
    try {
        $pdo->exec($statement);
    } catch (Throwable $exception) {
        error_log('Express bootstrap statement failed: ' . $exception->getMessage());
    }
}

function express_module_bootstrap_schema(PDO $pdo): void
{
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS servizi_express_operatori (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            soglia_riordino INT UNSIGNED NOT NULL DEFAULT 10,
            attivo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_servizi_express_operatori_nome (nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS servizi_express_vendite (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NULL,
            user_id INT UNSIGNED NULL,
            entrata_uscita_id INT UNSIGNED NULL,
            totale DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
            sconto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            metodo_pagamento VARCHAR(60) NOT NULL DEFAULT 'Contanti',
            stato ENUM('Completata','Annullata','Rimborsata') NOT NULL DEFAULT 'Completata',
            data_vendita DATETIME NOT NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_servizi_express_vendite_cliente (cliente_id),
            INDEX idx_servizi_express_vendite_user (user_id),
            INDEX idx_servizi_express_vendite_data (data_vendita),
            CONSTRAINT fk_servizi_express_vendite_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
            CONSTRAINT fk_servizi_express_vendite_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_servizi_express_vendite_movimento FOREIGN KEY (entrata_uscita_id) REFERENCES entrate_uscite(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS servizi_express_iccid_stock (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operatore_id INT UNSIGNED NOT NULL,
            vendita_id INT UNSIGNED NULL,
            iccid VARCHAR(32) NOT NULL,
            stato ENUM('InStock','Reserved','Sold') NOT NULL DEFAULT 'InStock',
            note TEXT NULL,
            created_by INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_servizi_express_iccid_stock_iccid (iccid),
            INDEX idx_servizi_express_iccid_stock_operatore (operatore_id),
            INDEX idx_servizi_express_iccid_stock_stato (stato),
            CONSTRAINT fk_servizi_express_iccid_stock_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE RESTRICT,
            CONSTRAINT fk_servizi_express_iccid_stock_vendita FOREIGN KEY (vendita_id) REFERENCES servizi_express_vendite(id) ON DELETE SET NULL,
            CONSTRAINT fk_servizi_express_iccid_stock_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS servizi_express_vendita_righe (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            vendita_id INT UNSIGNED NOT NULL,
            operatore_id INT UNSIGNED NULL,
            iccid_stock_id INT UNSIGNED NULL,
            tipo ENUM('sim','prodotto','servizio') NOT NULL DEFAULT 'sim',
            descrizione VARCHAR(255) NOT NULL,
            quantita INT UNSIGNED NOT NULL DEFAULT 1,
            prezzo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
            totale_riga DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_servizi_express_vendita_righe_vendita (vendita_id),
            CONSTRAINT fk_servizi_express_vendita_righe_vendita FOREIGN KEY (vendita_id) REFERENCES servizi_express_vendite(id) ON DELETE CASCADE,
            CONSTRAINT fk_servizi_express_vendita_righe_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE SET NULL,
            CONSTRAINT fk_servizi_express_vendita_righe_iccid FOREIGN KEY (iccid_stock_id) REFERENCES servizi_express_iccid_stock(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS servizi_express_prodotti (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(150) NOT NULL,
            sku VARCHAR(100) NULL,
            imei VARCHAR(100) NULL,
            categoria VARCHAR(100) NULL,
            prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
            stock_quantita INT UNSIGNED NOT NULL DEFAULT 0,
            soglia_riordino INT UNSIGNED NOT NULL DEFAULT 0,
            note TEXT NULL,
            attivo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_servizi_express_prodotti_sku (sku),
            UNIQUE KEY uniq_servizi_express_prodotti_imei (imei)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS servizi_express_offerte (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            operatore_id INT UNSIGNED NULL,
            titolo VARCHAR(150) NOT NULL,
            descrizione TEXT NULL,
            prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            stato ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
            valid_from DATE NULL,
            valid_to DATE NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_servizi_express_offerte_operatore (operatore_id),
            INDEX idx_servizi_express_offerte_stato (stato),
            CONSTRAINT fk_servizi_express_offerte_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS servizi_express_richieste (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            cliente_id INT UNSIGNED NOT NULL,
            prodotto_id INT UNSIGNED NULL,
            titolo VARCHAR(150) NOT NULL,
            tipo_richiesta ENUM('Purchase','Reservation','Deposit','Installment','Support') NOT NULL DEFAULT 'Purchase',
            stato ENUM('Pending','InReview','Confirmed','Completed','Cancelled','Declined') NOT NULL DEFAULT 'Pending',
            importo_acconto DECIMAL(10,2) NULL,
            numero_rate INT UNSIGNED NULL,
            metodo_pagamento VARCHAR(60) NULL,
            data_desiderata DATE NULL,
            note_cliente TEXT NULL,
            nota_interna TEXT NULL,
            gestita_da INT UNSIGNED NULL,
            gestita_il DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_servizi_express_richieste_cliente (cliente_id),
            INDEX idx_servizi_express_richieste_prodotto (prodotto_id),
            INDEX idx_servizi_express_richieste_stato (stato),
            CONSTRAINT fk_servizi_express_richieste_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
            CONSTRAINT fk_servizi_express_richieste_prodotto FOREIGN KEY (prodotto_id) REFERENCES servizi_express_prodotti(id) ON DELETE SET NULL,
            CONSTRAINT fk_servizi_express_richieste_user FOREIGN KEY (gestita_da) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendita_righe ADD COLUMN IF NOT EXISTS prodotto_id INT UNSIGNED NULL AFTER iccid_stock_id');
    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendita_righe ADD COLUMN IF NOT EXISTS offerta_id INT UNSIGNED NULL AFTER prodotto_id');
    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendita_righe ADD COLUMN IF NOT EXISTS quantita_resa INT UNSIGNED NOT NULL DEFAULT 0 AFTER quantita');
    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendita_righe ADD COLUMN IF NOT EXISTS sconto_riga DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER totale_riga');
    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendita_righe ADD INDEX IF NOT EXISTS idx_servizi_express_vendita_righe_prodotto (prodotto_id)');
    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendita_righe ADD INDEX IF NOT EXISTS idx_servizi_express_vendita_righe_offerta (offerta_id)');
    express_module_safe_exec($pdo, 'ALTER TABLE servizi_express_vendite MODIFY cliente_id INT UNSIGNED NULL');

    $pdo->exec(
        "INSERT INTO servizi_express_operatori (nome, soglia_riordino) VALUES
            ('Iliad', 25),
            ('Fastweb Mobile', 20),
            ('Sky Mobile', 15),
            ('Tiscali Mobile', 15),
            ('WindTre', 25),
            ('Digi Mobile', 20)
         ON DUPLICATE KEY UPDATE soglia_riordino = VALUES(soglia_riordino), attivo = 1"
    );

    $bootstrapped = true;
}

function express_module_default_settings(): array
{
    return [
        'default_vat' => 22.0,
        'stock_alert_threshold' => 10,
        'payment_methods' => ['Contanti', 'Carta', 'POS', 'Bonifico'],
        'default_payment_method' => 'Contanti',
        'allow_negative_margin' => false,
    ];
}

function express_module_get_settings(PDO $pdo): array
{
    $defaults = express_module_default_settings();

    try {
        $stmt = $pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
        $stmt->execute([':chiave' => 'servizi_express_settings']);
        $value = $stmt->fetchColumn();

        if ($value === false || $value === null || $value === '') {
            return $defaults;
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            return $defaults;
        }

        $paymentMethods = [];
        foreach (($decoded['payment_methods'] ?? []) as $method) {
            $method = trim((string) $method);
            if ($method !== '' && !in_array($method, $paymentMethods, true)) {
                $paymentMethods[] = $method;
            }
        }

        if ($paymentMethods === []) {
            $paymentMethods = $defaults['payment_methods'];
        }

        $settings = [
            'default_vat' => isset($decoded['default_vat']) ? (float) $decoded['default_vat'] : $defaults['default_vat'],
            'stock_alert_threshold' => isset($decoded['stock_alert_threshold']) ? max(1, (int) $decoded['stock_alert_threshold']) : $defaults['stock_alert_threshold'],
            'payment_methods' => $paymentMethods,
            'default_payment_method' => trim((string) ($decoded['default_payment_method'] ?? $defaults['default_payment_method'])),
            'allow_negative_margin' => !empty($decoded['allow_negative_margin']),
        ];

        if (!in_array($settings['default_payment_method'], $paymentMethods, true)) {
            $settings['default_payment_method'] = $paymentMethods[0];
        }

        return $settings;
    } catch (Throwable $exception) {
        error_log('Express settings load failed: ' . $exception->getMessage());
        return $defaults;
    }
}

function express_module_log(PDO $pdo, int $userId, string $action, array $details = []): void
{
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
             VALUES (:user_id, :modulo, :azione, :dettagli, NOW())'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':modulo' => 'Servizi/Express',
            ':azione' => $action,
            ':dettagli' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $exception) {
        error_log('Express log failed: ' . $exception->getMessage());
    }
}

function express_module_require_access(?PDO $pdo = null, ?int $userId = null): void
{
    if (current_user_can('Admin', 'Manager')) {
        return;
    }

    if ($pdo instanceof PDO && ($userId ?? 0) > 0) {
        express_module_log($pdo, (int) $userId, 'Accesso negato modulo Express', [
            'requested_uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'role' => (string) ($_SESSION['role'] ?? ''),
        ]);
    }

    add_flash('warning', 'Solo Admin e Manager possono utilizzare il modulo Express.');
    header('Location: ' . dashboard_url());
    exit;
}

function express_module_provider_options(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query('SELECT id, nome, soglia_riordino, attivo FROM servizi_express_operatori WHERE attivo = 1 ORDER BY nome ASC');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_product_category_options(): array
{
    return ['Smartphone', 'Tablet', 'Accessori', 'SIM', 'Router', 'Servizi'];
}

function express_module_product_count(PDO $pdo): int
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query('SELECT COUNT(*) FROM servizi_express_prodotti');
    $count = $stmt ? $stmt->fetchColumn() : 0;
    return max(0, (int) $count);
}

function express_module_product_summary(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_products,
            SUM(CASE WHEN attivo = 1 THEN 1 ELSE 0 END) AS active_products,
            SUM(CASE WHEN attivo = 0 THEN 1 ELSE 0 END) AS inactive_products,
            COALESCE(SUM(stock_quantita), 0) AS stock_units,
            COALESCE(SUM(stock_quantita * prezzo), 0) AS stock_value,
            SUM(CASE WHEN attivo = 1 AND soglia_riordino > 0 AND stock_quantita <= soglia_riordino THEN 1 ELSE 0 END) AS low_stock_products
         FROM servizi_express_prodotti"
    );
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    return [
        'total_products' => (int) ($row['total_products'] ?? 0),
        'active_products' => (int) ($row['active_products'] ?? 0),
        'inactive_products' => (int) ($row['inactive_products'] ?? 0),
        'stock_units' => (int) ($row['stock_units'] ?? 0),
        'stock_value' => (float) ($row['stock_value'] ?? 0),
        'low_stock_products' => (int) ($row['low_stock_products'] ?? 0),
    ];
}

function express_module_product_list(PDO $pdo, int $page = 1, int $perPage = 10): array
{
    express_module_bootstrap_schema($pdo);

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare('SELECT * FROM servizi_express_prodotti ORDER BY attivo DESC, categoria ASC, nome ASC LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_product_options(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query('SELECT id, nome, sku, imei, categoria, prezzo, aliquota_iva, stock_quantita, soglia_riordino FROM servizi_express_prodotti WHERE attivo = 1 ORDER BY categoria ASC, nome ASC');
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_product_detail(PDO $pdo, int $productId): ?array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM servizi_express_prodotti WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function express_module_save_product(PDO $pdo, array $payload, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    $productId = (int) ($payload['product_id'] ?? 0);
    $name = trim((string) ($payload['name'] ?? ''));
    $category = trim((string) ($payload['category'] ?? ''));
    $sku = trim((string) ($payload['sku'] ?? ''));
    $imei = trim((string) ($payload['imei'] ?? ''));
    $price = round((float) ($payload['price'] ?? 0), 2);
    $taxRate = round((float) ($payload['tax_rate'] ?? 22), 2);
    $stockQuantity = max(0, (int) ($payload['stock_quantity'] ?? 0));
    $reorderThreshold = max(0, (int) ($payload['reorder_threshold'] ?? 0));
    $notes = trim((string) ($payload['notes'] ?? ''));
    $isActive = !empty($payload['is_active']) ? 1 : 0;

    $errors = [];
    if ($name === '') {
        $errors[] = 'Inserisci il nome del prodotto.';
    }
    if ($category === '') {
        $errors[] = 'Seleziona una categoria prodotto.';
    }
    if ($price < 0) {
        $errors[] = 'Il prezzo prodotto non può essere negativo.';
    }
    if ($taxRate < 0 || $taxRate > 100) {
        $errors[] = 'L\'aliquota IVA del prodotto non è valida.';
    }

    if ($errors !== []) {
        return ['success' => false, 'message' => implode(' ', $errors)];
    }

    try {
        if ($productId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE servizi_express_prodotti
                 SET nome = :nome, sku = :sku, imei = :imei, categoria = :categoria, prezzo = :prezzo,
                     aliquota_iva = :aliquota_iva, stock_quantita = :stock_quantita, soglia_riordino = :soglia_riordino,
                     note = :note, attivo = :attivo, updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $productId,
                ':nome' => $name,
                ':sku' => $sku !== '' ? $sku : null,
                ':imei' => $imei !== '' ? $imei : null,
                ':categoria' => $category,
                ':prezzo' => $price,
                ':aliquota_iva' => $taxRate,
                ':stock_quantita' => $stockQuantity,
                ':soglia_riordino' => $reorderThreshold,
                ':note' => $notes !== '' ? $notes : null,
                ':attivo' => $isActive,
            ]);

            express_module_log($pdo, $userId, 'Aggiornato prodotto Express', ['prodotto_id' => $productId, 'nome' => $name]);
            return ['success' => true, 'message' => 'Prodotto aggiornato correttamente.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO servizi_express_prodotti (nome, sku, imei, categoria, prezzo, aliquota_iva, stock_quantita, soglia_riordino, note, attivo, created_at, updated_at)
             VALUES (:nome, :sku, :imei, :categoria, :prezzo, :aliquota_iva, :stock_quantita, :soglia_riordino, :note, :attivo, NOW(), NOW())'
        );
        $stmt->execute([
            ':nome' => $name,
            ':sku' => $sku !== '' ? $sku : null,
            ':imei' => $imei !== '' ? $imei : null,
            ':categoria' => $category,
            ':prezzo' => $price,
            ':aliquota_iva' => $taxRate,
            ':stock_quantita' => $stockQuantity,
            ':soglia_riordino' => $reorderThreshold,
            ':note' => $notes !== '' ? $notes : null,
            ':attivo' => $isActive,
        ]);

        $newId = (int) $pdo->lastInsertId();
        express_module_log($pdo, $userId, 'Creato prodotto Express', ['prodotto_id' => $newId, 'nome' => $name]);
        return ['success' => true, 'message' => 'Prodotto creato correttamente.'];
    } catch (Throwable $exception) {
        error_log('Express product save failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile salvare il prodotto. Verifica SKU e IMEI.'];
    }
}

function express_module_offer_count(PDO $pdo): int
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query('SELECT COUNT(*) FROM servizi_express_offerte');
    $count = $stmt ? $stmt->fetchColumn() : 0;
    return max(0, (int) $count);
}

function express_module_offer_summary(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_offers,
            SUM(CASE WHEN stato = 'Active' THEN 1 ELSE 0 END) AS active_offers,
            SUM(CASE WHEN stato = 'Inactive' THEN 1 ELSE 0 END) AS inactive_offers,
            SUM(CASE WHEN stato = 'Active' AND valid_to IS NOT NULL AND valid_to >= CURDATE() AND valid_to <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring_soon,
            COUNT(DISTINCT CASE WHEN operatore_id IS NOT NULL THEN operatore_id END) AS covered_operators,
            COALESCE(AVG(CASE WHEN stato = 'Active' THEN prezzo END), 0) AS average_price
         FROM servizi_express_offerte"
    );
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    return [
        'total_offers' => (int) ($row['total_offers'] ?? 0),
        'active_offers' => (int) ($row['active_offers'] ?? 0),
        'inactive_offers' => (int) ($row['inactive_offers'] ?? 0),
        'expiring_soon' => (int) ($row['expiring_soon'] ?? 0),
        'covered_operators' => (int) ($row['covered_operators'] ?? 0),
        'average_price' => (float) ($row['average_price'] ?? 0),
    ];
}

function express_module_offer_list(PDO $pdo, int $page = 1, int $perPage = 10): array
{
    express_module_bootstrap_schema($pdo);

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT o.*, p.nome AS operatore
            FROM servizi_express_offerte o
            LEFT JOIN servizi_express_operatori p ON p.id = o.operatore_id
            ORDER BY o.stato = "Active" DESC, o.updated_at DESC, o.id DESC
            LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_offer_options(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $today = date('Y-m-d');
    $sql = 'SELECT o.id, o.operatore_id, o.titolo, o.descrizione, o.prezzo, o.valid_from, o.valid_to, p.nome AS operatore
            FROM servizi_express_offerte o
            LEFT JOIN servizi_express_operatori p ON p.id = o.operatore_id
            WHERE o.stato = "Active"
                            AND (o.valid_from IS NULL OR o.valid_from <= :today_from)
                            AND (o.valid_to IS NULL OR o.valid_to >= :today_to)
            ORDER BY p.nome ASC, o.titolo ASC';
    $stmt = $pdo->prepare($sql);
        $stmt->execute([
                ':today_from' => $today,
                ':today_to' => $today,
        ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function express_module_offer_detail(PDO $pdo, int $offerId): ?array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM servizi_express_offerte WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $offerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function express_module_save_offer(PDO $pdo, array $payload, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    $offerId = (int) ($payload['offer_id'] ?? 0);
    $providerId = (int) ($payload['operatore_id'] ?? 0);
    $title = trim((string) ($payload['title'] ?? ''));
    $description = trim((string) ($payload['description'] ?? ''));
    $price = round((float) ($payload['price'] ?? 0), 2);
    $status = (string) ($payload['status'] ?? 'Active');
    $validFrom = trim((string) ($payload['valid_from'] ?? ''));
    $validTo = trim((string) ($payload['valid_to'] ?? ''));

    if ($title === '') {
        return ['success' => false, 'message' => 'Inserisci il titolo dell\'offerta.'];
    }

    if ($price < 0) {
        return ['success' => false, 'message' => 'Il prezzo offerta non può essere negativo.'];
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $status = 'Active';
    }

    if ($validFrom !== '' && $validTo !== '' && $validTo < $validFrom) {
        return ['success' => false, 'message' => 'La fine validità non può precedere l\'inizio validità.'];
    }

    try {
        if ($offerId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE servizi_express_offerte
                 SET operatore_id = :operatore_id, titolo = :titolo, descrizione = :descrizione, prezzo = :prezzo,
                     stato = :stato, valid_from = :valid_from, valid_to = :valid_to, updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $offerId,
                ':operatore_id' => $providerId > 0 ? $providerId : null,
                ':titolo' => $title,
                ':descrizione' => $description !== '' ? $description : null,
                ':prezzo' => $price,
                ':stato' => $status,
                ':valid_from' => $validFrom !== '' ? $validFrom : null,
                ':valid_to' => $validTo !== '' ? $validTo : null,
            ]);

            express_module_log($pdo, $userId, 'Aggiornata offerta Express', ['offerta_id' => $offerId, 'titolo' => $title]);
            return ['success' => true, 'message' => 'Offerta aggiornata correttamente.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO servizi_express_offerte (operatore_id, titolo, descrizione, prezzo, stato, valid_from, valid_to, created_at, updated_at)
             VALUES (:operatore_id, :titolo, :descrizione, :prezzo, :stato, :valid_from, :valid_to, NOW(), NOW())'
        );
        $stmt->execute([
            ':operatore_id' => $providerId > 0 ? $providerId : null,
            ':titolo' => $title,
            ':descrizione' => $description !== '' ? $description : null,
            ':prezzo' => $price,
            ':stato' => $status,
            ':valid_from' => $validFrom !== '' ? $validFrom : null,
            ':valid_to' => $validTo !== '' ? $validTo : null,
        ]);

        $newId = (int) $pdo->lastInsertId();
        express_module_log($pdo, $userId, 'Creata offerta Express', ['offerta_id' => $newId, 'titolo' => $title]);
        return ['success' => true, 'message' => 'Offerta creata correttamente.'];
    } catch (Throwable $exception) {
        error_log('Express offer save failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile salvare l\'offerta.'];
    }
}

function express_module_low_stock_products(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $sql = 'SELECT id, nome, categoria, stock_quantita, soglia_riordino
            FROM servizi_express_prodotti
            WHERE attivo = 1 AND soglia_riordino > 0 AND stock_quantita <= soglia_riordino
            ORDER BY stock_quantita ASC, nome ASC';
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_report_data(PDO $pdo, array $filters = []): array
{
    express_module_bootstrap_schema($pdo);

    $view = (string) ($filters['view'] ?? 'daily');
    if (!in_array($view, ['daily', 'monthly', 'yearly'], true)) {
        $view = 'daily';
    }

    $today = new DateTimeImmutable('today');
    if ($view === 'daily') {
        $date = trim((string) ($filters['date'] ?? $today->format('Y-m-d')));
        $start = new DateTimeImmutable($date . ' 00:00:00');
        $end = $start->modify('+1 day');
    } elseif ($view === 'monthly') {
        $month = trim((string) ($filters['month'] ?? $today->format('Y-m')));
        $start = new DateTimeImmutable($month . '-01 00:00:00');
        $end = $start->modify('+1 month');
    } else {
        $year = (int) ($filters['year'] ?? $today->format('Y'));
        $start = new DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $end = $start->modify('+1 year');
    }

    $payment = trim((string) ($filters['payment'] ?? ''));
    $operatorId = (int) ($filters['operator_id'] ?? 0);

    $where = ['v.stato = "Completata"', 'v.data_vendita >= :start', 'v.data_vendita < :end'];
    $params = [
        ':start' => $start->format('Y-m-d H:i:s'),
        ':end' => $end->format('Y-m-d H:i:s'),
    ];

    if ($payment !== '') {
        $where[] = 'v.metodo_pagamento = :payment';
        $params[':payment'] = $payment;
    }
    if ($operatorId > 0) {
        $where[] = 'COALESCE(r.operatore_id, 0) = :operator_id';
        $params[':operator_id'] = $operatorId;
    }

    $whereSql = implode(' AND ', $where);

    $totalsSql = "SELECT COUNT(DISTINCT v.id) AS sales_count,
                         COALESCE(SUM(v.totale), 0) AS gross_revenue,
                         COALESCE(SUM(v.sconto), 0) AS discount_total,
                         COALESCE(SUM(v.totale - v.sconto), 0) AS net_revenue,
                         COALESCE(AVG(v.totale - v.sconto), 0) AS average_ticket
                  FROM servizi_express_vendite v
                  LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
                  WHERE {$whereSql}";
    $totalsStmt = $pdo->prepare($totalsSql);
    $totalsStmt->execute($params);
    $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $paymentsSql = "SELECT v.metodo_pagamento AS method,
                           COUNT(DISTINCT v.id) AS sale_count,
                           COALESCE(SUM(v.totale - v.sconto), 0) AS net_revenue
                    FROM servizi_express_vendite v
                    LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
                    WHERE {$whereSql}
                    GROUP BY v.metodo_pagamento
                    ORDER BY net_revenue DESC, method ASC";
    $paymentsStmt = $pdo->prepare($paymentsSql);
    $paymentsStmt->execute($params);

    $operatorsSql = "SELECT COALESCE(o.nome, 'Generico') AS operator_name,
                            COUNT(DISTINCT v.id) AS sale_count,
                            COALESCE(SUM(v.totale - v.sconto), 0) AS net_revenue
                     FROM servizi_express_vendite v
                     LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
                     LEFT JOIN servizi_express_operatori o ON o.id = r.operatore_id
                     WHERE {$whereSql}
                     GROUP BY COALESCE(o.id, 0), COALESCE(o.nome, 'Generico')
                     ORDER BY net_revenue DESC, operator_name ASC";
    $operatorsStmt = $pdo->prepare($operatorsSql);
    $operatorsStmt->execute($params);

    $productsSql = "SELECT COALESCE(p.nome, r.descrizione) AS item_name,
                           r.tipo,
                           SUM(r.quantita) AS total_quantity,
                           COALESCE(SUM(r.totale_riga - r.sconto_riga), 0) AS net_revenue
                    FROM servizi_express_vendite v
                    INNER JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
                    LEFT JOIN servizi_express_prodotti p ON p.id = r.prodotto_id
                    WHERE {$whereSql}
                    GROUP BY COALESCE(p.id, 0), COALESCE(p.nome, r.descrizione), r.tipo
                    ORDER BY net_revenue DESC, total_quantity DESC, item_name ASC
                    LIMIT 10";
    $productsStmt = $pdo->prepare($productsSql);
    $productsStmt->execute($params);

    if ($view === 'daily') {
        $trendExpr = 'DATE_FORMAT(v.data_vendita, "%H:00")';
    } elseif ($view === 'monthly') {
        $trendExpr = 'DATE_FORMAT(v.data_vendita, "%d/%m")';
    } else {
        $trendExpr = 'DATE_FORMAT(v.data_vendita, "%m/%Y")';
    }

    $trendSql = "SELECT {$trendExpr} AS label,
                        COUNT(DISTINCT v.id) AS sale_count,
                        COALESCE(SUM(v.totale - v.sconto), 0) AS net_revenue
                 FROM servizi_express_vendite v
                 LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
                 WHERE {$whereSql}
                 GROUP BY label
                 ORDER BY MIN(v.data_vendita) ASC";
    $trendStmt = $pdo->prepare($trendSql);
    $trendStmt->execute($params);

    return [
        'view' => $view,
        'filters' => [
            'date' => $filters['date'] ?? '',
            'month' => $filters['month'] ?? '',
            'year' => $filters['year'] ?? '',
            'payment' => $payment,
            'operator_id' => $operatorId > 0 ? (string) $operatorId : '',
        ],
        'period_label' => $start->format('d/m/Y') . ' - ' . $end->modify('-1 day')->format('d/m/Y'),
        'totals' => [
            'sales_count' => (int) ($totals['sales_count'] ?? 0),
            'gross_revenue' => (float) ($totals['gross_revenue'] ?? 0),
            'discount_total' => (float) ($totals['discount_total'] ?? 0),
            'net_revenue' => (float) ($totals['net_revenue'] ?? 0),
            'average_ticket' => (float) ($totals['average_ticket'] ?? 0),
        ],
        'payments' => $paymentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'operators' => $operatorsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'products' => $productsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'trend' => $trendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        'payment_options' => array_values(array_filter(array_map('strval', express_module_get_settings($pdo)['payment_methods'] ?? []))),
        'operator_options' => express_module_provider_options($pdo),
    ];
}

function express_module_add_provider(PDO $pdo, string $name, int $threshold, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'Inserisci il nome dell\'operatore.'];
    }

    $threshold = max(1, $threshold);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO servizi_express_operatori (nome, soglia_riordino, attivo)
             VALUES (:nome, :soglia, 1)
             ON DUPLICATE KEY UPDATE soglia_riordino = VALUES(soglia_riordino), attivo = 1, updated_at = NOW()'
        );
        $stmt->execute([
            ':nome' => $name,
            ':soglia' => $threshold,
        ]);

        express_module_log($pdo, $userId, 'Aggiornato operatore Express', ['nome' => $name, 'soglia_riordino' => $threshold]);

        return ['success' => true, 'message' => 'Operatore salvato correttamente.'];
    } catch (Throwable $exception) {
        error_log('Express provider save failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile salvare l\'operatore.'];
    }
}

function express_module_parse_iccid_input(string $raw): array
{
    $values = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $iccids = [];

    foreach ($values as $value) {
        $normalized = preg_replace('/\D+/', '', (string) $value) ?? '';
        if ($normalized !== '' && !in_array($normalized, $iccids, true)) {
            $iccids[] = $normalized;
        }
    }

    return $iccids;
}

function express_module_import_iccids(PDO $pdo, int $operatorId, array $iccids, string $notes, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    if ($operatorId <= 0) {
        return ['success' => false, 'message' => 'Seleziona un operatore valido.'];
    }

    if ($iccids === []) {
        return ['success' => false, 'message' => 'Inserisci almeno un ICCID valido.'];
    }

    $created = 0;
    $duplicates = 0;
    $invalid = [];

    $insert = $pdo->prepare(
        'INSERT INTO servizi_express_iccid_stock (operatore_id, iccid, stato, note, created_by)
         VALUES (:operatore_id, :iccid, :stato, :note, :created_by)'
    );

    foreach ($iccids as $iccid) {
        if (!preg_match('/^\d{19,20}$/', $iccid)) {
            $invalid[] = $iccid;
            continue;
        }

        try {
            $insert->execute([
                ':operatore_id' => $operatorId,
                ':iccid' => $iccid,
                ':stato' => 'InStock',
                ':note' => $notes,
                ':created_by' => $userId > 0 ? $userId : null,
            ]);
            $created++;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $duplicates++;
                continue;
            }

            throw $exception;
        }
    }

    express_module_log($pdo, $userId, 'Importato stock ICCID Express', [
        'operatore_id' => $operatorId,
        'creati' => $created,
        'duplicati' => $duplicates,
        'invalidi' => count($invalid),
    ]);

    $parts = [];
    if ($created > 0) {
        $parts[] = $created . ' ICCID importati';
    }
    if ($duplicates > 0) {
        $parts[] = $duplicates . ' duplicati ignorati';
    }
    if ($invalid !== []) {
        $parts[] = count($invalid) . ' non validi';
    }

    return [
        'success' => $created > 0,
        'message' => $parts ? implode(', ', $parts) . '.' : 'Nessun ICCID importato.',
    ];
}

function express_module_dashboard_stats(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stats = [
        'stock_available' => 0,
        'sold_today' => 0,
        'revenue_month' => 0.0,
        'sales_count_month' => 0,
    ];

    $stockValue = $pdo->query("SELECT COUNT(*) FROM servizi_express_iccid_stock WHERE stato = 'InStock'");
    if ($stockValue) {
        $stats['stock_available'] = (int) $stockValue->fetchColumn();
    }

    $todayValue = $pdo->query("SELECT COUNT(*) FROM servizi_express_vendite WHERE DATE(data_vendita) = CURDATE() AND stato = 'Completata'");
    if ($todayValue) {
        $stats['sold_today'] = (int) $todayValue->fetchColumn();
    }

    $monthStmt = $pdo->query(
        "SELECT COALESCE(SUM(totale), 0) AS revenue, COUNT(*) AS total_sales
         FROM servizi_express_vendite
         WHERE stato = 'Completata'
           AND YEAR(data_vendita) = YEAR(CURDATE())
           AND MONTH(data_vendita) = MONTH(CURDATE())"
    );
    if ($monthStmt) {
        $row = $monthStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $stats['revenue_month'] = (float) ($row['revenue'] ?? 0);
        $stats['sales_count_month'] = (int) ($row['total_sales'] ?? 0);
    }

    return $stats;
}

function express_module_dashboard_period_range(string $period): array
{
    $today = new DateTimeImmutable('today');

    if ($period === '30d') {
        $start = $today->modify('-29 days');
        $label = 'Ultimi 30 giorni';
    } elseif ($period === 'month') {
        $start = $today->modify('first day of this month');
        $label = 'Mese corrente';
    } else {
        $period = '7d';
        $start = $today->modify('-6 days');
        $label = 'Ultimi 7 giorni';
    }

    return [
        'period' => $period,
        'start' => $start,
        'end' => $today->modify('+1 day'),
        'label' => $label,
    ];
}

function express_module_dashboard_period_summary(PDO $pdo, string $period = '7d'): array
{
    express_module_bootstrap_schema($pdo);

    $range = express_module_dashboard_period_range($period);
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN stato = 'Completata' THEN totale - sconto ELSE 0 END), 0) AS revenue,
            SUM(CASE WHEN stato = 'Completata' THEN 1 ELSE 0 END) AS completed_sales,
            SUM(CASE WHEN stato = 'Rimborsata' THEN 1 ELSE 0 END) AS refunded_sales,
            SUM(CASE WHEN stato = 'Annullata' THEN 1 ELSE 0 END) AS cancelled_sales
         FROM servizi_express_vendite
         WHERE data_vendita >= :start AND data_vendita < :end"
    );
    $stmt->execute([
        ':start' => $range['start']->format('Y-m-d 00:00:00'),
        ':end' => $range['end']->format('Y-m-d 00:00:00'),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'period' => $range['period'],
        'label' => $range['label'],
        'revenue' => (float) ($row['revenue'] ?? 0),
        'completed_sales' => (int) ($row['completed_sales'] ?? 0),
        'refunded_sales' => (int) ($row['refunded_sales'] ?? 0),
        'cancelled_sales' => (int) ($row['cancelled_sales'] ?? 0),
    ];
}

function express_module_operator_stock_breakdown(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $sql = "SELECT o.id,
                   o.nome,
                   COALESCE(SUM(CASE WHEN s.stato = 'InStock' THEN 1 ELSE 0 END), 0) AS available_stock
            FROM servizi_express_operatori o
            LEFT JOIN servizi_express_iccid_stock s ON s.operatore_id = o.id
            WHERE o.attivo = 1
            GROUP BY o.id, o.nome
            HAVING available_stock > 0
            ORDER BY available_stock DESC, o.nome ASC";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_stock_summary(PDO $pdo, array $filters = []): array
{
    express_module_bootstrap_schema($pdo);

    $operatorId = (int) ($filters['operatore_id'] ?? 0);
    $iccid = preg_replace('/\D+/', '', (string) ($filters['iccid'] ?? ''));
    $where = [];
    $params = [];

    if ($operatorId > 0) {
        $where[] = 's.operatore_id = :operatore_id';
        $params[':operatore_id'] = $operatorId;
    }
    if ($iccid !== '') {
        $where[] = 'REPLACE(REPLACE(REPLACE(REPLACE(s.iccid, " ", ""), "-", ""), ".", ""), "/", "") LIKE :iccid';
        $params[':iccid'] = '%' . $iccid . '%';
    }

    $sql = "SELECT
                COUNT(*) AS total_rows,
                SUM(CASE WHEN s.stato = 'InStock' THEN 1 ELSE 0 END) AS available_rows,
                SUM(CASE WHEN s.stato = 'Reserved' THEN 1 ELSE 0 END) AS reserved_rows,
                SUM(CASE WHEN s.stato = 'Sold' THEN 1 ELSE 0 END) AS sold_rows,
                MAX(s.created_at) AS last_import_at
            FROM servizi_express_iccid_stock s";

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $operatorName = 'Tutti gli operatori';
    if ($operatorId > 0) {
        $nameStmt = $pdo->prepare('SELECT nome FROM servizi_express_operatori WHERE id = :id LIMIT 1');
        $nameStmt->execute([':id' => $operatorId]);
        $operatorName = (string) ($nameStmt->fetchColumn() ?: 'Operatore selezionato');
    }

    return [
        'total_rows' => (int) ($row['total_rows'] ?? 0),
        'available_rows' => (int) ($row['available_rows'] ?? 0),
        'reserved_rows' => (int) ($row['reserved_rows'] ?? 0),
        'sold_rows' => (int) ($row['sold_rows'] ?? 0),
        'last_import_at' => (string) ($row['last_import_at'] ?? ''),
        'operator_label' => $operatorName,
        'has_filters' => $operatorId > 0 || $iccid !== '',
    ];
}

function express_module_sales_trend(PDO $pdo, int $days = 7): array
{
    express_module_bootstrap_schema($pdo);

    $days = max(3, min($days, 31));
    $start = (new DateTimeImmutable('today'))->modify('-' . ($days - 1) . ' days');
    $labels = [];
    $map = [];

    for ($index = 0; $index < $days; $index++) {
        $day = $start->modify('+' . $index . ' days');
        $key = $day->format('Y-m-d');
        $labels[] = $day->format('d/m');
        $map[$key] = [
            'label' => $day->format('d/m'),
            'sales_count' => 0,
            'revenue' => 0.0,
        ];
    }

    $stmt = $pdo->prepare(
        "SELECT DATE(data_vendita) AS sale_date,
                COUNT(*) AS sales_count,
                COALESCE(SUM(totale - sconto), 0) AS revenue
         FROM servizi_express_vendite
         WHERE stato = 'Completata'
           AND data_vendita >= :start
         GROUP BY DATE(data_vendita)
         ORDER BY sale_date ASC"
    );
    $stmt->execute([':start' => $start->format('Y-m-d 00:00:00')]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (string) ($row['sale_date'] ?? '');
        if (!isset($map[$key])) {
            continue;
        }
        $map[$key]['sales_count'] = (int) ($row['sales_count'] ?? 0);
        $map[$key]['revenue'] = (float) ($row['revenue'] ?? 0);
    }

    $salesCountValues = array_map(static fn (array $day): int => (int) $day['sales_count'], $map);
    $revenueValues = array_map(static fn (array $day): float => round((float) $day['revenue'], 2), $map);
    $hasData = array_sum($salesCountValues) > 0 || array_sum($revenueValues) > 0;

    return [
        'labels' => array_column($map, 'label'),
        'sales_count' => $salesCountValues,
        'revenue' => $revenueValues,
        'has_data' => $hasData,
    ];
}

function express_module_top_items(PDO $pdo, string $period = '7d', int $limit = 5): array
{
    express_module_bootstrap_schema($pdo);

    $range = express_module_dashboard_period_range($period);
    $limit = max(1, min($limit, 10));
    $sql = "SELECT COALESCE(p.nome, f.titolo, r.descrizione) AS item_name,
                   r.tipo,
                   SUM(r.quantita) AS total_quantity,
                   COALESCE(SUM(r.totale_riga - r.sconto_riga), 0) AS revenue
            FROM servizi_express_vendita_righe r
            INNER JOIN servizi_express_vendite v ON v.id = r.vendita_id
            LEFT JOIN servizi_express_prodotti p ON p.id = r.prodotto_id
            LEFT JOIN servizi_express_offerte f ON f.id = r.offerta_id
            WHERE v.stato = 'Completata'
              AND v.data_vendita >= :start
              AND v.data_vendita < :end
            GROUP BY COALESCE(p.id, 0), COALESCE(f.id, 0), COALESCE(p.nome, f.titolo, r.descrizione), r.tipo
            ORDER BY total_quantity DESC, revenue DESC, item_name ASC
            LIMIT {$limit}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $range['start']->format('Y-m-d 00:00:00'),
        ':end' => $range['end']->format('Y-m-d 00:00:00'),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function express_module_payment_breakdown(PDO $pdo, string $period = '7d'): array
{
    express_module_bootstrap_schema($pdo);

    $range = express_module_dashboard_period_range($period);

    $stmt = $pdo->prepare(
        "SELECT metodo_pagamento,
                COUNT(*) AS sale_count,
                COALESCE(SUM(totale - sconto), 0) AS revenue
         FROM servizi_express_vendite
         WHERE stato = 'Completata'
           AND data_vendita >= :start
           AND data_vendita < :end
         GROUP BY metodo_pagamento
         ORDER BY revenue DESC, metodo_pagamento ASC"
    );
    $stmt->execute([
        ':start' => $range['start']->format('Y-m-d 00:00:00'),
        ':end' => $range['end']->format('Y-m-d 00:00:00'),
    ]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function express_module_recent_activity(PDO $pdo, int $limit = 8): array
{
    express_module_bootstrap_schema($pdo);

    $limit = max(1, min($limit, 20));
    $sql = "SELECT v.id,
                   v.stato,
                   v.totale,
                   v.updated_at,
                   v.data_vendita,
                   c.ragione_sociale,
                   c.nome,
                   c.cognome,
                   u.nome AS user_nome,
                   u.cognome AS user_cognome
            FROM servizi_express_vendite v
            LEFT JOIN clienti c ON c.id = v.cliente_id
            LEFT JOIN users u ON u.id = v.user_id
            ORDER BY COALESCE(v.updated_at, v.data_vendita) DESC, v.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->query($sql);
    return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

function express_module_low_stock(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $sql = "SELECT o.id,
                   o.nome,
                   o.soglia_riordino,
                   COALESCE(SUM(CASE WHEN s.stato = 'InStock' THEN 1 ELSE 0 END), 0) AS giacenza
            FROM servizi_express_operatori o
            LEFT JOIN servizi_express_iccid_stock s ON s.operatore_id = o.id
            WHERE o.attivo = 1
            GROUP BY o.id, o.nome, o.soglia_riordino
            HAVING giacenza <= o.soglia_riordino
            ORDER BY giacenza ASC, o.nome ASC";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_recent_sales(PDO $pdo, int $limit = 10): array
{
    express_module_bootstrap_schema($pdo);

    $limit = max(1, min($limit, 50));

    $sql = "SELECT v.id,
                   v.totale,
                   v.metodo_pagamento,
                   v.stato,
                   v.data_vendita,
                   c.ragione_sociale,
                   c.nome,
                   c.cognome,
                   u.nome AS user_nome,
                   u.cognome AS user_cognome,
                   r.descrizione,
                   r.tipo,
                   s.iccid
            FROM servizi_express_vendite v
            LEFT JOIN clienti c ON c.id = v.cliente_id
            LEFT JOIN users u ON u.id = v.user_id
            LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
            LEFT JOIN servizi_express_iccid_stock s ON s.id = r.iccid_stock_id
            GROUP BY v.id, v.totale, v.metodo_pagamento, v.stato, v.data_vendita, c.ragione_sociale, c.nome, c.cognome, u.nome, u.cognome, r.descrizione, r.tipo, s.iccid
            ORDER BY v.data_vendita DESC, v.id DESC
            LIMIT {$limit}";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_stock_count(PDO $pdo, array $filters = []): int
{
    express_module_bootstrap_schema($pdo);

    $operatorId = (int) ($filters['operatore_id'] ?? 0);
    $iccid = preg_replace('/\D+/', '', (string) ($filters['iccid'] ?? ''));
    $where = [];
    $params = [];

    if ($operatorId > 0) {
        $where[] = 's.operatore_id = :operatore_id';
        $params[':operatore_id'] = $operatorId;
    }
    if ($iccid !== '') {
        $where[] = 'REPLACE(REPLACE(REPLACE(REPLACE(s.iccid, " ", ""), "-", ""), ".", ""), "/", "") LIKE :iccid';
        $params[':iccid'] = '%' . $iccid . '%';
    }

    $sql = 'SELECT COUNT(*) FROM servizi_express_iccid_stock s';
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->fetchColumn();
    return max(0, (int) $count);
}

function express_module_stock_rows(PDO $pdo, int $page = 1, int $perPage = 10, array $filters = []): array
{
    express_module_bootstrap_schema($pdo);

    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;
    $operatorId = (int) ($filters['operatore_id'] ?? 0);
    $iccid = preg_replace('/\D+/', '', (string) ($filters['iccid'] ?? ''));
    $where = [];

    $sql = "SELECT s.id,
                   s.iccid,
                   s.stato,
                   s.note,
                   s.created_at,
                   o.nome AS operatore,
                   v.id AS vendita_id
            FROM servizi_express_iccid_stock s
            INNER JOIN servizi_express_operatori o ON o.id = s.operatore_id
            LEFT JOIN servizi_express_vendite v ON v.id = s.vendita_id";

    if ($operatorId > 0) {
        $where[] = 's.operatore_id = :operatore_id';
    }
    if ($iccid !== '') {
        $where[] = 'REPLACE(REPLACE(REPLACE(REPLACE(s.iccid, " ", ""), "-", ""), ".", ""), "/", "") LIKE :iccid';
    }
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= "
            ORDER BY s.created_at DESC, s.id DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    if ($operatorId > 0) {
        $stmt->bindValue(':operatore_id', $operatorId, PDO::PARAM_INT);
    }
    if ($iccid !== '') {
        $stmt->bindValue(':iccid', '%' . $iccid . '%', PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function express_module_available_iccids(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $sql = "SELECT s.id, s.iccid, o.id AS operatore_id, o.nome AS operatore
            FROM servizi_express_iccid_stock s
            INNER JOIN servizi_express_operatori o ON o.id = s.operatore_id
            WHERE s.stato = 'InStock'
            ORDER BY o.nome ASC, s.iccid ASC";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_client_options(PDO $pdo): array
{
    $sql = "SELECT id, ragione_sociale, nome, cognome, email
            FROM clienti
            ORDER BY ragione_sociale ASC, cognome ASC, nome ASC
            LIMIT 500";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_customer_count(PDO $pdo, string $search = ''): int
{
    $params = [];
    $sql = 'SELECT COUNT(*)
            FROM clienti';

    if ($search !== '') {
        $sql .= ' WHERE ragione_sociale LIKE :search OR nome LIKE :search OR cognome LIKE :search OR email LIKE :search OR telefono LIKE :search OR cf_piva LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $count = $stmt->fetchColumn();
    return max(0, (int) $count);
}

function express_module_customer_summary(PDO $pdo, string $search = ''): array
{
    $params = [];
    $whereSql = '';

    if ($search !== '') {
        $whereSql = ' WHERE ragione_sociale LIKE :search OR nome LIKE :search OR cognome LIKE :search OR email LIKE :search OR telefono LIKE :search OR cf_piva LIKE :search';
        $params[':search'] = '%' . $search . '%';
    }

    $stmt = $pdo->prepare(
        "SELECT
            COUNT(*) AS total_customers,
            SUM(CASE WHEN ragione_sociale IS NOT NULL AND ragione_sociale <> '' THEN 1 ELSE 0 END) AS company_customers,
            SUM(CASE WHEN email IS NOT NULL AND email <> '' THEN 1 ELSE 0 END) AS email_customers,
            SUM(CASE WHEN telefono IS NOT NULL AND telefono <> '' THEN 1 ELSE 0 END) AS phone_customers,
            SUM(CASE WHEN note IS NOT NULL AND note <> '' THEN 1 ELSE 0 END) AS noted_customers
         FROM clienti" . $whereSql
    );
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'total_customers' => (int) ($row['total_customers'] ?? 0),
        'company_customers' => (int) ($row['company_customers'] ?? 0),
        'email_customers' => (int) ($row['email_customers'] ?? 0),
        'phone_customers' => (int) ($row['phone_customers'] ?? 0),
        'noted_customers' => (int) ($row['noted_customers'] ?? 0),
        'has_filters' => $search !== '',
    ];
}

function express_module_customer_list(PDO $pdo, string $search = '', int $page = 1, int $perPage = 10): array
{
    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;
    $sql = 'SELECT id, ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note, created_at, updated_at
            FROM clienti';

    if ($search !== '') {
        $sql .= ' WHERE ragione_sociale LIKE :search OR nome LIKE :search OR cognome LIKE :search OR email LIKE :search OR telefono LIKE :search OR cf_piva LIKE :search';
    }

    $sql .= ' ORDER BY ragione_sociale ASC, cognome ASC, nome ASC LIMIT :limit OFFSET :offset';
    $stmt = $pdo->prepare($sql);
    if ($search !== '') {
        $stmt->bindValue(':search', '%' . $search . '%', PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function express_module_customer_detail(PDO $pdo, int $customerId): ?array
{
    $stmt = $pdo->prepare('SELECT id, ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note FROM clienti WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function express_module_save_customer(PDO $pdo, array $payload, int $userId): array
{
    $customerId = (int) ($payload['customer_id'] ?? 0);
    $companyName = trim((string) ($payload['ragione_sociale'] ?? ''));
    $firstName = trim((string) ($payload['nome'] ?? ''));
    $lastName = trim((string) ($payload['cognome'] ?? ''));
    $taxCode = trim((string) ($payload['cf_piva'] ?? ''));
    $email = trim((string) ($payload['email'] ?? ''));
    $phone = trim((string) ($payload['telefono'] ?? ''));
    $address = trim((string) ($payload['indirizzo'] ?? ''));
    $note = trim((string) ($payload['note'] ?? ''));

    if ($companyName === '' && ($firstName === '' || $lastName === '')) {
        return ['success' => false, 'message' => 'Inserisci ragione sociale oppure nome e cognome del cliente.'];
    }

    try {
        if ($customerId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE clienti
                 SET ragione_sociale = :ragione_sociale, nome = :nome, cognome = :cognome, cf_piva = :cf_piva,
                     email = :email, telefono = :telefono, indirizzo = :indirizzo, note = :note, updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $customerId,
                ':ragione_sociale' => $companyName,
                ':nome' => $firstName,
                ':cognome' => $lastName,
                ':cf_piva' => $taxCode !== '' ? $taxCode : null,
                ':email' => $email !== '' ? $email : null,
                ':telefono' => $phone !== '' ? $phone : null,
                ':indirizzo' => $address !== '' ? $address : null,
                ':note' => $note !== '' ? $note : null,
            ]);

            express_module_log($pdo, $userId, 'Aggiornato cliente Express', ['cliente_id' => $customerId]);
            return ['success' => true, 'message' => 'Cliente aggiornato correttamente.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO clienti (ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note, created_at, updated_at)
             VALUES (:ragione_sociale, :nome, :cognome, :cf_piva, :email, :telefono, :indirizzo, :note, NOW(), NOW())'
        );
        $stmt->execute([
            ':ragione_sociale' => $companyName,
            ':nome' => $firstName,
            ':cognome' => $lastName,
            ':cf_piva' => $taxCode !== '' ? $taxCode : null,
            ':email' => $email !== '' ? $email : null,
            ':telefono' => $phone !== '' ? $phone : null,
            ':indirizzo' => $address !== '' ? $address : null,
            ':note' => $note !== '' ? $note : null,
        ]);

        $newId = (int) $pdo->lastInsertId();
        express_module_log($pdo, $userId, 'Creato cliente Express', ['cliente_id' => $newId]);
        return ['success' => true, 'message' => 'Cliente creato correttamente.'];
    } catch (Throwable $exception) {
        error_log('Express customer save failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile salvare il cliente.'];
    }
}

function express_module_request_type_options(): array
{
    return ['Purchase', 'Reservation', 'Deposit', 'Installment', 'Support'];
}

function express_module_request_status_options(): array
{
    return ['Pending', 'InReview', 'Confirmed', 'Completed', 'Cancelled', 'Declined'];
}

function express_module_request_summary(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_requests,
            SUM(CASE WHEN stato = 'Pending' THEN 1 ELSE 0 END) AS pending_requests,
            SUM(CASE WHEN stato = 'Completed' THEN 1 ELSE 0 END) AS completed_requests,
            SUM(CASE WHEN stato = 'Cancelled' OR stato = 'Declined' THEN 1 ELSE 0 END) AS closed_requests,
            SUM(CASE WHEN tipo_richiesta = 'Purchase' THEN 1 ELSE 0 END) AS purchase_requests,
            SUM(CASE WHEN tipo_richiesta = 'Support' THEN 1 ELSE 0 END) AS support_requests
         FROM servizi_express_richieste"
    );
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];

    return [
        'total_requests' => (int) ($row['total_requests'] ?? 0),
        'pending_requests' => (int) ($row['pending_requests'] ?? 0),
        'completed_requests' => (int) ($row['completed_requests'] ?? 0),
        'closed_requests' => (int) ($row['closed_requests'] ?? 0),
        'purchase_requests' => (int) ($row['purchase_requests'] ?? 0),
        'support_requests' => (int) ($row['support_requests'] ?? 0),
    ];
}

function express_module_request_list(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $sql = 'SELECT r.*, c.ragione_sociale, c.nome, c.cognome, p.nome AS prodotto_nome, u.nome AS user_nome, u.cognome AS user_cognome
            FROM servizi_express_richieste r
            INNER JOIN clienti c ON c.id = r.cliente_id
            LEFT JOIN servizi_express_prodotti p ON p.id = r.prodotto_id
            LEFT JOIN users u ON u.id = r.gestita_da
            ORDER BY FIELD(r.stato, "Pending", "InReview", "Confirmed", "Completed", "Cancelled", "Declined"), r.created_at DESC';
    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_request_detail(PDO $pdo, int $requestId): ?array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM servizi_express_richieste WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function express_module_save_request(PDO $pdo, array $payload, int $userId, string $role): array
{
    express_module_bootstrap_schema($pdo);

    $requestId = (int) ($payload['request_id'] ?? 0);
    $customerId = (int) ($payload['cliente_id'] ?? 0);
    $productId = (int) ($payload['product_id'] ?? 0);
    $title = trim((string) ($payload['titolo'] ?? ''));
    $type = (string) ($payload['tipo_richiesta'] ?? 'Purchase');
    $status = (string) ($payload['stato'] ?? 'Pending');
    $deposit = trim((string) ($payload['importo_acconto'] ?? ''));
    $installments = trim((string) ($payload['numero_rate'] ?? ''));
    $paymentMethod = trim((string) ($payload['metodo_pagamento'] ?? ''));
    $desiredDate = trim((string) ($payload['data_desiderata'] ?? ''));
    $customerNote = trim((string) ($payload['note_cliente'] ?? ''));
    $internalNote = trim((string) ($payload['nota_interna'] ?? ''));

    if ($customerId <= 0) {
        return ['success' => false, 'message' => 'Seleziona un cliente valido.'];
    }
    if ($title === '') {
        return ['success' => false, 'message' => 'Inserisci il titolo della richiesta.'];
    }
    if (!in_array($type, express_module_request_type_options(), true)) {
        $type = 'Purchase';
    }
    if (!in_array($status, express_module_request_status_options(), true)) {
        $status = 'Pending';
    }

    try {
        if ($requestId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE servizi_express_richieste
                 SET cliente_id = :cliente_id, prodotto_id = :prodotto_id, titolo = :titolo, tipo_richiesta = :tipo_richiesta,
                     stato = :stato, importo_acconto = :importo_acconto, numero_rate = :numero_rate, metodo_pagamento = :metodo_pagamento,
                     data_desiderata = :data_desiderata, note_cliente = :note_cliente, nota_interna = :nota_interna,
                     gestita_da = :gestita_da, gestita_il = NOW(), updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                ':id' => $requestId,
                ':cliente_id' => $customerId,
                ':prodotto_id' => $productId > 0 ? $productId : null,
                ':titolo' => $title,
                ':tipo_richiesta' => $type,
                ':stato' => $status,
                ':importo_acconto' => $deposit !== '' ? round((float) $deposit, 2) : null,
                ':numero_rate' => $installments !== '' ? max(1, (int) $installments) : null,
                ':metodo_pagamento' => $paymentMethod !== '' ? $paymentMethod : null,
                ':data_desiderata' => $desiredDate !== '' ? $desiredDate : null,
                ':note_cliente' => $customerNote !== '' ? $customerNote : null,
                ':nota_interna' => $internalNote !== '' ? $internalNote : null,
                ':gestita_da' => $userId > 0 ? $userId : null,
            ]);

            express_module_log($pdo, $userId, 'Aggiornata richiesta Express', ['richiesta_id' => $requestId, 'stato' => $status]);
            return ['success' => true, 'message' => 'Richiesta aggiornata correttamente.'];
        }

        $stmt = $pdo->prepare(
            'INSERT INTO servizi_express_richieste (
                cliente_id, prodotto_id, titolo, tipo_richiesta, stato, importo_acconto, numero_rate, metodo_pagamento,
                data_desiderata, note_cliente, nota_interna, gestita_da, gestita_il, created_at, updated_at
            ) VALUES (
                :cliente_id, :prodotto_id, :titolo, :tipo_richiesta, :stato, :importo_acconto, :numero_rate, :metodo_pagamento,
                :data_desiderata, :note_cliente, :nota_interna, NULL, NULL, NOW(), NOW()
            )'
        );
        $stmt->execute([
            ':cliente_id' => $customerId,
            ':prodotto_id' => $productId > 0 ? $productId : null,
            ':titolo' => $title,
            ':tipo_richiesta' => $type,
            ':stato' => $status,
            ':importo_acconto' => $deposit !== '' ? round((float) $deposit, 2) : null,
            ':numero_rate' => $installments !== '' ? max(1, (int) $installments) : null,
            ':metodo_pagamento' => $paymentMethod !== '' ? $paymentMethod : null,
            ':data_desiderata' => $desiredDate !== '' ? $desiredDate : null,
            ':note_cliente' => $customerNote !== '' ? $customerNote : null,
            ':nota_interna' => $internalNote !== '' ? $internalNote : null,
        ]);

        $newId = (int) $pdo->lastInsertId();
        create_notification($pdo, [
            'scope' => 'user',
            'type' => 'info',
            'title' => 'Nuova richiesta Express',
            'message' => 'Registrata richiesta cliente: ' . $title,
            'metadata' => ['module' => 'express', 'request_id' => $newId, 'customer_id' => $customerId],
        ], $userId, $role);

        express_module_log($pdo, $userId, 'Creata richiesta Express', ['richiesta_id' => $newId, 'tipo' => $type]);
        return ['success' => true, 'message' => 'Richiesta creata correttamente.'];
    } catch (Throwable $exception) {
        error_log('Express request save failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile salvare la richiesta.'];
    }
}

function express_module_notification_list(PDO $pdo, int $userId, string $role, int $limit = 50): array
{
    $payload = fetch_notifications($pdo, $userId, $role, $limit);
    $items = [];

    foreach (($payload['items'] ?? []) as $item) {
        $metadata = $item['metadata'] ?? null;
        if (is_array($metadata) && (($metadata['module'] ?? '') === 'express')) {
            $items[] = $item;
        }
    }

    return $items;
}

function express_module_client_label(array $client): string
{
    $company = trim((string) ($client['ragione_sociale'] ?? ''));
    $person = trim((string) (($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')));
    $email = trim((string) ($client['email'] ?? ''));

    $label = $company !== '' ? $company : $person;
    if ($label === '') {
        $label = 'Cliente #' . (int) ($client['id'] ?? 0);
    }
    if ($email !== '') {
        $label .= ' · ' . $email;
    }

    return $label;
}

function express_module_sale_customer_label(array $sale, string $fallback = 'Cliente libero'): string
{
    $company = trim((string) ($sale['ragione_sociale'] ?? ''));
    $person = trim((string) (($sale['cognome'] ?? '') . ' ' . ($sale['nome'] ?? '')));

    if ($company !== '') {
        return $company;
    }

    if ($person !== '') {
        return $person;
    }

    return $fallback;
}

function express_module_clean_stock_note(?string $note): string
{
    $value = trim((string) $note);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s*\[LEGACY_EXPRESS_ICCID_ID:\d+\]\s*/i', ' ', $value);
    $value = preg_replace('/\s{2,}/', ' ', (string) $value);

    return trim((string) $value);
}

function express_module_extract_sale_lines(array $payload): array
{
    $lines = [];

    $types = $payload['line_type'] ?? null;
    $descriptions = $payload['line_description'] ?? null;
    $quantities = $payload['line_quantity'] ?? null;
    $prices = $payload['line_price'] ?? null;
    $vats = $payload['line_vat'] ?? null;
    $operatorIds = $payload['line_operator_id'] ?? null;
    $iccidIds = $payload['line_iccid_stock_id'] ?? null;
    $productIds = $payload['line_product_id'] ?? null;
    $offerIds = $payload['line_offer_id'] ?? null;

    if (is_array($descriptions)) {
        $count = count($descriptions);
        for ($index = 0; $index < $count; $index++) {
            $lines[] = [
                'tipo' => is_array($types) ? (string) ($types[$index] ?? 'sim') : 'sim',
                'descrizione' => (string) ($descriptions[$index] ?? ''),
                'quantita' => is_array($quantities) ? (int) ($quantities[$index] ?? 1) : 1,
                'prezzo_unitario' => is_array($prices) ? (float) ($prices[$index] ?? 0) : 0,
                'aliquota_iva' => is_array($vats) ? (float) ($vats[$index] ?? 22) : 22,
                'operatore_id' => is_array($operatorIds) ? (int) ($operatorIds[$index] ?? 0) : 0,
                'iccid_stock_id' => is_array($iccidIds) ? (int) ($iccidIds[$index] ?? 0) : 0,
                'product_id' => is_array($productIds) ? (int) ($productIds[$index] ?? 0) : 0,
                'offer_id' => is_array($offerIds) ? (int) ($offerIds[$index] ?? 0) : 0,
            ];
        }
    }

    if ($lines === []) {
        $lines[] = [
            'tipo' => (string) ($payload['tipo'] ?? 'sim'),
            'descrizione' => (string) ($payload['descrizione'] ?? ''),
            'quantita' => (int) ($payload['quantita'] ?? 1),
            'prezzo_unitario' => (float) ($payload['prezzo_unitario'] ?? 0),
            'aliquota_iva' => (float) ($payload['aliquota_iva'] ?? 22),
            'operatore_id' => (int) ($payload['operatore_id'] ?? 0),
            'iccid_stock_id' => (int) ($payload['iccid_stock_id'] ?? 0),
            'product_id' => (int) ($payload['product_id'] ?? 0),
            'offer_id' => (int) ($payload['offer_id'] ?? 0),
        ];
    }

    return array_values(array_filter($lines, static function (array $line): bool {
        return trim((string) ($line['descrizione'] ?? '')) !== ''
            || (int) ($line['iccid_stock_id'] ?? 0) > 0
            || (int) ($line['product_id'] ?? 0) > 0
            || (int) ($line['offer_id'] ?? 0) > 0;
    }));
}

function express_module_create_sale(PDO $pdo, array $payload, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    $clientId = (int) ($payload['cliente_id'] ?? 0);
    $paymentMethod = trim((string) ($payload['metodo_pagamento'] ?? 'Contanti'));
    $soldAt = trim((string) ($payload['data_vendita'] ?? ''));
    $note = trim((string) ($payload['note'] ?? ''));
    $rawLines = express_module_extract_sale_lines($payload);

    if ($rawLines === []) {
        return ['success' => false, 'message' => 'Aggiungi almeno una riga vendita prima di salvare.'];
    }

    try {
        $soldAtValue = new DateTimeImmutable($soldAt !== '' ? $soldAt : 'now');
    } catch (Throwable $exception) {
        return ['success' => false, 'message' => 'Data vendita non valida.'];
    }

    if ($clientId > 0) {
        $clientStmt = $pdo->prepare('SELECT id FROM clienti WHERE id = :id LIMIT 1');
        $clientStmt->execute([':id' => $clientId]);
        if (!$clientStmt->fetchColumn()) {
            return ['success' => false, 'message' => 'Cliente non trovato.'];
        }
    }

    $validatedLines = [];
    $total = 0.0;
    $movementParts = [];
    $usedIccidIds = [];
    $reservedProductQuantities = [];

    foreach ($rawLines as $index => $rawLine) {
        $lineNumber = $index + 1;
        $description = trim((string) ($rawLine['descrizione'] ?? ''));
        $quantity = max(1, (int) ($rawLine['quantita'] ?? 1));
        $unitPrice = round((float) ($rawLine['prezzo_unitario'] ?? 0), 2);
        $vatRate = round((float) ($rawLine['aliquota_iva'] ?? 22), 2);
        $operatorId = (int) ($rawLine['operatore_id'] ?? 0);
        $iccidId = (int) ($rawLine['iccid_stock_id'] ?? 0);
        $productId = (int) ($rawLine['product_id'] ?? 0);
        $offerId = (int) ($rawLine['offer_id'] ?? 0);
        $type = trim((string) ($rawLine['tipo'] ?? 'sim'));

        if ($productId > 0 && $iccidId > 0) {
            return ['success' => false, 'message' => 'La riga ' . $lineNumber . ' non può avere insieme prodotto di catalogo e ICCID.'];
        }

        if ($unitPrice < 0) {
            return ['success' => false, 'message' => 'Il prezzo unitario della riga ' . $lineNumber . ' non può essere negativo.'];
        }

        if ($vatRate < 0 || $vatRate > 100) {
            return ['success' => false, 'message' => 'Aliquota IVA non valida alla riga ' . $lineNumber . '.'];
        }

        $productRow = null;
        if ($productId > 0) {
            $reservedProductQuantities[$productId] = ($reservedProductQuantities[$productId] ?? 0) + $quantity;
            $productRow = express_module_product_detail($pdo, $productId);
            if ($productRow === null || (int) ($productRow['attivo'] ?? 0) !== 1) {
                return ['success' => false, 'message' => 'Prodotto non disponibile alla riga ' . $lineNumber . '.'];
            }
            if ((int) ($productRow['stock_quantita'] ?? 0) < $reservedProductQuantities[$productId]) {
                return ['success' => false, 'message' => 'Stock prodotto insufficiente alla riga ' . $lineNumber . '.'];
            }

            $description = (string) ($productRow['nome'] ?? $description);
            $unitPrice = round((float) ($productRow['prezzo'] ?? $unitPrice), 2);
            $vatRate = round((float) ($productRow['aliquota_iva'] ?? $vatRate), 2);
            $type = 'prodotto';
        }

        $offerRow = null;
        if ($offerId > 0) {
            $offerRow = express_module_offer_detail($pdo, $offerId);
            if ($offerRow === null || (string) ($offerRow['stato'] ?? '') !== 'Active') {
                return ['success' => false, 'message' => 'Offerta non disponibile alla riga ' . $lineNumber . '.'];
            }
            $today = (new DateTimeImmutable('today'))->format('Y-m-d');
            if (!empty($offerRow['valid_from']) && (string) $offerRow['valid_from'] > $today) {
                return ['success' => false, 'message' => 'Offerta non ancora attiva alla riga ' . $lineNumber . '.'];
            }
            if (!empty($offerRow['valid_to']) && (string) $offerRow['valid_to'] < $today) {
                return ['success' => false, 'message' => 'Offerta scaduta alla riga ' . $lineNumber . '.'];
            }

            if ($description === '') {
                $description = (string) ($offerRow['titolo'] ?? 'Offerta Express');
            }
            if ($unitPrice <= 0) {
                $unitPrice = round((float) ($offerRow['prezzo'] ?? 0), 2);
            }
            if ($operatorId <= 0) {
                $operatorId = (int) ($offerRow['operatore_id'] ?? 0);
            }
            if ($type === 'sim') {
                $type = $iccidId > 0 ? 'sim' : 'servizio';
            }
        }

        $iccidRow = null;
        if ($iccidId > 0) {
            if (in_array($iccidId, $usedIccidIds, true)) {
                return ['success' => false, 'message' => 'Lo stesso ICCID è stato inserito più volte nella vendita.'];
            }

            $stockStmt = $pdo->prepare(
                "SELECT s.id, s.iccid, s.operatore_id, s.stato, o.nome AS operatore
                 FROM servizi_express_iccid_stock s
                 INNER JOIN servizi_express_operatori o ON o.id = s.operatore_id
                 WHERE s.id = :id
                 LIMIT 1"
            );
            $stockStmt->execute([':id' => $iccidId]);
            $iccidRow = $stockStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($iccidRow === null || ($iccidRow['stato'] ?? '') !== 'InStock') {
                return ['success' => false, 'message' => 'ICCID non disponibile alla riga ' . $lineNumber . '.'];
            }

            $operatorId = (int) ($iccidRow['operatore_id'] ?? 0);
            $type = 'sim';
            $usedIccidIds[] = $iccidId;

            if ($description === '' && $offerRow !== null) {
                $description = (string) ($offerRow['titolo'] ?? 'Vendita SIM');
            }
        }

        if ($description === '') {
            return ['success' => false, 'message' => 'Inserisci una descrizione o seleziona un articolo alla riga ' . $lineNumber . '.'];
        }

        $lineTotal = round($quantity * $unitPrice, 2);
        $total += $lineTotal;
        $validatedLines[] = [
            'tipo' => in_array($type, ['sim', 'prodotto', 'servizio'], true) ? $type : 'sim',
            'descrizione' => $description,
            'quantita' => $quantity,
            'prezzo_unitario' => $unitPrice,
            'aliquota_iva' => $vatRate,
            'operatore_id' => $operatorId > 0 ? $operatorId : null,
            'iccid_stock_id' => $iccidId > 0 ? $iccidId : null,
            'product_id' => $productId > 0 ? $productId : null,
            'offer_id' => $offerId > 0 ? $offerId : null,
            'totale_riga' => $lineTotal,
            'iccid' => (string) ($iccidRow['iccid'] ?? ''),
        ];

        $movementParts[] = $description . ($iccidRow !== null && !empty($iccidRow['iccid']) ? ' - ICCID ' . $iccidRow['iccid'] : '');
    }

    $total = round($total, 2);

    try {
        $pdo->beginTransaction();

        $saleStmt = $pdo->prepare(
            'INSERT INTO servizi_express_vendite (
                cliente_id, user_id, totale, iva, sconto, metodo_pagamento, stato, data_vendita, note, created_at, updated_at
            ) VALUES (
                :cliente_id, :user_id, :totale, :iva, :sconto, :metodo_pagamento, :stato, :data_vendita, :note, NOW(), NOW()
            )'
        );
        $saleStmt->execute([
            ':cliente_id' => $clientId > 0 ? $clientId : null,
            ':user_id' => $userId > 0 ? $userId : null,
            ':totale' => $total,
            ':iva' => round((float) ($validatedLines[0]['aliquota_iva'] ?? 22), 2),
            ':sconto' => 0,
            ':metodo_pagamento' => $paymentMethod,
            ':stato' => 'Completata',
            ':data_vendita' => $soldAtValue->format('Y-m-d H:i:s'),
            ':note' => $note !== '' ? $note : null,
        ]);
        $saleId = (int) $pdo->lastInsertId();

        $lineStmt = $pdo->prepare(
            'INSERT INTO servizi_express_vendita_righe (
                vendita_id, operatore_id, iccid_stock_id, tipo, descrizione, quantita, prezzo_unitario, aliquota_iva, totale_riga, created_at
            ) VALUES (
                :vendita_id, :operatore_id, :iccid_stock_id, :tipo, :descrizione, :quantita, :prezzo_unitario, :aliquota_iva, :totale_riga, NOW()
            )'
        );
        $updateLineStmt = $pdo->prepare(
            'UPDATE servizi_express_vendita_righe
             SET prodotto_id = :prodotto_id, offerta_id = :offerta_id, sconto_riga = :sconto_riga
             WHERE id = :id'
        );

        foreach ($validatedLines as $line) {
            $lineStmt->execute([
                ':vendita_id' => $saleId,
                ':operatore_id' => $line['operatore_id'],
                ':iccid_stock_id' => $line['iccid_stock_id'],
                ':tipo' => $line['tipo'],
                ':descrizione' => $line['descrizione'],
                ':quantita' => $line['quantita'],
                ':prezzo_unitario' => $line['prezzo_unitario'],
                ':aliquota_iva' => $line['aliquota_iva'],
                ':totale_riga' => $line['totale_riga'],
            ]);

            $lineId = (int) $pdo->lastInsertId();
            $updateLineStmt->execute([
                ':prodotto_id' => $line['product_id'],
                ':offerta_id' => $line['offer_id'],
                ':sconto_riga' => 0,
                ':id' => $lineId,
            ]);
        }

        $movementDescription = 'Vendita Express - ' . mb_substr(implode(' | ', array_slice($movementParts, 0, 3)), 0, 180);
        if (count($movementParts) > 3) {
            $movementDescription = mb_substr($movementDescription . ' +' . (count($movementParts) - 3) . ' righe', 0, 180);
        }

        $movementStmt = $pdo->prepare(
            'INSERT INTO entrate_uscite (
                cliente_id, tipo_movimento, descrizione, listino_voce, metodo, stato, importo, quantita, prezzo_unitario, data_pagamento, note, created_at, updated_at
            ) VALUES (
                :cliente_id, :tipo_movimento, :descrizione, :listino_voce, :metodo, :stato, :importo, :quantita, :prezzo_unitario, :data_pagamento, :note, NOW(), NOW()
            )'
        );
        $movementStmt->execute([
            ':cliente_id' => $clientId > 0 ? $clientId : null,
            ':tipo_movimento' => 'Entrata',
            ':descrizione' => mb_substr($movementDescription, 0, 180),
            ':listino_voce' => mb_substr($validatedLines[0]['descrizione'] . (count($validatedLines) > 1 ? ' +' . (count($validatedLines) - 1) : ''), 0, 180),
            ':metodo' => $paymentMethod,
            ':stato' => 'Pagato',
            ':importo' => $total,
            ':quantita' => array_sum(array_map(static fn (array $line): int => (int) $line['quantita'], $validatedLines)),
            ':prezzo_unitario' => $total,
            ':data_pagamento' => $soldAtValue->format('Y-m-d'),
            ':note' => $note !== '' ? $note : null,
        ]);
        $movementId = (int) $pdo->lastInsertId();

        $updateSale = $pdo->prepare('UPDATE servizi_express_vendite SET entrata_uscita_id = :entrata_uscita_id WHERE id = :id');
        $updateSale->execute([
            ':entrata_uscita_id' => $movementId,
            ':id' => $saleId,
        ]);

        $stockUpdate = $pdo->prepare(
            "UPDATE servizi_express_iccid_stock
             SET stato = 'Sold', vendita_id = :vendita_id, updated_at = NOW()
             WHERE id = :id"
        );
        $productUpdate = $pdo->prepare(
            'UPDATE servizi_express_prodotti
             SET stock_quantita = GREATEST(stock_quantita - :quantity, 0), updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($validatedLines as $line) {
            if (!empty($line['iccid_stock_id'])) {
                $stockUpdate->execute([
                    ':vendita_id' => $saleId,
                    ':id' => $line['iccid_stock_id'],
                ]);
            }

            if (!empty($line['product_id'])) {
                $productUpdate->execute([
                    ':quantity' => $line['quantita'],
                    ':id' => $line['product_id'],
                ]);
            }
        }

        express_module_log($pdo, $userId, 'Creata vendita Express', [
            'vendita_id' => $saleId,
            'cliente_id' => $clientId,
            'totale' => $total,
            'righe' => count($validatedLines),
            'dettaglio_righe' => array_map(static function (array $line): array {
                return [
                    'tipo' => (string) ($line['tipo'] ?? ''),
                    'descrizione' => (string) ($line['descrizione'] ?? ''),
                    'quantita' => (int) ($line['quantita'] ?? 0),
                    'prezzo_unitario' => (float) ($line['prezzo_unitario'] ?? 0),
                    'totale_riga' => (float) ($line['totale_riga'] ?? 0),
                    'operatore_id' => (int) ($line['operatore_id'] ?? 0),
                    'iccid_stock_id' => (int) ($line['iccid_stock_id'] ?? 0),
                    'product_id' => (int) ($line['product_id'] ?? 0),
                    'offer_id' => (int) ($line['offer_id'] ?? 0),
                ];
            }, $validatedLines),
        ]);

        $pdo->commit();

        return ['success' => true, 'message' => 'Vendita registrata correttamente.', 'sale_id' => $saleId];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Express sale create failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile registrare la vendita.'];
    }
}

function express_module_reverse_sale_inventory(PDO $pdo, array $sale): void
{
    foreach (($sale['items'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $iccidStockId = (int) ($item['iccid_stock_id'] ?? 0);
        if ($iccidStockId > 0) {
            $stockUpdate = $pdo->prepare(
                "UPDATE servizi_express_iccid_stock
                 SET stato = 'InStock', vendita_id = NULL, updated_at = NOW()
                 WHERE id = :id"
            );
            $stockUpdate->execute([':id' => $iccidStockId]);
        }

        $productId = (int) ($item['prodotto_id'] ?? 0);
        $quantity = max(1, (int) ($item['quantita'] ?? 1));
        if ($productId > 0) {
            $productUpdate = $pdo->prepare(
                'UPDATE servizi_express_prodotti
                 SET stock_quantita = stock_quantita + :quantity, updated_at = NOW()
                 WHERE id = :id'
            );
            $productUpdate->execute([
                ':quantity' => $quantity,
                ':id' => $productId,
            ]);
        }
    }
}

function express_module_append_sale_note(?string $existingNote, string $actionLabel, string $reason): ?string
{
    $parts = [];
    $baseNote = trim((string) $existingNote);
    if ($baseNote !== '') {
        $parts[] = $baseNote;
    }

    $entry = $actionLabel;
    if ($reason !== '') {
        $entry .= ': ' . $reason;
    }
    $parts[] = '[' . (new DateTimeImmutable('now'))->format('d/m/Y H:i') . '] ' . $entry;

    $finalNote = trim(implode(PHP_EOL . PHP_EOL, $parts));
    return $finalNote !== '' ? $finalNote : null;
}

function express_module_register_sale_reversal_movement(PDO $pdo, array $sale, string $actionLabel, string $reason): void
{
    $movementStmt = $pdo->prepare(
        'INSERT INTO entrate_uscite (
            cliente_id, tipo_movimento, descrizione, listino_voce, riferimento, metodo, stato, importo, quantita, prezzo_unitario, data_pagamento, note, created_at, updated_at
        ) VALUES (
            :cliente_id, :tipo_movimento, :descrizione, :listino_voce, :riferimento, :metodo, :stato, :importo, :quantita, :prezzo_unitario, :data_pagamento, :note, NOW(), NOW()
        )'
    );

    $saleId = (int) ($sale['id'] ?? 0);
    $movementStmt->execute([
        ':cliente_id' => (int) ($sale['cliente_id'] ?? 0) ?: null,
        ':tipo_movimento' => 'Uscita',
        ':descrizione' => mb_substr($actionLabel . ' vendita Express #' . $saleId, 0, 180),
        ':listino_voce' => mb_substr('Vendita Express #' . $saleId, 0, 180),
        ':riferimento' => 'EXP-' . $saleId,
        ':metodo' => (string) ($sale['metodo_pagamento'] ?? 'Contanti'),
        ':stato' => 'Pagato',
        ':importo' => round((float) ($sale['totale'] ?? 0), 2),
        ':quantita' => 1,
        ':prezzo_unitario' => round((float) ($sale['totale'] ?? 0), 2),
        ':data_pagamento' => !empty($sale['data_vendita']) ? substr((string) $sale['data_vendita'], 0, 10) : date('Y-m-d'),
        ':note' => $reason !== '' ? mb_substr($reason, 0, 65535) : null,
    ]);
}

function express_module_register_partial_refund_movement(PDO $pdo, array $sale, array $refundLines, string $reason): void
{
    $saleId = (int) ($sale['id'] ?? 0);
    $amount = 0.0;
    $quantity = 0;
    $descriptions = [];

    foreach ($refundLines as $line) {
        $refundQuantity = max(1, (int) ($line['refund_quantity'] ?? 0));
        $unitPrice = round((float) ($line['prezzo_unitario'] ?? 0), 2);
        $amount += $refundQuantity * $unitPrice;
        $quantity += $refundQuantity;
        $descriptions[] = (string) ($line['descrizione'] ?? 'Voce Express');
    }

    $amount = round($amount, 2);
    $summary = implode(' | ', array_slice($descriptions, 0, 3));
    if (count($descriptions) > 3) {
        $summary .= ' +' . (count($descriptions) - 3) . ' righe';
    }

    $movementStmt = $pdo->prepare(
        'INSERT INTO entrate_uscite (
            cliente_id, tipo_movimento, descrizione, listino_voce, riferimento, metodo, stato, importo, quantita, prezzo_unitario, data_pagamento, note, created_at, updated_at
        ) VALUES (
            :cliente_id, :tipo_movimento, :descrizione, :listino_voce, :riferimento, :metodo, :stato, :importo, :quantita, :prezzo_unitario, :data_pagamento, :note, NOW(), NOW()
        )'
    );

    $movementStmt->execute([
        ':cliente_id' => (int) ($sale['cliente_id'] ?? 0) ?: null,
        ':tipo_movimento' => 'Uscita',
        ':descrizione' => mb_substr('Reso parziale vendita Express #' . $saleId . ' - ' . $summary, 0, 180),
        ':listino_voce' => mb_substr($summary !== '' ? $summary : 'Vendita Express #' . $saleId, 0, 180),
        ':riferimento' => 'EXP-PARTIAL-' . $saleId,
        ':metodo' => (string) ($sale['metodo_pagamento'] ?? 'Contanti'),
        ':stato' => 'Pagato',
        ':importo' => $amount,
        ':quantita' => max(1, $quantity),
        ':prezzo_unitario' => $amount,
        ':data_pagamento' => !empty($sale['data_vendita']) ? substr((string) $sale['data_vendita'], 0, 10) : date('Y-m-d'),
        ':note' => $reason !== '' ? mb_substr($reason, 0, 65535) : null,
    ]);
}

function express_module_refund_sale_partial(PDO $pdo, int $saleId, array $refundQuantities, string $reason, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    $sale = express_module_sale_detail($pdo, $saleId);
    if ($sale === null) {
        return ['success' => false, 'message' => 'Vendita non trovata.'];
    }

    if (!in_array((string) ($sale['stato'] ?? ''), ['Completata', 'Rimborsata'], true)) {
        return ['success' => false, 'message' => 'La vendita non consente un reso parziale nello stato attuale.'];
    }

    $selectedLines = [];
    foreach (($sale['items'] ?? []) as $item) {
        $lineId = (int) ($item['id'] ?? 0);
        if ($lineId <= 0) {
            continue;
        }

        $requestedRefund = max(0, (int) ($refundQuantities[$lineId] ?? 0));
        if ($requestedRefund <= 0) {
            continue;
        }

        $soldQuantity = max(1, (int) ($item['quantita'] ?? 1));
        $alreadyRefunded = max(0, (int) ($item['quantita_resa'] ?? 0));
        $availableRefund = max(0, $soldQuantity - $alreadyRefunded);
        if ($requestedRefund > $availableRefund) {
            return ['success' => false, 'message' => 'La quantità di reso supera il disponibile per la riga #' . $lineId . '.'];
        }

        $item['refund_quantity'] = $requestedRefund;
        $selectedLines[] = $item;
    }

    if ($selectedLines === []) {
        return ['success' => false, 'message' => 'Seleziona almeno una riga con quantità di reso maggiore di zero.'];
    }

    try {
        $pdo->beginTransaction();

        $refundUpdateStmt = $pdo->prepare(
            'UPDATE servizi_express_vendita_righe
             SET quantita_resa = quantita_resa + :refund_quantity
             WHERE id = :id'
        );
        $stockUpdate = $pdo->prepare(
            "UPDATE servizi_express_iccid_stock
             SET stato = 'InStock', vendita_id = NULL, updated_at = NOW()
             WHERE id = :id"
        );
        $productUpdate = $pdo->prepare(
            'UPDATE servizi_express_prodotti
             SET stock_quantita = stock_quantita + :quantity, updated_at = NOW()
             WHERE id = :id'
        );

        foreach ($selectedLines as $line) {
            $refundQuantity = (int) $line['refund_quantity'];
            $refundUpdateStmt->execute([
                ':refund_quantity' => $refundQuantity,
                ':id' => (int) $line['id'],
            ]);

            if ((int) ($line['iccid_stock_id'] ?? 0) > 0 && $refundQuantity > 0) {
                $stockUpdate->execute([':id' => (int) $line['iccid_stock_id']]);
            }

            if ((int) ($line['prodotto_id'] ?? 0) > 0 && $refundQuantity > 0) {
                $productUpdate->execute([
                    ':quantity' => $refundQuantity,
                    ':id' => (int) $line['prodotto_id'],
                ]);
            }
        }

        express_module_register_partial_refund_movement($pdo, $sale, $selectedLines, $reason);

        $reloadedSale = express_module_sale_detail($pdo, $saleId);
        $isFullyRefunded = true;
        foreach (($reloadedSale['items'] ?? []) as $item) {
            $soldQuantity = max(1, (int) ($item['quantita'] ?? 1));
            $alreadyRefunded = max(0, (int) ($item['quantita_resa'] ?? 0));
            if ($alreadyRefunded < $soldQuantity) {
                $isFullyRefunded = false;
                break;
            }
        }

        $updateSale = $pdo->prepare(
            'UPDATE servizi_express_vendite
             SET stato = :stato, note = :note, updated_at = NOW()
             WHERE id = :id'
        );
        $updateSale->execute([
            ':stato' => $isFullyRefunded ? 'Rimborsata' : 'Completata',
            ':note' => express_module_append_sale_note((string) ($sale['note'] ?? ''), 'Reso parziale', $reason),
            ':id' => $saleId,
        ]);

        express_module_log($pdo, $userId, 'Reso parziale vendita Express', [
            'vendita_id' => $saleId,
            'righe' => array_map(static fn (array $line): array => [
                'riga_id' => (int) ($line['id'] ?? 0),
                'descrizione' => (string) ($line['descrizione'] ?? ''),
                'quantita_resa' => (int) ($line['refund_quantity'] ?? 0),
            ], $selectedLines),
            'motivo' => $reason,
        ]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => $isFullyRefunded
                ? 'Reso completato: tutte le righe risultano rimborsate.'
                : 'Reso parziale registrato correttamente.',
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Express partial refund failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile registrare il reso parziale.'];
    }
}

function express_module_change_sale_status(PDO $pdo, int $saleId, string $targetStatus, string $reason, int $userId): array
{
    express_module_bootstrap_schema($pdo);

    if (!in_array($targetStatus, ['Annullata', 'Rimborsata'], true)) {
        return ['success' => false, 'message' => 'Stato vendita non supportato.'];
    }

    $sale = express_module_sale_detail($pdo, $saleId);
    if ($sale === null) {
        return ['success' => false, 'message' => 'Vendita non trovata.'];
    }

    if ((string) ($sale['stato'] ?? '') !== 'Completata') {
        return ['success' => false, 'message' => 'Solo le vendite completate possono essere annullate o rimborsate.'];
    }

    $actionLabel = $targetStatus === 'Annullata' ? 'Annullamento' : 'Rimborso';

    try {
        $pdo->beginTransaction();

        express_module_reverse_sale_inventory($pdo, $sale);
        express_module_register_sale_reversal_movement($pdo, $sale, $actionLabel, $reason);

        $updateSale = $pdo->prepare(
            'UPDATE servizi_express_vendite
             SET stato = :stato, note = :note, updated_at = NOW()
             WHERE id = :id'
        );
        $updateSale->execute([
            ':stato' => $targetStatus,
            ':note' => express_module_append_sale_note((string) ($sale['note'] ?? ''), $actionLabel, $reason),
            ':id' => $saleId,
        ]);

        express_module_log($pdo, $userId, $actionLabel . ' vendita Express', [
            'vendita_id' => $saleId,
            'nuovo_stato' => $targetStatus,
            'motivo' => $reason,
            'righe_coinvolte' => array_map(static fn (array $item): array => [
                'riga_id' => (int) ($item['id'] ?? 0),
                'descrizione' => (string) ($item['descrizione'] ?? ''),
                'quantita' => (int) ($item['quantita'] ?? 0),
                'iccid_stock_id' => (int) ($item['iccid_stock_id'] ?? 0),
                'prodotto_id' => (int) ($item['prodotto_id'] ?? 0),
            ], (array) ($sale['items'] ?? [])),
        ]);

        $pdo->commit();

        return [
            'success' => true,
            'message' => $targetStatus === 'Annullata'
                ? 'Vendita annullata e stock ripristinato.'
                : 'Reso registrato e stock ripristinato.',
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Express sale status change failed: ' . $exception->getMessage());
        return ['success' => false, 'message' => 'Impossibile aggiornare la vendita.'];
    }
}

function express_module_cancel_sale(PDO $pdo, int $saleId, string $reason, int $userId): array
{
    return express_module_change_sale_status($pdo, $saleId, 'Annullata', $reason, $userId);
}

function express_module_refund_sale(PDO $pdo, int $saleId, string $reason, int $userId): array
{
    return express_module_change_sale_status($pdo, $saleId, 'Rimborsata', $reason, $userId);
}

function express_module_sales_rows(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $sql = "SELECT v.id,
                   v.totale,
                   v.iva,
                   v.metodo_pagamento,
                   v.stato,
                   v.data_vendita,
                   v.entrata_uscita_id,
                   c.ragione_sociale,
                   c.nome,
                   c.cognome,
                   u.nome AS user_nome,
                   u.cognome AS user_cognome,
                   COUNT(r.id) AS righe
            FROM servizi_express_vendite v
            LEFT JOIN clienti c ON c.id = v.cliente_id
            LEFT JOIN users u ON u.id = v.user_id
            LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
            GROUP BY v.id, v.totale, v.iva, v.metodo_pagamento, v.stato, v.data_vendita, v.entrata_uscita_id, c.ragione_sociale, c.nome, c.cognome, u.nome, u.cognome
            ORDER BY v.data_vendita DESC, v.id DESC";

    $stmt = $pdo->query($sql);
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

function express_module_sales_summary(PDO $pdo): array
{
    express_module_bootstrap_schema($pdo);

    $stmt = $pdo->query(
        "SELECT
            COUNT(*) AS total_sales,
            COALESCE(SUM(CASE WHEN stato = 'Completata' THEN totale ELSE 0 END), 0) AS completed_revenue,
            SUM(CASE WHEN stato = 'Completata' THEN 1 ELSE 0 END) AS completed_sales,
            SUM(CASE WHEN stato = 'Annullata' THEN 1 ELSE 0 END) AS cancelled_sales,
            SUM(CASE WHEN stato = 'Rimborsata' THEN 1 ELSE 0 END) AS refunded_sales,
            MAX(data_vendita) AS latest_sale_at
         FROM servizi_express_vendite"
    );
    $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $completedSales = (int) ($row['completed_sales'] ?? 0);
    $completedRevenue = (float) ($row['completed_revenue'] ?? 0);

    return [
        'total_sales' => (int) ($row['total_sales'] ?? 0),
        'completed_revenue' => $completedRevenue,
        'completed_sales' => $completedSales,
        'average_ticket' => $completedSales > 0 ? $completedRevenue / $completedSales : 0.0,
        'cancelled_sales' => (int) ($row['cancelled_sales'] ?? 0),
        'refunded_sales' => (int) ($row['refunded_sales'] ?? 0),
        'latest_sale_at' => (string) ($row['latest_sale_at'] ?? ''),
    ];
}

function express_module_sale_detail(PDO $pdo, int $saleId): ?array
{
    express_module_bootstrap_schema($pdo);

    $saleStmt = $pdo->prepare(
        "SELECT v.*, c.ragione_sociale, c.nome, c.cognome, c.email, c.telefono,
                u.nome AS user_nome, u.cognome AS user_cognome
         FROM servizi_express_vendite v
         LEFT JOIN clienti c ON c.id = v.cliente_id
         LEFT JOIN users u ON u.id = v.user_id
         WHERE v.id = :id
         LIMIT 1"
    );
    $saleStmt->execute([':id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($sale === null) {
        return null;
    }

    $itemsStmt = $pdo->prepare(
           "SELECT r.*, o.nome AS operatore, s.iccid, p.nome AS prodotto_nome, f.titolo AS offerta_titolo
         FROM servizi_express_vendita_righe r
         LEFT JOIN servizi_express_operatori o ON o.id = r.operatore_id
         LEFT JOIN servizi_express_iccid_stock s ON s.id = r.iccid_stock_id
            LEFT JOIN servizi_express_prodotti p ON p.id = r.prodotto_id
            LEFT JOIN servizi_express_offerte f ON f.id = r.offerta_id
         WHERE r.vendita_id = :id
         ORDER BY r.id ASC"
    );
    $itemsStmt->execute([':id' => $saleId]);
    $sale['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $sale;
}

function express_module_company_settings(PDO $pdo): array
{
    static $cached = null;

    if (is_array($cached)) {
        return $cached;
    }

    $defaults = [
        'ragione_sociale' => 'Express Telefonia',
        'indirizzo' => '',
        'cap' => '',
        'citta' => '',
        'provincia' => '',
        'telefono' => '',
        'email' => '',
        'pec' => '',
        'sdi' => '',
        'vat_country' => 'IT',
        'piva' => '',
        'iban' => '',
        'note' => '',
        'company_logo' => '',
    ];

    try {
        $projectRoot = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../';
        $settingsService = new SettingsService($pdo, $projectRoot);
        $cached = $settingsService->fetchCompanySettings($defaults);
    } catch (Throwable $exception) {
        error_log('Express company settings load failed: ' . $exception->getMessage());
        $cached = $defaults;
    }

    return $cached;
}

function express_module_company_print_header(PDO $pdo): array
{
    $config = express_module_company_settings($pdo);

    $companyName = trim((string) ($config['ragione_sociale'] ?? ''));
    if ($companyName === '') {
        $companyName = 'Express Telefonia';
    }

    $addressParts = array_filter([
        trim((string) ($config['indirizzo'] ?? '')),
        trim(implode(' ', array_filter([
            trim((string) ($config['cap'] ?? '')),
            trim((string) ($config['citta'] ?? '')),
            trim((string) ($config['provincia'] ?? '')),
        ]))),
    ], static fn ($value): bool => $value !== '');

    return [
        'company_name' => $companyName,
        'address_lines' => array_values($addressParts),
        'phone' => trim((string) ($config['telefono'] ?? '')),
        'email' => trim((string) ($config['email'] ?? '')),
    ];
}

function express_module_sale_document_note(array $sale): string
{
    $items = is_array($sale['items'] ?? null) ? $sale['items'] : [];
    $onlySimItems = $items !== [];

    foreach ($items as $item) {
        if (!is_array($item) || (string) ($item['tipo'] ?? '') !== 'sim') {
            $onlySimItems = false;
            break;
        }
    }

    if ($onlySimItems) {
        return "Operazione non soggetta a IVA ai sensi dell'art. 74 DPR 633/72";
    }

    return 'Documento gestionale interno non valido ai fini fiscali.';
}

function express_module_render_nav(string $active): void
{
    $items = [
        'dashboard' => ['label' => 'Dashboard', 'href' => express_module_url('index')],
        'stock' => ['label' => 'Stock ICCID', 'href' => express_module_url('stock')],
        'products' => ['label' => 'Prodotti', 'href' => express_module_url('products')],
        'offers' => ['label' => 'Offerte', 'href' => express_module_url('offers')],
        'customers' => ['label' => 'Clienti', 'href' => express_module_url('customers')],
        'requests' => ['label' => 'Richieste', 'href' => express_module_url('requests')],
        'notifications' => ['label' => 'Notifiche', 'href' => express_module_url('notifications')],
        'sales' => ['label' => 'Vendite', 'href' => express_module_url('sales')],
        'reports' => ['label' => 'Report', 'href' => express_module_url('reports')],
        'new-sale' => ['label' => 'Nuova vendita', 'href' => express_module_url('create_sale')],
    ];
    ?>
    <div class="card ag-card mb-4">
        <div class="card-body py-3">
            <div class="d-flex flex-wrap gap-2 align-items-center justify-content-between">
                <div>
                    <h1 class="h3 mb-0">Express Telefonia</h1>
                    <p class="text-muted mb-0">Gestione operativa di ICCID, vendite, offerte e clienti del reparto telefonia.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($items as $key => $item): ?>
                        <a class="btn <?php echo $active === $key ? 'btn-warning text-dark' : 'btn-outline-secondary'; ?>" href="<?php echo $item['href']; ?>">
                            <?php echo sanitize_output($item['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function express_module_url(string $path = '', array $query = []): string
{
    $normalizedPath = trim($path, '/');
    $url = base_url('modules/servizi/express' . ($normalizedPath !== '' ? '/' . $normalizedPath : ''));

    if ($query !== []) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}
