<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function notification_type_map(): array
{
    return [
        'info' => ['icon' => 'fa-circle-info', 'color' => 'text-info', 'priority' => 1],
        'success' => ['icon' => 'fa-circle-check', 'color' => 'text-success', 'priority' => 2],
        'warning' => ['icon' => 'fa-triangle-exclamation', 'color' => 'text-warning', 'priority' => 3],
        'error' => ['icon' => 'fa-circle-exclamation', 'color' => 'text-danger', 'priority' => 4],
        'bug' => ['icon' => 'fa-bug', 'color' => 'text-danger', 'priority' => 5],
    ];
}

function notification_type_label(string $type): string
{
    $map = [
        'info' => 'Informazione',
        'success' => 'Successo',
        'warning' => 'Avviso',
        'error' => 'Errore',
        'bug' => 'Bug',
    ];

    return $map[$type] ?? ucfirst($type);
}

function normalize_notification_type(?string $type): string
{
    $value = strtolower(trim((string) $type));
    $map = notification_type_map();
    return array_key_exists($value, $map) ? $value : 'info';
}

function notification_can_view_bug(string $role): bool
{
    return in_array($role, ['Admin', 'Tecnico', 'Manager'], true);
}

function notification_prepare_metadata(mixed $metadata): ?string
{
    if ($metadata === null || $metadata === '') {
        return null;
    }
    if (is_string($metadata)) {
        return $metadata;
    }
    $encoded = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? null : $encoded;
}

function create_notification(PDO $pdo, array $payload, int $userId, string $role): int
{
    $type = normalize_notification_type($payload['type'] ?? 'info');
    if ($type === 'bug' && !notification_can_view_bug($role)) {
        return 0;
    }

    $scope = isset($payload['scope']) ? strtolower(trim((string) $payload['scope'])) : 'user';
    if (!in_array($scope, ['user', 'role'], true)) {
        $scope = 'user';
    }

    $targetUserId = $scope === 'role' ? null : $userId;
    $targetRole = $scope === 'role' ? (string) ($payload['role'] ?? $role) : $role;

    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
        $title = notification_type_label($type);
    }
    $message = trim((string) ($payload['message'] ?? ''));
    if ($message === '') {
        $message = 'Nuova notifica.';
    }

    $metadata = notification_prepare_metadata($payload['metadata'] ?? null);

    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, role, type, title, message, metadata, is_read, created_at)
        VALUES (:user_id, :role, :type, :title, :message, :metadata, 0, NOW())');
    $stmt->bindValue(':user_id', $targetUserId, $targetUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':role', $targetRole !== '' ? $targetRole : null, $targetRole !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->bindValue(':type', $type, PDO::PARAM_STR);
    $stmt->bindValue(':title', $title, PDO::PARAM_STR);
    $stmt->bindValue(':message', $message, PDO::PARAM_STR);
    $stmt->bindValue(':metadata', $metadata, $metadata !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt->execute();

    return (int) $pdo->lastInsertId();
}

function fetch_notifications(PDO $pdo, int $userId, string $role, int $limit = 10, ?int $beforeId = null): array
{
    $limit = max(1, min(50, $limit));
    $canViewBug = notification_can_view_bug($role) ? 1 : 0;

    $filters = '((user_id = :user_id) OR (user_id IS NULL AND role = :role))';
    $filters .= $canViewBug ? '' : " AND type <> 'bug'";
    if ($beforeId !== null) {
        $filters .= ' AND id < :before_id';
    }

    $sql = "SELECT id, user_id, role, type, title, message, metadata, is_read, created_at
        FROM notifications
        WHERE {$filters}
        ORDER BY id DESC
        LIMIT :limit";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':role', $role, PDO::PARAM_STR);
    if ($beforeId !== null) {
        $stmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $countSql = "SELECT COUNT(*) FROM notifications WHERE {$filters} AND is_read = 0";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $countStmt->bindValue(':role', $role, PDO::PARAM_STR);
    if ($beforeId !== null) {
        $countStmt->bindValue(':before_id', $beforeId, PDO::PARAM_INT);
    }
    $countStmt->execute();
    $unreadCount = (int) $countStmt->fetchColumn();

    $map = notification_type_map();
    $items = array_map(static function (array $row) use ($map): array {
        $metadata = null;
        if (!empty($row['metadata'])) {
            $decoded = json_decode((string) $row['metadata'], true);
            $metadata = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }
        $type = normalize_notification_type((string) ($row['type'] ?? 'info'));
        $typeConfig = $map[$type] ?? $map['info'];

        return [
            'id' => (int) $row['id'],
            'type' => $type,
            'title' => (string) ($row['title'] ?? ''),
            'message' => (string) ($row['message'] ?? ''),
            'metadata' => $metadata,
            'isRead' => (bool) ($row['is_read'] ?? 0),
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'createdAtLabel' => format_datetime_locale($row['created_at'] ?? null),
            'icon' => $typeConfig['icon'],
            'colorClass' => $typeConfig['color'],
            'priority' => $typeConfig['priority'],
        ];
    }, $rows);

    $lastItem = end($items);
    $nextCursor = $lastItem ? (int) $lastItem['id'] : null;

    return [
        'items' => $items,
        'unreadCount' => $unreadCount,
        'nextCursor' => $nextCursor,
        'hasMore' => count($items) === $limit,
    ];
}

function fetch_notification_by_id(PDO $pdo, int $notificationId, int $userId, string $role): ?array
{
    $filters = 'id = :id AND ((user_id = :user_id) OR (user_id IS NULL AND role = :role))';
    if (!notification_can_view_bug($role)) {
        $filters .= " AND type <> 'bug'";
    }

    $stmt = $pdo->prepare("SELECT id, user_id, role, type, title, message, metadata, is_read, created_at FROM notifications WHERE {$filters} LIMIT 1");
    $stmt->bindValue(':id', $notificationId, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':role', $role, PDO::PARAM_STR);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $map = notification_type_map();
    $metadata = null;
    if (!empty($row['metadata'])) {
        $decoded = json_decode((string) $row['metadata'], true);
        $metadata = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
    $type = normalize_notification_type((string) ($row['type'] ?? 'info'));
    $typeConfig = $map[$type] ?? $map['info'];

    return [
        'id' => (int) $row['id'],
        'type' => $type,
        'title' => (string) ($row['title'] ?? ''),
        'message' => (string) ($row['message'] ?? ''),
        'metadata' => $metadata,
        'isRead' => (bool) ($row['is_read'] ?? 0),
        'createdAt' => (string) ($row['created_at'] ?? ''),
        'createdAtLabel' => format_datetime_locale($row['created_at'] ?? null),
        'icon' => $typeConfig['icon'],
        'colorClass' => $typeConfig['color'],
        'priority' => $typeConfig['priority'],
    ];
}

function mark_notification_read(PDO $pdo, int $notificationId, int $userId, string $role): bool
{
    $filters = 'id = :id AND ((user_id = :user_id) OR (user_id IS NULL AND role = :role))';
    if (!notification_can_view_bug($role)) {
        $filters .= " AND type <> 'bug'";
    }

    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE {$filters}");
    $stmt->bindValue(':id', $notificationId, PDO::PARAM_INT);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':role', $role, PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->rowCount() > 0;
}

function mark_all_notifications_read(PDO $pdo, int $userId, string $role): int
{
    $filters = '((user_id = :user_id) OR (user_id IS NULL AND role = :role))';
    if (!notification_can_view_bug($role)) {
        $filters .= " AND type <> 'bug'";
    }

    $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE {$filters}");
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':role', $role, PDO::PARAM_STR);
    $stmt->execute();

    return (int) $stmt->rowCount();
}

function build_bug_notification(Throwable $exception, array $context = []): array
{
    $trace = $exception->getTrace();
    $origin = $trace[0] ?? [];
    $file = $exception->getFile();
    $line = $exception->getLine();
    $function = $origin['function'] ?? ($context['function'] ?? 'N/D');

    $metadata = [
        'code' => $exception->getCode(),
        'file' => $file,
        'line' => $line,
        'function' => $function,
        'context' => $context,
        'suggestions' => [
            'causes' => [
                'Input non valido o dati mancanti.',
                'Dipendenza o configurazione non disponibile.',
                'Errore SQL o connessione al database instabile.',
            ],
            'checks' => [
                'Verifica i parametri in ingresso e la loro validazione.',
                'Controlla variabili d’ambiente e permessi.',
                'Controlla log applicativi e query SQL correlate.',
            ],
            'fix' => 'Riprodurre l’errore in ambiente di staging, correggere la validazione o la dipendenza, poi rilasciare il fix con test regressivi.',
        ],
    ];

    return [
        'type' => 'bug',
        'title' => 'Bug rilevato',
        'message' => sprintf('%s (file %s:%d)', $exception->getMessage(), basename($file), $line),
        'metadata' => $metadata,
    ];
}

function notify_bug(PDO $pdo, Throwable $exception, array $context = []): void
{
    static $reporting = false;
    if ($reporting) {
        return;
    }
    $reporting = true;

    try {
        $payload = build_bug_notification($exception, $context);
        foreach (['Admin', 'Tecnico'] as $role) {
            create_notification($pdo, array_merge($payload, ['scope' => 'role', 'role' => $role]), 0, $role);
        }
    } catch (Throwable $ignored) {
        // fail silently
    }

    $reporting = false;
}

function register_bug_notification_handler(PDO $pdo): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    $previousExceptionHandler = set_exception_handler(static function (Throwable $exception) use ($pdo, &$previousExceptionHandler): void {
        notify_bug($pdo, $exception, ['source' => 'exception']);
        if (is_callable($previousExceptionHandler)) {
            $previousExceptionHandler($exception);
        }
    });

    $previousErrorHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use ($pdo, &$previousErrorHandler): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        notify_bug($pdo, new ErrorException($message, 0, $severity, $file, $line), ['source' => 'error']);
        if (is_callable($previousErrorHandler)) {
            return (bool) $previousErrorHandler($severity, $message, $file, $line);
        }
        return false;
    });
}
