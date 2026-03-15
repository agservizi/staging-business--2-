<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

class ExpressPortalService
{
    public function ensureSchema(): void
    {
        $pdo = PortalDatabase::getConnection();

        $statements = [
            "CREATE TABLE IF NOT EXISTS servizi_express_portale_clienti (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pickup_customer_id INT NOT NULL,
                cliente_id INT UNSIGNED NOT NULL,
                stato ENUM('active','disabled') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_servizi_express_portale_pickup (pickup_customer_id),
                UNIQUE KEY uniq_servizi_express_portale_cliente (cliente_id),
                INDEX idx_servizi_express_portale_stato (stato),
                CONSTRAINT fk_servizi_express_portale_pickup FOREIGN KEY (pickup_customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_servizi_express_portale_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];

        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
    }

    public function resolveBusinessCustomer(array $portalCustomer): ?array
    {
        $this->ensureSchema();

        $pickupCustomerId = (int) ($portalCustomer['id'] ?? 0);
        if ($pickupCustomerId <= 0) {
            return null;
        }

        $mapped = portal_fetch_one(
            'SELECT c.*
             FROM servizi_express_portale_clienti l
             INNER JOIN clienti c ON c.id = l.cliente_id
             WHERE l.pickup_customer_id = ? AND l.stato = ?',
            [$pickupCustomerId, 'active']
        );
        if ($mapped) {
            return $mapped;
        }

        $email = trim((string) ($portalCustomer['email'] ?? ''));
        $phone = trim((string) ($portalCustomer['phone'] ?? ''));

        $sql = 'SELECT * FROM clienti WHERE 1=0';
        $params = [];
        if ($email !== '') {
            $sql = 'SELECT * FROM clienti WHERE email = ? LIMIT 1';
            $params = [$email];
        } elseif ($phone !== '') {
            $sql = 'SELECT * FROM clienti WHERE telefono = ? LIMIT 1';
            $params = [$phone];
        } else {
            return null;
        }

        $customer = portal_fetch_one($sql, $params);
        if (!$customer) {
            return null;
        }

        portal_insert('servizi_express_portale_clienti', [
            'pickup_customer_id' => $pickupCustomerId,
            'cliente_id' => (int) $customer['id'],
            'stato' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $customer;
    }

    public function getDashboardData(int $businessCustomerId): array
    {
        $salesCount = (int) portal_fetch_value('SELECT COUNT(*) FROM servizi_express_vendite WHERE cliente_id = ? AND stato = ?', [$businessCustomerId, 'Completata']);
        $revenue = (float) portal_fetch_value('SELECT COALESCE(SUM(totale - sconto), 0) FROM servizi_express_vendite WHERE cliente_id = ? AND stato = ?', [$businessCustomerId, 'Completata']);
        $requestsOpen = (int) portal_fetch_value('SELECT COUNT(*) FROM servizi_express_richieste WHERE cliente_id = ? AND stato IN (?,?,?)', [$businessCustomerId, 'Pending', 'InReview', 'Confirmed']);
        $paymentsCount = (int) portal_fetch_value(
            'SELECT COUNT(*)
             FROM entrate_uscite e
             INNER JOIN servizi_express_vendite v ON v.entrata_uscita_id = e.id
             WHERE v.cliente_id = ? AND e.tipo_movimento = ?',
            [$businessCustomerId, 'Entrata']
        );

        $recentSales = portal_fetch_all(
            'SELECT id, totale, sconto, metodo_pagamento, data_vendita, stato
             FROM servizi_express_vendite
             WHERE cliente_id = ?
             ORDER BY data_vendita DESC, id DESC
             LIMIT 5',
            [$businessCustomerId]
        );

        $recentRequests = portal_fetch_all(
            'SELECT id, titolo, tipo_richiesta, stato, created_at
             FROM servizi_express_richieste
             WHERE cliente_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 5',
            [$businessCustomerId]
        );

        return [
            'sales_count' => $salesCount,
            'revenue' => $revenue,
            'requests_open' => $requestsOpen,
            'payments_count' => $paymentsCount,
            'recent_sales' => $recentSales,
            'recent_requests' => $recentRequests,
        ];
    }

    public function listSales(int $businessCustomerId): array
    {
        return portal_fetch_all(
            'SELECT v.id, v.totale, v.sconto, v.metodo_pagamento, v.stato, v.data_vendita, v.note,
                    COUNT(r.id) AS righe_count,
                    GROUP_CONCAT(r.descrizione ORDER BY r.id ASC SEPARATOR " | ") AS anteprima_righe
             FROM servizi_express_vendite v
             LEFT JOIN servizi_express_vendita_righe r ON r.vendita_id = v.id
             WHERE v.cliente_id = ?
             GROUP BY v.id, v.totale, v.sconto, v.metodo_pagamento, v.stato, v.data_vendita, v.note
             ORDER BY v.data_vendita DESC, v.id DESC',
            [$businessCustomerId]
        );
    }

    public function getSaleDetail(int $businessCustomerId, int $saleId): ?array
    {
        $sale = portal_fetch_one(
            'SELECT * FROM servizi_express_vendite WHERE id = ? AND cliente_id = ? LIMIT 1',
            [$saleId, $businessCustomerId]
        );
        if (!$sale) {
            return null;
        }

        $sale['items'] = portal_fetch_all(
            'SELECT r.*, o.nome AS operatore, s.iccid, p.nome AS prodotto_nome, f.titolo AS offerta_titolo
             FROM servizi_express_vendita_righe r
             LEFT JOIN servizi_express_operatori o ON o.id = r.operatore_id
             LEFT JOIN servizi_express_iccid_stock s ON s.id = r.iccid_stock_id
             LEFT JOIN servizi_express_prodotti p ON p.id = r.prodotto_id
             LEFT JOIN servizi_express_offerte f ON f.id = r.offerta_id
             WHERE r.vendita_id = ?
             ORDER BY r.id ASC',
            [$saleId]
        );

        return $sale;
    }

    public function listPayments(int $businessCustomerId): array
    {
        return portal_fetch_all(
            'SELECT e.id, e.descrizione, e.metodo, e.stato, e.importo, e.data_pagamento, e.note, v.id AS vendita_id
             FROM entrate_uscite e
             INNER JOIN servizi_express_vendite v ON v.entrata_uscita_id = e.id
             WHERE v.cliente_id = ? AND e.tipo_movimento = ?
             ORDER BY COALESCE(e.data_pagamento, CURDATE()) DESC, e.id DESC',
            [$businessCustomerId, 'Entrata']
        );
    }

    public function listRequests(int $businessCustomerId): array
    {
        return portal_fetch_all(
            'SELECT r.*, p.nome AS prodotto_nome
             FROM servizi_express_richieste r
             LEFT JOIN servizi_express_prodotti p ON p.id = r.prodotto_id
             WHERE r.cliente_id = ?
             ORDER BY r.created_at DESC, r.id DESC',
            [$businessCustomerId]
        );
    }

    public function createPortalRequest(int $businessCustomerId, array $portalCustomer, array $payload): array
    {
        $title = trim((string) ($payload['titolo'] ?? ''));
        $type = (string) ($payload['tipo_richiesta'] ?? 'Support');
        $productId = (int) ($payload['product_id'] ?? 0);
        $note = trim((string) ($payload['note_cliente'] ?? ''));
        $desiredDate = trim((string) ($payload['data_desiderata'] ?? ''));

        if ($title === '') {
            return ['success' => false, 'message' => 'Inserisci un oggetto richiesta.'];
        }

        if (!in_array($type, ['Purchase', 'Reservation', 'Deposit', 'Installment', 'Support'], true)) {
            $type = 'Support';
        }

        $requestId = portal_insert('servizi_express_richieste', [
            'cliente_id' => $businessCustomerId,
            'prodotto_id' => $productId > 0 ? $productId : null,
            'titolo' => $title,
            'tipo_richiesta' => $type,
            'stato' => 'Pending',
            'data_desiderata' => $desiredDate !== '' ? $desiredDate : null,
            'note_cliente' => $note !== '' ? $note : null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        if ((int) ($portalCustomer['id'] ?? 0) > 0) {
            portal_insert('pickup_customer_notifications', [
                'customer_id' => (int) $portalCustomer['id'],
                'type' => 'system_message',
                'title' => 'Richiesta Express registrata',
                'message' => 'La tua richiesta "' . $title . '" è stata inviata al team Coresuite.',
                'tracking_code' => null,
                'sent_via_email' => 0,
                'sent_via_sms' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return ['success' => true, 'message' => 'Richiesta inviata correttamente.', 'request_id' => $requestId];
    }

    public function productOptions(): array
    {
        return portal_fetch_all(
            'SELECT id, nome, categoria, prezzo FROM servizi_express_prodotti WHERE attivo = 1 ORDER BY categoria ASC, nome ASC'
        );
    }
}
