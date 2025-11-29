<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const TICKET_UPLOAD_SUBDIR = 'ticket';
const TICKET_MAX_UPLOAD_BYTES = 10_485_760; // 10MB

function ticket_allowed_extensions(): array
{
    return ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
}

function ticket_status_options(): array
{
    return [
        'OPEN' => 'Aperto',
        'IN_PROGRESS' => 'In lavorazione',
        'WAITING_CLIENT' => 'In attesa cliente',
        'WAITING_PARTNER' => 'In attesa fornitore',
        'RESOLVED' => 'Risolto',
        'CLOSED' => 'Chiuso',
        'ARCHIVED' => 'Archiviato',
    ];
}

function ticket_priority_options(): array
{
    return [
        'LOW' => 'Bassa',
        'MEDIUM' => 'Media',
        'HIGH' => 'Alta',
        'URGENT' => 'Urgente',
    ];
}

function ticket_channel_options(): array
{
    return [
        'PORTAL' => 'Portale',
        'EMAIL' => 'Email',
        'PHONE' => 'Telefono',
        'INTERNAL' => 'Interno',
    ];
}

function ticket_type_options(): array
{
    return [
        'SUPPORT' => 'Supporto',
        'TECH' => 'Tecnico',
        'ADMIN' => 'Amministrativo',
        'SALES' => 'Commerciale',
    ];
}

function ticket_status_badge(string $status): string
{
    $map = [
        'OPEN' => 'bg-primary',
        'IN_PROGRESS' => 'bg-info text-dark',
        'WAITING_CLIENT' => 'bg-warning text-dark',
        'WAITING_PARTNER' => 'bg-warning text-dark',
        'RESOLVED' => 'bg-success',
        'CLOSED' => 'bg-secondary',
        'ARCHIVED' => 'bg-dark',
    ];

    $key = strtoupper($status);
    return $map[$key] ?? 'bg-secondary';
}

function ticket_priority_badge(string $priority): string
{
    $map = [
        'LOW' => 'bg-secondary',
        'MEDIUM' => 'bg-info text-dark',
        'HIGH' => 'bg-warning text-dark',
        'URGENT' => 'bg-danger',
    ];

    $key = strtoupper($priority);
    return $map[$key] ?? 'bg-secondary';
}

function ticket_ensure_upload_dir(): string
{
    $baseUploadDir = realpath(__DIR__ . '/../uploads');
    if ($baseUploadDir === false) {
        $baseUploadDir = __DIR__ . '/../uploads';
    }

    $ticketDir = $baseUploadDir . '/' . TICKET_UPLOAD_SUBDIR;

    if (!is_dir($ticketDir)) {
        mkdir($ticketDir, 0775, true);
    }

    return $ticketDir;
}

function ticket_store_attachments(array $files, int $ticketId, ?int $messageId = null): array
{
    $paths = [];
    if (!isset($files['name'])) {
        return $paths;
    }

    $uploadDir = ticket_ensure_upload_dir();

    $totalFiles = is_array($files['name']) ? count($files['name']) : 0;
    for ($i = 0; $i < $totalFiles; $i++) {
        if ((int) $files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        if ((int) $files['size'][$i] > TICKET_MAX_UPLOAD_BYTES) {
            continue;
        }

        $originalName = (string) $files['name'][$i];
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $extensionLower = strtolower($extension);
        if ($extensionLower !== '' && !in_array($extensionLower, ticket_allowed_extensions(), true)) {
            continue;
        }
        $safeExtension = $extension !== '' ? '.' . strtolower($extension) : '';
        $filename = sprintf('ticket_%d_msg_%d_%s%s', $ticketId, $messageId ?? time(), bin2hex(random_bytes(4)), $safeExtension);

        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($files['tmp_name'][$i], $destination)) {
            continue;
        }

        $relativePath = 'uploads/' . TICKET_UPLOAD_SUBDIR . '/' . $filename;
        $paths[] = $relativePath;
    }

    return $paths;
}

function ticket_fetch_collection(PDO $pdo, array $filters, int $page = 1, int $perPage = 15): array
{
    $page = max($page, 1);
    $perPage = max($perPage, 5);
    $offset = ($page - 1) * $perPage;

    $conditions = [];
    $params = [];

    if (!empty($filters['search'])) {
        $conditions[] = '(t.subject LIKE :search OR t.customer_name LIKE :search OR t.codice LIKE :search)';
        $params['search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['status'])) {
        $conditions[] = 't.status = :status';
        $params['status'] = strtoupper((string) $filters['status']);
    }

    if (!empty($filters['priority'])) {
        $conditions[] = 't.priority = :priority';
        $params['priority'] = strtoupper((string) $filters['priority']);
    }

    if (!empty($filters['channel'])) {
        $conditions[] = 't.channel = :channel';
        $params['channel'] = strtoupper((string) $filters['channel']);
    }

    if (!empty($filters['type'])) {
        $conditions[] = 't.type = :type';
        $params['type'] = strtoupper((string) $filters['type']);
    }

    if (!empty($filters['customer_id'])) {
        $conditions[] = 't.customer_id = :customer_id';
        $params['customer_id'] = (int) $filters['customer_id'];
    }

    if (!empty($filters['assigned_to'])) {
        $conditions[] = 't.assigned_to = :assigned_to';
        $params['assigned_to'] = (int) $filters['assigned_to'];
    }

    if (!empty($filters['date_from'])) {
        $conditions[] = 't.created_at >= :date_from';
        $params['date_from'] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $conditions[] = 't.created_at <= :date_to';
        $params['date_to'] = $filters['date_to'] . ' 23:59:59';
    }

    $baseQuery = 'FROM tickets t
        LEFT JOIN clienti c ON c.id = t.customer_id
        LEFT JOIN users u ON u.id = t.assigned_to';

    $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery $where");
    foreach ($params as $key => $value) {
        $countStmt->bindValue(':' . $key, $value);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $sql = "SELECT t.*, c.ragione_sociale AS company_name, c.nome AS customer_first_name, c.cognome AS customer_last_name,
            u.nome AS agent_name, u.cognome AS agent_lastname
            $baseQuery $where
            ORDER BY t.updated_at DESC
            LIMIT :offset, :limit";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'data' => $rows,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
    ];
}

function ticket_find(PDO $pdo, int $ticketId): ?array
{
    $stmt = $pdo->prepare('SELECT t.*, u.nome AS agent_name, u.cognome AS agent_lastname, u.email AS agent_email,
        creator.nome AS creator_name, creator.cognome AS creator_lastname, creator.username AS creator_username,
        c.ragione_sociale AS company_name, c.nome AS customer_first_name, c.cognome AS customer_last_name
        FROM tickets t
        LEFT JOIN users u ON u.id = t.assigned_to
        LEFT JOIN users creator ON creator.id = t.created_by
        LEFT JOIN clienti c ON c.id = t.customer_id
        WHERE t.id = :id LIMIT 1');
    $stmt->bindValue(':id', $ticketId, PDO::PARAM_INT);
    $stmt->execute();
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);

    return $ticket ?: null;
}

function ticket_messages(PDO $pdo, int $ticketId): array
{
    $stmt = $pdo->prepare('SELECT tm.* FROM ticket_messages tm WHERE tm.ticket_id = :id ORDER BY tm.created_at ASC');
    $stmt->bindValue(':id', $ticketId, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_assignments(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT id, nome, cognome, username FROM users WHERE ruolo IN ('Admin','Manager','Operatore','Support') ORDER BY cognome, nome");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_clients(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, ragione_sociale, nome, cognome, email FROM clienti ORDER BY ragione_sociale, cognome, nome');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function ticket_generate_code(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

function ticket_insert_message(PDO $pdo, array $payload): int
{
    $stmt = $pdo->prepare('INSERT INTO ticket_messages (ticket_id, author_id, author_name, body, attachments, is_internal, visibility, status_snapshot, notified_client, notified_admin)
        VALUES (:ticket_id, :author_id, :author_name, :body, :attachments, :is_internal, :visibility, :status_snapshot, :notified_client, :notified_admin)');
    $stmt->execute([
        ':ticket_id' => $payload['ticket_id'],
        ':author_id' => $payload['author_id'],
        ':author_name' => $payload['author_name'],
        ':body' => $payload['body'],
        ':attachments' => $payload['attachments'],
        ':is_internal' => $payload['is_internal'],
        ':visibility' => $payload['visibility'],
        ':status_snapshot' => $payload['status_snapshot'],
        ':notified_client' => $payload['notified_client'],
        ':notified_admin' => $payload['notified_admin'],
    ]);

    return (int) $pdo->lastInsertId();
}

function ticket_update_status(PDO $pdo, int $ticketId, string $status, ?int $userId = null): void
{
    $stmt = $pdo->prepare('UPDATE tickets SET status = :status, updated_at = NOW(), assigned_to = COALESCE(assigned_to, :user_id) WHERE id = :id');
    $stmt->execute([
        ':status' => strtoupper($status),
        ':user_id' => $userId,
        ':id' => $ticketId,
    ]);
}

function ticket_summary(PDO $pdo): array
{
    $statusStmt = $pdo->query('SELECT status, COUNT(*) AS total FROM tickets GROUP BY status');
    $statusData = $statusStmt->fetchAll(PDO::FETCH_ASSOC);

    $totals = [
        'total' => 0,
        'open' => 0,
        'waiting' => 0,
        'overdue' => 0,
    ];

    foreach ($statusData as $row) {
        $totals['total'] += (int) $row['total'];
        if (in_array($row['status'], ['OPEN', 'IN_PROGRESS'], true)) {
            $totals['open'] += (int) $row['total'];
        }
        if (in_array($row['status'], ['WAITING_CLIENT', 'WAITING_PARTNER'], true)) {
            $totals['waiting'] += (int) $row['total'];
        }
    }

    $overdueStmt = $pdo->query("SELECT COUNT(*) FROM tickets WHERE sla_due_at IS NOT NULL AND sla_due_at < NOW() AND status NOT IN ('RESOLVED','CLOSED','ARCHIVED')");
    $totals['overdue'] = (int) $overdueStmt->fetchColumn();

    return $totals;
}
