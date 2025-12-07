<?php
declare(strict_types=1);

namespace App\Services\Opportunities;

use RuntimeException;

final class PromotionLibraryService
{
    private const MAX_FILE_SIZE = 20_971_520; // 20MB
    /**
     * @var array<int,string>
     */
    private const ALLOWED_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];

    private string $basePath;
    private string $relativeBase = 'storage/uploads/opportunities/promotions';

    public function __construct()
    {
        $storageRoot = realpath(__DIR__ . '/../../../storage');
        if ($storageRoot === false) {
            throw new RuntimeException('Cartella storage non disponibile.');
        }

        $this->basePath = rtrim($storageRoot, '/') . '/uploads/opportunities/promotions';
        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0775, true) && !is_dir($this->basePath)) {
            throw new RuntimeException('Impossibile inizializzare la libreria promo.');
        }
    }

    /**
     * @return array{
     *     current_path:string,
     *     breadcrumbs:array<int,array{label:string,path:string}>,
     *     directories:array<int,array{name:string,path:string,is_empty:bool,items:int}>,
     *     files:array<int,array{name:string,path:string,extension:string,size:int,size_label:string,modified_at:string,public_url:string}>
     * }
     */
    public function listContents(string $relativePath = ''): array
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $absolute = $this->absolutePath($normalized);
        if (!is_dir($absolute)) {
            throw new RuntimeException('La cartella selezionata non esiste più.');
        }

        $directories = [];
        $files = [];
        $items = scandir($absolute) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (str_starts_with($item, '.')) {
                continue;
            }
            $entryAbsolute = $absolute . '/' . $item;
            $entryRelative = trim($normalized === '' ? $item : $normalized . '/' . $item, '/');
            if (is_dir($entryAbsolute)) {
                $directories[] = [
                    'name' => $item,
                    'path' => $entryRelative,
                    'is_empty' => $this->isDirectoryEmpty($entryAbsolute),
                    'items' => $this->countVisibleEntries($entryAbsolute),
                ];
                continue;
            }
            if (!is_file($entryAbsolute)) {
                continue;
            }
            $size = (int) (@filesize($entryAbsolute) ?: 0);
            $modifiedAt = (int) (@filemtime($entryAbsolute) ?: time());
            $extension = strtolower((string) pathinfo($item, PATHINFO_EXTENSION));
            $files[] = [
                'name' => $item,
                'path' => $entryRelative,
                'extension' => $extension,
                'size' => $size,
                'size_label' => $this->formatBytes($size),
                'modified_at' => date('c', $modifiedAt),
                'public_url' => $this->buildPublicUrl($entryRelative),
            ];
        }

        usort($directories, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        usort($files, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'current_path' => $normalized,
            'breadcrumbs' => $this->buildBreadcrumbs($normalized),
            'directories' => $directories,
            'files' => $files,
        ];
    }

    public function createFolder(string $folderName, string $parentPath = ''): string
    {
        $parent = $this->normalizeRelativePath($parentPath);
        $name = $this->sanitizeFolderName($folderName);
        if ($name === '') {
            throw new RuntimeException('Inserisci un nome cartella valido.');
        }

        $relative = trim($parent === '' ? $name : $parent . '/' . $name, '/');
        $absolute = $this->absolutePath($relative);
        if (is_dir($absolute)) {
            throw new RuntimeException('Esiste già una cartella con questo nome.');
        }
        if (!mkdir($absolute, 0775, true) && !is_dir($absolute)) {
            throw new RuntimeException('Impossibile creare la cartella richiesta.');
        }

        return $relative;
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     * @return array{name:string,relative_path:string,public_url:string,size:int}
     */
    public function uploadFile(array $file, string $targetFolder = ''): array
    {
        $this->assertValidUploadArray($file);
        $folder = $this->normalizeRelativePath($targetFolder);
        $destinationDir = $this->absolutePath($folder);
        if (!is_dir($destinationDir)) {
            throw new RuntimeException('La cartella selezionata non esiste più.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new RuntimeException('Carica un file valido.');
        }
        if ($size > self::MAX_FILE_SIZE) {
            throw new RuntimeException('Ogni file può pesare al massimo 20MB.');
        }

        $originalName = (string) ($file['name'] ?? 'promo');
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new RuntimeException('Formato non supportato. Usa PDF o immagini.');
        }

        $storedName = $this->uniqueFilename($destinationDir, $originalName, $extension);
        $targetPath = $destinationDir . '/' . $storedName;
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
            throw new RuntimeException('Origine del file non valida.');
        }
        if (!move_uploaded_file($tmpPath, $targetPath)) {
            throw new RuntimeException('Impossibile salvare il file.');
        }

        $relativeFile = trim($folder === '' ? $storedName : $folder . '/' . $storedName, '/');

        return [
            'name' => $storedName,
            'relative_path' => $relativeFile,
            'public_url' => $this->buildPublicUrl($relativeFile),
            'size' => $size,
        ];
    }

    /**
     * @param array<int,array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int}> $files
     * @return array{uploaded:int,errors:array<int,string>}
     */
    public function uploadMany(array $files, string $targetFolder = ''): array
    {
        $uploaded = 0;
        $errors = [];

        foreach ($files as $file) {
            try {
                $this->uploadFile($file, $targetFolder);
                $uploaded += 1;
            } catch (RuntimeException $exception) {
                $fileName = trim((string) ($file['name'] ?? ''));
                if ($fileName === '') {
                    $fileName = 'File';
                }
                $errors[] = sprintf('%s: %s', $fileName, $exception->getMessage());
            }
        }

        return ['uploaded' => $uploaded, 'errors' => $errors];
    }

    public function deleteFile(string $relativeFile): void
    {
        $relative = $this->normalizeRelativePath($relativeFile);
        if ($relative === '') {
            throw new RuntimeException('Percorso file non valido.');
        }
        $absolute = $this->absolutePath($relative);
        if (!is_file($absolute)) {
            throw new RuntimeException('Il file selezionato non esiste più.');
        }
        if (!@unlink($absolute)) {
            throw new RuntimeException('Impossibile eliminare il file.');
        }
    }

    public function deleteFolder(string $relativePath): void
    {
        $relative = $this->normalizeRelativePath($relativePath);
        if ($relative === '') {
            throw new RuntimeException('Non puoi eliminare la cartella principale.');
        }
        $absolute = $this->absolutePath($relative);
        if (!is_dir($absolute)) {
            throw new RuntimeException('La cartella non esiste più.');
        }
        if (!$this->isDirectoryEmpty($absolute)) {
            throw new RuntimeException('La cartella contiene ancora file o sotto-cartelle.');
        }
        if (!@rmdir($absolute)) {
            throw new RuntimeException('Impossibile eliminare la cartella selezionata.');
        }
    }

    public function buildPublicUrl(string $relativePath): string
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        return $normalized === '' ? $this->relativeBase : $this->relativeBase . '/' . $normalized;
    }

    public function normalizeInputPath(string $path): string
    {
        return $this->normalizeRelativePath($path);
    }

    /**
     * @return array<int,array{label:string,path:string}>
     */
    private function buildBreadcrumbs(string $relativePath): array
    {
        $breadcrumbs = [
            ['label' => 'Libreria promo', 'path' => ''],
        ];
        if ($relativePath === '') {
            return $breadcrumbs;
        }

        $parts = explode('/', $relativePath);
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current === '' ? $part : $current . '/' . $part;
            $breadcrumbs[] = [
                'label' => $part,
                'path' => $current,
            ];
        }

        return $breadcrumbs;
    }

    private function absolutePath(string $relativePath): string
    {
        $relativePath = $relativePath === '' ? '' : '/' . $relativePath;
        return $this->basePath . $relativePath;
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);
        $safeSegments = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $safeSegments[] = $segment;
        }

        return implode('/', $safeSegments);
    }

    private function sanitizeFolderName(string $folderName): string
    {
        $folderName = trim($folderName);
        $folderName = preg_replace('/\s+/', ' ', $folderName) ?? '';
        $folderName = preg_replace('/[^A-Za-z0-9 _-]/', '', $folderName) ?? '';

        return trim($folderName, ' ');
    }

    private function isDirectoryEmpty(string $path): bool
    {
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (str_starts_with($item, '.')) {
                continue;
            }
            return false;
        }

        return true;
    }

    private function countVisibleEntries(string $path): int
    {
        $items = scandir($path) ?: [];
        $count = 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if (str_starts_with($item, '.')) {
                continue;
            }
            $count += 1;
        }

        return $count;
    }

    private function uniqueFilename(string $directory, string $original, string $extension): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $slug = $this->slugify($base);
        if ($slug === '') {
            $slug = 'promo';
        }

        $extension = $extension !== '' ? '.' . strtolower($extension) : '';
        $candidate = $slug . $extension;
        $suffix = 1;
        while (is_file($directory . '/' . $candidate)) {
            $candidate = sprintf('%s-%02d%s', $slug, $suffix, $extension);
            $suffix += 1;
            if ($suffix > 50) {
                $candidate = $slug . '-' . bin2hex(random_bytes(4)) . $extension;
                break;
            }
        }

        return $candidate;
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = (int) floor(log($bytes, 1024));
        $power = max(0, min($power, count($units) - 1));
        $value = $bytes / (1024 ** $power);

        return sprintf('%.1f %s', $value, $units[$power]);
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $file
     */
    private function assertValidUploadArray(array $file): void
    {
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Errore durante il caricamento del file.');
        }
        if (!isset($file['tmp_name'])) {
            throw new RuntimeException('File non valido.');
        }
    }
}
