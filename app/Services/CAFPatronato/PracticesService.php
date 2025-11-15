<?php
declare(strict_types=1);

namespace App\Services\CAFPatronato;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;
use function caf_patronato_build_download_url;
use function caf_patronato_encrypt_uploaded_file;
use function caf_patronato_generate_standard_filename;
use function caf_patronato_get_encryption_key;
use const CAF_PATRONATO_ENCRYPTION_SUFFIX;

final class PracticesService
{
    private const STORAGE_DIR = 'assets/uploads/caf-patronato';
    private const DEFAULT_PER_PAGE = 10;
    private const MAX_PER_PAGE = 50;
    private const TRACKING_CODE_PREFIXES = [
        'CAF' => 'CAF-PRAT-',
        'PATRONATO' => 'PAT-PRAT-',
    ];
    private const TRACKING_CODE_DIGITS = 5;
    private const MAX_TRACKING_STEPS = 200;
    private const TRACKING_DESCRIPTION_MAX_LENGTH = 600;
    private const TRACKING_AUTHOR_KEYS = ['admin', 'manager', 'operatore', 'patronato'];

    private PDO $pdo;
    private string $projectRoot;
    private string $storagePath;

    /**
     * @var array<string,string>
     */
    private array $allowedMimeTypes = [
        'application/pdf' => 'PDF',
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/webp' => 'WEBP',
    ];

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $normalizedRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
        $normalizedRoot = $this->resolveProjectRoot($normalizedRoot);

        $this->projectRoot = $normalizedRoot;
        $this->storagePath = $this->projectRoot . DIRECTORY_SEPARATOR . self::STORAGE_DIR;

        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException('Impossibile creare la directory allegati CAF/Patronato.');
        }

        $this->ensureDefaultStatuses();
    }

    private function resolveProjectRoot(string $candidate): string
    {
        $root = $candidate;
        if (preg_match('/[\\/]api$/i', $root)) {
            $parent = dirname($root);
            if ($parent !== '' && $parent !== DIRECTORY_SEPARATOR) {
                $root = $parent;
            }
        }

        if ($this->hasAssetsDirectory($root)) {
            return $root;
        }

        $parent = dirname($root);
        if ($parent !== '' && $parent !== DIRECTORY_SEPARATOR && $this->hasAssetsDirectory($parent)) {
            return $parent;
        }

        return $candidate;
    }

    private function hasAssetsDirectory(string $root): bool
    {
        return is_dir($root . DIRECTORY_SEPARATOR . 'assets');
    }

    public function storagePathForPractice(int $practiceId): string
    {
        $practiceId = max(0, $practiceId);
        $path = $this->storagePath . DIRECTORY_SEPARATOR . $practiceId;
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Impossibile creare la directory degli allegati per la pratica #' . $practiceId);
        }

        return $path;
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,pagination:array<string,int>,summaries:array<string,mixed>}
     */
    public function listPractices(array $filters, ?int $operatorId, bool $canViewAll): array
    {
        [$whereSql, $params] = $this->buildFilterSql($filters, $operatorId, $canViewAll);

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        if ($perPage <= 0) {
            $perPage = self::DEFAULT_PER_PAGE;
        }
        if ($perPage > self::MAX_PER_PAGE) {
            $perPage = self::MAX_PER_PAGE;
        }
        $offset = ($page - 1) * $perPage;

        $orderBy = $this->resolveOrderBy($filters['order'] ?? null);

        $sql = <<<SQL
SELECT
    p.id,
    p.titolo,
    p.descrizione,
    p.categoria,
    p.stato,
    p.data_creazione,
    p.data_aggiornamento,
    p.scadenza,
    p.tracking_code,
    p.tracking_steps,
    p.allegati,
    p.note,
    p.metadati,
    tp.id AS tipo_id,
    tp.nome AS tipo_nome,
    tp.id AS tipo_id,
    tp.categoria AS tipo_categoria,
    op.id AS operatore_id,
    op.nome AS operatore_nome,
    op.cognome AS operatore_cognome,
    op.ruolo AS operatore_ruolo,
    u_admin.username AS admin_username,
    u_admin.nome AS admin_nome,
    u_admin.cognome AS admin_cognome,
    c.id AS cliente_id,
    c.ragione_sociale AS cliente_ragione_sociale,
    c.nome AS cliente_nome,
    c.cognome AS cliente_cognome,
    c.email AS cliente_email
FROM pratiche p
INNER JOIN tipologie_pratiche tp ON tp.id = p.tipo_pratica
INNER JOIN users u_admin ON u_admin.id = p.id_admin
LEFT JOIN utenti_caf_patronato op ON op.id = p.id_utente_caf_patronato
LEFT JOIN clienti c ON c.id = p.cliente_id
{$whereSql}
{$orderBy}
LIMIT :limit OFFSET :offset
SQL;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = array_map(fn(array $row): array => $this->normalizePracticeRow($row), $rows);

        $countSql = 'SELECT COUNT(*) FROM pratiche p INNER JOIN tipologie_pratiche tp ON tp.id = p.tipo_pratica LEFT JOIN utenti_caf_patronato op ON op.id = p.id_utente_caf_patronato ' . $whereSql;
        $countStmt = $this->pdo->prepare($countSql);
        foreach ($params as $name => $value) {
            $countStmt->bindValue($name, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();
        $maxPages = (int) ceil($total / $perPage) ?: 1;
        if ($page > $maxPages) {
            $page = $maxPages;
        }

        $summaries = $this->buildSummary($filters, $operatorId, $canViewAll);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => $maxPages,
            ],
            'summaries' => $summaries,
        ];
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildFilterSql(array $filters, ?int $operatorId, bool $canViewAll): array
    {
        $conditions = [];
        $params = [];

        if (!$canViewAll && $operatorId !== null) {
            $conditions[] = 'p.id_utente_caf_patronato = :current_operator';
            $params[':current_operator'] = $operatorId;
        }

        if (!empty($filters['categoria']) && in_array(strtoupper((string) $filters['categoria']), ['CAF', 'PATRONATO'], true)) {
            $conditions[] = 'p.categoria = :categoria';
            $params[':categoria'] = strtoupper((string) $filters['categoria']);
        }

        if (!empty($filters['stato'])) {
            $conditions[] = 'p.stato = :stato';
            $params[':stato'] = (string) $filters['stato'];
        }

        if (!empty($filters['tipo_pratica'])) {
            $conditions[] = 'p.tipo_pratica = :tipo';
            $params[':tipo'] = (int) $filters['tipo_pratica'];
        }

        if (!empty($filters['operatore']) && $canViewAll) {
            $conditions[] = 'p.id_utente_caf_patronato = :operatore';
            $params[':operatore'] = (int) $filters['operatore'];
        }

        if (array_key_exists('assegnata', $filters) && $filters['assegnata'] !== '' && $filters['assegnata'] !== null) {
            $value = (string) $filters['assegnata'];
            if ($value === '1') {
                $conditions[] = 'p.id_utente_caf_patronato IS NOT NULL';
            } elseif ($value === '0') {
                $conditions[] = 'p.id_utente_caf_patronato IS NULL';
            }
        }

        if (!empty($filters['cliente_id'])) {
            $conditions[] = 'p.cliente_id = :cliente_id';
            $params[':cliente_id'] = (int) $filters['cliente_id'];
        }

        if (!empty($filters['tracking_code'])) {
            $conditions[] = 'UPPER(p.tracking_code) = :tracking_code';
            $params[':tracking_code'] = strtoupper(trim((string) $filters['tracking_code']));
        }

        if (!empty($filters['search'])) {
            $needle = '%' . str_replace('%', '\%', trim((string) $filters['search'])) . '%';
            $conditions[] = '(p.titolo LIKE :search OR p.descrizione LIKE :search OR p.note LIKE :search)';
            $params[':search'] = $needle;
        }

        if (!empty($filters['dal'])) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters['dal']) ?: DateTimeImmutable::createFromFormat('d/m/Y', (string) $filters['dal']);
            if ($date) {
                $conditions[] = 'p.data_creazione >= :data_inizio';
                $params[':data_inizio'] = $date->format('Y-m-d') . ' 00:00:00';
            }
        }

        if (!empty($filters['al'])) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters['al']) ?: DateTimeImmutable::createFromFormat('d/m/Y', (string) $filters['al']);
            if ($date) {
                $conditions[] = 'p.data_creazione <= :data_fine';
                $params[':data_fine'] = $date->format('Y-m-d') . ' 23:59:59';
            }
        }

        $whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$whereSql, $params];
    }

    private function resolveOrderBy($order): string
    {
        $order = is_string($order) ? strtolower($order) : 'recenti';

        return match ($order) {
            'scadenza' => 'ORDER BY CASE WHEN p.scadenza IS NULL THEN 1 ELSE 0 END, p.scadenza ASC, p.data_creazione DESC',
            'stato' => 'ORDER BY p.stato ASC, p.data_creazione DESC',
            'assegnatario' => 'ORDER BY op.nome ASC, op.cognome ASC, p.data_creazione DESC',
            default => 'ORDER BY p.data_aggiornamento DESC',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizePracticeRow(array $row): array
    {
        $attachments = $this->decodeJson($row['allegati'] ?? null);
        if ($attachments) {
            $attachments = array_map(static function ($entry) {
                if (!is_array($entry)) {
                    return $entry;
                }
                if (empty($entry['download_url']) && !empty($entry['id']) && function_exists('caf_patronato_build_download_url')) {
                    $entry['download_url'] = caf_patronato_build_download_url('document', (int) $entry['id']);
                }
                return $entry;
            }, $attachments);
        }
        $meta = $this->decodeJson($row['metadati'] ?? null);

        $assignee = null;
        if (!empty($row['operatore_id'])) {
            $assignee = [
                'id' => (int) $row['operatore_id'],
                'nome' => trim((string) ($row['operatore_nome'] ?? '')) . ' ' . trim((string) ($row['operatore_cognome'] ?? '')),
                'ruolo' => (string) ($row['operatore_ruolo'] ?? ''),
            ];
        }

        $cliente = null;
        if (!empty($row['cliente_id'])) {
            $cliente = [
                'id' => (int) $row['cliente_id'],
                'ragione_sociale' => $row['cliente_ragione_sociale'] ?? null,
                'nome' => $row['cliente_nome'] ?? null,
                'cognome' => $row['cliente_cognome'] ?? null,
                'email' => $row['cliente_email'] ?? null,
            ];
        }

        $practice = [
            'id' => (int) $row['id'],
            'titolo' => (string) $row['titolo'],
            'descrizione' => $row['descrizione'] ?? null,
            'categoria' => (string) $row['categoria'],
            'stato' => (string) $row['stato'],
            'data_creazione' => (string) $row['data_creazione'],
            'data_aggiornamento' => (string) $row['data_aggiornamento'],
            'scadenza' => $row['scadenza'],
            'note' => $row['note'] ?? null,
            'metadati' => $meta,
            'tracking_code' => isset($row['tracking_code']) ? (string) $row['tracking_code'] : '',
            'tracking_steps' => $this->decodeTrackingSteps($row['tracking_steps'] ?? null),
            'allegati' => $attachments,
            'tipo' => [
                'id' => (int) $row['tipo_id'],
                'nome' => (string) $row['tipo_nome'],
                'categoria' => (string) $row['tipo_categoria'],
            ],
            'assegnatario' => $assignee,
            'cliente' => $cliente,
        ];

        $practice['customer_email'] = $this->resolvePracticeCustomerEmail($practice);

        return $practice;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $raw
     * @return array<int,array{data:string,autore:string,descrizione:string,pubblico:bool}>
     */
    private function decodeTrackingSteps($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_string($raw)) {
            try {
                $raw = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                return [];
            }
        }

        if (!is_array($raw)) {
            return [];
        }

        $steps = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $normalized = $this->normalizeTrackingStep($entry);
            if ($normalized !== null) {
                $steps[] = $normalized;
            }
        }

        usort($steps, static function (array $first, array $second): int {
            $comparison = strcmp($first['data'], $second['data']);
            return $comparison !== 0 ? $comparison : 0;
        });

        return $steps;
    }

    private function normalizeTrackingStep(array $step): ?array
    {
        $description = $this->sanitizeTrackingDescription($step['descrizione'] ?? null);
        if ($description === '') {
            return null;
        }

        $date = $this->normalizeTrackingDate(isset($step['data']) ? (string) $step['data'] : null);
        $author = $this->normalizeTrackingAuthor($step['autore'] ?? null);
        $isPublic = $this->normalizeTrackingVisibility($step['pubblico'] ?? ($step['public'] ?? false));

        return [
            'data' => $date,
            'autore' => $author,
            'descrizione' => $description,
            'pubblico' => $isPublic,
        ];
    }

    private function normalizeTrackingDate(?string $value): string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate !== '') {
            $formats = ['Y-m-d H:i:s', DateTimeImmutable::RFC3339, DateTimeImmutable::ATOM];
            foreach ($formats as $format) {
                $date = DateTimeImmutable::createFromFormat($format, $candidate);
                if ($date instanceof DateTimeImmutable) {
                    return $date->format('Y-m-d H:i:s');
                }
            }
            try {
                $date = new DateTimeImmutable($candidate);
                return $date->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // fallback handled below
            }
        }

        return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
    }

    private function normalizeTrackingAuthor($role): string
    {
        if (is_string($role)) {
            $candidate = strtolower(trim($role));
            if ($candidate === 'admin' || $candidate === 'administrator') {
                return 'admin';
            }
            if ($candidate === 'manager') {
                return 'manager';
            }
            if ($candidate === 'patronato' || $candidate === 'patron') {
                return 'patronato';
            }
            if ($candidate === 'operatore' || $candidate === 'operator' || $candidate === 'operatrice') {
                return 'operatore';
            }
            if (in_array($candidate, self::TRACKING_AUTHOR_KEYS, true)) {
                return $candidate;
            }
        }

        return 'operatore';
    }

    private function normalizeTrackingVisibility($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'si', 'sì', 'yes', 'y'], true);
        }

        return false;
    }

    private function sanitizeTrackingDescription($description): string
    {
        if (!is_string($description)) {
            return '';
        }

        $clean = trim(strip_tags($description));
        $clean = preg_replace('/\s+/u', ' ', $clean) ?: $clean;
        if (mb_strlen($clean) > self::TRACKING_DESCRIPTION_MAX_LENGTH) {
            $clean = mb_substr($clean, 0, self::TRACKING_DESCRIPTION_MAX_LENGTH);
        }

        return trim($clean);
    }

    private function persistTrackingSteps(int $practiceId, array $steps): void
    {
        try {
            $encoded = json_encode($steps, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossibile serializzare la timeline della pratica: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $stmt = $this->pdo->prepare('UPDATE pratiche SET tracking_steps = :steps, data_aggiornamento = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':steps' => $encoded,
            ':id' => $practiceId,
        ]);
    }

    /**
     * @return array<int,array{data:string,autore:string,descrizione:string,pubblico:bool}>
     */
    private function fetchTrackingSteps(int $practiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT tracking_steps FROM pratiche WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $practiceId]);
        $raw = $stmt->fetchColumn();

        if ($raw === false || $raw === null) {
            return [];
        }

        return $this->decodeTrackingSteps((string) $raw);
    }

    /**
     * @return array<int,array{data:string,autore:string,descrizione:string,pubblico:bool}>
     */
    private function appendTrackingStepInternal(int $practiceId, string $description, string $authorRole, bool $isPublic): array
    {
        $steps = $this->fetchTrackingSteps($practiceId);
        $steps[] = $this->buildTrackingStep($description, $authorRole, null, $isPublic);
        if (count($steps) > self::MAX_TRACKING_STEPS) {
            $steps = array_slice($steps, -self::MAX_TRACKING_STEPS);
        }

        $this->persistTrackingSteps($practiceId, $steps);

        return $steps;
    }

    private function generateTrackingCode(?string $category = null): string
    {
        $prefix = $this->resolveTrackingPrefix($category);
        $maxAttempts = 25_000;
        $maxValue = (10 ** self::TRACKING_CODE_DIGITS) - 1;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $number = random_int(0, $maxValue);
            $code = $prefix . str_pad((string) $number, self::TRACKING_CODE_DIGITS, '0', STR_PAD_LEFT);
            $stmt = $this->pdo->prepare('SELECT 1 FROM pratiche WHERE tracking_code = :code LIMIT 1');
            $stmt->execute([':code' => $code]);
            if ($stmt->fetchColumn() === false) {
                return $code;
            }
        }

        throw new RuntimeException('Impossibile generare un codice di tracking univoco.');
    }

    private function resolveTrackingPrefix(?string $category): string
    {
        $normalized = strtoupper(trim((string) ($category ?? '')));
        if (isset(self::TRACKING_CODE_PREFIXES[$normalized])) {
            return self::TRACKING_CODE_PREFIXES[$normalized];
        }

        return self::TRACKING_CODE_PREFIXES['CAF'];
    }

    private function sendCustomerCreationMail(array &$practice, int $creatorUserId, ?string $recipientOverride = null): bool
    {
        $recipient = null;
        if ($recipientOverride !== null) {
            $recipient = $this->normalizeEmailCandidate($recipientOverride);
        }
        if ($recipient === null) {
            $recipient = $this->resolvePracticeCustomerEmail($practice);
        }
        if ($recipient === null || !function_exists('send_system_mail')) {
            return false;
        }

        $trackingCode = (string) ($practice['tracking_code'] ?? '');
        $trackingLink = $this->buildTrackingLink($trackingCode);

        $fieldDefinitions = $this->getCustomFieldDefinitionsForPractice($practice);
        $mailContent = $this->buildCustomerEmailContent($practice, $trackingLink, $fieldDefinitions);
        $subjectReference = $trackingCode !== '' ? $trackingCode : ($practice['titolo'] ?? 'Pratica CAF/Patronato');
        $subject = 'Conferma registrazione pratica ' . $subjectReference;

        $htmlBody = function_exists('render_mail_template')
            ? render_mail_template('Conferma pratica CAF/Patronato', $mailContent)
            : $mailContent;

        try {
            $sent = send_system_mail($recipient, $subject, $htmlBody);
        } catch (Throwable $exception) {
            error_log('CAF/Patronato customer mail dispatch error: ' . $exception->getMessage());
            return false;
        }

        if (!$sent) {
            return false;
        }

        $payload = array_filter([
            'email' => $recipient,
            'tracking_code' => $trackingCode !== '' ? $trackingCode : null,
        ]);
        $this->recordEvent((int) $practice['id'], 'notifica_cliente', 'Email di conferma inviata al cliente', $payload ?: null, $creatorUserId, null);

        try {
            $steps = $this->appendTrackingStepInternal((int) $practice['id'], 'Email di conferma inviata al cliente', 'admin', false);
            $practice['tracking_steps'] = $steps;
        } catch (Throwable $exception) {
            error_log('CAF/Patronato timeline append warning: ' . $exception->getMessage());
        }

        return true;
    }

    private function buildTrackingLink(string $trackingCode): string
    {
        $path = 'tracking.php';
        if ($trackingCode !== '') {
            $path .= '?code=' . rawurlencode($trackingCode);
        }

        if (function_exists('base_url')) {
            return base_url($path);
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * @param array<string,mixed> $practice
     * @return array<string,array{label:string,type:string}>
     */
    private function getCustomFieldDefinitionsForPractice(array $practice): array
    {
        $typeId = isset($practice['tipo']['id']) ? (int) $practice['tipo']['id'] : 0;
        if ($typeId <= 0) {
            return [];
        }

        try {
            $type = $this->getType($typeId);
        } catch (Throwable) {
            return [];
        }

        $fields = $type['campi_personalizzati'] ?? [];
        if (!is_array($fields)) {
            return [];
        }

        $definitions = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $slug = (string) ($field['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $definitions[$slug] = [
                'label' => (string) ($field['label'] ?? $this->humanizeSlug($slug)),
                'type' => strtolower((string) ($field['type'] ?? 'text')),
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string,mixed> $practice
     * @param array<string,array{label:string,type:string}> $fieldDefinitions
     */
    private function buildCustomerEmailContent(array $practice, string $trackingLink, array $fieldDefinitions): string
    {
        $theme = $this->resolveCategoryTheme($practice['categoria'] ?? null);
        $trackingCode = (string) ($practice['tracking_code'] ?? '');

        $statusCode = (string) ($practice['stato'] ?? '');
        $status = $statusCode !== '' ? $this->getStatusByCode($statusCode) : null;
        $statusLabel = $status['nome'] ?? ($statusCode !== '' ? $statusCode : 'In lavorazione');

        $summaryRows = [];
        $summaryRows['Titolo pratica'] = (string) ($practice['titolo'] ?? '');
        if ($trackingCode !== '') {
            $summaryRows['Codice di tracking'] = $trackingCode;
        }
        $summaryRows['Categoria'] = (string) ($practice['categoria'] ?? $theme['label']);
        if (!empty($practice['tipo']['nome'])) {
            $summaryRows['Tipologia'] = (string) $practice['tipo']['nome'];
        }
        $summaryRows['Stato attuale'] = $statusLabel;
        $summaryRows['Data di apertura'] = $this->formatDateForCustomer($practice['data_creazione'] ?? null);
        $summaryRows['Ultimo aggiornamento'] = $this->formatDateForCustomer($practice['data_aggiornamento'] ?? null);
        if (!empty($practice['scadenza'])) {
            $summaryRows['Scadenza prevista'] = $this->formatDateForCustomer($practice['scadenza'], 'd/m/Y');
        }

        if (!empty($practice['assegnatario']['nome'])) {
            $role = (string) ($practice['assegnatario']['ruolo'] ?? '');
            $summaryRows['Operatore di riferimento'] = trim((string) $practice['assegnatario']['nome'] . ($role !== '' ? ' (' . $role . ')' : ''));
        }

        if (!empty($practice['cliente']) && is_array($practice['cliente'])) {
            $cliente = $practice['cliente'];
            $labelParts = [];
            if (!empty($cliente['ragione_sociale'])) {
                $labelParts[] = (string) $cliente['ragione_sociale'];
            }
            $fullName = trim(((string) ($cliente['nome'] ?? '')) . ' ' . ((string) ($cliente['cognome'] ?? '')));
            if ($fullName !== '') {
                $labelParts[] = $fullName;
            }
            if (!empty($cliente['email'])) {
                $labelParts[] = (string) $cliente['email'];
            }
            if (!empty($cliente['telefono'])) {
                $labelParts[] = (string) $cliente['telefono'];
            }
            $summaryRows['Cliente associato'] = $labelParts ? implode(' · ', array_unique(array_filter($labelParts))) : ('Cliente #' . (int) ($cliente['id'] ?? 0));
        }

        $metadata = isset($practice['metadati']) && is_array($practice['metadati']) ? $practice['metadati'] : [];
        $metadataRows = $this->formatMetadataRows($metadata, $fieldDefinitions);

        $summaryTable = $this->renderEmailKeyValueTable($summaryRows);
        $metadataSection = '';
        if ($metadataRows) {
            $metadataTable = $this->renderEmailKeyValueTable($metadataRows);
            $metadataSection = '<div style="margin:28px 0 0;"><h2 style="margin:0 0 12px;font-size:17px;color:' . $theme['accent'] . ';">Informazioni aggiuntive</h2>' . $metadataTable . '</div>';
        }

        $categoryLabel = htmlspecialchars($theme['label'], ENT_QUOTES, 'UTF-8');
        $trackingLabel = htmlspecialchars($trackingCode !== '' ? $trackingCode : 'In generazione', ENT_QUOTES, 'UTF-8');
        $statusLabelEscaped = htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8');
        $typeName = isset($practice['tipo']['nome']) ? trim((string) $practice['tipo']['nome']) : '';
        $typeMarkup = $typeName !== ''
            ? '<div style="font-size:12px;margin-top:4px;opacity:0.7;">Tipologia: ' . htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8') . '</div>'
            : '';
        $taglineMarkup = $theme['tagline'] !== ''
            ? '<p style="margin:18px 0 0;font-size:13px;opacity:0.85;">' . htmlspecialchars($theme['tagline'], ENT_QUOTES, 'UTF-8') . '</p>'
            : '';

        $card = '<div style="margin:0 0 24px;padding:20px;border-radius:18px;background:' . $theme['cardBackground'] . ';border:1px solid ' . $theme['cardBorder'] . ';color:' . $theme['cardText'] . ';box-shadow:0 10px 26px rgba(9, 32, 74, 0.08);">'
            . '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:16px;">'
            . '<span style="display:inline-block;padding:6px 14px;border-radius:999px;background:' . $theme['badgeBackground'] . ';color:' . $theme['badgeText'] . ';font-size:12px;letter-spacing:0.08em;text-transform:uppercase;">' . $categoryLabel . '</span>'
            . '<div style="flex:1;min-width:220px;">'
            . '<div style="font-size:13px;opacity:0.72;margin-bottom:4px;">Codice pratica</div>'
            . '<div style="font-size:24px;font-weight:700;letter-spacing:0.04em;">' . $trackingLabel . '</div>'
            . '</div>'
            . '<div style="flex:1;min-width:200px;text-align:right;">'
            . '<div style="font-size:13px;opacity:0.72;margin-bottom:4px;">Stato attuale</div>'
            . '<div style="font-size:16px;font-weight:600;">' . $statusLabelEscaped . '</div>'
            . $typeMarkup
            . '</div>'
            . '</div>'
            . $taglineMarkup
            . '</div>';

        $trackingButtonBlock = '';
        if ($trackingLink !== '') {
            $safeLink = htmlspecialchars($trackingLink, ENT_QUOTES, 'UTF-8');
            $trackingButtonBlock = '<div style="margin:24px 0 20px;">'
                . '<a href="' . $safeLink . '" style="display:inline-block;background:' . $theme['accent'] . ';color:#ffffff;padding:12px 24px;border-radius:999px;font-weight:600;text-decoration:none;">Apri il portale di tracking</a>'
                . '<p style="margin:12px 0 0;font-size:12px;color:#516070;">Se il pulsante non dovesse funzionare, copia e incolla questo link nel tuo browser:<br><span style="word-break:break-all;"><a href="' . $safeLink . '" style="color:' . $theme['accent'] . ';">' . $safeLink . '</a></span></p>'
                . '</div>';
        }

        $timelineHtml = $this->buildTimelinePreviewSection(is_array($practice['tracking_steps'] ?? null) ? $practice['tracking_steps'] : [], $theme['accent']);

        $customerName = $this->resolveCustomerDisplayName($practice);
        $greeting = $customerName !== ''
            ? 'Gentile ' . htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') . ','
            : 'Gentile cliente,';

        $parts = [];
        $parts[] = '<p style="margin:0 0 16px;">' . $greeting . '</p>';
        $parts[] = '<p style="margin:0 0 20px;color:#1c2534;">ti confermiamo la registrazione della tua pratica presso il servizio ' . $categoryLabel . '. Di seguito trovi il riepilogo aggiornato e i prossimi passi utili.</p>';
        $parts[] = $card;
        $parts[] = $trackingButtonBlock;
        $parts[] = '<div style="margin:28px 0 0;"><h2 style="margin:0 0 12px;font-size:17px;color:' . $theme['accent'] . ';">Riepilogo pratica</h2>' . $summaryTable . '</div>';
        if ($metadataSection !== '') {
            $parts[] = $metadataSection;
        }
        if ($timelineHtml !== '') {
            $parts[] = $timelineHtml;
        }
        $parts[] = '<p style="margin:32px 0 0;color:#1c2534;">Per qualsiasi necessità puoi rispondere a questa email o contattare il tuo referente dedicato.</p>';
        $parts[] = '<p style="margin:12px 0 0;color:#1c2534;">Cordiali saluti,<br><strong>Coresuite Business</strong></p>';

        return implode('', $parts);
    }

    /**
     * @param array<string,string> $rows
     */
    private function renderEmailKeyValueTable(array $rows): string
    {
        $tableRows = '';
        foreach ($rows as $label => $value) {
            $valueString = trim((string) $value);
            if ($valueString === '') {
                continue;
            }
            $tableRows .= '<tr>'
                . '<th align="left" style="padding:10px 16px;background:#f4f6fb;font-size:13px;width:220px;color:#274060;">' . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8') . '</th>'
                . '<td style="padding:10px 16px;font-size:15px;color:#1c2534;background:#ffffff;border-bottom:1px solid #eef1f6;">' . htmlspecialchars($valueString, ENT_QUOTES, 'UTF-8') . '</td>'
                . '</tr>';
        }

        if ($tableRows === '') {
            $tableRows = '<tr><td style="padding:12px 16px;font-size:14px;color:#516070;">Dati non disponibili.</td></tr>';
        }

        return '<table cellspacing="0" cellpadding="0" style="width:100%;border-collapse:separate;border-spacing:0;background:#ffffff;border:1px solid #dde2eb;border-radius:12px;overflow:hidden;">'
            . $tableRows
            . '</table>';
    }

    private function buildTimelinePreviewSection(array $steps, string $accentColor): string
    {
        if (!$steps) {
            return '';
        }

        $normalized = [];
        foreach ($steps as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $description = trim((string) ($entry['descrizione'] ?? ''));
            if ($description === '') {
                continue;
            }
            $normalized[] = [
                'data' => $entry['data'] ?? null,
                'autore' => $entry['autore'] ?? null,
                'descrizione' => $description,
            ];
        }

        if (!$normalized) {
            return '';
        }

        $latest = array_slice(array_reverse($normalized), 0, 3);
        $items = '';
        foreach ($latest as $entry) {
            $dateLabel = $this->formatDateForCustomer($entry['data'] ?? null);
            if ($dateLabel === '') {
                $dateLabel = 'Aggiornamento';
            }
            $authorLabel = $this->resolveTimelineAuthorLabel($entry['autore'] ?? null);
            $items .= '<li style="list-style:none;margin:0 0 12px;padding:12px 16px;background:#f7f9fc;border-radius:10px;border:1px solid #e3e9f5;">'
                . '<div style="font-size:13px;color:#516070;font-weight:600;">' . htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') . ' · ' . htmlspecialchars($authorLabel, ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="font-size:15px;color:#1c2534;margin-top:4px;">' . htmlspecialchars($entry['descrizione'], ENT_QUOTES, 'UTF-8') . '</div>'
                . '</li>';
        }

        return '<div style="margin:28px 0 0;"><h2 style="margin:0 0 12px;font-size:17px;color:' . htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') . ';">Ultimi aggiornamenti</h2><ul style="margin:0;padding:0;">' . $items . '</ul></div>';
    }

    private function resolveTimelineAuthorLabel($author): string
    {
        $normalized = strtolower(trim((string) ($author ?? '')));

        return match ($normalized) {
            'admin' => 'Team amministrativo',
            'manager' => 'Coordinamento',
            'patronato' => 'Operatore Patronato',
            'operatore' => 'Operatore CAF',
            default => $normalized !== '' ? ucfirst($normalized) : 'Staff Coresuite',
        };
    }

    private function resolveCustomerDisplayName(array $practice): string
    {
        if (empty($practice['cliente']) || !is_array($practice['cliente'])) {
            return '';
        }

        $cliente = $practice['cliente'];

        $company = trim((string) ($cliente['ragione_sociale'] ?? ''));
        $name = trim(((string) ($cliente['nome'] ?? '')) . ' ' . ((string) ($cliente['cognome'] ?? '')));

        if ($company !== '') {
            return $company;
        }

        if ($name !== '') {
            return $name;
        }

        return '';
    }

    private function resolveCategoryTheme(?string $category): array
    {
        $normalized = strtoupper(trim((string) ($category ?? '')));

        if ($normalized === 'PATRONATO') {
            return [
                'label' => 'Patronato',
                'accent' => '#0b6b53',
                'cardBackground' => 'linear-gradient(135deg, #f6fff9, #e4fff3)',
                'cardBorder' => '#bfe8d4',
                'cardText' => '#0b4632',
                'badgeBackground' => '#0b6b53',
                'badgeText' => '#ffffff',
                'tagline' => 'Tutela previdenziale e assistenza dedicata.',
            ];
        }

        return [
            'label' => 'CAF',
            'accent' => '#0b2f6b',
            'cardBackground' => 'linear-gradient(135deg, #f7f9ff, #eef3ff)',
            'cardBorder' => '#d5e1f8',
            'cardText' => '#0b2f6b',
            'badgeBackground' => '#0b2f6b',
            'badgeText' => '#ffffff',
            'tagline' => 'Assistenza fiscale personalizzata e gestione documentale.',
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     * @param array<string,array{label:string,type:string}> $fieldDefinitions
     * @return array<string,string>
     */
    private function formatMetadataRows(array $metadata, array $fieldDefinitions): array
    {
        $rows = [];
        foreach ($metadata as $slug => $value) {
            if (!is_string($slug) || $slug === '') {
                continue;
            }
            if ($this->shouldSkipMetadataField($slug)) {
                continue;
            }
            $definition = $fieldDefinitions[$slug] ?? null;
            $label = $definition['label'] ?? $this->humanizeSlug($slug);
            $type = $definition['type'] ?? null;
            $normalized = $this->normalizeMetadataValue($value, $type);
            if ($normalized === '') {
                continue;
            }
            $rows[$label] = $normalized;
        }

        return $rows;
    }

    private function normalizeMetadataValue($value, ?string $type): string
    {
        if (is_array($value)) {
            $flattened = [];
            foreach ($value as $item) {
                $itemValue = $this->normalizeMetadataValue($item, null);
                if ($itemValue !== '') {
                    $flattened[] = $itemValue;
                }
            }
            $flattened = array_unique($flattened);
            return $flattened ? implode(', ', $flattened) : '';
        }

        if ($value === null) {
            return '';
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        if ($type === 'checkbox') {
            $normalized = strtolower($raw);
            return in_array($normalized, ['1', 'true', 'si', 'sì', 'yes', 'on'], true) ? 'Sì' : 'No';
        }

        if ($type === 'date') {
            $formatted = $this->formatDateForCustomer($raw, 'd/m/Y');
            if ($formatted !== '') {
                return $formatted;
            }
        }

        if (function_exists('mb_strlen')) {
            if (mb_strlen($raw) > 500) {
                return mb_substr($raw, 0, 497) . '...';
            }
        } elseif (strlen($raw) > 500) {
            return substr($raw, 0, 497) . '...';
        }

        return $raw;
    }

    private function shouldSkipMetadataField(string $slug): bool
    {
        $normalized = strtolower($slug);
        $blacklist = ['nota', 'note', 'intern', 'memo', 'privacy', 'consenso', 'gdpr', 'allegat', 'file', 'password'];
        foreach ($blacklist as $token) {
            if (str_contains($normalized, $token)) {
                return true;
            }
        }

        return false;
    }

    private function humanizeSlug(string $slug): string
    {
        $candidate = str_replace(['_', '-'], ' ', $slug);
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate) ?? $slug);
        if ($candidate === '') {
            return $slug;
        }

        if (function_exists('mb_convert_case')) {
            return mb_convert_case($candidate, MB_CASE_TITLE, 'UTF-8');
        }

        return ucwords(strtolower($candidate));
    }

    private function formatDateForCustomer($value, string $format = 'd/m/Y H:i'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $stringValue = is_string($value) ? trim($value) : (string) $value;
        if ($stringValue === '') {
            return '';
        }

        try {
            $date = new DateTimeImmutable($stringValue);
        } catch (Throwable) {
            return $stringValue;
        }

        return $date->format($format);
    }

    private function resolvePracticeCustomerEmail(array $practice): ?string
    {
        $candidates = [];

        if (!empty($practice['cliente']['email'])) {
            $candidates[] = (string) $practice['cliente']['email'];
        }

        if (!empty($practice['metadati']) && is_array($practice['metadati'])) {
            $candidates = array_merge($candidates, $this->collectEmailsFromValue($practice['metadati']));
        }

        foreach ($candidates as $candidate) {
            $email = $this->normalizeEmailCandidate($candidate);
            if ($email !== null) {
                return $email;
            }
        }

        return $this->fetchLegacyModuleEmail($practice);
    }

    private function normalizeEmailCandidate(string $candidate): ?string
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return null;
        }

        if (filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $candidate, $matches) === 1) {
            $email = $matches[0] ?? '';
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function collectEmailsFromValue($value): array
    {
        if (is_array($value)) {
            $collected = [];
            foreach ($value as $item) {
                $collected = array_merge($collected, $this->collectEmailsFromValue($item));
            }
            return $collected;
        }

        if (!is_string($value)) {
            return [];
        }

        $value = trim($value);
        if ($value === '') {
            return [];
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [$value];
        }

        if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $value, $matches) && !empty($matches[0])) {
            return $matches[0];
        }

        return [];
    }

    private function fetchLegacyModuleEmail(array $practice): ?string
    {
        $metadata = $practice['metadati'] ?? null;
        if (!is_array($metadata)) {
            return null;
        }

        $sourceId = null;
        foreach (['caf_patronato_pratica_id', 'caf_pratica_id'] as $key) {
            if (isset($metadata[$key]) && is_numeric($metadata[$key])) {
                $candidate = (int) $metadata[$key];
                if ($candidate > 0) {
                    $sourceId = $candidate;
                    break;
                }
            }
        }

        $email = null;

        if ($sourceId !== null) {
            try {
                $stmt = $this->pdo->prepare('SELECT email FROM caf_patronato_pratiche WHERE id = :id LIMIT 1');
                $stmt->execute([':id' => $sourceId]);
                $email = $stmt->fetchColumn() ?: null;
            } catch (Throwable $exception) {
                error_log('CAF/Patronato legacy email lookup failed: ' . $exception->getMessage());
            }
        }

        if (!is_string($email) || trim($email) === '') {
            $sourceCode = null;
            foreach (['caf_patronato_code', 'caf_pratica_code'] as $key) {
                if (!empty($metadata[$key]) && is_string($metadata[$key])) {
                    $sourceCode = trim((string) $metadata[$key]);
                    break;
                }
            }
            if ($sourceCode === null && !empty($practice['tracking_code']) && is_string($practice['tracking_code'])) {
                $sourceCode = trim((string) $practice['tracking_code']);
            }

            if ($sourceCode !== null && $sourceCode !== '') {
                try {
                    $stmt = $this->pdo->prepare('SELECT email FROM caf_patronato_pratiche WHERE pratica_code = :code LIMIT 1');
                    $stmt->execute([':code' => $sourceCode]);
                    $email = $stmt->fetchColumn() ?: null;
                } catch (Throwable $exception) {
                    error_log('CAF/Patronato legacy email lookup failed: ' . $exception->getMessage());
                }
            }
        }

        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return $this->normalizeEmailCandidate((string) $email);
    }

    private function buildTrackingStep(string $description, string $authorRole, ?DateTimeImmutable $timestamp = null, bool $isPublic = false): array
    {
        $cleanDescription = $this->sanitizeTrackingDescription($description);
        if ($cleanDescription === '') {
            throw new RuntimeException('La descrizione dello step di tracking è obbligatoria.');
        }

        $timestamp ??= new DateTimeImmutable('now');

        return [
            'data' => $timestamp->format('Y-m-d H:i:s'),
            'autore' => $this->normalizeTrackingAuthor($authorRole),
            'descrizione' => $cleanDescription,
            'pubblico' => $isPublic,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getPractice(int $practiceId, bool $canManageAll, ?int $operatorId): array
    {
        $sql = <<<SQL
SELECT
    p.*,
    tp.id AS tipo_id,
    tp.nome AS tipo_nome,
    tp.categoria AS tipo_categoria,
    tp.campi_personalizzati,
    op.id AS operatore_id,
    op.nome AS operatore_nome,
    op.cognome AS operatore_cognome,
    op.ruolo AS operatore_ruolo,
    op.email AS operatore_email,
    u_admin.username AS admin_username,
    u_admin.nome AS admin_nome,
    u_admin.cognome AS admin_cognome,
    c.id AS cliente_id,
    c.ragione_sociale AS cliente_ragione_sociale,
    c.nome AS cliente_nome,
    c.cognome AS cliente_cognome,
    c.email AS cliente_email
FROM pratiche p
INNER JOIN tipologie_pratiche tp ON tp.id = p.tipo_pratica
INNER JOIN users u_admin ON u_admin.id = p.id_admin
LEFT JOIN utenti_caf_patronato op ON op.id = p.id_utente_caf_patronato
LEFT JOIN clienti c ON c.id = p.cliente_id
WHERE p.id = :id
LIMIT 1
SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Pratica non trovata.');
        }

        $assignedOperator = (int) ($row['operatore_id'] ?? 0);
        if (!$canManageAll && ($operatorId === null || $assignedOperator !== $operatorId)) {
            throw new RuntimeException('Non hai accesso a questa pratica.');
        }

        $practice = $this->normalizePracticeRow($row);
        $practice['campi_personalizzati'] = $this->decodeJson($row['campi_personalizzati'] ?? null);
        $practice['metadati'] = $this->decodeJson($row['metadati'] ?? null);
        $practice['documenti'] = $this->listDocuments($practiceId);
        $practice['note_storico'] = $this->listNotes($practiceId, $canManageAll, $operatorId);
        $practice['eventi'] = $this->listEvents($practiceId);
        $practice['customer_email'] = $this->resolvePracticeCustomerEmail($practice);

        return $practice;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createPractice(array $payload, int $adminUserId): array
    {
        $data = $this->validatePracticePayload($payload, true);
        $data['id_admin'] = $adminUserId;

        $trackingCode = $this->generateTrackingCode($data['categoria'] ?? null);
        $initialTimelineStep = $this->buildTrackingStep('Pratica registrata nel sistema.', 'admin', null, true);
        try {
            $trackingStepsPayload = json_encode([$initialTimelineStep], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossibile inizializzare la timeline della pratica: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO pratiche (titolo, descrizione, tipo_pratica, categoria, stato, data_creazione, data_aggiornamento, id_admin, id_utente_caf_patronato, tracking_code, tracking_steps, allegati, note, metadati, scadenza, cliente_id)
                VALUES (:titolo, :descrizione, :tipo_pratica, :categoria, :stato, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, :id_admin, :id_operatore, :tracking_code, :tracking_steps, :allegati, :note, :metadati, :scadenza, :cliente_id)');
            $stmt->execute([
                ':titolo' => $data['titolo'],
                ':descrizione' => $data['descrizione'] ?? null,
                ':tipo_pratica' => $data['tipo_pratica'],
                ':categoria' => $data['categoria'],
                ':stato' => $data['stato'],
                ':id_admin' => $data['id_admin'],
                ':id_operatore' => $data['id_utente_caf_patronato'] ?? null,
                ':tracking_code' => $trackingCode,
                ':tracking_steps' => $trackingStepsPayload,
                ':allegati' => json_encode([], JSON_THROW_ON_ERROR),
                ':note' => $data['note'] ?? null,
                ':metadati' => $data['metadati'] ? json_encode($data['metadati'], JSON_THROW_ON_ERROR) : null,
                ':scadenza' => $data['scadenza'] ?? null,
                ':cliente_id' => $data['cliente_id'] ?? null,
            ]);
            $practiceId = (int) $this->pdo->lastInsertId();

            if (!empty($data['id_utente_caf_patronato'])) {
                $this->recordEvent($practiceId, 'assegnazione', 'Pratica assegnata all\'operatore ID ' . $data['id_utente_caf_patronato'], ['operatore_id' => (int) $data['id_utente_caf_patronato']], $adminUserId, null);
                $this->notifyAssignment($practiceId, (int) $data['id_utente_caf_patronato'], 'Nuova pratica assegnata');
            }

            $this->recordEvent($practiceId, 'creazione', 'Pratica creata dall\'utente #' . $adminUserId, ['stato' => $data['stato']], $adminUserId, null);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw new RuntimeException('Impossibile creare la pratica: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $practice = $this->getPractice($practiceId, true, null);

        try {
            $this->sendCustomerCreationMail($practice, $adminUserId);
        } catch (Throwable $exception) {
            error_log('CAF/Patronato customer mail warning: ' . $exception->getMessage());
        }

        return $practice;
    }

    public function sendCustomerConfirmationMail(int $practiceId, int $requestUserId, ?string $recipientOverride = null): bool
    {
        $practice = $this->getPractice($practiceId, true, null);

        $needsRefresh = false;

        if (empty($practice['tracking_code'])) {
            $practice['tracking_code'] = $this->generateTrackingCode($practice['categoria'] ?? null);
            $stmt = $this->pdo->prepare('UPDATE pratiche SET tracking_code = :code, data_aggiornamento = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':code' => $practice['tracking_code'],
                ':id' => $practiceId,
            ]);
            $needsRefresh = true;
        }

        if (empty($practice['tracking_steps'])) {
            $initialStep = $this->buildTrackingStep('Pratica registrata nel sistema.', 'admin', null, true);
            $this->persistTrackingSteps($practiceId, [$initialStep]);
            $needsRefresh = true;
        }

        if ($needsRefresh) {
            $practice = $this->getPractice($practiceId, true, null);
        }

        return $this->sendCustomerCreationMail($practice, $requestUserId, $recipientOverride);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updatePractice(int $practiceId, array $payload, int $requestUserId, bool $canManageAll, ?int $operatorId): array
    {
        $current = $this->getPractice($practiceId, $canManageAll, $operatorId);
        $data = $this->validatePracticePayload($payload, false, $current);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE pratiche SET titolo = :titolo, descrizione = :descrizione, tipo_pratica = :tipo_pratica, categoria = :categoria, stato = :stato, id_utente_caf_patronato = :id_operatore, note = :note, metadati = :metadati, scadenza = :scadenza, cliente_id = :cliente_id, data_aggiornamento = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':titolo' => $data['titolo'],
                ':descrizione' => $data['descrizione'] ?? null,
                ':tipo_pratica' => $data['tipo_pratica'],
                ':categoria' => $data['categoria'],
                ':stato' => $data['stato'],
                ':id_operatore' => $data['id_utente_caf_patronato'] ?? null,
                ':note' => $data['note'] ?? null,
                ':metadati' => $data['metadati'] ? json_encode($data['metadati'], JSON_THROW_ON_ERROR) : null,
                ':scadenza' => $data['scadenza'] ?? null,
                ':cliente_id' => $data['cliente_id'] ?? null,
                ':id' => $practiceId,
            ]);

            if (($current['assegnatario']['id'] ?? null) !== ($data['id_utente_caf_patronato'] ?? null)) {
                $assignedId = $data['id_utente_caf_patronato'] ?? null;
                $message = $assignedId ? 'Pratica assegnata all\'operatore ID ' . $assignedId : 'Assegnazione operatore rimossa';
                $this->recordEvent($practiceId, 'assegnazione', $message, ['nuovo_operatore_id' => $assignedId], $requestUserId, $operatorId);
                if ($assignedId) {
                    $this->notifyAssignment($practiceId, (int) $assignedId, 'Aggiornamento pratica assegnata');
                }
            }

            if ($current['stato'] !== $data['stato']) {
                $this->recordEvent($practiceId, 'stato', 'Stato aggiornato a ' . $data['stato'], ['stato_precedente' => $current['stato'], 'stato_nuovo' => $data['stato']], $requestUserId, $operatorId);
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw new RuntimeException('Impossibile aggiornare la pratica: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        return $this->getPractice($practiceId, $canManageAll, $operatorId);
    }

    public function updateStatus(int $practiceId, string $statusCode, int $requestUserId, ?int $operatorId, bool $canManageAll, ?string $authorRole = null, bool $registerTimeline = true): array
    {
        $practice = $this->getPractice($practiceId, $canManageAll, $operatorId);
        $status = $this->getStatusByCode($statusCode);
        if ($status === null) {
            throw new RuntimeException('Stato selezionato non valido.');
        }

        $sql = 'UPDATE pratiche SET stato = :stato, data_aggiornamento = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':stato' => $status['codice'],
            ':id' => $practiceId,
        ]);

        $this->recordEvent($practiceId, 'stato', 'Stato aggiornato a ' . $status['nome'], ['codice' => $status['codice']], $requestUserId, $operatorId);

        if ($registerTimeline) {
            $message = 'Stato aggiornato a ' . $status['nome'];
            $roleKey = $authorRole !== null && $authorRole !== '' ? $authorRole : ($operatorId !== null ? 'patronato' : 'operatore');
            $isPublicTimeline = $roleKey === 'patronato';
            try {
                $this->appendTrackingStepInternal($practiceId, $message, $roleKey, $isPublicTimeline);
            } catch (Throwable $exception) {
                error_log('CAF/Patronato timeline update warning: ' . $exception->getMessage());
            }
        }

        return $this->getPractice($practiceId, $canManageAll, $operatorId);
    }

    /**
     * @return array<int,array{data:string,autore:string,descrizione:string,pubblico:bool}>
     */
    public function addTrackingStep(int $practiceId, string $description, int $requestUserId, ?int $operatorId, bool $canManageAll, ?string $authorRole = null, bool $isPublic = false): array
    {
        $practice = $this->getPractice($practiceId, $canManageAll, $operatorId);
        $roleKey = $authorRole !== null && $authorRole !== '' ? $authorRole : ($operatorId !== null ? 'patronato' : 'operatore');

        try {
            $steps = $this->appendTrackingStepInternal($practiceId, $description, $roleKey, $isPublic);
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossibile aggiornare la timeline della pratica: ' . $exception->getMessage(), (int) $exception->getCode(), $exception);
        }

        $this->recordEvent($practiceId, 'tracking_step', 'Aggiornamento timeline aggiunto', ['descrizione' => $description, 'pubblico' => $isPublic], $requestUserId, $operatorId);

        return $steps;
    }

    /**
     * @return array<string,mixed>
     */
    public function getPracticeByTrackingCode(string $trackingCode): array
    {
        $code = strtoupper(trim($trackingCode));
        if ($code === '') {
            throw new RuntimeException('Codice di tracking non valido.');
        }

        $stmt = $this->pdo->prepare('SELECT id, titolo, categoria, stato, tracking_code, tracking_steps, data_creazione, data_aggiornamento FROM pratiche WHERE UPPER(tracking_code) = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Codice di tracking non trovato.');
        }

        return [
            'id' => (int) $row['id'],
            'titolo' => (string) $row['titolo'],
            'categoria' => (string) $row['categoria'],
            'stato' => (string) $row['stato'],
            'tracking_code' => (string) $row['tracking_code'],
            'tracking_steps' => $this->decodeTrackingSteps($row['tracking_steps'] ?? null),
            'data_creazione' => (string) $row['data_creazione'],
            'data_aggiornamento' => (string) $row['data_aggiornamento'],
        ];
    }

    /**
     * @return array{practice:array<string,mixed>,steps:array<int,array{data:string,autore:string,descrizione:string,pubblico:bool}>}
     */
    public function getPublicTrackingViewData(string $trackingCode): array
    {
        $practice = $this->getPracticeByTrackingCode($trackingCode);
        $publicSteps = array_values(array_filter(
            $practice['tracking_steps'],
            static fn(array $step): bool => !empty($step['pubblico'])
        ));

        return [
            'practice' => [
                'id' => $practice['id'],
                'titolo' => $practice['titolo'],
                'categoria' => $practice['categoria'],
                'stato' => $practice['stato'],
                'tracking_code' => $practice['tracking_code'],
                'data_creazione' => $practice['data_creazione'],
                'data_aggiornamento' => $practice['data_aggiornamento'],
            ],
            'steps' => $publicSteps,
        ];
    }

    public function addNote(int $practiceId, string $content, int $requestUserId, ?int $operatorId, bool $visibleToAdmin, bool $visibleToOperator): array
    {
        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException('La nota non può essere vuota.');
        }
        if (mb_strlen($content) > 4000) {
            throw new RuntimeException('La nota non può superare i 4000 caratteri.');
        }

        $sql = 'INSERT INTO pratiche_note (pratica_id, autore_user_id, autore_operatore_id, contenuto, visibile_admin, visibile_operatore, created_at) VALUES (:pratica_id, :autore_user_id, :autore_operatore_id, :contenuto, :visibile_admin, :visibile_operatore, CURRENT_TIMESTAMP)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':pratica_id' => $practiceId,
            ':autore_user_id' => $requestUserId ?: null,
            ':autore_operatore_id' => $operatorId ?: null,
            ':contenuto' => $content,
            ':visibile_admin' => $visibleToAdmin ? 1 : 0,
            ':visibile_operatore' => $visibleToOperator ? 1 : 0,
        ]);

        $this->recordEvent($practiceId, 'nota', 'Aggiunta una nota', ['nota' => $content], $requestUserId, $operatorId);

        return $this->listNotes($practiceId, true, null);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listNotes(int $practiceId, bool $isAdmin, ?int $operatorId): array
    {
        $conditions = ['pratica_id = :id'];
        $params = [':id' => $practiceId];
        if (!$isAdmin) {
            $conditions[] = 'visibile_operatore = 1';
        }

        $sql = 'SELECT pn.*, u.username, u.nome, u.cognome, op.nome AS operatore_nome, op.cognome AS operatore_cognome FROM pratiche_note pn LEFT JOIN users u ON u.id = pn.autore_user_id LEFT JOIN utenti_caf_patronato op ON op.id = pn.autore_operatore_id WHERE ' . implode(' AND ', $conditions) . ' ORDER BY pn.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $notes = [];
        foreach ($rows as $row) {
            if (!$isAdmin && $operatorId !== null) {
                $noteOperator = $row['autore_operatore_id'] ? (int) $row['autore_operatore_id'] : null;
                $noteUser = $row['autore_user_id'] ? (int) $row['autore_user_id'] : null;
                if ($noteOperator !== null && $noteOperator !== $operatorId && $noteUser === null) {
                    continue;
                }
            }

            $notes[] = [
                'id' => (int) $row['id'],
                'contenuto' => (string) $row['contenuto'],
                'visibile_admin' => (bool) $row['visibile_admin'],
                'visibile_operatore' => (bool) $row['visibile_operatore'],
                'created_at' => (string) $row['created_at'],
                'autore' => [
                    'user_id' => $row['autore_user_id'] ? (int) $row['autore_user_id'] : null,
                    'operatore_id' => $row['autore_operatore_id'] ? (int) $row['autore_operatore_id'] : null,
                    'nome' => $this->buildAuthorLabel($row),
                ],
            ];
        }

        return $notes;
    }

    public function deleteNote(int $noteId, int $practiceId, bool $isAdmin, ?int $operatorId): void
    {
        if (!$isAdmin && $operatorId === null) {
            throw new RuntimeException('Non hai i permessi per eliminare note.');
        }

        $sql = 'DELETE FROM pratiche_note WHERE id = :id AND pratica_id = :pratica_id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $noteId,
            ':pratica_id' => $practiceId,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listDocuments(int $practiceId): array
    {
        $sql = 'SELECT * FROM pratiche_documenti WHERE pratica_id = :id ORDER BY created_at DESC, id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $practiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            $downloadUrl = function_exists('caf_patronato_build_download_url')
                ? caf_patronato_build_download_url('document', (int) $row['id'])
                : '';
            return [
                'id' => (int) $row['id'],
                'file_name' => (string) $row['file_name'],
                'file_path' => (string) $row['file_path'],
                'mime_type' => (string) $row['mime_type'],
                'file_size' => (int) $row['file_size'],
                'uploaded_by' => $row['uploaded_by'] ? (int) $row['uploaded_by'] : null,
                'uploaded_operatore_id' => $row['uploaded_operatore_id'] ? (int) $row['uploaded_operatore_id'] : null,
                'created_at' => (string) $row['created_at'],
                'download_url' => $downloadUrl,
            ];
        }, $rows);
    }

    /**
     * @param array{name:string,tmp_name:string,size:int,error:int,type?:string} $uploadedFile
     *
     * @return array<int,array<string,mixed>>
     */
    public function addDocument(int $practiceId, array $uploadedFile, int $requestUserId, ?int $operatorId): array
    {
        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Errore durante l\'upload del file.');
        }
        if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            throw new RuntimeException('File caricato non valido.');
        }

        $size = (int) ($uploadedFile['size'] ?? 0);
        if ($size <= 0 || $size > 20_000_000) {
            throw new RuntimeException('Ogni allegato deve essere compreso tra 1 byte e 20 MB.');
        }

        $detectedMime = $this->detectMime($uploadedFile['tmp_name']);
        if (!isset($this->allowedMimeTypes[$detectedMime])) {
            throw new RuntimeException('Formato file non supportato. Formati ammessi: ' . implode(', ', array_values($this->allowedMimeTypes)) . '.');
        }

        $safeName = $this->sanitizeFileName($uploadedFile['name'] ?? 'documento');
        if ($operatorId !== null && $detectedMime === 'application/pdf') {
            $context = $this->resolveFilenameContext($practiceId);
            $serviceLabel = $context['servizio'] ?? ($context['titolo'] ?? '');
            $nominativoLabel = $context['nominativo'] ?? ($context['titolo'] ?? '');
            $standardName = caf_patronato_generate_standard_filename($serviceLabel, $nominativoLabel);
            if ($standardName !== null) {
                $safeName = $standardName;
            }
        }
        $practiceDir = $this->storagePathForPractice($practiceId);
        caf_patronato_get_encryption_key();
        $storedName = $this->uniqueFileName($practiceDir, $safeName . CAF_PATRONATO_ENCRYPTION_SUFFIX);
        $destination = $practiceDir . DIRECTORY_SEPARATOR . $storedName;
        caf_patronato_encrypt_uploaded_file($uploadedFile['tmp_name'], $destination);

        $relativePath = self::STORAGE_DIR . '/' . $practiceId . '/' . $storedName;
        $sql = 'INSERT INTO pratiche_documenti (pratica_id, file_name, file_path, mime_type, file_size, uploaded_by, uploaded_operatore_id, created_at) VALUES (:pratica_id, :file_name, :file_path, :mime_type, :file_size, :uploaded_by, :uploaded_operatore_id, CURRENT_TIMESTAMP)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':pratica_id' => $practiceId,
            ':file_name' => $safeName,
            ':file_path' => $relativePath,
            ':mime_type' => $detectedMime,
            ':file_size' => $size,
            ':uploaded_by' => $requestUserId ?: null,
            ':uploaded_operatore_id' => $operatorId ?: null,
        ]);

        $this->syncAttachmentsJson($practiceId);
        $this->recordEvent($practiceId, 'allegato', 'Caricato un nuovo documento', ['file' => $safeName], $requestUserId, $operatorId);

        try {
            $roleKey = $operatorId !== null ? 'patronato' : 'operatore';
            $description = sprintf('Documento caricato: %s', $safeName);
            $isPublicTimeline = $roleKey === 'patronato';
            $this->appendTrackingStepInternal($practiceId, $description, $roleKey, $isPublicTimeline);
        } catch (Throwable $exception) {
            error_log('CAF/Patronato timeline append warning (document upload): ' . $exception->getMessage());
        }

        return $this->listDocuments($practiceId);
    }

    public function deleteDocument(int $documentId, int $practiceId, bool $canManageAll, ?int $operatorId): void
    {
        if (!$canManageAll && $operatorId === null) {
            throw new RuntimeException('Non hai i permessi per rimuovere documenti.');
        }

        $stmt = $this->pdo->prepare('SELECT file_path, file_name FROM pratiche_documenti WHERE id = :id AND pratica_id = :pratica_id');
        $stmt->execute([
            ':id' => $documentId,
            ':pratica_id' => $practiceId,
        ]);
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$file) {
            return;
        }

        $deleteStmt = $this->pdo->prepare('DELETE FROM pratiche_documenti WHERE id = :id AND pratica_id = :pratica_id');
        $deleteStmt->execute([
            ':id' => $documentId,
            ':pratica_id' => $practiceId,
        ]);

        $absolutePath = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $file['file_path']);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        $this->syncAttachmentsJson($practiceId);

        $fileName = isset($file['file_name']) ? (string) $file['file_name'] : basename((string) ($file['file_path'] ?? ''));
        $this->recordEvent($practiceId, 'allegato', 'Documento eliminato', ['file' => $fileName], null, $operatorId);

        try {
            $roleKey = $operatorId !== null ? 'patronato' : 'operatore';
            $description = sprintf('Documento eliminato: %s', $fileName);
            $this->appendTrackingStepInternal($practiceId, $description, $roleKey, false);
        } catch (Throwable $exception) {
            error_log('CAF/Patronato timeline append warning (document delete): ' . $exception->getMessage());
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listTypes(?string $categoria = null): array
    {
        $sql = 'SELECT * FROM tipologie_pratiche';
        $params = [];
        if ($categoria !== null) {
            $sql .= ' WHERE categoria = :categoria';
            $params[':categoria'] = strtoupper($categoria);
        }
        $sql .= ' ORDER BY nome ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'nome' => (string) $row['nome'],
                'categoria' => (string) $row['categoria'],
                'campi_personalizzati' => $this->decodeJson($row['campi_personalizzati'] ?? null),
                'created_at' => (string) $row['created_at'],
                'updated_at' => (string) $row['updated_at'],
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function createType(array $payload): array
    {
        $data = $this->validateTypePayload($payload, true);
        $sql = 'INSERT INTO tipologie_pratiche (nome, categoria, campi_personalizzati, created_at, updated_at) VALUES (:nome, :categoria, :campi_personalizzati, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':nome' => $data['nome'],
            ':categoria' => $data['categoria'],
            ':campi_personalizzati' => $data['campi_personalizzati'] ? json_encode($data['campi_personalizzati'], JSON_THROW_ON_ERROR) : null,
        ]);

        return $this->getType((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateType(int $typeId, array $payload): array
    {
        $data = $this->validateTypePayload($payload, false, $typeId);
        $sql = 'UPDATE tipologie_pratiche SET nome = :nome, categoria = :categoria, campi_personalizzati = :campi_personalizzati, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':nome' => $data['nome'],
            ':categoria' => $data['categoria'],
            ':campi_personalizzati' => $data['campi_personalizzati'] ? json_encode($data['campi_personalizzati'], JSON_THROW_ON_ERROR) : null,
            ':id' => $typeId,
        ]);

        return $this->getType($typeId);
    }

    public function deleteType(int $typeId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tipologie_pratiche WHERE id = :id');
        $stmt->execute([':id' => $typeId]);
    }

    public function getType(int $typeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tipologie_pratiche WHERE id = :id');
        $stmt->execute([':id' => $typeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Tipologia non trovata.');
        }

        return [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome'],
            'categoria' => (string) $row['categoria'],
            'campi_personalizzati' => $this->decodeJson($row['campi_personalizzati'] ?? null),
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listOperators(?string $categoria = null, bool $onlyActive = true): array
    {
        $sql = 'SELECT op.*, u.username, u.email AS user_email, u.ruolo AS user_role FROM utenti_caf_patronato op LEFT JOIN users u ON u.id = op.user_id';
        $params = [];
        $conditions = [];
        if ($categoria !== null) {
            $conditions[] = 'op.ruolo = :categoria';
            $params[':categoria'] = strtoupper($categoria);
        }
        if ($onlyActive) {
            $conditions[] = 'op.attivo = 1';
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY op.attivo DESC, op.nome ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'nome' => (string) $row['nome'],
                'cognome' => (string) $row['cognome'],
                'email' => (string) $row['email'],
                'ruolo' => (string) $row['ruolo'],
                'attivo' => (bool) $row['attivo'],
                'user_id' => $row['user_id'] ? (int) $row['user_id'] : null,
                'user_username' => $row['username'] ?? null,
                'user_email' => $row['user_email'] ?? null,
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function saveOperator(?int $operatorId, array $payload): array
    {
        $data = $this->validateOperatorPayload($payload);
        if ($operatorId === null) {
            $sql = 'INSERT INTO utenti_caf_patronato (user_id, nome, cognome, email, password_hash, ruolo, attivo, created_at, updated_at) VALUES (:user_id, :nome, :cognome, :email, :password_hash, :ruolo, :attivo, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $data['user_id'],
                ':nome' => $data['nome'],
                ':cognome' => $data['cognome'],
                ':email' => $data['email'],
                ':password_hash' => password_hash($data['password'] ?: bin2hex(random_bytes(6)), PASSWORD_BCRYPT),
                ':ruolo' => $data['ruolo'],
                ':attivo' => $data['attivo'] ? 1 : 0,
            ]);
            $operatorId = (int) $this->pdo->lastInsertId();
        } else {
            $fields = 'user_id = :user_id, nome = :nome, cognome = :cognome, email = :email, ruolo = :ruolo, attivo = :attivo, updated_at = CURRENT_TIMESTAMP';
            $params = [
                ':user_id' => $data['user_id'],
                ':nome' => $data['nome'],
                ':cognome' => $data['cognome'],
                ':email' => $data['email'],
                ':ruolo' => $data['ruolo'],
                ':attivo' => $data['attivo'] ? 1 : 0,
                ':id' => $operatorId,
            ];
            if ($data['password'] !== '') {
                $fields .= ', password_hash = :password_hash';
                $params[':password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
            }
            $sql = 'UPDATE utenti_caf_patronato SET ' . $fields . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
        }

        return $this->getOperator($operatorId);
    }

    public function toggleOperator(int $operatorId, bool $enable): void
    {
        $stmt = $this->pdo->prepare('UPDATE utenti_caf_patronato SET attivo = :attivo, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':id' => $operatorId,
            ':attivo' => $enable ? 1 : 0,
        ]);
    }

    public function getOperator(int $operatorId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM utenti_caf_patronato WHERE id = :id');
        $stmt->execute([':id' => $operatorId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Operatore non trovato.');
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => $row['user_id'] ? (int) $row['user_id'] : null,
            'nome' => (string) $row['nome'],
            'cognome' => (string) $row['cognome'],
            'email' => (string) $row['email'],
            'ruolo' => (string) $row['ruolo'],
            'attivo' => (bool) $row['attivo'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    public function findOperatorIdByUser(int $userId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM utenti_caf_patronato WHERE user_id = :user_id AND attivo = 1');
        $stmt->execute([':user_id' => $userId]);
        $value = $stmt->fetchColumn();

        return $value ? (int) $value : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listNotifications(?int $userId, ?int $operatorId, bool $showRead = false): array
    {
        $conditions = [];
        $params = [];
        if ($userId !== null) {
            $conditions[] = 'destinatario_user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        if ($operatorId !== null) {
            $conditions[] = 'destinatario_operatore_id = :operator_id';
            $params[':operator_id'] = $operatorId;
        }

        if (!$conditions) {
            return [];
        }

        if (!$showRead) {
            $conditions[] = 'stato = \'nuova\'';
        }

        $sql = 'SELECT * FROM pratiche_notifiche WHERE ' . implode(' AND ', $conditions) . ' ORDER BY created_at DESC LIMIT 50';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'pratica_id' => (int) $row['pratica_id'],
                'tipo' => (string) $row['tipo'],
                'messaggio' => (string) $row['messaggio'],
                'channel' => (string) $row['channel'],
                'stato' => (string) $row['stato'],
                'created_at' => (string) $row['created_at'],
                'read_at' => $row['read_at'],
            ];
        }, $rows);
    }

    public function markNotificationRead(int $notificationId, ?int $userId, ?int $operatorId): void
    {
        $conditions = ['id = :id'];
        $params = [':id' => $notificationId];
        if ($userId !== null) {
            $conditions[] = 'destinatario_user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        if ($operatorId !== null) {
            $conditions[] = 'destinatario_operatore_id = :operator_id';
            $params[':operator_id'] = $operatorId;
        }

        $sql = 'UPDATE pratiche_notifiche SET stato = \'letta\', read_at = CURRENT_TIMESTAMP WHERE ' . implode(' AND ', $conditions);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listStatuses(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM pratiche_stati ORDER BY ordering ASC, id ASC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'codice' => (string) $row['codice'],
                'nome' => (string) $row['nome'],
                'colore' => (string) $row['colore'],
                'ordering' => (int) $row['ordering'],
            ];
        }, $rows);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function createStatus(array $payload): array
    {
        $data = $this->validateStatusPayload($payload, true);
        $sql = 'INSERT INTO pratiche_stati (codice, nome, colore, ordering, created_at, updated_at) VALUES (:codice, :nome, :colore, :ordering, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':codice' => $data['codice'],
            ':nome' => $data['nome'],
            ':colore' => $data['colore'],
            ':ordering' => $data['ordering'],
        ]);

        return $this->getStatus((int) $this->pdo->lastInsertId());
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateStatusDefinition(int $statusId, array $payload): array
    {
        $data = $this->validateStatusPayload($payload, false, $statusId);
        $sql = 'UPDATE pratiche_stati SET codice = :codice, nome = :nome, colore = :colore, ordering = :ordering, updated_at = CURRENT_TIMESTAMP WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':codice' => $data['codice'],
            ':nome' => $data['nome'],
            ':colore' => $data['colore'],
            ':ordering' => $data['ordering'],
            ':id' => $statusId,
        ]);

        return $this->getStatus($statusId);
    }

    public function deleteStatus(int $statusId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM pratiche_stati WHERE id = :id');
        $stmt->execute([':id' => $statusId]);
    }

    public function getStatus(int $statusId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pratiche_stati WHERE id = :id');
        $stmt->execute([':id' => $statusId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('Stato non trovato.');
        }

        return [
            'id' => (int) $row['id'],
            'codice' => (string) $row['codice'],
            'nome' => (string) $row['nome'],
            'colore' => (string) $row['colore'],
            'ordering' => (int) $row['ordering'],
        ];
    }

    public function getStatusByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pratiche_stati WHERE codice = :codice LIMIT 1');
        $stmt->execute([':codice' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'codice' => (string) $row['codice'],
            'nome' => (string) $row['nome'],
            'colore' => (string) $row['colore'],
            'ordering' => (int) $row['ordering'],
        ];
    }

    private function ensureDefaultStatuses(): void
    {
        $defaults = [
            ['codice' => 'in_lavorazione', 'nome' => 'In lavorazione', 'colore' => 'sky', 'ordering' => 10],
            ['codice' => 'completata', 'nome' => 'Completata', 'colore' => 'emerald', 'ordering' => 20],
            ['codice' => 'sospesa', 'nome' => 'Sospesa', 'colore' => 'amber', 'ordering' => 30],
            ['codice' => 'archiviata', 'nome' => 'Archiviata', 'colore' => 'slate', 'ordering' => 40],
        ];

        foreach ($defaults as $status) {
            $existing = $this->getStatusByCode($status['codice']);
            if ($existing !== null) {
                continue;
            }

            $stmt = $this->pdo->prepare('INSERT INTO pratiche_stati (codice, nome, colore, ordering, created_at, updated_at) VALUES (:codice, :nome, :colore, :ordering, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)');
            $stmt->execute([
                ':codice' => $status['codice'],
                ':nome' => $status['nome'],
                ':colore' => $status['colore'],
                ':ordering' => $status['ordering'],
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|null $current
     * @return array<string,mixed>
     */
    private function validatePracticePayload(array $payload, bool $isCreate, ?array $current = null): array
    {
        $data = [];
        $data['titolo'] = trim((string) ($payload['titolo'] ?? ''));
        if ($data['titolo'] === '') {
            throw new RuntimeException('Il titolo della pratica è obbligatorio.');
        }
        if (mb_strlen($data['titolo']) > 200) {
            throw new RuntimeException('Il titolo non può superare i 200 caratteri.');
        }

        $data['descrizione'] = trim((string) ($payload['descrizione'] ?? ''));
        if ($data['descrizione'] === '') {
            $data['descrizione'] = null;
        }

        $tipoId = isset($payload['tipo_pratica']) ? (int) $payload['tipo_pratica'] : 0;
        if ($tipoId <= 0) {
            throw new RuntimeException('Seleziona una tipologia di pratica.');
        }

        $tipo = $this->getType($tipoId);
        $data['tipo_pratica'] = $tipo['id'];
        $data['categoria'] = $tipo['categoria'];

        if (isset($payload['categoria'])) {
            $categoria = strtoupper(trim((string) $payload['categoria']));
            if ($categoria !== '' && $categoria !== $data['categoria']) {
                throw new RuntimeException('La categoria selezionata non corrisponde alla tipologia.');
            }
        }

        $statusCode = (string) ($payload['stato'] ?? '');
        if ($statusCode === '') {
            $statusCode = $current['stato'] ?? 'in_lavorazione';
        }
        $status = $this->getStatusByCode($statusCode);
        if ($status === null) {
            throw new RuntimeException('Lo stato indicato non è valido.');
        }
        $data['stato'] = $status['codice'];

        $note = trim((string) ($payload['note'] ?? ''));
        $data['note'] = $note !== '' ? $note : null;

        $scadenza = trim((string) ($payload['scadenza'] ?? ''));
        if ($scadenza !== '') {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', $scadenza) ?: DateTimeImmutable::createFromFormat('d/m/Y', $scadenza);
            if (!$date) {
                throw new RuntimeException('La scadenza indicata non è valida.');
            }
            $data['scadenza'] = $date->format('Y-m-d');
        } else {
            $data['scadenza'] = null;
        }

        $clienteId = isset($payload['cliente_id']) ? (int) $payload['cliente_id'] : 0;
        $data['cliente_id'] = $clienteId > 0 ? $clienteId : null;

        $metadati = $payload['metadati'] ?? [];
        if (!is_array($metadati)) {
            throw new RuntimeException('I campi personalizzati devono essere inviati come oggetto JSON.');
        }
        $data['metadati'] = $this->filterCustomFields($metadati, $tipo['campi_personalizzati']);

        $assegnatario = isset($payload['id_utente_caf_patronato']) ? (int) $payload['id_utente_caf_patronato'] : null;
        if ($assegnatario !== null && $assegnatario > 0) {
            $operator = $this->getOperator($assegnatario);
            if (!$operator['attivo']) {
                throw new RuntimeException('L\'operatore selezionato non è attivo.');
            }
            if ($operator['ruolo'] !== $data['categoria']) {
                throw new RuntimeException('L\'operatore selezionato appartiene ad una categoria differente.');
            }
            $data['id_utente_caf_patronato'] = $operator['id'];
        } else {
            $data['id_utente_caf_patronato'] = null;
        }

        return $data;
    }

    /**
     * @param array<string,mixed>|null $schema
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function filterCustomFields(array $values, ?array $schema): array
    {
        if (!$schema) {
            return [];
        }

        $filtered = [];
        foreach ($schema as $field) {
            if (!is_array($field)) {
                continue;
            }
            $slug = (string) ($field['slug'] ?? '');
            if ($slug === '' || !array_key_exists($slug, $values)) {
                continue;
            }
            $value = $values[$slug];
            if (is_array($value)) {
                $filtered[$slug] = $value;
                continue;
            }
            $filtered[$slug] = trim((string) $value);
        }

        return $filtered;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function validateTypePayload(array $payload, bool $isCreate, ?int $typeId = null): array
    {
        $nome = trim((string) ($payload['nome'] ?? ''));
        if ($nome === '') {
            throw new RuntimeException('Il nome della tipologia è obbligatorio.');
        }
        if (mb_strlen($nome) > 160) {
            throw new RuntimeException('Il nome della tipologia non può superare i 160 caratteri.');
        }

        $categoria = strtoupper(trim((string) ($payload['categoria'] ?? '')));
        if (!in_array($categoria, ['CAF', 'PATRONATO'], true)) {
            throw new RuntimeException('La categoria deve essere CAF o Patronato.');
        }

        $customFields = [];
        if (!empty($payload['campi_personalizzati'])) {
            $candidate = $payload['campi_personalizzati'];
            if (is_string($candidate)) {
                $decoded = json_decode($candidate, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('La definizione dei campi personalizzati deve essere un JSON valido.');
                }
                $candidate = $decoded;
            }
            if (!is_array($candidate)) {
                throw new RuntimeException('La definizione dei campi personalizzati deve essere un array.');
            }
            foreach ($candidate as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $slug = trim((string) ($field['slug'] ?? ''));
                $label = trim((string) ($field['label'] ?? ''));
                if ($slug === '' || $label === '') {
                    continue;
                }
                $type = strtolower(trim((string) ($field['type'] ?? 'text')));
                if (!in_array($type, ['text', 'textarea', 'date', 'select', 'checkbox', 'number'], true)) {
                    $type = 'text';
                }
                $entry = [
                    'slug' => $slug,
                    'label' => $label,
                    'type' => $type,
                    'required' => !empty($field['required']),
                ];
                if (!empty($field['options']) && is_array($field['options'])) {
                    $entry['options'] = array_values(array_filter(array_map(static fn($value) => trim((string) $value), $field['options'])));
                }
                $customFields[] = $entry;
            }
        }

        $query = 'SELECT id FROM tipologie_pratiche WHERE nome = :nome AND categoria = :categoria';
        $params = [
            ':nome' => $nome,
            ':categoria' => $categoria,
        ];
        if (!$isCreate && $typeId !== null) {
            $query .= ' AND id <> :id';
            $params[':id'] = $typeId;
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Esiste già una tipologia con questo nome per la categoria selezionata.');
        }

        return [
            'nome' => $nome,
            'categoria' => $categoria,
            'campi_personalizzati' => $customFields,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function validateOperatorPayload(array $payload): array
    {
        $nome = trim((string) ($payload['nome'] ?? ''));
        $cognome = trim((string) ($payload['cognome'] ?? ''));
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $ruolo = strtoupper(trim((string) ($payload['ruolo'] ?? '')));

        if ($nome === '' || $cognome === '') {
            throw new RuntimeException('Nome e cognome sono obbligatori per l\'operatore.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Indirizzo email operatore non valido.');
        }
        if (!in_array($ruolo, ['CAF', 'PATRONATO'], true)) {
            throw new RuntimeException('Il ruolo operatore deve essere CAF o Patronato.');
        }

        $password = (string) ($payload['password'] ?? '');
        if (!empty($payload['force_password']) && $password === '') {
            throw new RuntimeException('È necessario indicare una password per l\'operatore.');
        }

        $userId = isset($payload['user_id']) ? (int) $payload['user_id'] : null;
        if ($userId !== null && $userId <= 0) {
            $userId = null;
        }

        return [
            'nome' => $nome,
            'cognome' => $cognome,
            'email' => $email,
            'ruolo' => $ruolo,
            'attivo' => !empty($payload['attivo']),
            'password' => $password,
            'user_id' => $userId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function validateStatusPayload(array $payload, bool $isCreate, ?int $statusId = null): array
    {
        $codice = strtolower(trim((string) ($payload['codice'] ?? '')));
        if ($codice === '') {
            throw new RuntimeException('Il codice dello stato è obbligatorio.');
        }
        if (!preg_match('/^[a-z0-9_\-]+$/', $codice)) {
            throw new RuntimeException('Il codice dello stato può contenere solo lettere minuscole, numeri, trattini e underscore.');
        }

        $nome = trim((string) ($payload['nome'] ?? ''));
        if ($nome === '') {
            throw new RuntimeException('Il nome dello stato è obbligatorio.');
        }

        $colore = $this->sanitizeStatusColor($payload['colore'] ?? null);

        $ordering = isset($payload['ordering']) ? (int) $payload['ordering'] : 0;
        if ($ordering < 0) {
            $ordering = 0;
        }

        $query = 'SELECT id FROM pratiche_stati WHERE codice = :codice';
        $params = [':codice' => $codice];
        if (!$isCreate && $statusId !== null) {
            $query .= ' AND id <> :id';
            $params[':id'] = $statusId;
        }
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        if ($stmt->fetchColumn()) {
            throw new RuntimeException('Esiste già uno stato con questo codice.');
        }

        return [
            'codice' => $codice,
            'nome' => $nome,
            'colore' => $colore,
            'ordering' => $ordering,
        ];
    }

    private function sanitizeStatusColor($rawColor): string
    {
        $default = 'secondary';
        if (!is_string($rawColor)) {
            return $default;
        }
        $normalized = strtolower(trim($rawColor));
        if ($normalized === '') {
            return $default;
        }

        if (preg_match('/#[0-9a-f]{3,8}/i', $normalized) && preg_match('/^#[0-9a-f]{3}$|^#[0-9a-f]{6}$|^#[0-9a-f]{8}$/i', $normalized)) {
            return strtolower($normalized);
        }

        if (preg_match('/^rgba?\([0-9.,\s%]+\)$/i', $normalized)) {
            return $normalized;
        }

        if (preg_match('/^[a-z0-9_-]{1,32}$/', $normalized)) {
            return $normalized;
        }

        if (preg_match('/^soft-[a-z0-9_-]{1,27}$/', $normalized)) { // 32 chars total accounting for prefix
            return $normalized;
        }

        $tokens = preg_split('/\s+/', $normalized);
        $validTokens = [];
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^(bg|text|border)-[a-z0-9_-]{1,32}$/', $token)) {
                $validTokens[] = $token;
                continue;
            }
            if (preg_match('/^(soft-)?[a-z0-9_-]{1,32}$/', $token)) {
                $validTokens[] = $token;
                continue;
            }
            if (preg_match('/^#[0-9a-f]{3}$|^#[0-9a-f]{6}$|^#[0-9a-f]{8}$/i', $token)) {
                $validTokens[] = strtolower($token);
                continue;
            }
            if (preg_match('/^rgba?\([0-9.,%]+\)$/i', $token)) {
                $validTokens[] = $token;
                continue;
            }
        }

        if (!empty($validTokens)) {
            return implode(' ', array_unique($validTokens));
        }

        return $default;
    }

    private function syncAttachmentsJson(int $practiceId): void
    {
        $documents = $this->listDocuments($practiceId);
        $snapshot = [];
        foreach ($documents as $document) {
            $snapshot[] = [
                'id' => $document['id'],
                'file_name' => $document['file_name'],
                'file_path' => $document['file_path'],
                'mime_type' => $document['mime_type'],
                'file_size' => $document['file_size'],
                'created_at' => $document['created_at'],
            ];
        }

        $stmt = $this->pdo->prepare('UPDATE pratiche SET allegati = :allegati, data_aggiornamento = CURRENT_TIMESTAMP WHERE id = :id');
        $stmt->execute([
            ':allegati' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ':id' => $practiceId,
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function resolveFilenameContext(int $practiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT titolo, metadati FROM pratiche WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $practiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return [];
        }

        $context = [
            'titolo' => (string) ($row['titolo'] ?? ''),
        ];

        $meta = $this->decodeJson($row['metadati'] ?? null);
        if ($meta) {
            foreach (['nominativo', 'servizio'] as $key) {
                if (isset($meta[$key]) && is_string($meta[$key])) {
                    $value = trim($meta[$key]);
                    if ($value !== '') {
                        $context[$key] = $value;
                    }
                }
            }
        }

        if ((!isset($context['nominativo']) || $context['nominativo'] === ''
            || !isset($context['servizio']) || $context['servizio'] === '')
            && $context['titolo'] !== '') {
            $parts = array_map('trim', preg_split('/\s*-\s*/', $context['titolo']) ?: []);
            if (!isset($context['nominativo']) && isset($parts[0]) && $parts[0] !== '') {
                $context['nominativo'] = $parts[0];
            }
            if (!isset($context['servizio']) && isset($parts[1]) && $parts[1] !== '') {
                $context['servizio'] = $parts[1];
            }
        }

        return $context;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function listEvents(int $practiceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pratiche_eventi WHERE pratica_id = :id ORDER BY created_at DESC, id DESC');
        $stmt->execute([':id' => $practiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'evento' => (string) $row['evento'],
                'messaggio' => $row['messaggio'],
                'payload' => $row['payload'] ? json_decode((string) $row['payload'], true) : null,
                'creato_da' => $row['creato_da'] ? (int) $row['creato_da'] : null,
                'creato_operatore_id' => $row['creato_operatore_id'] ? (int) $row['creato_operatore_id'] : null,
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    private function recordEvent(int $practiceId, string $event, ?string $message, ?array $payload, ?int $userId, ?int $operatorId): void
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO pratiche_eventi (pratica_id, evento, messaggio, payload, creato_da, creato_operatore_id, created_at) VALUES (:pratica_id, :evento, :messaggio, :payload, :creato_da, :creato_operatore_id, CURRENT_TIMESTAMP)');
            $stmt->execute([
                ':pratica_id' => $practiceId,
                ':evento' => $event,
                ':messaggio' => $message,
                ':payload' => $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : null,
                ':creato_da' => $userId ?: null,
                ':creato_operatore_id' => $operatorId ?: null,
            ]);
        } catch (Throwable) {
            // Ignora errori nel log per non interrompere il flusso principale.
        }
    }

    private function notifyAssignment(int $practiceId, int $operatorId, string $subject): void
    {
        try {
            $operator = $this->getOperator($operatorId);
        } catch (RuntimeException) {
            return;
        }

        $stmt = $this->pdo->prepare('SELECT titolo FROM pratiche WHERE id = :id');
        $stmt->execute([':id' => $practiceId]);
        $title = (string) $stmt->fetchColumn();

        $message = sprintf('Ti è stata assegnata la pratica #%d: %s.', $practiceId, $title);
        $this->createNotification($practiceId, $operator, $subject, $message);

        if (($operator['email'] ?? '') !== '') {
            $this->sendAssignmentEmail((string) $operator['email'], $subject, $practiceId, $title, $operator);
        }
    }

    /**
     * @param array<string,mixed> $operator
     */
    private function createNotification(int $practiceId, array $operator, string $subject, string $message): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO pratiche_notifiche (pratica_id, destinatario_user_id, destinatario_operatore_id, tipo, messaggio, channel, stato, created_at) VALUES (:pratica_id, :user_id, :operatore_id, :tipo, :messaggio, :channel, :stato, CURRENT_TIMESTAMP)');
        $stmt->execute([
            ':pratica_id' => $practiceId,
            ':user_id' => $operator['user_id'] ?? null,
            ':operatore_id' => $operator['id'],
            ':tipo' => $subject,
            ':messaggio' => $message,
            ':channel' => 'dashboard',
            ':stato' => 'nuova',
        ]);
    }

    /**
     * @param array<string,mixed> $operator
     */
    private function sendAssignmentEmail(string $recipient, string $subject, int $practiceId, string $title, array $operator): void
    {
        if (!function_exists('render_mail_template') || !function_exists('send_system_mail')) {
            return;
        }

        $body = sprintf(
            '<p>Ciao %s %s,</p><p>ti è stata assegnata una nuova pratica <strong>#%d</strong> con titolo <em>%s</em>.</p><p>Accedi al gestionale per visualizzare i dettagli.</p>',
            htmlspecialchars((string) $operator['nome'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) $operator['cognome'], ENT_QUOTES, 'UTF-8'),
            $practiceId,
            htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
        );

        $html = render_mail_template('Nuova pratica assegnata', $body);
        @send_system_mail($recipient, $subject, $html);
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(array $filters, ?int $operatorId, bool $canViewAll): array
    {
        [$whereSql, $params] = $this->buildFilterSql($filters, $operatorId, $canViewAll);

        $sql = <<<SQL
SELECT p.stato, COUNT(*) AS totale
FROM pratiche p
INNER JOIN tipologie_pratiche tp ON tp.id = p.tipo_pratica
{$whereSql}
GROUP BY p.stato
SQL;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $byStatus = [];
        foreach ($rows as $row) {
            $status = (string) $row['stato'];
            $byStatus[$status] = (int) $row['totale'];
        }

        $totalSql = 'SELECT COUNT(*) FROM pratiche p INNER JOIN tipologie_pratiche tp ON tp.id = p.tipo_pratica ' . $whereSql;
        $totalStmt = $this->pdo->prepare($totalSql);
        foreach ($params as $name => $value) {
            $totalStmt->bindValue($name, $value);
        }
        $totalStmt->execute();
        $total = (int) $totalStmt->fetchColumn();

        return [
            'totale' => $total,
            'per_stato' => $byStatus,
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function buildAuthorLabel(array $row): string
    {
        $parts = [];
        if (!empty($row['operatore_nome']) || !empty($row['operatore_cognome'])) {
            $parts[] = trim((string) ($row['operatore_nome'] ?? '')) . ' ' . trim((string) ($row['operatore_cognome'] ?? ''));
        } elseif (!empty($row['nome']) || !empty($row['cognome'])) {
            $parts[] = trim((string) ($row['nome'] ?? '')) . ' ' . trim((string) ($row['cognome'] ?? ''));
        } elseif (!empty($row['username'])) {
            $parts[] = (string) $row['username'];
        }

        $label = trim(implode(' ', $parts));
        return $label !== '' ? $label : 'Sistema';
    }

    private function detectMime(string $filePath): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $filePath);
        finfo_close($finfo);

        return $mime ?: 'application/octet-stream';
    }

    private function sanitizeFileName(string $fileName): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
        if ($normalized === null || $normalized === '') {
            return 'documento_' . bin2hex(random_bytes(4));
        }

        return $normalized;
    }

    private function uniqueFileName(string $directory, string $fileName): string
    {
        $path = $directory . DIRECTORY_SEPARATOR . $fileName;
        if (!file_exists($path)) {
            return $fileName;
        }

        $parts = pathinfo($fileName);
        $name = $parts['filename'] ?? 'documento';
        $extension = isset($parts['extension']) ? '.' . $parts['extension'] : '';
        $counter = 1;

        do {
            $candidate = $name . '_' . $counter . $extension;
            $path = $directory . DIRECTORY_SEPARATOR . $candidate;
            $counter++;
        } while (file_exists($path));

        return $candidate;
    }
}
