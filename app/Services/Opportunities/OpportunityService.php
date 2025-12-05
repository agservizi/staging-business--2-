<?php
declare(strict_types=1);

namespace App\Services\Opportunities;

use DateTimeImmutable;
use JsonException;
use PDO;
use RuntimeException;

final class OpportunityService
{
    private const CATEGORIES = ['telefonia', 'luce', 'gas'];
    private const CLONEABLE_FIELDS = [
        'category',
        'provider_id',
        'offer_id',
        'customer_first_name',
        'customer_last_name',
        'customer_tax_code',
        'customer_birth_date',
        'customer_birth_place',
        'customer_phone',
        'customer_email',
        'customer_address',
        'customer_city',
        'customer_postal_code',
        'customer_province',
        'document_type',
        'document_number',
        'document_issued_by',
        'document_issued_at',
        'document_expires_at',
        'telefonia_current_operator',
        'telefonia_line_number',
        'luce_pod',
        'gas_pdr',
        'payment_method',
        'payment_iban',
        'payment_holder_is_customer',
        'payment_holder_first_name',
        'payment_holder_last_name',
        'payment_holder_tax_code',
        'additional_notes',
    ];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{data:array<string,mixed>,saved_at:string|null}|null
     */
    public function getCollaboratorDraft(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $row = $this->fetchDraftRow($userId);
        if ($row === null) {
            return null;
        }

        $payload = $this->decodeDraftPayload($row['payload'] ?? null);
        if ($payload === null) {
            return null;
        }

        return [
            'data' => $payload,
            'saved_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{data:array<string,mixed>,saved_at:string|null}
     */
    public function saveCollaboratorDraft(int $userId, array $payload): array
    {
        if ($userId <= 0) {
            throw new RuntimeException('Utente non valido.');
        }

        $encoded = $this->encodeDraftPayload($payload);

        $stmt = $this->pdo->prepare(
            'INSERT INTO opportunity_drafts (collaborator_id, payload)
             VALUES (:user, :payload)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':payload', $encoded, PDO::PARAM_STR);
        $stmt->execute();

        $row = $this->fetchDraftRow($userId);
        if ($row === null) {
            throw new RuntimeException('Impossibile recuperare la bozza salvata.');
        }

        $resultPayload = $this->decodeDraftPayload($row['payload'] ?? null) ?? [];

        return [
            'data' => $resultPayload,
            'saved_at' => $row['updated_at'] ?? null,
        ];
    }

    public function deleteCollaboratorDraft(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $stmt = $this->pdo->prepare('DELETE FROM opportunity_drafts WHERE collaborator_id = :user');
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeDraftPayload(null|string $encoded): ?array
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        try {
            $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function encodeDraftPayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Impossibile serializzare la bozza: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string,string>|null
     */
    private function fetchDraftRow(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT payload, updated_at FROM opportunity_drafts WHERE collaborator_id = :user LIMIT 1');
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listCollaboratorOpportunities(int $userId, array $filters = []): array
    {
        $conditions = ['o.collaborator_id = :user'];
        $params = [':user' => $userId];

        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        if ($status !== '') {
            $conditions[] = 'o.status_code = :status';
            $params[':status'] = $status;
        }

        $category = isset($filters['category']) ? strtolower(trim((string) $filters['category'])) : '';
        if ($category !== '' && in_array($category, self::CATEGORIES, true)) {
            $conditions[] = 'o.category = :category';
            $params[':category'] = $this->validateCategory($category);
        }

        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $conditions[] = '(o.code LIKE :search OR o.customer_first_name LIKE :search OR o.customer_last_name LIKE :search OR o.provider_label LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereClause = implode(' AND ', $conditions);

        $stmt = $this->pdo->prepare(
            'SELECT o.*, s.label AS status_label, s.color AS status_color
             FROM opportunities o
             LEFT JOIN opportunity_statuses s ON s.code = o.status_code
             WHERE ' . $whereClause . '
             ORDER BY o.created_at DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>
     */
    public function getCollaboratorSummary(int $userId): array
    {
        $emptySummary = [
            'totals' => [
                'total' => 0,
                'active' => 0,
                'won' => 0,
                'lost' => 0,
            ],
            'status_breakdown' => [],
            'monthly_trend' => [
                'labels' => [],
                'values' => [],
            ],
            'last_activity' => null,
        ];

        if ($userId <= 0) {
            return $emptySummary;
        }

        $statusBreakdown = [];
        $totals = [
            'total' => 0,
            'active' => 0,
            'won' => 0,
            'lost' => 0,
        ];

        $statusStmt = $this->pdo->prepare(
            'SELECT o.status_code, s.label, s.color, s.ordering, COUNT(*) AS total
             FROM opportunities o
             LEFT JOIN opportunity_statuses s ON s.code = o.status_code
             WHERE o.collaborator_id = :user
             GROUP BY o.status_code, s.label, s.color, s.ordering
             ORDER BY s.ordering ASC'
        );
        $statusStmt->execute([':user' => $userId]);

        while ($row = $statusStmt->fetch(PDO::FETCH_ASSOC)) {
            $count = (int) ($row['total'] ?? 0);
            $code = (string) ($row['status_code'] ?? '');
            $statusBreakdown[] = [
                'code' => $code,
                'label' => (string) ($row['label'] ?? $code),
                'color' => (string) ($row['color'] ?? 'secondary'),
                'total' => $count,
            ];
            $totals['total'] += $count;

            if ($code === 'attivato') {
                $totals['won'] += $count;
            } elseif ($code === 'annullato') {
                $totals['lost'] += $count;
            }
        }

        $totals['active'] = max(0, $totals['total'] - $totals['won'] - $totals['lost']);

        $monthlyTrend = [
            'labels' => [],
            'values' => [],
        ];

        $currentMonth = new DateTimeImmutable('first day of this month 00:00:00');
        $monthKeys = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $currentMonth->modify(sprintf('-%d months', $i));
            if ($month === false) {
                continue;
            }
            $key = $month->format('Y-m');
            $monthKeys[$key] = [
                'label' => $this->formatItalianMonth($month),
                'value' => 0,
            ];
        }

        $trendStmt = $this->pdo->prepare(
            'SELECT DATE_FORMAT(o.created_at, "%Y-%m") AS month_key, COUNT(*) AS total
             FROM opportunities o
             WHERE o.collaborator_id = :user AND o.created_at >= :startDate
             GROUP BY month_key
             ORDER BY month_key'
        );
        $trendStmt->execute([
            ':user' => $userId,
            ':startDate' => ($currentMonth->modify('-5 months') ?: $currentMonth)->format('Y-m-01 00:00:00'),
        ]);

        while ($row = $trendStmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string) ($row['month_key'] ?? '');
            if ($key === '' || !isset($monthKeys[$key])) {
                continue;
            }
            $monthKeys[$key]['value'] = (int) ($row['total'] ?? 0);
        }

        foreach ($monthKeys as $monthData) {
            $monthlyTrend['labels'][] = $monthData['label'];
            $monthlyTrend['values'][] = $monthData['value'];
        }

        $activityStmt = $this->pdo->prepare(
            'SELECT o.code, o.status_code, s.label AS status_label, s.color AS status_color,
                    COALESCE(o.last_status_change, o.updated_at, o.created_at) AS reference_date
             FROM opportunities o
             LEFT JOIN opportunity_statuses s ON s.code = o.status_code
             WHERE o.collaborator_id = :user
             ORDER BY reference_date DESC
             LIMIT 1'
        );
        $activityStmt->execute([':user' => $userId]);
        $lastActivity = $activityStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'totals' => $totals,
            'status_breakdown' => $statusBreakdown,
            'monthly_trend' => $monthlyTrend,
            'last_activity' => $lastActivity,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getCollaboratorStalledReminders(int $userId, int $thresholdDays = 5, int $limit = 5): array
    {
        if ($userId <= 0 || $thresholdDays <= 0 || $limit <= 0) {
            return [];
        }

        $referenceExpr = 'COALESCE(o.last_status_change, o.updated_at, o.created_at)';
        $sql = 'SELECT o.code, o.status_code, s.label AS status_label, s.color AS status_color,
                       o.provider_label, o.customer_first_name, o.customer_last_name,
                       ' . $referenceExpr . ' AS reference_date,
                       TIMESTAMPDIFF(DAY, ' . $referenceExpr . ', NOW()) AS days_waiting
                FROM opportunities o
                LEFT JOIN opportunity_statuses s ON s.code = o.status_code
                WHERE o.collaborator_id = :user
                  AND o.status_code NOT IN (\'attivato\', \'annullato\')
                  AND TIMESTAMPDIFF(DAY, ' . $referenceExpr . ', NOW()) >= :threshold
                ORDER BY reference_date ASC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':threshold', $thresholdDays, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findCustomerByTaxCode(string $taxCode): ?array
    {
        $normalized = strtoupper(trim($taxCode));
        if ($normalized === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT customer_first_name, customer_last_name, customer_tax_code,
                    customer_birth_date, customer_birth_place,
                    customer_phone, customer_email,
                    customer_address, customer_city, customer_postal_code, customer_province,
                    document_type, document_number, document_issued_by, document_issued_at, document_expires_at,
                    telefonia_current_operator, telefonia_line_number,
                    luce_pod, gas_pdr,
                    payment_method, payment_iban, payment_holder_is_customer,
                    payment_holder_first_name, payment_holder_last_name, payment_holder_tax_code
             FROM opportunities
             WHERE UPPER(customer_tax_code) = :taxCode
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([':taxCode' => $normalized]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * @return array{form:array<string,mixed>,meta:array<string,mixed>}|null
     */
    public function getCollaboratorClonePayload(int $opportunityId, int $userId): ?array
    {
        if ($opportunityId <= 0 || $userId <= 0) {
            return null;
        }

        $columns = implode(', ', array_unique(array_merge([
            'id',
            'code',
            'provider_label',
            'offer_label',
            'created_at',
        ], self::CLONEABLE_FIELDS)));

        $stmt = $this->pdo->prepare(
            'SELECT ' . $columns . ' FROM opportunities WHERE id = :id AND collaborator_id = :user LIMIT 1'
        );
        $stmt->bindValue(':id', $opportunityId, PDO::PARAM_INT);
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $formPayload = [];
        foreach (self::CLONEABLE_FIELDS as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($field === 'payment_holder_is_customer') {
                $formPayload[$field] = ((int) $value) === 1 ? '1' : '0';
            } elseif (in_array($field, ['provider_id', 'offer_id'], true)) {
                $formPayload[$field] = $value !== null ? (string) $value : '';
            } else {
                $formPayload[$field] = $value ?? '';
            }
        }

        return [
            'form' => $formPayload,
            'meta' => [
                'code' => $row['code'] ?? null,
                'category' => $row['category'] ?? null,
                'provider_label' => $row['provider_label'] ?? null,
                'offer_label' => $row['offer_label'] ?? null,
                'created_at' => $row['created_at'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listAdminOpportunities(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'o.status_code = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['category'])) {
            $conditions[] = 'o.category = :category';
            $params[':category'] = $this->validateCategory($filters['category']);
        }
        if (!empty($filters['collaborator_id'])) {
            $conditions[] = 'o.collaborator_id = :collaborator';
            $params[':collaborator'] = (int) $filters['collaborator_id'];
        }
        if (!empty($filters['search'])) {
            $conditions[] = '(o.code LIKE :search OR o.customer_first_name LIKE :search OR o.customer_last_name LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

        $sql = 'SELECT o.*, s.label AS status_label, s.color AS status_color,
                       u.nome AS collaborator_name, u.cognome AS collaborator_surname
                FROM opportunities o
                LEFT JOIN opportunity_statuses s ON s.code = o.status_code
                LEFT JOIN users u ON u.id = o.collaborator_id
                ' . $whereClause . '
                ORDER BY o.created_at DESC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getStatusOptions(): array
    {
        $stmt = $this->pdo->query('SELECT code, label, color FROM opportunity_statuses ORDER BY ordering, label');
        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function getProviderCatalog(?string $category = null): array
    {
        $conditions = 'WHERE p.active = 1';
        $params = [];
        if ($category !== null) {
            $conditions .= ' AND p.category = :category';
            $params[':category'] = $this->validateCategory($category);
        }

        $sql = 'SELECT p.id AS provider_id, p.category, p.name AS provider_name, p.slug AS provider_slug,
                       p.default_commission, p.ordering AS provider_ordering,
                       o.id AS offer_id, o.name AS offer_name, o.slug AS offer_slug, o.commission AS offer_commission,
                       o.ordering AS offer_ordering
                FROM opportunity_providers p
                LEFT JOIN opportunity_offers o ON o.provider_id = p.id AND o.active = 1
                ' . $conditions . '
                ORDER BY p.category, p.ordering, p.name, o.ordering, o.name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $catalog = [
            'telefonia' => [],
            'luce' => [],
            'gas' => [],
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoryKey = $row['category'];
            if (!isset($catalog[$categoryKey])) {
                $catalog[$categoryKey] = [];
            }

            if (!isset($catalog[$categoryKey][$row['provider_id']])) {
                $catalog[$categoryKey][$row['provider_id']] = [
                    'id' => (int) $row['provider_id'],
                    'name' => (string) $row['provider_name'],
                    'slug' => (string) $row['provider_slug'],
                    'default_commission' => $row['default_commission'],
                    'offers' => [],
                ];
            }

            if ($row['offer_id']) {
                $catalog[$categoryKey][$row['provider_id']]['offers'][] = [
                    'id' => (int) $row['offer_id'],
                    'name' => (string) $row['offer_name'],
                    'slug' => (string) $row['offer_slug'],
                    'commission' => $row['offer_commission'],
                ];
            }
        }

        foreach ($catalog as $key => $providers) {
            $catalog[$key] = array_values($providers);
        }

        return $catalog;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $uploadedFiles
     * @return array<string,mixed>
     */
    public function createOpportunity(array $input, int $collaboratorId, array $uploadedFiles = []): array
    {
        if ($collaboratorId <= 0) {
            throw new RuntimeException('Utente non valido.');
        }

        $category = $this->validateCategory((string) ($input['category'] ?? ''));

        $providerId = isset($input['provider_id']) ? (int) $input['provider_id'] : 0;
        if ($providerId <= 0) {
            throw new RuntimeException('Seleziona un gestore valido.');
        }

        $provider = $this->fetchProvider($providerId, $category);
        if ($provider === null) {
            throw new RuntimeException('Il gestore selezionato non è disponibile.');
        }

        $offerId = isset($input['offer_id']) ? (int) $input['offer_id'] : null;
        $offer = null;
        if ($offerId) {
            $offer = $this->fetchOffer($offerId, $providerId);
            if ($offer === null) {
                throw new RuntimeException('L\'offerta selezionata non è disponibile.');
            }
        }

        $customerFirstName = $this->requireString($input, 'customer_first_name', 'Nome cliente');
        $customerLastName = $this->requireString($input, 'customer_last_name', 'Cognome cliente');
        $customerTaxCode = $this->requireString($input, 'customer_tax_code', 'Codice fiscale');
        $customerPhone = $this->requireString($input, 'customer_phone', 'Telefono');
        $customerEmail = $this->requireEmail($input, 'customer_email');
        $documentNumber = $this->requireString($input, 'document_number', 'Numero documento');
        $documentType = $this->requireString($input, 'document_type', 'Tipologia documento');
        $documentExpiresAt = $this->requireString($input, 'document_expires_at', 'Scadenza documento');

        if ($category === 'telefonia') {
            $this->requireString($input, 'telefonia_current_operator', 'Operatore attuale');
            $this->requireString($input, 'telefonia_line_number', 'Numero linea');
        }

        if ($category === 'luce') {
            $this->requireString($input, 'luce_pod', 'Codice POD');
        }

        if ($category === 'gas') {
            $this->requireString($input, 'gas_pdr', 'Codice PDR');
        }

        $rawPaymentMethod = $category === 'telefonia'
            ? (string) ($input['payment_method'] ?? 'iban')
            : $this->requireString($input, 'payment_method', 'Metodo di pagamento');

        $paymentMethod = strtolower(trim($rawPaymentMethod));
        if ($paymentMethod === '') {
            $paymentMethod = 'iban';
        }

        $categoryAllowsBollettino = in_array($category, ['luce', 'gas'], true);
        $telefoniaAllowsBollettino = false;
        if ($category === 'telefonia') {
            $providerSlug = strtolower((string) ($provider['slug'] ?? ''));
            $providerName = strtolower((string) ($provider['name'] ?? ''));
            $slugAllows = $providerSlug !== '' && str_contains($providerSlug, 'enel') && str_contains($providerSlug, 'fibra');
            $nameAllows = $providerName !== '' && str_contains($providerName, 'enel') && str_contains($providerName, 'fibra');
            $telefoniaAllowsBollettino = $slugAllows || $nameAllows;
        }
        $bollettinoAllowed = $categoryAllowsBollettino || $telefoniaAllowsBollettino;

        if (!in_array($paymentMethod, ['iban', 'bollettino'], true)) {
            throw new RuntimeException('Metodo di pagamento non valido.');
        }

        if ($paymentMethod === 'bollettino' && !$bollettinoAllowed) {
            throw new RuntimeException('Il bollettino non è disponibile per il gestore selezionato.');
        }

        if ($paymentMethod === 'iban') {
            $iban = $this->requireString($input, 'payment_iban', 'IBAN');
            $this->assertValidIban($iban);
        } else {
            $iban = (string) ($input['payment_iban'] ?? '');
        }

        $paymentHolderIsCustomer = isset($input['payment_holder_is_customer'])
            ? (int) $input['payment_holder_is_customer'] === 1
            : true;

        if ($paymentMethod === 'iban' && !$paymentHolderIsCustomer) {
            $this->requireString($input, 'payment_holder_first_name', 'Nome intestatario IBAN');
            $this->requireString($input, 'payment_holder_last_name', 'Cognome intestatario IBAN');
            $this->requireString($input, 'payment_holder_tax_code', 'Codice fiscale intestatario IBAN');
        }

        $code = $this->generateUniqueCode();

        $commissionAmount = $offer['commission'] ?? $provider['default_commission'] ?? null;

        $payload = [
            'code' => $code,
            'category' => $category,
            'status_code' => 'in_verifica',
            'provider_id' => $providerId,
            'offer_id' => $offer['id'] ?? null,
            'provider_label' => $provider['name'],
            'offer_label' => $offer['name'] ?? null,
            'collaborator_id' => $collaboratorId,
            'commission_amount' => $commissionAmount,
            'customer_first_name' => $customerFirstName,
            'customer_last_name' => $customerLastName,
            'customer_tax_code' => $customerTaxCode,
            'customer_birth_date' => $this->nullOrString($input['customer_birth_date'] ?? null),
            'customer_birth_place' => $this->nullOrString($input['customer_birth_place'] ?? null),
            'customer_phone' => $customerPhone,
            'customer_email' => $customerEmail,
            'customer_address' => $this->nullOrString($input['customer_address'] ?? null),
            'customer_city' => $this->nullOrString($input['customer_city'] ?? null),
            'customer_postal_code' => $this->nullOrString($input['customer_postal_code'] ?? null),
            'customer_province' => $this->nullOrString($input['customer_province'] ?? null),
            'document_type' => $documentType,
            'document_number' => $documentNumber,
            'document_issued_by' => $this->nullOrString($input['document_issued_by'] ?? null),
            'document_issued_at' => $this->nullOrString($input['document_issued_at'] ?? null),
            'document_expires_at' => $documentExpiresAt,
            'telefonia_current_operator' => $this->nullOrString($input['telefonia_current_operator'] ?? null),
            'telefonia_line_number' => $this->nullOrString($input['telefonia_line_number'] ?? null),
            'luce_pod' => $this->nullOrString($input['luce_pod'] ?? null),
            'gas_pdr' => $this->nullOrString($input['gas_pdr'] ?? null),
            'payment_method' => $paymentMethod,
            'payment_iban' => $iban,
            'payment_holder_is_customer' => $paymentHolderIsCustomer ? 1 : 0,
            'payment_holder_first_name' => $paymentHolderIsCustomer ? null : $this->nullOrString($input['payment_holder_first_name'] ?? null),
            'payment_holder_last_name' => $paymentHolderIsCustomer ? null : $this->nullOrString($input['payment_holder_last_name'] ?? null),
            'payment_holder_tax_code' => $paymentHolderIsCustomer ? null : $this->nullOrString($input['payment_holder_tax_code'] ?? null),
            'additional_notes' => $this->nullOrString($input['additional_notes'] ?? null),
        ];

        $uploadTokens = $this->extractUploadTokens($input);
        $resolvedUploads = ['files' => [], 'tokens' => []];
        if ($uploadTokens !== []) {
            $resolvedUploads = OpportunityUploadStorage::resolveFiles($collaboratorId, $uploadTokens);
            $missingTokens = array_diff($uploadTokens, $resolvedUploads['tokens'] ?? []);
            if ($missingTokens !== []) {
                throw new RuntimeException('Alcuni allegati temporanei sono scaduti. Caricali nuovamente.');
            }
        }

        $normalizedFiles = array_merge(
            $this->normalizeUploadedFiles($uploadedFiles),
            $resolvedUploads['files'] ?? []
        );

        $this->pdo->beginTransaction();
        try {
            $columns = array_keys($payload);
            $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);
            $stmt = $this->pdo->prepare(
                'INSERT INTO opportunities (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $placeholders) . ')'
            );
            foreach ($payload as $column => $value) {
                $stmt->bindValue(':' . $column, $value);
            }
            $stmt->execute();
            $opportunityId = (int) $this->pdo->lastInsertId();

            if ($normalizedFiles) {
                $this->persistFiles($opportunityId, $normalizedFiles, $collaboratorId);
                $resolvedTokens = $resolvedUploads['tokens'] ?? [];
                if ($resolvedTokens) {
                    OpportunityUploadStorage::cleanupTokens($collaboratorId, $resolvedTokens);
                }
            }

            $this->pdo->commit();
        } catch (RuntimeException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw new RuntimeException('Errore durante il salvataggio della opportunity.');
        }

        return $this->findById($opportunityId) ?? $payload;
    }

    public function findById(int $opportunityId): ?array
    {
        $stmt = $this->pdo->prepare(
                'SELECT o.*, s.label AS status_label, s.color AS status_color,
                    u.nome AS collaborator_name, u.cognome AS collaborator_surname, u.email AS collaborator_email,
                    m.nome AS manager_name, m.cognome AS manager_surname
             FROM opportunities o
             LEFT JOIN opportunity_statuses s ON s.code = o.status_code
             LEFT JOIN users u ON u.id = o.collaborator_id
             LEFT JOIN users m ON m.id = o.managed_by
             WHERE o.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $opportunityId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $files
     */
    public function updateStatus(int $opportunityId, string $statusCode, int $userId, ?string $adminNotes = null, array $files = []): void
    {
        $statusCode = $this->validateStatus($statusCode);

        $stmt = $this->pdo->prepare(
            'UPDATE opportunities
             SET status_code = :status, last_status_change = NOW(), admin_notes = :notes, managed_by = :manager
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $statusCode,
            ':notes' => $adminNotes !== null ? trim($adminNotes) : null,
            ':manager' => $userId,
            ':id' => $opportunityId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Opportunity non trovata o stato invariato.');
        }

        if ($files) {
            $normalized = $this->normalizeUploadedFiles($files);
            if ($normalized) {
                $this->persistFiles($opportunityId, $normalized, $userId);
            }
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateCodes(int $opportunityId, array $payload, int $userId): void
    {
        $contractCode = $this->nullOrString($payload['contract_code'] ?? null);
        $clientCode = $this->nullOrString($payload['client_code'] ?? null);

        $stmt = $this->pdo->prepare(
            'UPDATE opportunities SET contract_code = :contract, client_code = :client, managed_by = :manager WHERE id = :id'
        );
        $stmt->execute([
            ':contract' => $contractCode,
            ':client' => $clientCode,
            ':manager' => $userId,
            ':id' => $opportunityId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new RuntimeException('Impossibile aggiornare i codici.');
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listFiles(int $opportunityId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM opportunity_files WHERE opportunity_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute([':id' => $opportunityId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string,mixed> $files
     */
    public function addFiles(int $opportunityId, array $files, int $userId): void
    {
        $normalized = $this->normalizeUploadedFiles($files);
        if (!$normalized) {
            return;
        }

        $this->persistFiles($opportunityId, $normalized, $userId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listStatusesDetailed(): array
    {
        $stmt = $this->pdo->query('SELECT id, code, label, color, is_core, ordering FROM opportunity_statuses ORDER BY ordering, label');

        return $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    }

    /**
     * @param array<string,mixed> $input
     */
    public function createStatusDefinition(array $input): void
    {
        $label = $this->requireString($input, 'label', 'Titolo stato');
        $color = $this->requireString($input, 'color', 'Colore');
        $ordering = isset($input['ordering']) ? (int) $input['ordering'] : 0;
        $customCode = $this->nullOrString($input['code'] ?? null);

        $code = $customCode ?: $this->generateStatusCodeFromLabel($label);
        if ($this->statusCodeExists($code)) {
            throw new RuntimeException('Codice stato già utilizzato.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO opportunity_statuses (code, label, color, ordering, is_core)
             VALUES (:code, :label, :color, :ordering, 0)'
        );
        $stmt->execute([
            ':code' => $code,
            ':label' => $label,
            ':color' => $color,
            ':ordering' => $ordering,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function updateStatusDefinition(int $statusId, array $input): void
    {
        $status = $this->findStatusById($statusId);
        if ($status === null) {
            throw new RuntimeException('Stato non trovato.');
        }
        if ((int) ($status['is_core'] ?? 0) === 1) {
            throw new RuntimeException('Non puoi modificare uno stato di sistema.');
        }

        $label = $this->requireString($input, 'label', 'Titolo stato');
        $color = $this->requireString($input, 'color', 'Colore');
        $ordering = isset($input['ordering']) ? (int) $input['ordering'] : 0;

        $stmt = $this->pdo->prepare(
            'UPDATE opportunity_statuses SET label = :label, color = :color, ordering = :ordering WHERE id = :id LIMIT 1'
        );
        $stmt->execute([
            ':label' => $label,
            ':color' => $color,
            ':ordering' => $ordering,
            ':id' => $statusId,
        ]);
    }

    public function deleteStatus(int $statusId): void
    {
        $status = $this->findStatusById($statusId);
        if ($status === null) {
            throw new RuntimeException('Stato non trovato.');
        }
        if ((int) ($status['is_core'] ?? 0) === 1) {
            throw new RuntimeException('Non puoi eliminare uno stato di sistema.');
        }

        $usageStmt = $this->pdo->prepare('SELECT COUNT(1) FROM opportunities WHERE status_code = :code');
        $usageStmt->execute([':code' => $status['code']]);
        if ((int) $usageStmt->fetchColumn() > 0) {
            throw new RuntimeException('Non puoi eliminare uno stato in uso.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM opportunity_statuses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $statusId]);
    }

    /**
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function listProvidersWithOffers(?string $category = null, bool $onlyActive = false): array
    {
        $conditions = [];
        $params = [];
        if ($category !== null && $category !== '') {
            $conditions[] = 'p.category = :category';
            $params[':category'] = $this->validateCategory($category);
        }
        if ($onlyActive) {
            $conditions[] = 'p.active = 1';
        }

        $whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
        $offerJoin = 'LEFT JOIN opportunity_offers o ON o.provider_id = p.id';
        if ($onlyActive) {
            $offerJoin .= ' AND o.active = 1';
        }

        $sql = 'SELECT
                    p.id AS provider_id,
                    p.category,
                    p.name AS provider_name,
                    p.slug AS provider_slug,
                    p.active AS provider_active,
                    p.ordering AS provider_ordering,
                    p.default_commission AS provider_commission,
                    o.id AS offer_id,
                    o.name AS offer_name,
                    o.slug AS offer_slug,
                    o.commission AS offer_commission,
                    o.active AS offer_active,
                    o.ordering AS offer_ordering
                FROM opportunity_providers p
                ' . $offerJoin . '
                ' . $whereClause . '
                ORDER BY p.category, p.ordering, p.name, o.ordering, o.name';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $catalog = [
            'telefonia' => [],
            'luce' => [],
            'gas' => [],
        ];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $categoryKey = $row['category'];
            if (!isset($catalog[$categoryKey])) {
                $catalog[$categoryKey] = [];
            }
            $providerId = (int) $row['provider_id'];
            if (!isset($catalog[$categoryKey][$providerId])) {
                $catalog[$categoryKey][$providerId] = [
                    'id' => $providerId,
                    'name' => (string) $row['provider_name'],
                    'slug' => (string) $row['provider_slug'],
                    'active' => (int) $row['provider_active'] === 1,
                    'ordering' => (int) $row['provider_ordering'],
                    'default_commission' => $row['provider_commission'],
                    'offers' => [],
                ];
            }

            if (!empty($row['offer_id'])) {
                $catalog[$categoryKey][$providerId]['offers'][] = [
                    'id' => (int) $row['offer_id'],
                    'name' => (string) $row['offer_name'],
                    'slug' => (string) $row['offer_slug'],
                    'commission' => $row['offer_commission'],
                    'active' => (int) $row['offer_active'] === 1,
                    'ordering' => (int) $row['offer_ordering'],
                ];
            }
        }

        foreach ($catalog as $key => $providers) {
            $catalog[$key] = array_values($providers);
        }

        return $catalog;
    }

    /**
     * @param array<string,mixed> $input
     */
    public function createProvider(array $input): void
    {
        $category = $this->validateCategory((string) ($input['category'] ?? ''));
        $name = $this->requireString($input, 'name', 'Nome gestore');
        $slug = $this->slugify($this->nullOrString($input['slug'] ?? null) ?: $name);
        $slug = $this->ensureProviderSlug($category, $slug);
        $ordering = isset($input['ordering']) ? (int) $input['ordering'] : 0;
        $commission = $this->normalizeDecimal($input['default_commission'] ?? null);
        $active = isset($input['active']) ? ((int) $input['active'] === 1 ? 1 : 0) : 1;

        $stmt = $this->pdo->prepare(
            'INSERT INTO opportunity_providers (category, name, slug, ordering, default_commission, active)
             VALUES (:category, :name, :slug, :ordering, :commission, :active)'
        );
        $stmt->execute([
            ':category' => $category,
            ':name' => $name,
            ':slug' => $slug,
            ':ordering' => $ordering,
            ':commission' => $commission,
            ':active' => $active,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function updateProvider(int $providerId, array $input): void
    {
        $provider = $this->findProviderById($providerId);
        if ($provider === null) {
            throw new RuntimeException('Gestore non trovato.');
        }

        $name = $this->requireString($input, 'name', 'Nome gestore');
        $ordering = isset($input['ordering']) ? (int) $input['ordering'] : (int) ($provider['ordering'] ?? 0);
        $commission = $this->normalizeDecimal($input['default_commission'] ?? null);
        $active = isset($input['active']) ? ((int) $input['active'] === 1 ? 1 : 0) : (int) ($provider['active'] ?? 1);

        $stmt = $this->pdo->prepare(
            'UPDATE opportunity_providers
             SET name = :name, ordering = :ordering, default_commission = :commission, active = :active
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':ordering' => $ordering,
            ':commission' => $commission,
            ':active' => $active,
            ':id' => $providerId,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function createOffer(array $input): void
    {
        $providerId = isset($input['provider_id']) ? (int) $input['provider_id'] : 0;
        if ($providerId <= 0) {
            throw new RuntimeException('Gestore non valido.');
        }

        $provider = $this->findProviderById($providerId);
        if ($provider === null) {
            throw new RuntimeException('Gestore non trovato.');
        }

        $name = $this->requireString($input, 'name', 'Nome offerta');
        $slug = $this->slugify($this->nullOrString($input['slug'] ?? null) ?: $name);
        $slug = $this->ensureOfferSlug($providerId, $slug);
        $commission = $this->normalizeDecimal($input['commission'] ?? null);
        $ordering = isset($input['ordering']) ? (int) $input['ordering'] : 0;
        $active = isset($input['active']) ? ((int) $input['active'] === 1 ? 1 : 0) : 1;

        $stmt = $this->pdo->prepare(
            'INSERT INTO opportunity_offers (provider_id, name, slug, commission, ordering, active)
             VALUES (:provider_id, :name, :slug, :commission, :ordering, :active)'
        );
        $stmt->execute([
            ':provider_id' => $providerId,
            ':name' => $name,
            ':slug' => $slug,
            ':commission' => $commission,
            ':ordering' => $ordering,
            ':active' => $active,
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function updateOffer(int $offerId, array $input): void
    {
        $offer = $this->findOfferById($offerId);
        if ($offer === null) {
            throw new RuntimeException('Offerta non trovata.');
        }

        $name = $this->requireString($input, 'name', 'Nome offerta');
        $commission = $this->normalizeDecimal($input['commission'] ?? null);
        $ordering = isset($input['ordering']) ? (int) $input['ordering'] : (int) ($offer['ordering'] ?? 0);
        $active = isset($input['active']) ? ((int) $input['active'] === 1 ? 1 : 0) : (int) ($offer['active'] ?? 1);

        $stmt = $this->pdo->prepare(
            'UPDATE opportunity_offers
             SET name = :name, commission = :commission, ordering = :ordering, active = :active
             WHERE id = :id'
        );
        $stmt->execute([
            ':name' => $name,
            ':commission' => $commission,
            ':ordering' => $ordering,
            ':active' => $active,
            ':id' => $offerId,
        ]);
    }

    private function validateCategory(string $category): string
    {
        $category = strtolower(trim($category));
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new RuntimeException('Categoria non valida.');
        }

        return $category;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchProvider(int $providerId, string $category): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM opportunity_providers WHERE id = :id AND category = :category AND active = 1 LIMIT 1'
        );
        $stmt->execute([
            ':id' => $providerId,
            ':category' => $category,
        ]);

        $provider = $stmt->fetch(PDO::FETCH_ASSOC);

        return $provider ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchOffer(int $offerId, int $providerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM opportunity_offers WHERE id = :id AND provider_id = :provider AND active = 1 LIMIT 1'
        );
        $stmt->execute([
            ':id' => $offerId,
            ':provider' => $providerId,
        ]);

        $offer = $stmt->fetch(PDO::FETCH_ASSOC);

        return $offer ?: null;
    }

    private function generateUniqueCode(): string
    {
        do {
            $code = 'OP' . strtoupper(bin2hex(random_bytes(4)));
        } while ($this->codeExists($code));

        return $code;
    }

    private function validateStatus(string $statusCode): string
    {
        $statusCode = trim($statusCode);
        if ($statusCode === '') {
            throw new RuntimeException('Seleziona uno stato.');
        }
        $stmt = $this->pdo->prepare('SELECT 1 FROM opportunity_statuses WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $statusCode]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Stato non valido.');
        }

        return $statusCode;
    }

    private function codeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM opportunities WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<int,string>
     */
    private function extractUploadTokens(array $input): array
    {
        if (!isset($input['upload_tokens_payload'])) {
            return [];
        }

        $rawValue = $input['upload_tokens_payload'];
        $tokens = [];

        if (is_array($rawValue)) {
            $tokens = $rawValue;
        } elseif (is_string($rawValue)) {
            $trimmed = trim($rawValue);
            if ($trimmed === '') {
                return [];
            }
            if (str_starts_with($trimmed, '[')) {
                try {
                    $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $tokens = $decoded;
                    }
                } catch (JsonException) {
                    $tokens = [];
                }
            }
            if ($tokens === []) {
                $tokens = explode(',', $trimmed);
            }
        } elseif (is_scalar($rawValue)) {
            $tokens = [(string) $rawValue];
        }

        $tokens = array_map(static fn ($token): string => trim((string) $token), $tokens);
        $tokens = array_filter($tokens, static fn (string $token): bool => $token !== '');

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<string,mixed> $files
     * @return array<int,array{name:string,type:string,tmp_name:string,size:int}>
     */
    private function normalizeUploadedFiles(array $files): array
    {
        if (!$files) {
            return [];
        }

        if (!isset($files['name'])) {
            return [];
        }

        $normalized = [];
        $fileCount = is_array($files['name']) ? count($files['name']) : 1;

        for ($index = 0; $index < $fileCount; $index += 1) {
            $name = is_array($files['name']) ? $files['name'][$index] : $files['name'];
            $type = is_array($files['type']) ? $files['type'][$index] : $files['type'];
            $tmpName = is_array($files['tmp_name']) ? $files['tmp_name'][$index] : $files['tmp_name'];
            $error = is_array($files['error']) ? $files['error'][$index] : $files['error'];
            $size = is_array($files['size']) ? (int) $files['size'][$index] : (int) $files['size'];

            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Errore durante il caricamento di un allegato.');
            }

            $normalized[] = [
                'name' => (string) $name,
                'type' => (string) $type,
                'tmp_name' => (string) $tmpName,
                'size' => $size,
            ];
        }

        return $normalized;
    }

    private function formatItalianMonth(DateTimeImmutable $date): string
    {
        static $months = [
            1 => 'Gen',
            2 => 'Feb',
            3 => 'Mar',
            4 => 'Apr',
            5 => 'Mag',
            6 => 'Giu',
            7 => 'Lug',
            8 => 'Ago',
            9 => 'Set',
            10 => 'Ott',
            11 => 'Nov',
            12 => 'Dic',
        ];

        $monthIndex = (int) $date->format('n');
        $monthLabel = $months[$monthIndex] ?? $date->format('M');

        return sprintf('%s %s', $monthLabel, $date->format('Y'));
    }

    /**
     * @param array<int,array{name:string,type:string,tmp_name:string,size:int,token?:string}> $files
     */
    private function persistFiles(int $opportunityId, array $files, int $uploaderId): void
    {
        $storageBase = realpath(__DIR__ . '/../../../storage');
        if ($storageBase === false) {
            throw new RuntimeException('Cartella storage non disponibile.');
        }

        $targetDir = $storageBase . '/uploads/opportunities/' . $opportunityId;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Impossibile creare la cartella degli allegati.');
        }

        $insertStmt = $this->pdo->prepare(
            'INSERT INTO opportunity_files (opportunity_id, original_name, stored_name, file_path, mime_type, file_size, checksum, uploaded_by)
             VALUES (:opportunity_id, :original_name, :stored_name, :file_path, :mime_type, :file_size, :checksum, :uploaded_by)'
        );

        foreach ($files as $file) {
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $storedName = sprintf('%s_%s', date('YmdHis'), bin2hex(random_bytes(6)));
            if ($extension !== '') {
                $storedName .= '.' . strtolower($extension);
            }

            $targetPath = $targetDir . '/' . $storedName;
            $this->transferUploadedFile($file, $targetPath);

            $relativePath = 'storage/uploads/opportunities/' . $opportunityId . '/' . $storedName;
            $checksum = hash_file('sha256', $targetPath) ?: null;

            $insertStmt->execute([
                ':opportunity_id' => $opportunityId,
                ':original_name' => $file['name'],
                ':stored_name' => $storedName,
                ':file_path' => $relativePath,
                ':mime_type' => $file['type'] ?: 'application/octet-stream',
                ':file_size' => $file['size'],
                ':checksum' => $checksum,
                ':uploaded_by' => $uploaderId,
            ]);
        }
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,size:int,token?:string} $file
     */
    private function transferUploadedFile(array $file, string $targetPath): void
    {
        $sourcePath = (string) $file['tmp_name'];
        if ($sourcePath === '' || !is_file($sourcePath)) {
            throw new RuntimeException('Il file temporaneo non è più disponibile.');
        }

        $token = isset($file['token']) ? trim((string) $file['token']) : '';
        if ($token !== '') {
            if (!@rename($sourcePath, $targetPath)) {
                if (!@copy($sourcePath, $targetPath)) {
                    throw new RuntimeException('Impossibile spostare uno degli allegati temporanei.');
                }
                @unlink($sourcePath);
            }
            return;
        }

        if (!move_uploaded_file($sourcePath, $targetPath)) {
            throw new RuntimeException('Impossibile salvare uno degli allegati.');
        }
    }

    private function findStatusById(int $statusId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM opportunity_statuses WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $statusId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function statusCodeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM opportunity_statuses WHERE code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);

        return (bool) $stmt->fetchColumn();
    }

    private function generateStatusCodeFromLabel(string $label): string
    {
        $base = $this->slugify($label);
        if ($base === '') {
            $base = 'status';
        }
        $base = substr($base, 0, 50);
        $code = $base;
        $suffix = 1;
        while ($this->statusCodeExists($code)) {
            $suffixString = (string) $suffix;
            $available = 60 - (strlen($suffixString) + 1);
            $code = substr($base, 0, max(1, $available)) . '-' . $suffixString;
            $suffix += 1;
            if ($suffix > 99) {
                throw new RuntimeException('Impossibile generare un codice stato univoco.');
            }
        }

        return $code;
    }

    private function findProviderById(int $providerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM opportunity_providers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $providerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function findOfferById(int $offerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM opportunity_offers WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $offerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function ensureProviderSlug(string $category, string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = substr($baseSlug !== '' ? $baseSlug : 'provider', 0, 160);
        $suffix = 1;
        while ($this->providerSlugExists($category, $slug, $ignoreId)) {
            $slugCandidate = $baseSlug !== '' ? $baseSlug : 'provider';
            $appendix = '-' . $suffix;
            $slug = substr($slugCandidate, 0, max(1, 160 - strlen($appendix))) . $appendix;
            $suffix += 1;
            if ($suffix > 99) {
                throw new RuntimeException('Impossibile generare uno slug per il gestore.');
            }
        }

        return $slug;
    }

    private function providerSlugExists(string $category, string $slug, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM opportunity_providers WHERE category = :category AND slug = :slug';
        $params = [
            ':category' => $category,
            ':slug' => $slug,
        ];
        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    private function ensureOfferSlug(int $providerId, string $baseSlug, ?int $ignoreId = null): string
    {
        $slug = substr($baseSlug !== '' ? $baseSlug : 'offerta', 0, 160);
        $suffix = 1;
        while ($this->offerSlugExists($providerId, $slug, $ignoreId)) {
            $slugCandidate = $baseSlug !== '' ? $baseSlug : 'offerta';
            $appendix = '-' . $suffix;
            $slug = substr($slugCandidate, 0, max(1, 160 - strlen($appendix))) . $appendix;
            $suffix += 1;
            if ($suffix > 99) {
                throw new RuntimeException('Impossibile generare uno slug per l\'offerta.');
            }
        }

        return $slug;
    }

    private function offerSlugExists(int $providerId, string $slug, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT 1 FROM opportunity_offers WHERE provider_id = :provider AND slug = :slug';
        $params = [
            ':provider' => $providerId,
            ':slug' => $slug,
        ];
        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $params[':id'] = $ignoreId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    private function slugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if ($transliterated !== false && $transliterated !== null) {
            $value = $transliterated;
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value;
    }

    private function normalizeDecimal($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = is_scalar($value) ? (string) $value : '';
        $stringValue = trim(str_replace(',', '.', $stringValue));
        if ($stringValue === '') {
            return null;
        }
        if (!is_numeric($stringValue)) {
            throw new RuntimeException('Inserisci un valore numerico valido.');
        }

        return number_format((float) $stringValue, 2, '.', '');
    }

    private function requireString(array $input, string $key, string $label): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '') {
            throw new RuntimeException(sprintf('%s è obbligatorio.', $label));
        }

        return $value;
    }

    private function requireEmail(array $input, string $key): string
    {
        $value = trim((string) ($input[$key] ?? ''));
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email cliente non valida.');
        }

        return $value;
    }

    private function nullOrString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function assertValidIban(string $iban): void
    {
        $normalized = strtoupper(str_replace(' ', '', $iban));
        if (!preg_match('/^[A-Z]{2}[0-9A-Z]{13,30}$/', $normalized)) {
            throw new RuntimeException('IBAN non valido.');
        }
    }
}
