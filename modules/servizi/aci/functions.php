<?php
declare(strict_types=1);

const ACI_UPLOAD_DIR = 'assets/uploads/aci';
const ACI_MAX_UPLOAD_SIZE = 12_582_912; // 12 MB

/**
 * @return array<string,mixed>|null
 */
function aci_get_pratica(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT p.*, c.ragione_sociale, c.nome, c.cognome
        FROM servizi_aci_pratiche p
        LEFT JOIN clienti c ON c.id = p.cliente_id
        WHERE p.id = :id
        LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function aci_get_attachments(PDO $pdo, int $praticaId): array
{
    $stmt = $pdo->prepare('SELECT * FROM servizi_aci_allegati WHERE pratica_id = :pratica_id ORDER BY id ASC');
    $stmt->execute([':pratica_id' => $praticaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string,mixed>|null
 */
function aci_get_attachment(PDO $pdo, int $attachmentId): ?array
{
    $stmt = $pdo->prepare('SELECT a.*, p.id AS pratica_id
        FROM servizi_aci_allegati a
        INNER JOIN servizi_aci_pratiche p ON p.id = a.pratica_id
        WHERE a.id = :id
        LIMIT 1');
    $stmt->execute([':id' => $attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function aci_normalize_files(?array $files): array
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
function aci_allowed_mime_types(): array
{
    return [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];
}

function aci_store_attachment(PDO $pdo, int $praticaId, array $file, array &$errors, ?string $categoria = null): ?array
{
    if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Errore nel caricamento di un allegato.';
        return null;
    }

    if (($file['size'] ?? 0) > ACI_MAX_UPLOAD_SIZE) {
        $errors[] = 'Un allegato supera la dimensione massima consentita (12 MB).';
        return null;
    }

    $original = sanitize_filename((string) $file['name']);
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $allowed = aci_allowed_mime_types();
    if (!isset($allowed[$extension])) {
        $errors[] = 'Formato file non consentito (ammessi PDF/JPG/PNG).';
        return null;
    }

    $storageDir = public_path(ACI_UPLOAD_DIR . '/' . $praticaId);
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

    $relativePath = ACI_UPLOAD_DIR . '/' . $praticaId . '/' . $fileName;
    $mimeType = is_file($destination) ? (mime_content_type($destination) ?: 'application/octet-stream') : 'application/octet-stream';
    $fileSize = is_file($destination) ? (int) filesize($destination) : 0;

    $categoria = $categoria !== null && trim($categoria) !== '' ? $categoria : 'generico';

    $stmt = $pdo->prepare('INSERT INTO servizi_aci_allegati (pratica_id, categoria, file_name, file_path, file_size, mime_type, created_at)
        VALUES (:pratica_id, :categoria, :file_name, :file_path, :file_size, :mime_type, NOW())');
    $stmt->execute([
        ':pratica_id' => $praticaId,
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

function aci_handle_uploads(PDO $pdo, int $praticaId, ?array $files, array &$errors, ?string $categoria = null): int
{
    $items = aci_normalize_files($files);
    if (!$items) {
        return 0;
    }

    $saved = 0;
    foreach ($items as $item) {
        $result = aci_store_attachment($pdo, $praticaId, $item, $errors, $categoria);
        if ($result) {
            $saved++;
        }
    }

    return $saved;
}

function aci_handle_upload_category(PDO $pdo, int $praticaId, ?array $files, string $categoria, array &$errors): int
{
    return aci_handle_uploads($pdo, $praticaId, $files, $errors, $categoria);
}

function aci_delete_attachment_files(array $attachments): void
{
    foreach ($attachments as $attachment) {
        $path = (string) ($attachment['file_path'] ?? '');
        if ($path === '') {
            continue;
        }
        $absolutePath = rtrim(project_root_path(), '/') . '/' . ltrim($path, '/');
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
