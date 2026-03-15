<?php
declare(strict_types=1);

const VISURE_CR_UPLOAD_DIR = 'assets/uploads/visure-cr';
const VISURE_CR_MAX_UPLOAD_SIZE = 12_582_912; // 12 MB

/**
 * @return array<int,array<string,mixed>>
 */
function visure_cr_normalize_files(?array $files): array
{
    if ($files === null || !isset($files['name'])) {
        return [];
    }

    if (!is_array($files['name'])) {
        return [$files];
    }

    $normalized = [];
    foreach ($files['name'] as $index => $name) {
        $normalized[] = [
            'name' => $name,
            'type' => $files['type'][$index] ?? '',
            'tmp_name' => $files['tmp_name'][$index] ?? '',
            'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $files['size'][$index] ?? 0,
        ];
    }

    return $normalized;
}

/**
 * @return array<string,string>
 */
function visure_cr_allowed_mime_types(): array
{
    return [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
}

function visure_cr_store_attachment(PDO $pdo, int $richiestaId, array $file, string $categoria, array &$errors): ?array
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Errore nel caricamento di un allegato.';
        return null;
    }

    if (($file['size'] ?? 0) > VISURE_CR_MAX_UPLOAD_SIZE) {
        $errors[] = 'Un allegato supera la dimensione massima consentita (12 MB).';
        return null;
    }

    $original = sanitize_filename((string) $file['name']);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = visure_cr_allowed_mime_types();
    if (!isset($allowed[$extension])) {
        $errors[] = 'Formato file non consentito (ammessi PDF/JPG/PNG).';
        return null;
    }

    $storageDir = public_path(VISURE_CR_UPLOAD_DIR . '/' . $richiestaId);
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        $errors[] = 'Impossibile creare la cartella per gli allegati.';
        return null;
    }

    $timestamp = date('YmdHis');
    $unique = bin2hex(random_bytes(4));
    $fileName = sprintf('%s_%s_%s', $timestamp, $unique, $original);
    $destination = $storageDir . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
        $errors[] = 'Impossibile salvare un allegato.';
        return null;
    }

    $relativePath = VISURE_CR_UPLOAD_DIR . '/' . $richiestaId . '/' . $fileName;
    $mimeType = is_file($destination) ? (mime_content_type($destination) ?: 'application/octet-stream') : 'application/octet-stream';
    $fileSize = is_file($destination) ? (int) filesize($destination) : 0;

    $stmt = $pdo->prepare('INSERT INTO servizi_visure_cr_allegati (richiesta_id, categoria, file_name, file_path, file_size, mime_type, created_at)
        VALUES (:richiesta_id, :categoria, :file_name, :file_path, :file_size, :mime_type, NOW())');
    $stmt->execute([
        ':richiesta_id' => $richiestaId,
        ':categoria' => $categoria,
        ':file_name' => $original,
        ':file_path' => $relativePath,
        ':file_size' => $fileSize,
        ':mime_type' => $mimeType,
    ]);

    return [
        'file_name' => $original,
        'file_path' => $relativePath,
        'file_size' => $fileSize,
        'mime_type' => $mimeType,
        'categoria' => $categoria,
    ];
}

function visure_cr_handle_upload(PDO $pdo, int $richiestaId, ?array $files, string $categoria, array &$errors): int
{
    $items = visure_cr_normalize_files($files);
    if (!$items) {
        return 0;
    }

    $saved = 0;
    foreach ($items as $item) {
        $result = visure_cr_store_attachment($pdo, $richiestaId, $item, $categoria, $errors);
        if ($result) {
            $saved++;
        }
    }

    return $saved;
}

/**
 * @return array<string,mixed>|null
 */
function visure_cr_get_richiesta(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM servizi_visure_cr_pratiche WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function visure_cr_get_attachments(PDO $pdo, int $richiestaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM servizi_visure_cr_allegati WHERE richiesta_id = :richiesta_id ORDER BY id ASC');
    $stmt->execute([':richiesta_id' => $richiestaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function visure_cr_get_attachment(PDO $pdo, int $attachmentId): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, p.id AS richiesta_id
        FROM servizi_visure_cr_allegati a
        INNER JOIN servizi_visure_cr_pratiche p ON p.id = a.richiesta_id
        WHERE a.id = :id
        LIMIT 1');
    $stmt->execute([':id' => $attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
