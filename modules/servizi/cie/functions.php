<?php

declare(strict_types=1);

const CIE_MODULE_LOG = 'Servizi/CIE';

const CIE_UPLOAD_RULES = [
    'documento_identita' => [
        'dir' => 'uploads/cie/documenti',
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],
    'max_size' => 10 * 1024 * 1024, // 10 MB
    ],
    'foto_cittadino' => [
        'dir' => 'uploads/cie/foto',
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
        ],
    'max_size' => 5 * 1024 * 1024, // 5 MB
    ],
    'ricevuta' => [
        'dir' => 'uploads/cie/ricevute',
        'allowed_mimes' => [
            'application/pdf',
        ],
    'max_size' => 15 * 1024 * 1024, // 15 MB
    ],
];

function cie_prenotazioni_columns(PDO $pdo): array
{
    // Cache the table columns so we can adapt queries to evolving schemas at runtime.
    static $cache = null;

    if (is_array($cache)) {
        return $cache;
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM cie_prenotazioni');
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['Field'])) {
                $columns[] = (string) $row['Field'];
            }
        }
    } catch (Throwable) {
        $columns = [];
    }

    $cache = $columns;
    return $columns;
}

function cie_prenotazioni_has_column(PDO $pdo, string $column): bool
{
    return in_array($column, cie_prenotazioni_columns($pdo), true);
}

function cie_supports_prenotazione_code(PDO $pdo): bool
{
    return cie_prenotazioni_has_column($pdo, 'prenotazione_code');
}

function cie_fallback_booking_code(int $id, ?string $createdAt): string
{
    $datePart = '00000000';
    if ($createdAt) {
        $patterns = ['Y-m-d H:i:s', 'Y-m-d'];
        foreach ($patterns as $pattern) {
            $dt = DateTime::createFromFormat($pattern, $createdAt);
            if ($dt instanceof DateTime) {
                $datePart = $dt->format('Ymd');
                break;
            }
        }
    }

    if ($datePart === '00000000') {
        $datePart = date('Ymd');
    }

    if ($id > 0) {
        return sprintf('CIE-%s-%04d', $datePart, $id);
    }

    return sprintf('CIE-%s-%s', $datePart, strtoupper(bin2hex(random_bytes(3))));
}

function cie_booking_code(array $booking): string
{
    $code = (string) ($booking['booking_code'] ?? '');
    if ($code !== '') {
        return $code;
    }

    $code = (string) ($booking['prenotazione_code'] ?? '');
    if ($code !== '') {
        return $code;
    }

    $id = (int) ($booking['id'] ?? 0);
    $createdAt = $booking['created_at'] ?? null;

    return cie_fallback_booking_code($id, is_string($createdAt) ? $createdAt : null);
}

function cie_status_map(): array
{
    return [
        'nuova' => [
            'label' => 'Nuova richiesta',
            'badge' => 'badge bg-secondary',
        ],
        'dati_inviati' => [
            'label' => 'Dati inviati',
            'badge' => 'badge bg-info text-dark',
        ],
        'appuntamento_confermato' => [
            'label' => 'Appuntamento confermato',
            'badge' => 'badge bg-primary',
        ],
        'completata' => [
            'label' => 'Completata',
            'badge' => 'badge bg-success',
        ],
        'annullata' => [
            'label' => 'Annullata',
            'badge' => 'badge bg-danger',
        ],
    ];
}

function cie_allowed_statuses(): array
{
    return array_keys(cie_status_map());
}

function cie_status_label(string $status): string
{
    $map = cie_status_map();
    return $map[$status]['label'] ?? ucfirst(str_replace('_', ' ', $status));
}

function cie_status_badge(string $status): string
{
    $map = cie_status_map();
    return $map[$status]['badge'] ?? 'badge bg-light text-dark';
}

function cie_generate_code(PDO $pdo): string
{
    if (!cie_supports_prenotazione_code($pdo)) {
        return '';
    }

    do {
        $code = 'CIE-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM cie_prenotazioni WHERE prenotazione_code = :code');
        $stmt->execute(['code' => $code]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $code;
}

function cie_fetch_clients(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT id, nome, cognome, cf_piva, email, telefono, indirizzo FROM clienti ORDER BY cognome, nome');
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function cie_fetch_bookings(PDO $pdo, array $filters = []): array
{
    $sql = 'SELECT cp.*, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.cf_piva AS cliente_cf,
            u.username AS created_by_username, uu.username AS updated_by_username
        FROM cie_prenotazioni cp
        LEFT JOIN clienti c ON cp.cliente_id = c.id
        LEFT JOIN users u ON cp.created_by = u.id
        LEFT JOIN users uu ON cp.updated_by = uu.id';

    $where = [];
    $params = [];
    $hasBookingCodeColumn = cie_supports_prenotazione_code($pdo);

    if (!empty($filters['stato']) && in_array($filters['stato'], cie_allowed_statuses(), true)) {
        $where[] = 'cp.stato = :stato';
        $params[':stato'] = $filters['stato'];
    }

    if (!empty($filters['cliente_id'])) {
        $where[] = 'cp.cliente_id = :cliente_id';
        $params[':cliente_id'] = (int) $filters['cliente_id'];
    }

    if (!empty($filters['search'])) {
        $searchable = [
            'cp.cittadino_nome',
            'cp.cittadino_cognome',
            'cp.cittadino_cf',
            'cp.comune_richiesta',
        ];

        if ($hasBookingCodeColumn) {
            array_unshift($searchable, 'cp.prenotazione_code');
        } else {
            $searchable[] = 'CAST(cp.id AS CHAR)';
        }

        $where[] = '(' . implode(' OR ', array_map(static fn (string $column): string => $column . ' LIKE :search', $searchable)) . ')';
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['created_from'])) {
        $where[] = 'cp.created_at >= :created_from';
        $params[':created_from'] = $filters['created_from'] . ' 00:00:00';
    }

    if (!empty($filters['created_to'])) {
        $where[] = 'cp.created_at <= :created_to';
        $params[':created_to'] = $filters['created_to'] . ' 23:59:59';
    }

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY cp.created_at DESC, cp.id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($results as &$row) {
        $row['booking_code'] = cie_booking_code($row);
    }
    unset($row);

    return $results;
}

function cie_fetch_stats(PDO $pdo): array
{
    $statuses = array_fill_keys(cie_allowed_statuses(), 0);
    $stmt = $pdo->query('SELECT stato, COUNT(*) AS total FROM cie_prenotazioni GROUP BY stato');
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status = (string) ($row['stato'] ?? '');
        if ($status !== '' && isset($statuses[$status])) {
            $statuses[$status] = (int) $row['total'];
        }
    }

    $stmtTotal = $pdo->query('SELECT COUNT(*) FROM cie_prenotazioni');
    $total = (int) $stmtTotal->fetchColumn();

    return [
        'by_status' => $statuses,
        'total' => $total,
    ];
}

function cie_fetch_booking(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT cp.*, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.cf_piva AS cliente_cf,
            c.email AS cliente_email, c.telefono AS cliente_telefono, c.indirizzo AS cliente_indirizzo,
            u.username AS created_by_username, uu.username AS updated_by_username
        FROM cie_prenotazioni cp
        LEFT JOIN clienti c ON cp.cliente_id = c.id
        LEFT JOIN users u ON cp.created_by = u.id
        LEFT JOIN users uu ON cp.updated_by = uu.id
        WHERE cp.id = :id');
    $stmt->execute([':id' => $id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$booking) {
        return null;
    }

    $booking['booking_code'] = cie_booking_code($booking);

    $historyStmt = $pdo->prepare('SELECT id, channel, message_subject, sent_at, notes
        FROM cie_prenotazioni_notifiche
        WHERE prenotazione_id = :id
        ORDER BY sent_at DESC, id DESC');
    try {
        $historyStmt->execute([':id' => $id]);
        $booking['notification_history'] = $historyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $booking['notification_history'] = [];
    }

    return $booking;
}

function cie_create(PDO $pdo, array $data, array $files): int
{
    $uploads = [];
    $pdo->beginTransaction();

    try {
    $code = cie_generate_code($pdo);
    $uploads = cie_process_uploads($files, []);
    $data['stato'] = 'appuntamento_confermato';

        $availableColumns = array_flip(cie_prenotazioni_columns($pdo));
        $insertColumns = [];
        $insertValues = [];
        $params = [];
        $userId = cie_current_user_id();

        $addColumn = static function (string $column, string $paramName, $value) use (&$insertColumns, &$insertValues, &$params, $availableColumns): void {
            if (!isset($availableColumns[$column])) {
                return;
            }
            $insertColumns[] = $column;
            $insertValues[] = ':' . $paramName;
            $params[$paramName] = $value;
        };

        $addColumn('prenotazione_code', 'prenotazione_code', $code);
        $addColumn('cliente_id', 'cliente_id', $data['cliente_id'] ?? null);
        $addColumn('cittadino_nome', 'cittadino_nome', $data['cittadino_nome']);
        $addColumn('cittadino_cognome', 'cittadino_cognome', $data['cittadino_cognome']);
        $addColumn('cittadino_cf', 'cittadino_cf', $data['cittadino_cf'] ?? null);
        $addColumn('cittadino_email', 'cittadino_email', $data['cittadino_email'] ?? null);
        $addColumn('cittadino_telefono', 'cittadino_telefono', $data['cittadino_telefono'] ?? null);
        $addColumn('data_nascita', 'data_nascita', $data['data_nascita'] ?? null);
        $addColumn('luogo_nascita', 'luogo_nascita', $data['luogo_nascita'] ?? null);
        $addColumn('residenza_indirizzo', 'residenza_indirizzo', $data['residenza_indirizzo'] ?? null);
        $addColumn('residenza_cap', 'residenza_cap', $data['residenza_cap'] ?? null);
        $addColumn('residenza_citta', 'residenza_citta', $data['residenza_citta'] ?? null);
        $addColumn('residenza_provincia', 'residenza_provincia', $data['residenza_provincia'] ?? null);
        $addColumn('comune_richiesta', 'comune_richiesta', $data['comune_richiesta']);
        $addColumn('disponibilita_data', 'disponibilita_data', $data['disponibilita_data'] ?? null);
        $addColumn('disponibilita_fascia', 'disponibilita_fascia', $data['disponibilita_fascia'] ?? null);
        $addColumn('appuntamento_data', 'appuntamento_data', $data['appuntamento_data'] ?? null);
        $addColumn('appuntamento_orario', 'appuntamento_orario', $data['appuntamento_orario'] ?? null);
        $addColumn('appuntamento_numero', 'appuntamento_numero', $data['appuntamento_numero'] ?? null);
        $addColumn('stato', 'stato', $data['stato'] ?? 'nuova');
        $addColumn('documento_identita_path', 'documento_identita_path', $uploads['documento_identita']['path'] ?? null);
        $addColumn('documento_identita_nome', 'documento_identita_nome', $uploads['documento_identita']['name'] ?? null);
        $addColumn('documento_identita_mime', 'documento_identita_mime', $uploads['documento_identita']['mime'] ?? null);
        $addColumn('foto_cittadino_path', 'foto_cittadino_path', $uploads['foto_cittadino']['path'] ?? null);
        $addColumn('foto_cittadino_nome', 'foto_cittadino_nome', $uploads['foto_cittadino']['name'] ?? null);
        $addColumn('foto_cittadino_mime', 'foto_cittadino_mime', $uploads['foto_cittadino']['mime'] ?? null);
        $addColumn('ricevuta_path', 'ricevuta_path', $uploads['ricevuta']['path'] ?? null);
        $addColumn('ricevuta_nome', 'ricevuta_nome', $uploads['ricevuta']['name'] ?? null);
        $addColumn('ricevuta_mime', 'ricevuta_mime', $uploads['ricevuta']['mime'] ?? null);
        $addColumn('note', 'note', $data['note'] ?? null);
        $addColumn('esito', 'esito', $data['esito'] ?? null);
        $addColumn('created_by', 'created_by', $userId ?: null);
        $addColumn('updated_by', 'updated_by', $userId ?: null);

        if (!$insertColumns) {
            throw new RuntimeException('Nessuna colonna disponibile per creare la prenotazione CIE.');
        }

        $sql = sprintf(
            'INSERT INTO cie_prenotazioni (%s) VALUES (%s)',
            implode(', ', $insertColumns),
            implode(', ', $insertValues)
        );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $id = (int) $pdo->lastInsertId();
        cie_log_action($pdo, 'Creazione prenotazione', 'Prenotazione CIE #' . $id . ' creata');

        $pdo->commit();

        try {
            $freshBooking = cie_fetch_booking($pdo, $id);
            if ($freshBooking !== null) {
                $sent = cie_send_email_notification($pdo, $freshBooking, 'summary');
                $logMessage = $sent
                    ? 'Riepilogo inviato automaticamente al cittadino.'
                    : 'Invio automatico non eseguito (email assente o invio fallito).';
                cie_log_action($pdo, 'Notifica prenotazione', 'Prenotazione CIE #' . $id . ': ' . $logMessage);
            }
        } catch (Throwable $notificationException) {
            cie_log_action($pdo, 'Notifica prenotazione', 'Prenotazione CIE #' . $id . ': errore invio automatico - ' . $notificationException->getMessage());
        }

        return $id;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        cie_cleanup_uploads($uploads);
        throw $exception;
    }
}

function cie_update(PDO $pdo, int $id, array $data, array $files, array $options = []): bool
{
    $existing = cie_fetch_booking($pdo, $id);
    if ($existing === null) {
        return false;
    }

    $uploads = [];
    $pdo->beginTransaction();

    try {
        $uploads = cie_process_uploads($files, $existing, $options);
        $availableColumns = array_flip(cie_prenotazioni_columns($pdo));
        $setParts = [];
        $params = ['id' => $id];
        $userId = cie_current_user_id();

        $addColumn = static function (string $column, string $paramName, $value) use (&$setParts, &$params, $availableColumns): void {
            if (!isset($availableColumns[$column])) {
                return;
            }
            $setParts[] = sprintf('%s = :%s', $column, $paramName);
            $params[$paramName] = $value;
        };

        $addColumn('cliente_id', 'cliente_id', $data['cliente_id'] ?? null);
        $addColumn('cittadino_nome', 'cittadino_nome', $data['cittadino_nome']);
        $addColumn('cittadino_cognome', 'cittadino_cognome', $data['cittadino_cognome']);
        $addColumn('cittadino_cf', 'cittadino_cf', $data['cittadino_cf'] ?? null);
        $addColumn('cittadino_email', 'cittadino_email', $data['cittadino_email'] ?? null);
        $addColumn('cittadino_telefono', 'cittadino_telefono', $data['cittadino_telefono'] ?? null);
        $addColumn('data_nascita', 'data_nascita', $data['data_nascita'] ?? null);
        $addColumn('luogo_nascita', 'luogo_nascita', $data['luogo_nascita'] ?? null);
        $addColumn('residenza_indirizzo', 'residenza_indirizzo', $data['residenza_indirizzo'] ?? null);
        $addColumn('residenza_cap', 'residenza_cap', $data['residenza_cap'] ?? null);
        $addColumn('residenza_citta', 'residenza_citta', $data['residenza_citta'] ?? null);
        $addColumn('residenza_provincia', 'residenza_provincia', $data['residenza_provincia'] ?? null);
        $addColumn('comune_richiesta', 'comune_richiesta', $data['comune_richiesta']);
        $addColumn('disponibilita_data', 'disponibilita_data', $data['disponibilita_data'] ?? null);
        $addColumn('disponibilita_fascia', 'disponibilita_fascia', $data['disponibilita_fascia'] ?? null);
        $addColumn('appuntamento_data', 'appuntamento_data', $data['appuntamento_data'] ?? null);
        $addColumn('appuntamento_orario', 'appuntamento_orario', $data['appuntamento_orario'] ?? null);
        $addColumn('appuntamento_numero', 'appuntamento_numero', $data['appuntamento_numero'] ?? null);
        $addColumn('stato', 'stato', $data['stato'] ?? $existing['stato']);
        $addColumn('documento_identita_path', 'documento_identita_path', $uploads['documento_identita']['path'] ?? null);
        $addColumn('documento_identita_nome', 'documento_identita_nome', $uploads['documento_identita']['name'] ?? null);
        $addColumn('documento_identita_mime', 'documento_identita_mime', $uploads['documento_identita']['mime'] ?? null);
        $addColumn('foto_cittadino_path', 'foto_cittadino_path', $uploads['foto_cittadino']['path'] ?? null);
        $addColumn('foto_cittadino_nome', 'foto_cittadino_nome', $uploads['foto_cittadino']['name'] ?? null);
        $addColumn('foto_cittadino_mime', 'foto_cittadino_mime', $uploads['foto_cittadino']['mime'] ?? null);
        $addColumn('ricevuta_path', 'ricevuta_path', $uploads['ricevuta']['path'] ?? null);
        $addColumn('ricevuta_nome', 'ricevuta_nome', $uploads['ricevuta']['name'] ?? null);
        $addColumn('ricevuta_mime', 'ricevuta_mime', $uploads['ricevuta']['mime'] ?? null);
        $addColumn('note', 'note', $data['note'] ?? null);
        $addColumn('esito', 'esito', $data['esito'] ?? null);
        $addColumn('updated_by', 'updated_by', $userId ?: null);

        if (isset($availableColumns['updated_at'])) {
            $setParts[] = 'updated_at = NOW()';
        }

        if (!$setParts) {
            throw new RuntimeException('Nessuna colonna disponibile per aggiornare la prenotazione CIE.');
        }

        $sql = sprintf('UPDATE cie_prenotazioni SET %s WHERE id = :id', implode(', ', $setParts));
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        cie_log_action($pdo, 'Aggiornamento prenotazione', 'Prenotazione CIE #' . $id . ' aggiornata');
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        $pdo->rollBack();
        cie_cleanup_uploads($uploads);
        throw $exception;
    }
}

function cie_update_status(PDO $pdo, int $id, string $status): bool
{
    if (!in_array($status, cie_allowed_statuses(), true)) {
        return false;
    }

    $setParts = ['stato = :stato'];
    $params = [
        'stato' => $status,
        'id' => $id,
    ];

    if (cie_prenotazioni_has_column($pdo, 'updated_at')) {
        $setParts[] = 'updated_at = NOW()';
    }

    if (cie_prenotazioni_has_column($pdo, 'updated_by')) {
        $setParts[] = 'updated_by = :updated_by';
        $params['updated_by'] = cie_current_user_id();
    }

    $sql = sprintf('UPDATE cie_prenotazioni SET %s WHERE id = :id', implode(', ', $setParts));
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    cie_log_action($pdo, 'Cambio stato prenotazione', 'Prenotazione CIE #' . $id . ' impostata a ' . $status);
    return $stmt->rowCount() > 0;
}

function cie_delete(PDO $pdo, int $id): bool
{
    $booking = cie_fetch_booking($pdo, $id);
    if ($booking === null) {
        return false;
    }

    $paths = array_filter([
        $booking['documento_identita_path'] ?? null,
        $booking['foto_cittadino_path'] ?? null,
        $booking['ricevuta_path'] ?? null,
    ]);

    $stmt = $pdo->prepare('DELETE FROM cie_prenotazioni WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() > 0) {
        foreach ($paths as $path) {
            cie_delete_file($path);
        }
        cie_log_action($pdo, 'Eliminazione prenotazione', 'Prenotazione CIE #' . $id . ' eliminata');
        return true;
    }

    return false;
}

function cie_process_uploads(array $files, array $existing = [], array $options = []): array
{
    $results = [];
    foreach (CIE_UPLOAD_RULES as $field => $rule) {
        $removeFlag = !empty($options['remove_' . $field]);
        $fileInfo = $files[$field] ?? null;

        if ($fileInfo && isset($fileInfo['error']) && (int) $fileInfo['error'] !== UPLOAD_ERR_NO_FILE) {
            $results[$field] = cie_store_upload($field, $fileInfo, $rule);
            if (!empty($existing[$field . '_path'])) {
                cie_delete_file((string) $existing[$field . '_path']);
            }
        } elseif ($removeFlag) {
            if (!empty($existing[$field . '_path'])) {
                cie_delete_file((string) $existing[$field . '_path']);
            }
            $results[$field] = ['path' => null, 'name' => null, 'mime' => null];
        } else {
            if (!empty($existing[$field . '_path'])) {
                $results[$field] = [
                    'path' => $existing[$field . '_path'],
                    'name' => $existing[$field . '_nome'] ?? $existing[$field . '_name'] ?? null,
                    'mime' => $existing[$field . '_mime'] ?? null,
                ];
            } else {
                $results[$field] = ['path' => null, 'name' => null, 'mime' => null];
            }
        }
    }

    return $results;
}

function cie_store_upload(string $field, array $fileInfo, array $rule): array
{
    if (!isset($fileInfo['tmp_name'], $fileInfo['name'], $fileInfo['type'], $fileInfo['error'], $fileInfo['size'])) {
        throw new RuntimeException('Upload non valido per ' . $field);
    }

    if ((int) $fileInfo['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Errore nel caricamento del file ' . $fileInfo['name']);
    }

    if ($fileInfo['size'] > $rule['max_size']) {
        throw new RuntimeException('Il file ' . $fileInfo['name'] . ' supera la dimensione massima consentita.');
    }

    $mime = cie_detect_mime_type($fileInfo['tmp_name'], (string) $fileInfo['type']);
    if (!in_array($mime, $rule['allowed_mimes'], true)) {
        throw new RuntimeException('Tipo di file non supportato per ' . $fileInfo['name']);
    }

    $destinationDir = public_path($rule['dir']);
    if (!is_dir($destinationDir) && !mkdir($destinationDir, 0775, true) && !is_dir($destinationDir)) {
        throw new RuntimeException('Impossibile creare la directory di caricamento.');
    }

    $extension = pathinfo((string) $fileInfo['name'], PATHINFO_EXTENSION);
    $safeName = sanitize_filename(pathinfo((string) $fileInfo['name'], PATHINFO_FILENAME));
    $newName = $safeName . '_' . bin2hex(random_bytes(4)) . ($extension ? '.' . strtolower((string) $extension) : '');
    $relativePath = $rule['dir'] . '/' . $newName;
    $destinationPath = public_path($relativePath);

    if (!move_uploaded_file($fileInfo['tmp_name'], $destinationPath)) {
        throw new RuntimeException('Impossibile spostare il file caricato.');
    }

    return [
        'path' => $relativePath,
        'name' => (string) $fileInfo['name'],
        'mime' => $mime,
    ];
}

function cie_delete_file(string $relativePath): void
{
    $absolute = public_path($relativePath);
    if (is_file($absolute)) {
        unlink($absolute);
    }
}

function cie_cleanup_uploads(array $uploads): void
{
    foreach ($uploads as $upload) {
        if (!empty($upload['path'])) {
            cie_delete_file((string) $upload['path']);
        }
    }
}

function cie_detect_mime_type(string $path, string $fallback): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                return $detected;
            }
        }
    }

    return $fallback;
}

function cie_current_user_id(): ?int
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $userId = (int) $_SESSION['user_id'];
    return $userId > 0 ? $userId : null;
}

function cie_log_action(PDO $pdo, string $action, string $details): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
            VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
        $stmt->execute([
            ':user_id' => cie_current_user_id(),
            ':modulo' => CIE_MODULE_LOG,
            ':azione' => $action,
            ':dettagli' => $details,
        ]);
    } catch (Throwable) {
        // Il logging non deve bloccare il flusso principale.
    }
}

function cie_build_portal_url(array $booking): string
{
    $base = 'https://www.prenotazionicie.interno.gov.it/cittadino/n/sc/wizardAppuntamentoCittadino/sceltaComune';
    $params = [];

    if (!empty($booking['cittadino_nome'])) {
        $params['nome'] = $booking['cittadino_nome'];
    }
    if (!empty($booking['cittadino_cognome'])) {
        $params['cognome'] = $booking['cittadino_cognome'];
    }
    if (!empty($booking['cittadino_cf'])) {
        $params['codiceFiscale'] = $booking['cittadino_cf'];
    }
    if (!empty($booking['comune_richiesta'])) {
        $params['comune'] = $booking['comune_richiesta'];
    }

    return $base . (!empty($params) ? '?' . http_build_query($params) : '');
}

function cie_send_email_notification(PDO $pdo, array $booking, string $type): bool
{
    $email = trim((string) ($booking['cittadino_email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $bookingCode = cie_booking_code($booking);
    $normalizedType = strtolower($type) === 'reminder' ? 'reminder' : 'summary';

    $subject = $normalizedType === 'reminder'
        ? 'Reminder appuntamento CIE - ' . $bookingCode
        : 'Conferma prenotazione CIE - ' . $bookingCode;

    $details = [
        'Codice prenotazione' => $bookingCode,
        'Cittadino' => trim((string) ($booking['cittadino_cognome'] ?? '') . ' ' . ($booking['cittadino_nome'] ?? '')),
        'Codice fiscale' => (string) ($booking['cittadino_cf'] ?? ''),
        'Comune richiesta' => (string) ($booking['comune_richiesta'] ?? ''),
        'Disponibilità preferita' => ($booking['disponibilita_data'] ?? '') !== ''
            ? format_date_locale($booking['disponibilita_data']) . ' ' . ($booking['disponibilita_fascia'] ?? '')
            : '—',
        'Appuntamento' => ($booking['appuntamento_data'] ?? '') !== ''
            ? format_date_locale($booking['appuntamento_data']) . ' ' . ($booking['appuntamento_orario'] ?? '')
            : 'In attesa di conferma',
    ];

    if (!empty($booking['appuntamento_numero'])) {
        $details['Numero prenotazione portale'] = (string) $booking['appuntamento_numero'];
    }

    $portalUrl = cie_build_portal_url($booking);
    $details['Portale ministeriale'] = $portalUrl;

    $rows = '';
    foreach ($details as $label => $value) {
        $rows .= '<tr><th align="left" style="padding:6px 12px;background:#f8f9fc;width:220px;">' . htmlspecialchars($label, ENT_QUOTES) . '</th>';
        $rows .= '<td style="padding:6px 12px;">' . htmlspecialchars($value, ENT_QUOTES) . '</td></tr>';
    }

    $content = '<p style="margin:0 0 12px;">Gentile cittadino,</p>';
    $content .= $normalizedType === 'reminder'
        ? '<p style="margin:0 0 12px;">ti ricordiamo l\'appuntamento per la Carta d\'Identità Elettronica.</p>'
        : '<p style="margin:0 0 12px;">abbiamo registrato la tua richiesta per la Carta d\'Identità Elettronica.</p>';
    $content .= '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;background:#ffffff;border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">' . $rows . '</table>';
    $content .= '<p style="margin:12px 0 0;">Per completare la procedura visita il portale del Ministero: <a href="' . htmlspecialchars($portalUrl, ENT_QUOTES) . '">prenotazionicie.interno.gov.it</a>.</p>';

    $mailAssets = cie_prepare_mail_attachments($booking, $bookingCode);
    $attachments = $mailAssets['attachments'];
    $documentsCount = (int) $mailAssets['documents_count'];
    $hasCalendarInvite = (bool) $mailAssets['has_calendar'];

    if ($documentsCount > 0) {
        $content .= '<p style="margin:12px 0 0;">In allegato trovi i documenti caricati per la pratica.</p>';
    }

    if ($hasCalendarInvite) {
        $content .= '<p style="margin:12px 0 0;">Apri l\'invito calendario (.ics) allegato per salvare l\'appuntamento sul tuo dispositivo.</p>';
    }

    $htmlBody = render_mail_template('Prenotazione Carta d\'Identità Elettronica', $content);
    $sent = send_system_mail($email, $subject, $htmlBody, $attachments);

    $channel = $normalizedType === 'reminder' ? 'email_reminder' : 'email';
    if ($sent) {
        $notesParts = [];
        if ($documentsCount > 0) {
            $notesParts[] = 'Documenti=' . $documentsCount;
        }
        if ($hasCalendarInvite) {
            $notesParts[] = 'ICS allegato';
        }
        $notes = $notesParts ? implode(' | ', $notesParts) : null;
    } else {
        $notes = 'Invio email non riuscito';
    }
    cie_record_notification($pdo, (int) $booking['id'], $channel, $subject, $notes);

    if ($sent) {
        $field = $normalizedType === 'reminder' ? 'reminder_email_sent_at' : 'conferma_email_sent_at';
        $stmt = $pdo->prepare('UPDATE cie_prenotazioni SET ' . $field . ' = NOW(), updated_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $booking['id']]);
    }

    return $sent;
}

function cie_prepare_mail_attachments(array $booking, string $bookingCode): array
{
    $attachments = [];
    $documentsCount = 0;

    $documentFields = [
        'documento_identita',
        'foto_cittadino',
        'ricevuta',
    ];

    foreach ($documentFields as $field) {
        $pathKey = $field . '_path';
        if (empty($booking[$pathKey])) {
            continue;
        }

        $absolutePath = public_path((string) $booking[$pathKey]);
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            continue;
        }

        $nameKey = $field . '_nome';
        $mimeKey = $field . '_mime';

        $displayName = trim((string) ($booking[$nameKey] ?? basename($absolutePath)));
        if ($displayName === '') {
            $displayName = basename($absolutePath);
        }

        $mime = trim((string) ($booking[$mimeKey] ?? ''));
        if ($mime === '') {
            $detected = function_exists('mime_content_type') ? mime_content_type($absolutePath) : false;
            if (is_string($detected) && $detected !== '') {
                $mime = $detected;
            } else {
                $mime = 'application/octet-stream';
            }
        }

        $attachments[] = [
            'name' => $displayName,
            'mime' => $mime,
            'path' => $absolutePath,
        ];
        $documentsCount++;
    }

    $calendarAttachment = cie_build_calendar_invite($booking, $bookingCode);
    if ($calendarAttachment !== null) {
        $attachments[] = $calendarAttachment;
    }

    return [
        'attachments' => $attachments,
        'documents_count' => $documentsCount,
        'has_calendar' => $calendarAttachment !== null,
    ];
}

function cie_build_calendar_invite(array $booking, string $bookingCode): ?array
{
    $date = trim((string) ($booking['appuntamento_data'] ?? ''));
    if ($date === '') {
        return null;
    }

    $timeRaw = trim((string) ($booking['appuntamento_orario'] ?? ''));
    $startTime = '09:00';
    $endTime = null;

    if ($timeRaw !== '') {
        if (preg_match('/^(\d{2}:\d{2})(?:\s*-\s*(\d{2}:\d{2}))?$/', $timeRaw, $matches)) {
            $startTime = $matches[1];
            if (!empty($matches[2])) {
                $endTime = $matches[2];
            }
        } else {
            $parsed = strtotime($timeRaw);
            if ($parsed !== false) {
                $startTime = date('H:i', $parsed);
            }
        }
    }

    try {
        $timezone = new DateTimeZone('Europe/Rome');
        $start = new DateTimeImmutable($date . ' ' . $startTime, $timezone);
    } catch (Throwable) {
        try {
            $timezone = new DateTimeZone('Europe/Rome');
            $start = new DateTimeImmutable($date . ' 09:00', $timezone);
        } catch (Throwable) {
            return null;
        }
    }

    if ($endTime !== null) {
        try {
            $timezone = new DateTimeZone('Europe/Rome');
            $end = new DateTimeImmutable($date . ' ' . $endTime, $timezone);
        } catch (Throwable) {
            $end = $start->add(new DateInterval('PT30M'));
        }
        if ($end <= $start) {
            $end = $start->add(new DateInterval('PT30M'));
        }
    } else {
        $end = $start->add(new DateInterval('PT30M'));
    }

    $citizen = trim((string) (($booking['cittadino_nome'] ?? '') . ' ' . ($booking['cittadino_cognome'] ?? '')));
    $summaryParts = ['Appuntamento CIE'];
    if ($booking['comune_richiesta'] ?? '') {
        $summaryParts[] = 'Comune di ' . (string) $booking['comune_richiesta'];
    }
    if ($citizen !== '') {
        $summaryParts[] = $citizen;
    }
    $summary = implode(' - ', $summaryParts);

    $descriptionParts = [
        'Codice prenotazione: ' . $bookingCode,
        $citizen !== '' ? 'Cittadino: ' . $citizen : null,
        !empty($booking['comune_richiesta']) ? 'Comune: ' . $booking['comune_richiesta'] : null,
        !empty($booking['appuntamento_numero']) ? 'Numero prenotazione portale: ' . $booking['appuntamento_numero'] : null,
        'Portale ministeriale: ' . cie_build_portal_url($booking),
    ];
    $description = implode("\n", array_filter($descriptionParts, static fn ($value): bool => $value !== null && $value !== ''));

    $location = '';
    if (!empty($booking['comune_richiesta'])) {
        $location = 'Comune di ' . (string) $booking['comune_richiesta'];
    }

    $uidSeed = strtolower(preg_replace('/[^a-z0-9]/', '', $bookingCode));
    if ($uidSeed === '') {
        $uidSeed = bin2hex(random_bytes(6));
    }

    $dtStamp = gmdate('Ymd\THis\Z');
    $dtStart = $start->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    $dtEnd = $end->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Coresuite//CIE Booking//IT',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $uidSeed . '@coresuite.cie',
        'DTSTAMP:' . $dtStamp,
        'DTSTART:' . $dtStart,
        'DTEND:' . $dtEnd,
        'SUMMARY:' . cie_escape_ics_text($summary),
        'DESCRIPTION:' . cie_escape_ics_text($description),
    ];

    if ($location !== '') {
        $lines[] = 'LOCATION:' . cie_escape_ics_text($location);
    }

    if ($citizen !== '' && !empty($booking['cittadino_email'])) {
        $lines[] = 'ATTENDEE;CN=' . cie_escape_ics_text($citizen) . ';ROLE=REQ-PARTICIPANT:mailto:' . cie_escape_ics_text((string) $booking['cittadino_email']);
    }

    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $folded = array_map('cie_fold_ics_line', $lines);
    $content = implode("\r\n", $folded) . "\r\n";

    $fileName = sanitize_filename('prenotazione-cie-' . strtolower($bookingCode)) . '.ics';

    return [
        'name' => $fileName,
        'mime' => 'text/calendar; charset=UTF-8; method=PUBLISH',
        'content' => $content,
    ];
}

function cie_escape_ics_text(string $value): string
{
    $escaped = str_replace('\\', '\\\\', $value);
    $escaped = str_replace(["\r\n", "\n", "\r"], '\\n', $escaped);
    $escaped = str_replace(';', '\\;', $escaped);
    $escaped = str_replace(',', '\\,', $escaped);
    return $escaped;
}

function cie_fold_ics_line(string $line): string
{
    $maxLength = 73;
    if (function_exists('mb_strlen')) {
        $result = '';
        $remaining = $line;
        while (mb_strlen($remaining, 'UTF-8') > $maxLength) {
            $segment = mb_substr($remaining, 0, $maxLength, 'UTF-8');
            $result .= $segment . "\r\n" . ' ';
            $remaining = mb_substr($remaining, $maxLength, null, 'UTF-8');
        }
        return $result . $remaining;
    }

    $result = '';
    $remaining = $line;
    while (strlen($remaining) > $maxLength) {
        $result .= substr($remaining, 0, $maxLength) . "\r\n" . ' ';
        $remaining = substr($remaining, $maxLength);
    }

    return $result . $remaining;
}

function cie_record_notification(PDO $pdo, int $bookingId, string $channel, string $subject, ?string $notes = null): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO cie_prenotazioni_notifiche (prenotazione_id, channel, message_subject, notes, sent_at)
            VALUES (:prenotazione_id, :channel, :message_subject, :notes, NOW())');
        $stmt->execute([
            ':prenotazione_id' => $bookingId,
            ':channel' => $channel,
            ':message_subject' => $subject,
            ':notes' => $notes,
        ]);
    } catch (Throwable) {
        // Ignoriamo errori di tracciamento.
    }
}

function cie_record_whatsapp_trigger(PDO $pdo, int $bookingId, string $recipient, string $message): void
{
    $subject = 'Messaggio WhatsApp verso ' . $recipient;
    cie_record_notification($pdo, $bookingId, 'whatsapp', $subject, $message);
    $stmt = $pdo->prepare('UPDATE cie_prenotazioni SET reminder_whatsapp_sent_at = NOW(), updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $bookingId]);
}

function cie_build_whatsapp_link(array $booking): string
{
    $phone = preg_replace('/[^0-9+]/', '', (string) ($booking['cittadino_telefono'] ?? ''));
    $messageLines = [
        'Ciao ' . trim((string) ($booking['cittadino_nome'] ?? '')), 
        'ti ricordiamo la prenotazione CIE.',
    ];

    if (!empty($booking['appuntamento_data'])) {
        $messageLines[] = 'Data appuntamento: ' . format_date_locale($booking['appuntamento_data']);
    }
    if (!empty($booking['appuntamento_orario'])) {
        $messageLines[] = 'Orario: ' . $booking['appuntamento_orario'];
    }
    if (!empty($booking['comune_richiesta'])) {
        $messageLines[] = 'Comune: ' . $booking['comune_richiesta'];
    }
    $messageLines[] = 'Codice pratica: ' . cie_booking_code($booking);

    $text = urlencode(implode("\n", array_filter($messageLines)));
    $number = $phone !== '' ? $phone : '';

    return 'https://api.whatsapp.com/send?' . http_build_query([
        'phone' => $number,
        'text' => $text,
    ]);
}
