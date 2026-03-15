<?php
declare(strict_types=1);

namespace App\Services\Opportunities;

use JsonException;
use RuntimeException;

final class OpportunityUploadStorage
{
    private const BASE_TMP_DIR = __DIR__ . '/../../../storage/tmp/opportunity_uploads';
    private const MAX_FILE_SIZE = 10_485_760; // 10MB
    private const TTL_SECONDS = 172_800; // 48 ore
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'zip'];

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{token:string,original_name:string,mime_type:string,size:int,created_at:string}
     */
    public static function store(array $file, int $userId): array
    {
        self::assertValidUploadArray($file);
        self::ensureUserDir($userId);
        self::purgeExpired($userId);

        $originalName = (string) ($file['name'] ?? 'documento');
        $size = (int) ($file['size'] ?? 0);
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($size <= 0) {
            throw new RuntimeException('File vuoto o non valido.');
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Ogni file deve pesare al massimo 10MB.');
        }
        if ($extension !== '' && !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Formato file non supportato. Usa PDF, JPG, PNG o ZIP.');
        }
        if (!is_uploaded_file($tmpName)) {
            throw new RuntimeException('Caricamento non valido.');
        }

        $token = bin2hex(random_bytes(16));
        $storedName = $token . ($extension !== '' ? '.' . $extension : '');
        $userDir = self::userDir($userId);
        $targetPath = $userDir . '/' . $storedName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Impossibile salvare il file temporaneo. Riprova.');
        }

        $mimeType = self::detectMimeType($targetPath, (string) ($file['type'] ?? 'application/octet-stream'));
        $meta = [
            'token' => $token,
            'stored_name' => $storedName,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'created_at' => gmdate('c'),
        ];

        file_put_contents($userDir . '/' . $token . '.json', json_encode($meta, JSON_THROW_ON_ERROR));

        return [
            'token' => $token,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'size' => $size,
            'created_at' => $meta['created_at'],
        ];
    }

    public static function deleteToken(int $userId, string $token): bool
    {
        if ($token === '') {
            return false;
        }
        $userDir = self::userDir($userId);
        $metaPath = $userDir . '/' . $token . '.json';
        $storedName = self::readStoredName($metaPath);
        if ($storedName !== null) {
            $filePath = $userDir . '/' . $storedName;
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
        if (is_file($metaPath)) {
            return @unlink($metaPath);
        }
        return false;
    }

    /**
     * @return array<int,array{token:string,original_name:string,mime_type:string,size:int,created_at:string}>
     */
    public static function listTokens(int $userId): array
    {
        self::ensureUserDir($userId);
        self::purgeExpired($userId);

        $userDir = self::userDir($userId);
        $files = glob($userDir . '/*.json') ?: [];
        $uploads = [];
        foreach ($files as $metaPath) {
            $meta = self::readMetadata($metaPath);
            if ($meta === null) {
                continue;
            }
            $uploads[] = [
                'token' => (string) $meta['token'],
                'original_name' => (string) $meta['original_name'],
                'mime_type' => (string) $meta['mime_type'],
                'size' => (int) $meta['size'],
                'created_at' => (string) $meta['created_at'],
            ];
        }

        return $uploads;
    }

    /**
     * @param array<int,string> $tokens
     * @return array{files:array<int,array{name:string,type:string,tmp_name:string,size:int,token:string}>,tokens:array<int,string>}
     */
    public static function resolveFiles(int $userId, array $tokens): array
    {
        if ($tokens === []) {
            return ['files' => [], 'tokens' => []];
        }

        self::ensureUserDir($userId);
        self::purgeExpired($userId);

        $userDir = self::userDir($userId);
        $resolvedFiles = [];
        $resolvedTokens = [];

        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $metaPath = $userDir . '/' . $token . '.json';
            $meta = self::readMetadata($metaPath);
            if ($meta === null) {
                continue;
            }
            $storedName = (string) $meta['stored_name'];
            $filePath = $userDir . '/' . $storedName;
            if (!is_file($filePath)) {
                continue;
            }
            $resolvedFiles[] = [
                'name' => (string) $meta['original_name'],
                'type' => (string) $meta['mime_type'],
                'tmp_name' => $filePath,
                'size' => (int) $meta['size'],
                'token' => (string) $meta['token'],
            ];
            $resolvedTokens[] = (string) $meta['token'];
        }

        return ['files' => $resolvedFiles, 'tokens' => $resolvedTokens];
    }

    /**
     * @param array<int,string> $tokens
     */
    public static function cleanupTokens(int $userId, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }
        $userDir = self::userDir($userId);
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $metaPath = $userDir . '/' . $token . '.json';
            if (is_file($metaPath)) {
                $storedName = self::readStoredName($metaPath);
                @unlink($metaPath);
                if ($storedName !== null) {
                    $filePath = $userDir . '/' . $storedName;
                    if (is_file($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
        }
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    private static function assertValidUploadArray(array $file): void
    {
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Caricamento non riuscito.');
        }
        if (!isset($file['tmp_name'])) {
            throw new RuntimeException('File non valido.');
        }
    }

    private static function detectMimeType(string $path, string $fallback): string
    {
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $path) ?: null;
                finfo_close($finfo);
                if ($detected) {
                    return $detected;
                }
            }
        }

        return $fallback ?: 'application/octet-stream';
    }

    private static function ensureUserDir(int $userId): void
    {
        if ($userId <= 0) {
            throw new RuntimeException('Utente non valido.');
        }
        if (!is_dir(self::BASE_TMP_DIR) && !mkdir(self::BASE_TMP_DIR, 0775, true) && !is_dir(self::BASE_TMP_DIR)) {
            throw new RuntimeException('Cartella temporanea non disponibile.');
        }
        $userDir = self::userDir($userId);
        if (!is_dir($userDir) && !mkdir($userDir, 0775, true) && !is_dir($userDir)) {
            throw new RuntimeException('Impossibile inizializzare la cartella temporanea.');
        }
    }

    private static function userDir(int $userId): string
    {
        return rtrim(self::BASE_TMP_DIR, '/') . '/' . $userId;
    }

    private static function purgeExpired(int $userId): void
    {
        $userDir = self::userDir($userId);
        if (!is_dir($userDir)) {
            return;
        }
        $files = glob($userDir . '/*.json') ?: [];
        $threshold = time() - self::TTL_SECONDS;
        foreach ($files as $metaPath) {
            $meta = self::readMetadata($metaPath);
            if ($meta === null) {
                @unlink($metaPath);
                continue;
            }
            $createdAt = strtotime((string) ($meta['created_at'] ?? '')) ?: 0;
            if ($createdAt < $threshold) {
                $storedName = (string) ($meta['stored_name'] ?? '');
                $filePath = $userDir . '/' . $storedName;
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
                @unlink($metaPath);
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function readMetadata(string $metaPath): ?array
    {
        if (!is_file($metaPath)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($metaPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    private static function readStoredName(string $metaPath): ?string
    {
        $meta = self::readMetadata($metaPath);
        if ($meta === null) {
            return null;
        }
        return isset($meta['stored_name']) ? (string) $meta['stored_name'] : null;
    }
}
