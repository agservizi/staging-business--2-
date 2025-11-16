<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

class SettingsService
{
    private const MOVEMENT_DESCRIPTIONS_KEY = 'entrate_uscite_descrizioni';
    private const APPOINTMENT_STATUSES_KEY = 'servizi_appuntamenti_statuses';
    private const APPOINTMENT_TYPES_KEY = 'servizi_appuntamenti_tipologie';
    private const CAF_PATRONATO_TYPES_KEY = 'caf_patronato_tipologie';
    private const CAF_PATRONATO_STATUSES_KEY = 'caf_patronato_stati';
    private const CAF_PATRONATO_SERVICES_KEY = 'caf_patronato_servizi';
    private const UI_THEME_KEY = 'ui_theme';
    private const EMAIL_MARKETING_SETTINGS_KEY = 'email_marketing_settings';
    public const PORTAL_BRT_PRICING_KEY = 'portal_brt_pricing';
    public const CAF_PATRONATO_STATUS_CATEGORIES = [
        'pending' => 'Da lavorare / In attesa',
        'in_progress' => 'In lavorazione',
        'waiting' => 'In attesa documenti',
        'completed' => 'Completata',
        'archived' => 'Chiusa / Archiviata',
        'cancelled' => 'Annullata',
    ];
    private PDO $pdo;
    private string $rootPath;
    private string $backupPath;
    private string $brandingPath;
    private ?string $backupPassphrase;
    private string $backupCipher;

    public function __construct(PDO $pdo, string $rootPath)
    {
        $this->pdo = $pdo;
        $this->rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->backupPath = $this->rootPath . DIRECTORY_SEPARATOR . 'backups';
        $this->brandingPath = $this->rootPath . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'branding';
    $this->backupPassphrase = function_exists('env') ? (env('BACKUP_ENCRYPTION_KEY') ?: null) : null;
    $this->backupCipher = function_exists('env') ? (env('BACKUP_ENCRYPTION_CIPHER', 'AES-256-CBC') ?: 'AES-256-CBC') : 'AES-256-CBC';
    }

    public function fetchCompanySettings(array $defaults): array
    {
        try {
            $stmt = $this->pdo->query('SELECT chiave, valore FROM configurazioni');
            $config = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
        } catch (PDOException $e) {
            error_log('Settings fetch failed: ' . $e->getMessage());
            $config = [];
        }

        foreach ($defaults as $key => $default) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $default;
            }
        }

        return $config;
    }

    public function recentBackups(int $limit = 5): array
    {
        $result = $this->paginateBackups(1, $limit);
        return $result['items'];
    }

    /**
     * @return array{items: array<int, array{name:string,size:string,mtime:int|null}>, total:int}
     */
    public function paginateBackups(int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if (!is_dir($this->backupPath)) {
            return ['items' => [], 'total' => 0];
        }

        $patterns = ['*.sql', '*.sql.enc', '*.enc'];
        $files = [];
        foreach ($patterns as $pattern) {
            $found = glob($this->backupPath . DIRECTORY_SEPARATOR . $pattern) ?: [];
            $files = array_merge($files, $found);
        }

        if (!$files) {
            return ['items' => [], 'total' => 0];
        }

        $files = array_values(array_unique($files));
        rsort($files);

        $total = count($files);
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($files, $offset, $perPage);
        $items = [];

        foreach ($slice as $filePath) {
            $size = @filesize($filePath);
            $mtime = @filemtime($filePath);
            $items[] = [
                'name' => basename($filePath),
                'size' => $size !== false ? $this->formatBytes((int) $size) : '—',
                'mtime' => $mtime ?: null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    public function deleteBackup(string $filename, int $userId): array
    {
        $filename = trim($filename);
        if ($filename === '') {
            return ['success' => false, 'error' => 'Seleziona un backup valido.'];
        }

        if (!is_dir($this->backupPath)) {
            return ['success' => false, 'error' => 'La cartella dei backup non è disponibile.'];
        }

        $safeName = basename($filename);
        $backupDir = realpath($this->backupPath);
        $candidate = realpath($this->backupPath . DIRECTORY_SEPARATOR . $safeName);

        if (!$backupDir || !$candidate || strpos($candidate, $backupDir) !== 0 || !is_file($candidate)) {
            return ['success' => false, 'error' => 'Backup non trovato.'];
        }

        if (!@unlink($candidate)) {
            return ['success' => false, 'error' => 'Eliminazione del backup non riuscita.'];
        }

        $this->logActivity($userId, 'Eliminazione backup', ['file' => $safeName]);

        return ['success' => true];
    }

    public function cleanupBackupsOlderThan(int $days, int $userId): array
    {
        $days = max(1, $days);
        if (!is_dir($this->backupPath)) {
            return ['success' => true, 'removed' => 0];
        }

        $patterns = ['*.sql', '*.sql.enc', '*.enc'];
        $files = [];
        foreach ($patterns as $pattern) {
            $found = glob($this->backupPath . DIRECTORY_SEPARATOR . $pattern) ?: [];
            $files = array_merge($files, $found);
        }

        if (!$files) {
            return ['success' => true, 'removed' => 0];
        }

        $threshold = time() - ($days * 86400);
        $removed = 0;
        $deletedFiles = [];
        $backupDir = realpath($this->backupPath);

        foreach ($files as $filePath) {
            $mtime = @filemtime($filePath);
            if ($mtime !== false && $mtime >= $threshold) {
                continue;
            }

            $real = realpath($filePath);
            if (!$real || !$backupDir || strpos($real, $backupDir) !== 0 || !is_file($real)) {
                continue;
            }

            if (@unlink($real)) {
                $removed++;
                $deletedFiles[] = basename($real);
            }
        }

        if ($removed > 0) {
            $this->logActivity($userId, 'Pulizia backup', ['days' => $days, 'removed' => $deletedFiles]);
        }

        return ['success' => true, 'removed' => $removed];
    }

    public function getMovementDescriptions(): array
    {
        $defaults = [
            'entrate' => [],
            'uscite' => [],
        ];

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::MOVEMENT_DESCRIPTIONS_KEY]);
            $value = $stmt->fetchColumn();
            if ($value) {
                $decoded = json_decode((string) $value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $defaults['entrate'] = $this->sanitizeDescriptions($decoded['entrate'] ?? []);
                    $defaults['uscite'] = $this->sanitizeDescriptions($decoded['uscite'] ?? []);
                }
            }
        } catch (Throwable $e) {
            error_log('Movement descriptions fetch failed: ' . $e->getMessage());
        }

        return $defaults;
    }

    public function saveMovementDescriptions(array $entrate, array $uscite, int $userId): array
    {
        $entrate = $this->sanitizeDescriptions($entrate);
        $uscite = $this->sanitizeDescriptions($uscite);

        $invalid = array_merge(
            $this->validateDescriptions($entrate),
            $this->validateDescriptions($uscite)
        );
        $invalid = array_values(array_filter($invalid));

        if ($invalid) {
            return ['success' => false, 'errors' => $invalid];
        }

        $payload = json_encode([
            'entrate' => array_values($entrate),
            'uscite' => array_values($uscite),
        ], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['success' => false, 'errors' => ['Impossibile serializzare le descrizioni dei movimenti.']];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::MOVEMENT_DESCRIPTIONS_KEY,
                ':valore' => $payload,
            ]);

            $this->logActivity($userId, 'Aggiornamento descrizioni movimenti', [
                'entrate' => $entrate,
                'uscite' => $uscite,
            ]);

            return ['success' => true, 'errors' => []];
        } catch (Throwable $e) {
            error_log('Movement descriptions save failed: ' . $e->getMessage());
            return ['success' => false, 'errors' => ['Impossibile salvare le descrizioni dei movimenti.']];
        }
    }

    /**
     * @return array{available:array<int,string>,active:array<int,string>,completed:array<int,string>,cancelled:array<int,string>,confirmation:string}
     */
    public static function defaultAppointmentStatuses(): array
    {
        return [
            'available' => ['Programmato', 'Confermato', 'In corso', 'Completato', 'Annullato'],
            'active' => ['Programmato', 'Confermato', 'In corso'],
            'completed' => ['Completato'],
            'cancelled' => ['Annullato'],
            'confirmation' => 'Confermato',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultAppointmentTypes(): array
    {
        return ['Consulenza', 'Sopralluogo', 'Supporto tecnico', 'Rinnovo servizio'];
    }

    /**
     * @return array<int, array{key:string,label:string,prefix:string}>
     */
    public static function defaultCafPatronatoTypes(): array
    {
        return [
            ['key' => 'CAF', 'label' => 'CAF', 'prefix' => 'CAF'],
            ['key' => 'PATRONATO', 'label' => 'Patronato', 'prefix' => 'PAT'],
        ];
    }

    /**
     * @return array<int, array{value:string,label:string,category:string}>
     */
    public static function defaultCafPatronatoStatuses(): array
    {
        return [
            ['value' => 'Da lavorare', 'label' => 'Da lavorare', 'category' => 'pending'],
            ['value' => 'In lavorazione', 'label' => 'In lavorazione', 'category' => 'in_progress'],
            ['value' => 'In attesa documenti', 'label' => 'In attesa documenti', 'category' => 'waiting'],
            ['value' => 'Completata', 'label' => 'Completata', 'category' => 'completed'],
            ['value' => 'Chiusa', 'label' => 'Chiusa', 'category' => 'archived'],
            ['value' => 'Annullata', 'label' => 'Annullata', 'category' => 'cancelled'],
        ];
    }

    /**
     * @return array<string, array<int, array{name:string,price:float|null}>>
     */
    public static function defaultCafPatronatoServices(): array
    {
        return [
            'CAF' => [
                ['name' => 'ISEE', 'price' => null],
                ['name' => '730', 'price' => null],
                ['name' => 'IMU', 'price' => null],
                ['name' => 'Bonus Casa', 'price' => null],
            ],
            'PATRONATO' => [
                ['name' => 'NASpI', 'price' => null],
                ['name' => 'Invalidità civile', 'price' => null],
                ['name' => 'Pensione di reversibilità', 'price' => null],
                ['name' => 'Disoccupazione agricola', 'price' => null],
            ],
        ];
    }

    /**
     * @return array<string, array{label:string,description:string,accent:string,accent_strong:string,surface_alt:string,swatches:array<int,string>}>
     */
    public static function availableThemes(): array
    {
        return [
            'navy' => [
                'label' => 'Navy',
                'description' => 'Palette blu istituzionale con forte contrasto.',
                'accent' => '#0b2f6b',
                'accent_strong' => '#082451',
                'surface_alt' => '#f4f6fb',
                'swatches' => ['#0b2f6b', '#12468f', '#f4f6fb'],
            ],
            'emerald' => [
                'label' => 'Emerald',
                'description' => 'Verde petrolio dal tono moderno e rassicurante.',
                'accent' => '#0f766e',
                'accent_strong' => '#0b4f4a',
                'surface_alt' => '#ecfdf5',
                'swatches' => ['#0f766e', '#14b8a6', '#ecfdf5'],
            ],
            'violet' => [
                'label' => 'Blue Steel Professional',
                'description' => 'Blu istituzionale con superfici pulite e contrasti controllati.',
                'accent' => '#2563eb',
                'accent_strong' => '#1e3a8a',
                'surface_alt' => '#f8fafc',
                'swatches' => ['#0a192f', '#2563eb', '#f8fafc'],
            ],
            'sunset' => [
                'label' => 'Glass',
                'description' => 'Gradienti blu e viola con riflessi traslucidi per un look moderno.',
                'accent' => '#3b82f6',
                'accent_strong' => '#1d4ed8',
                'surface_alt' => 'rgba(255, 255, 255, 0.1)',
                'swatches' => ['#3b82f6', '#9333ea', '#a855f7'],
            ],
            'aurora' => [
                'label' => 'Aurora',
                'description' => 'Riflessi boreali con ciano elettrico e violetti luminosi.',
                'accent' => '#06b6d4',
                'accent_strong' => '#0e7490',
                'surface_alt' => 'rgba(8, 47, 73, 0.14)',
                'swatches' => ['#06b6d4', '#3b82f6', '#9333ea'],
            ],
            'midnight' => [
                'label' => 'Midnight',
                'description' => 'Blu profondo e grafite per dashboard notturne ad alto contrasto.',
                'accent' => '#1e40af',
                'accent_strong' => '#0b1120',
                'surface_alt' => 'rgba(15, 23, 42, 0.55)',
                'swatches' => ['#1e40af', '#0f172a', '#64748b'],
            ],
            'citrus' => [
                'label' => 'Citrus',
                'description' => 'Arancio vitaminico con gradiente sunrise e accenti caldi.',
                'accent' => '#f59e0b',
                'accent_strong' => '#b45309',
                'surface_alt' => '#fff7e6',
                'swatches' => ['#f59e0b', '#f97316', '#fef3c7'],
            ],
            'rose' => [
                'label' => 'Rose Quartz',
                'description' => 'Rosa brillante con trasparenze soft per interfacce delicate.',
                'accent' => '#ec4899',
                'accent_strong' => '#be185d',
                'surface_alt' => '#fdf2f8',
                'swatches' => ['#f472b6', '#ec4899', '#fdf2f8'],
            ],
            'frosted' => [
                'label' => 'Frosted Glass Pro',
                'description' => 'Finitura vetro digitale con trasparenze futuristiche e riflessi ciano-viola.',
                'accent' => '#38bdf8',
                'accent_strong' => '#0ea5e9',
                'surface_alt' => 'rgba(255, 255, 255, 0.08)',
                'swatches' => ['#38bdf8', '#22d3ee', '#9333ea'],
            ],
            'lagoon' => [
                'label' => 'Arctic Lagoon',
                'description' => 'Palette marina brillante con sfumature ghiaccio e blu oltremare.',
                'accent' => '#0ea5e9',
                'accent_strong' => '#0369a1',
                'surface_alt' => '#e0f7ff',
                'swatches' => ['#0ea5e9', '#22d3ee', '#0c4a6e'],
            ],
            'sage' => [
                'label' => 'Sage Mist',
                'description' => 'Toni verdi naturali e morbidi ideali per interfacce rilassanti.',
                'accent' => '#65a30d',
                'accent_strong' => '#3f6212',
                'surface_alt' => '#f7fde6',
                'swatches' => ['#65a30d', '#a3e635', '#f7fde6'],
            ],
            'ember' => [
                'label' => 'Ember Aura',
                'description' => 'Gradiente caldo tra rosso rubino e arancio carbonizzato.',
                'accent' => '#ef4444',
                'accent_strong' => '#b91c1c',
                'surface_alt' => '#fef2f2',
                'swatches' => ['#ef4444', '#f97316', '#fef2f2'],
            ],
            'slate' => [
                'label' => 'Slate Noir',
                'description' => 'Blu ardesia e grafite per dashboard professionali e sobrie.',
                'accent' => '#334155',
                'accent_strong' => '#1e293b',
                'surface_alt' => '#e2e8f0',
                'swatches' => ['#334155', '#1f2937', '#e2e8f0'],
            ],
            'nebula' => [
                'label' => 'Nebula Pulse',
                'description' => 'Mix cosmico di viola, ciano e luce stellare.',
                'accent' => '#8b5cf6',
                'accent_strong' => '#6d28d9',
                'surface_alt' => '#f5f3ff',
                'swatches' => ['#8b5cf6', '#22d3ee', '#f5f3ff'],
            ],
            'oasis' => [
                'label' => 'Oasis Drift',
                'description' => 'Turchese tropicale e verde acqua per UI fresche e luminose.',
                'accent' => '#2dd4bf',
                'accent_strong' => '#0f766e',
                'surface_alt' => '#ecfcf8',
                'swatches' => ['#2dd4bf', '#0f766e', '#ecfcf8'],
            ],
            'mocha' => [
                'label' => 'Mocha Velvet',
                'description' => 'Palette calda e vellutata con accenti coffee e caramello.',
                'accent' => '#b45309',
                'accent_strong' => '#7c2d12',
                'surface_alt' => '#f6ebe0',
                'swatches' => ['#b45309', '#92400e', '#f6ebe0'],
            ],
        ];
    }

    /**
     * @return array{available:array<int,string>,active:array<int,string>,completed:array<int,string>,cancelled:array<int,string>,confirmation:string}
     */
    public function getAppointmentStatuses(): array
    {
        $defaults = self::defaultAppointmentStatuses();

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::APPOINTMENT_STATUSES_KEY]);
            $value = $stmt->fetchColumn();

            if ($value === false || $value === null || $value === '') {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->normalizeAppointmentStatuses($decoded);
            }
        } catch (Throwable $exception) {
            error_log('Appointment statuses fetch failed: ' . $exception->getMessage());
        }

        return $defaults;
    }

    /**
     * @return array<int, string>
     */
    public function getAppointmentTypes(): array
    {
        $defaults = self::defaultAppointmentTypes();

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::APPOINTMENT_TYPES_KEY]);
            $value = $stmt->fetchColumn();

            if ($value === false || $value === null || $value === '') {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $list = [];
                if (isset($decoded['types']) && is_array($decoded['types'])) {
                    $list = $decoded['types'];
                } elseif ($this->isSequentialArray($decoded)) {
                    $list = $decoded;
                }

                if ($list) {
                    $types = $this->enforceAppointmentTypeLength($this->sanitizeStatusList($list));
                    if ($types) {
                        return $types;
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('Appointment types fetch failed: ' . $exception->getMessage());
        }

        return $defaults;
    }

    /**
     * @return array<int, array{key:string,label:string,prefix:string}>
     */
    public function getCafPatronatoTypes(): array
    {
        $defaults = self::defaultCafPatronatoTypes();

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::CAF_PATRONATO_TYPES_KEY]);
            $value = $stmt->fetchColumn();

            if ($value === false || $value === null || $value === '') {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $normalized = $this->normalizeCafPatronatoTypes($decoded);
                if ($normalized) {
                    return $normalized;
                }
            }
        } catch (Throwable $exception) {
            error_log('CAF/Patronato types fetch failed: ' . $exception->getMessage());
        }

        return $defaults;
    }

    /**
     * @return array<int, array{value:string,label:string,category:string}>
     */
    public function getCafPatronatoStatuses(): array
    {
        $defaults = self::defaultCafPatronatoStatuses();

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::CAF_PATRONATO_STATUSES_KEY]);
            $value = $stmt->fetchColumn();

            if ($value === false || $value === null || $value === '') {
                return $defaults;
            }

            $decoded = json_decode((string) $value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $normalized = $this->normalizeCafPatronatoStatuses($decoded);
                if ($normalized) {
                    return $normalized;
                }
            }
        } catch (Throwable $exception) {
            error_log('CAF/Patronato statuses fetch failed: ' . $exception->getMessage());
        }

        return $defaults;
    }

    /**
     * @return array<string, array<int, array{name:string,price:float|null}>>
     */
    public function getCafPatronatoServices(): array
    {
        $defaults = self::defaultCafPatronatoServices();
        $types = $this->getCafPatronatoTypes();
        $allowedKeys = [];
        foreach ($types as $type) {
            $allowedKeys[] = strtoupper((string) ($type['key'] ?? ''));
        }

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::CAF_PATRONATO_SERVICES_KEY]);
            $value = $stmt->fetchColumn();

            if ($value !== false && $value !== null && $value !== '') {
                $decoded = json_decode((string) $value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $normalized = $this->normalizeCafPatronatoServices($decoded, $allowedKeys);
                    if ($normalized) {
                        return $normalized;
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('CAF/Patronato services fetch failed: ' . $exception->getMessage());
        }

        $normalizedDefaults = $this->normalizeCafPatronatoServices($defaults, $allowedKeys);
        return $normalizedDefaults ?: $defaults;
    }

    /**
     * @return array<string, array<int,string>>
     */
    public function suggestCafPatronatoServices(): array
    {
        try {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT UPPER(COALESCE(tipo_pratica, '')) AS tipo, servizio
                 FROM caf_patronato_pratiche
                 WHERE servizio IS NOT NULL AND servizio <> ''
                 ORDER BY tipo ASC, servizio ASC"
            );
            if ($stmt === false) {
                return [];
            }

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!is_array($rows)) {
                return [];
            }

            $suggestions = [];
            foreach ($rows as $row) {
                $typeKey = strtoupper(trim((string) ($row['tipo'] ?? '')));
                if ($typeKey === '') {
                    $typeKey = 'CAF';
                }

                $value = trim((string) ($row['servizio'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $hash = mb_strtolower($value, 'UTF-8');
                if (!isset($suggestions[$typeKey])) {
                    $suggestions[$typeKey] = [];
                }
                if (isset($suggestions[$typeKey][$hash])) {
                    continue;
                }
                $suggestions[$typeKey][$hash] = mb_substr($value, 0, 120);
            }

            $result = [];
            foreach ($suggestions as $typeKey => $values) {
                $list = array_values($values);
                usort($list, static function (string $a, string $b): int {
                    return strcasecmp($a, $b);
                });
                $result[$typeKey] = $list;
            }

            return $result;
        } catch (Throwable $exception) {
            error_log('CAF/Patronato services suggestion failed: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @return array{success:bool,errors:array<int,string>,services:array<string,array<int,array{name:string,price:float|null}>>,auto_generated:bool}
     */
    public function importCafPatronatoServicesFromPratiche(int $userId): array
    {
        $suggested = $this->suggestCafPatronatoServices();
        if (!$suggested) {
            return [
                'success' => false,
                'errors' => ['Non sono stati trovati servizi registrati nelle pratiche.'],
                'services' => $this->getCafPatronatoServices(),
                'auto_generated' => true,
            ];
        }

        return $this->saveCafPatronatoServices($suggested, $userId, true);
    }

    /**
     * @return array{theme:string}
     */
    public function getAppearanceSettings(): array
    {
        $default = ['theme' => 'navy'];

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::UI_THEME_KEY]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null || $value === '') {
                return $default;
            }

            $theme = strtolower(trim((string) $value));
            $available = self::availableThemes();
            if (isset($available[$theme])) {
                return ['theme' => $theme];
            }
        } catch (Throwable $exception) {
            error_log('UI theme fetch failed: ' . $exception->getMessage());
        }

        return $default;
    }

    /**
     * @return array{
     *     sender_name:string,
     *     sender_email:string,
     *     reply_to_email:string,
     *     resend_api_key:string,
     *     unsubscribe_base_url:string,
     *     webhook_secret:string,
     *     test_address:string,
     *     has_resend_api_key:bool,
     *     resend_api_key_hint:string
     * }
     */
    public function getEmailMarketingSettings(bool $maskSecrets = true): array
    {
        $defaults = [
            'sender_name' => 'Coresuite Business',
            'sender_email' => 'marketing@example.com',
            'reply_to_email' => '',
            'resend_api_key' => '',
            'unsubscribe_base_url' => $this->defaultUnsubscribeBaseUrl(),
            'webhook_secret' => '',
            'test_address' => '',
        ];

        $config = $defaults;

        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::EMAIL_MARKETING_SETTINGS_KEY]);
            $value = $stmt->fetchColumn();
            if ($value !== false && $value !== null && $value !== '') {
                $decoded = json_decode((string) $value, true);
                if (is_array($decoded)) {
                    $config = array_replace_recursive($defaults, $decoded);
                }
            }
        } catch (Throwable $exception) {
            error_log('Email marketing settings fetch failed: ' . $exception->getMessage());
        }

        $formatted = $this->formatEmailMarketingSettings($config, $maskSecrets);

        return $formatted;
    }

    /**
     * @return array{success:bool,errors:array<int,string>,appearance:array{theme:string}}
     */
    public function saveAppearanceSettings(string $theme, int $userId): array
    {
        $theme = strtolower(trim($theme));
        $available = self::availableThemes();

        if (!isset($available[$theme])) {
            return [
                'success' => false,
                'errors' => ['Seleziona un tema colore valido.'],
                'appearance' => ['theme' => $this->getAppearanceSettings()['theme'] ?? 'navy'],
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::UI_THEME_KEY,
                ':valore' => $theme,
            ]);

            $this->logActivity($userId, 'Aggiornamento tema interfaccia', ['theme' => $theme]);

            if (function_exists('reset_ui_theme_cache')) {
                \reset_ui_theme_cache(['theme' => $theme]);
            }

            return ['success' => true, 'errors' => [], 'appearance' => ['theme' => $theme]];
        } catch (Throwable $exception) {
            error_log('UI theme save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare il tema selezionato.'],
                'appearance' => ['theme' => $this->getAppearanceSettings()['theme'] ?? 'navy'],
            ];
        }
    }

    /**
     * @param array<int,string> $available
     * @param array<int,string> $active
     * @param array<int,string> $completed
     * @param array<int,string> $cancelled
     * @return array{success:bool,errors:array<int,string>}
     */
    public function saveAppointmentStatuses(
        array $available,
        array $active,
        array $completed,
        array $cancelled,
        string $confirmationStatus,
        int $userId
    ): array {
        $available = $this->sanitizeStatusList($available);
        $active = $this->sanitizeStatusList($active);
        $completed = $this->sanitizeStatusList($completed);
        $cancelled = $this->sanitizeStatusList($cancelled);
        $confirmationStatus = $this->sanitizeStatusLabel($confirmationStatus);

        $errors = [];

        if (!$available) {
            $errors[] = 'Inserisci almeno uno stato disponibile.';
        }

        foreach ($available as $status) {
            if (mb_strlen($status) > 40) {
                $errors[] = 'Gli stati non possono superare i 40 caratteri.';
                break;
            }
        }

        $availableMap = $this->buildStatusMap($available);

        $activeResult = $this->filterStatusesAgainstAvailable($active, $availableMap);
        $active = $activeResult['values'];
        if ($activeResult['missing']) {
            $errors[] = 'Gli stati attivi devono essere compresi nell\'elenco degli stati disponibili.';
        }
        if (!$active) {
            $errors[] = 'Definisci almeno uno stato attivo per gli appuntamenti.';
        }

        $completedResult = $this->filterStatusesAgainstAvailable($completed, $availableMap);
        $completed = $completedResult['values'];
        if ($completedResult['missing']) {
            $errors[] = 'Gli stati completati devono essere compresi nell\'elenco degli stati disponibili.';
        }

        $cancelledResult = $this->filterStatusesAgainstAvailable($cancelled, $availableMap);
        $cancelled = $cancelledResult['values'];
        if ($cancelledResult['missing']) {
            $errors[] = 'Gli stati annullati devono essere compresi nell\'elenco degli stati disponibili.';
        }

        if ($confirmationStatus === '') {
            $errors[] = 'Seleziona lo stato che identifica un appuntamento confermato.';
        } elseif (!isset($availableMap[$this->statusKey($confirmationStatus)])) {
            $errors[] = 'Lo stato confermato deve essere presente nell\'elenco degli stati disponibili.';
        } else {
            $confirmationStatus = $availableMap[$this->statusKey($confirmationStatus)];
        }

        if ($errors) {
            return ['success' => false, 'errors' => array_values(array_unique($errors))];
        }

        $payload = [
            'available' => array_values($available),
            'active' => array_values($active),
            'completed' => array_values($completed),
            'cancelled' => array_values($cancelled),
            'confirmation' => $confirmationStatus,
        ];

        $serialized = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($serialized === false) {
            return ['success' => false, 'errors' => ['Impossibile serializzare la configurazione degli stati.']];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::APPOINTMENT_STATUSES_KEY,
                ':valore' => $serialized,
            ]);

            $this->logActivity($userId, 'Aggiornamento stati appuntamenti', $payload);

            if (function_exists('reset_appointment_status_config_cache')) {
                reset_appointment_status_config_cache($payload);
            }

            return ['success' => true, 'errors' => []];
        } catch (Throwable $exception) {
            error_log('Appointment statuses save failed: ' . $exception->getMessage());
            return ['success' => false, 'errors' => ['Impossibile salvare gli stati degli appuntamenti.']];
        }
    }

    /**
     * @param array<int, string> $types
     * @return array{success:bool,errors:array<int,string>,types:array<int,string>}
     */
    public function saveAppointmentTypes(array $types, int $userId): array
    {
        $types = $this->sanitizeStatusList($types);
        $errors = [];
        $hadInput = !empty($types);

        if (!$hadInput) {
            $errors[] = 'Inserisci almeno una tipologia di appuntamento.';
        }

        $enforcedTypes = $this->enforceAppointmentTypeLength($types);
        if (count($enforcedTypes) !== count($types)) {
            $errors[] = 'Le tipologie non possono superare i 60 caratteri.';
        }

        $types = $enforcedTypes;

        if (!$types && $hadInput) {
            $errors[] = 'Inserisci almeno una tipologia di appuntamento valida.';
        }

        if ($errors) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'types' => array_values($types),
            ];
        }

        $payload = json_encode(['types' => array_values($types)], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [
                'success' => false,
                'errors' => ['Impossibile serializzare le tipologie di appuntamento.'],
                'types' => array_values($types),
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::APPOINTMENT_TYPES_KEY,
                ':valore' => $payload,
            ]);

            $this->logActivity($userId, 'Aggiornamento tipologie appuntamenti', ['types' => array_values($types)]);

            if (function_exists('reset_appointment_type_config_cache')) {
                reset_appointment_type_config_cache(array_values($types));
            }

            return ['success' => true, 'errors' => [], 'types' => array_values($types)];
        } catch (Throwable $exception) {
            error_log('Appointment types save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare le tipologie di appuntamento.'],
                'types' => array_values($types),
            ];
        }
    }

    /**
     * @param array<int, array{key?:string,label?:string,prefix?:string}> $types
     * @return array{success:bool,errors:array<int,string>,config:array<int,array{key:string,label:string,prefix:string}>}
     */
    public function saveCafPatronatoTypes(array $types, int $userId): array
    {
        $errors = [];
        $normalized = [];
        $seen = [];

        foreach ($types as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawKey = strtoupper(trim((string) ($row['key'] ?? '')));
            $rawLabel = trim((string) ($row['label'] ?? ''));
            $rawPrefix = strtoupper(trim((string) ($row['prefix'] ?? '')));

            if ($rawKey === '' && $rawLabel === '' && $rawPrefix === '') {
                continue;
            }

            if ($rawKey === '') {
                $errors[] = 'La tipologia #' . ((int) $index + 1) . ' richiede un codice identificativo.';
                continue;
            }

            if (!preg_match('/^[A-Z0-9_-]{2,30}$/', $rawKey)) {
                $errors[] = 'Il codice "' . $rawKey . '" può contenere solo lettere, numeri, trattino e underscore (2-30 caratteri).';
                continue;
            }

            if (isset($seen[$rawKey])) {
                $errors[] = 'Il codice "' . $rawKey . '" è duplicato.';
                continue;
            }

            $label = $rawLabel !== '' ? mb_substr($rawLabel, 0, 80) : $rawKey;
            $prefix = $rawPrefix !== '' ? $rawPrefix : substr($rawKey, 0, 3);
            $prefix = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $prefix) ?: substr($rawKey, 0, 3), 0, 6));

            if ($prefix === '') {
                $prefix = 'CAF';
            }

            $seen[$rawKey] = true;
            $normalized[] = [
                'key' => $rawKey,
                'label' => $label,
                'prefix' => $prefix,
            ];
        }

        if (!$normalized) {
            $errors[] = 'Inserisci almeno una tipologia valida per le pratiche CAF & Patronato.';
        }

        if ($errors) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'config' => $normalized ?: self::defaultCafPatronatoTypes(),
            ];
        }

        $payload = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [
                'success' => false,
                'errors' => ['Impossibile serializzare le tipologie CAF & Patronato.'],
                'config' => $normalized,
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::CAF_PATRONATO_TYPES_KEY,
                ':valore' => $payload,
            ]);

            $this->logActivity($userId, 'Aggiornamento tipologie CAF/Patronato', ['types' => $normalized]);

            return ['success' => true, 'errors' => [], 'config' => $normalized];
        } catch (Throwable $exception) {
            error_log('CAF/Patronato types save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare le tipologie CAF & Patronato.'],
                'config' => $normalized,
            ];
        }
    }

    /**
     * @param array<mixed> $services
     * @return array{success:bool,errors:array<int,string>,services:array<string,array<int,array{name:string,price:float|null}>>,auto_generated:bool}
     */
    public function saveCafPatronatoServices(array $services, int $userId, bool $autoGenerated = false): array
    {
        $errors = [];
        $normalized = [];
        $totalServices = 0;

        $types = $this->getCafPatronatoTypes();
        $allowedKeys = [];
        foreach ($types as $type) {
            $key = strtoupper((string) ($type['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $allowedKeys[] = $key;
            $normalized[$key] = [];
        }

        $input = $services;
        if (!$this->isAssociativeArray($input)) {
            // Backward compatibility: treat as global list assigned to CAF
            $input = ['CAF' => $services];
        }

        foreach ($input as $typeKey => $rows) {
            $typeKey = strtoupper(trim((string) $typeKey));
            if ($typeKey === '') {
                continue;
            }

            if (!isset($normalized[$typeKey])) {
                $normalized[$typeKey] = [];
            }

            if (!is_array($rows)) {
                $rows = [$rows];
            }

            $seen = [];
            foreach ($rows as $index => $row) {
                if (is_string($row)) {
                    $value = trim($row);
                    $rawRow = ['name' => $row];
                } elseif (is_array($row)) {
                    $value = trim((string) ($row['name'] ?? ($row['value'] ?? ($row['label'] ?? ''))));
                    $rawRow = $row;
                } else {
                    continue;
                }

                if ($value === '') {
                    $hasContent = false;
                    if (is_array($rawRow)) {
                        foreach ($rawRow as $part) {
                            if (is_string($part) && trim($part) !== '') {
                                $hasContent = true;
                                break;
                            }
                        }
                    }

                    if ($hasContent) {
                        $errors[] = 'Il servizio #' . ((int) $index + 1) . ' per la tipologia ' . $typeKey . ' richiede un valore valido.';
                    }
                    continue;
                }

                if (mb_strlen($value) > 120) {
                    $errors[] = 'Il servizio "' . $value . '" per la tipologia ' . $typeKey . ' supera i 120 caratteri consentiti.';
                    continue;
                }

                $hash = mb_strtolower($value, 'UTF-8');
                if (isset($seen[$hash])) {
                    $errors[] = 'Il servizio "' . $value . '" è duplicato per la tipologia ' . $typeKey . '.';
                    continue;
                }

                $priceInput = is_array($rawRow) ? ($rawRow['price'] ?? null) : null;
                $priceProvided = $this->hasServicePricePayload($priceInput);
                $price = $this->sanitizeServicePriceValue($priceInput);
                if ($priceProvided && $price === null) {
                    $errors[] = 'Il prezzo inserito per "' . $value . '" nella tipologia ' . $typeKey . ' non è valido. Usa un importo positivo con al massimo due decimali.';
                    continue;
                }

                $seen[$hash] = true;
                $normalized[$typeKey][] = [
                    'name' => mb_substr($value, 0, 120),
                    'price' => $price,
                ];
                $totalServices++;
            }
        }

        if ($totalServices === 0) {
            $errors[] = 'Inserisci almeno un servizio richiesto valido.';
        }

        if ($errors) {
            $current = $this->getCafPatronatoServices();
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'services' => $current,
                'auto_generated' => $autoGenerated,
            ];
        }

        foreach ($normalized as $typeKey => $list) {
            if (!$list) {
                continue;
            }
            usort($list, static function (array $a, array $b): int {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
            $normalized[$typeKey] = array_values($list);
        }

        $payload = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [
                'success' => false,
                'errors' => ['Impossibile serializzare i servizi richiesti.'],
                'services' => $normalized,
                'auto_generated' => $autoGenerated,
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::CAF_PATRONATO_SERVICES_KEY,
                ':valore' => $payload,
            ]);

            $this->logActivity($userId, 'Aggiornamento servizi richiesti CAF/Patronato', [
                'services' => $normalized,
                'source' => $autoGenerated ? 'import' : 'manual',
            ]);

            return [
                'success' => true,
                'errors' => [],
                'services' => $normalized,
                'auto_generated' => $autoGenerated,
            ];
        } catch (Throwable $exception) {
            error_log('CAF/Patronato services save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare i servizi richiesti.'],
                'services' => $normalized,
                'auto_generated' => $autoGenerated,
            ];
        }
    }

    /**
     * @param array<int, array{value?:string,label?:string,category?:string}> $statuses
     * @return array{success:bool,errors:array<int,string>,config:array<int,array{value:string,label:string,category:string}>}
     */
    public function saveCafPatronatoStatuses(array $statuses, int $userId): array
    {
        $errors = [];
        $normalized = [];
        $seen = [];

        foreach ($statuses as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $rawValue = trim((string) ($row['value'] ?? ''));
            $rawLabel = trim((string) ($row['label'] ?? ''));
            $rawCategory = strtolower(trim((string) ($row['category'] ?? '')));

            if ($rawValue === '' && $rawLabel === '') {
                continue;
            }

            if ($rawValue === '') {
                $errors[] = 'Lo stato #' . ((int) $index + 1) . ' richiede un valore identificativo.';
                continue;
            }

            if (mb_strlen($rawValue) > 80) {
                $errors[] = 'Lo stato "' . $rawValue . '" supera gli 80 caratteri consentiti.';
                continue;
            }

            $hash = mb_strtolower($rawValue, 'UTF-8');
            if (isset($seen[$hash])) {
                $errors[] = 'Lo stato "' . $rawValue . '" è duplicato.';
                continue;
            }

            $label = $rawLabel !== '' ? mb_substr($rawLabel, 0, 80) : $rawValue;
            if (!isset(self::CAF_PATRONATO_STATUS_CATEGORIES[$rawCategory])) {
                $rawCategory = 'pending';
            }

            $seen[$hash] = true;
            $normalized[] = [
                'value' => $rawValue,
                'label' => $label,
                'category' => $rawCategory,
            ];
        }

        if (!$normalized) {
            $errors[] = 'Inserisci almeno uno stato valido per le pratiche.';
        }

        if ($errors) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'config' => $normalized ?: self::defaultCafPatronatoStatuses(),
            ];
        }

        $payload = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return [
                'success' => false,
                'errors' => ['Impossibile serializzare gli stati delle pratiche.'],
                'config' => $normalized,
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::CAF_PATRONATO_STATUSES_KEY,
                ':valore' => $payload,
            ]);

            $this->logActivity($userId, 'Aggiornamento stati CAF/Patronato', ['statuses' => $normalized]);

            return ['success' => true, 'errors' => [], 'config' => $normalized];
        } catch (Throwable $exception) {
            error_log('CAF/Patronato statuses save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare gli stati CAF & Patronato.'],
                'config' => $normalized,
            ];
        }
    }

    /**
     * @return array{success:bool,errors:array<int,string>,config:array<string,mixed>}
     */
    public function saveEmailMarketingSettings(array $payload, array $currentConfig, int $userId): array
    {
        $current = $this->formatEmailMarketingSettings($currentConfig, false);

        $senderName = trim((string) ($payload['sender_name'] ?? ''));
        $senderEmail = trim((string) ($payload['sender_email'] ?? ''));
        $replyTo = trim((string) ($payload['reply_to_email'] ?? ''));
        $unsubscribeBaseUrl = trim((string) ($payload['unsubscribe_base_url'] ?? ''));
        $webhookSecret = trim((string) ($payload['webhook_secret'] ?? ''));
        $testAddress = trim((string) ($payload['test_address'] ?? ''));
        $newApiKey = trim((string) ($payload['resend_api_key'] ?? ''));
        $removeApiKey = !empty($payload['remove_resend_api_key']);

        $errors = [];

        if ($senderName === '') {
            $errors[] = 'Il nome mittente è obbligatorio.';
        } elseif (mb_strlen($senderName) > 80) {
            $errors[] = 'Il nome mittente non può superare gli 80 caratteri.';
        }

        if ($senderEmail === '' || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Inserisci un indirizzo mittente valido.';
        }

        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Inserisci un indirizzo di risposta valido oppure lascia il campo vuoto.';
        }

        if ($unsubscribeBaseUrl === '') {
            $errors[] = 'Imposta l\'URL base per le disiscrizioni.';
        } elseif (!filter_var($unsubscribeBaseUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'L\'URL di disiscrizione non è valido.';
        }

        if ($webhookSecret !== '' && mb_strlen($webhookSecret) < 12) {
            $errors[] = 'Il segreto webhook deve contenere almeno 12 caratteri.';
        }

        if ($testAddress !== '' && !filter_var($testAddress, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Inserisci un indirizzo email di test valido.';
        }

        $resolvedApiKey = $current['resend_api_key'];
        if ($removeApiKey) {
            $resolvedApiKey = '';
        } elseif ($newApiKey !== '') {
            $resolvedApiKey = $newApiKey;
        }

        $unsubscribeBaseUrl = $unsubscribeBaseUrl !== '' ? rtrim($unsubscribeBaseUrl, '/') : $unsubscribeBaseUrl;

        $newConfig = [
            'sender_name' => $senderName,
            'sender_email' => $senderEmail,
            'reply_to_email' => $replyTo,
            'resend_api_key' => $resolvedApiKey,
            'unsubscribe_base_url' => $unsubscribeBaseUrl !== '' ? $unsubscribeBaseUrl : $this->defaultUnsubscribeBaseUrl(),
            'webhook_secret' => $webhookSecret,
            'test_address' => $testAddress,
        ];

        $maskExistingKey = $newApiKey === '' && !$removeApiKey;

        if ($errors) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'config' => $this->formatEmailMarketingSettings($newConfig, $maskExistingKey),
            ];
        }

        $payloadJson = json_encode($newConfig, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return [
                'success' => false,
                'errors' => ['Impossibile serializzare la configurazione email marketing.'],
                'config' => $this->formatEmailMarketingSettings($newConfig, $maskExistingKey),
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::EMAIL_MARKETING_SETTINGS_KEY,
                ':valore' => $payloadJson,
            ]);

            $this->logActivity($userId, 'Aggiornamento impostazioni email marketing', [
                'sender_email' => $newConfig['sender_email'],
                'reply_to_email' => $newConfig['reply_to_email'],
                'has_resend_api_key' => $newConfig['resend_api_key'] !== '',
                'unsubscribe_base_url' => $newConfig['unsubscribe_base_url'],
                'webhook_secret' => $newConfig['webhook_secret'] !== '' ? '***' : '',
                'test_address' => $newConfig['test_address'] !== '' ? '***' : '',
            ]);

            if (function_exists('reset_email_marketing_config_cache')) {
                reset_email_marketing_config_cache($this->formatEmailMarketingSettings($newConfig, false));
            }

            return [
                'success' => true,
                'errors' => [],
                'config' => $this->formatEmailMarketingSettings($newConfig),
            ];
        } catch (Throwable $exception) {
            error_log('Email marketing settings save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare le impostazioni email marketing.'],
                'config' => $this->formatEmailMarketingSettings($newConfig, $maskExistingKey),
            ];
        }
    }

    /**
     * @return array{currency:string,tiers:array<int,array{label:string,max_weight:float|null,max_volume:float|null,price:float}>}
     */
    public function getPortalBrtPricing(): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT valore FROM configurazioni WHERE chiave = :chiave LIMIT 1');
            $stmt->execute([':chiave' => self::PORTAL_BRT_PRICING_KEY]);
            $value = $stmt->fetchColumn();

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return self::normalizePortalBrtPricing($decoded);
                }
            }
        } catch (Throwable $exception) {
            error_log('Portal BRT pricing fetch failed: ' . $exception->getMessage());
        }

        return [
            'currency' => 'EUR',
            'tiers' => [],
        ];
    }

    /**
     * @return array{currency:string,tiers:array<int,array{label:string,max_weight:string,max_volume:string,price:string}>}
     */
    public function getPortalBrtPricingFormConfig(): array
    {
        $config = $this->getPortalBrtPricing();
        return $this->normalizeDisplayTiersForSuccess($config);
    }

    /**
     * @return array{success:bool,errors:array<int,string>,config:array{currency:string,tiers:array<int,array{label:string,max_weight:string,max_volume:string,price:string}>}}
     */
    public function savePortalBrtPricing(array $input, int $userId): array
    {
        $currency = self::normalizeCurrency($input['currency'] ?? null);
        $tiersInput = isset($input['tiers']) && is_array($input['tiers']) ? $input['tiers'] : [];

        $errors = [];
        $preparedTiers = [];
        $displayTiers = [];
        $previousWeight = null;
        $previousVolume = null;
        $hasUnlimitedBoth = false;

        foreach ($tiersInput as $index => $tierInput) {
            if (!is_array($tierInput)) {
                continue;
            }

            $rawLabel = is_string($tierInput['label'] ?? null) ? trim((string) $tierInput['label']) : '';
            $rawWeight = is_string($tierInput['max_weight'] ?? null) ? trim((string) $tierInput['max_weight']) : ($tierInput['max_weight'] ?? null);
            $rawVolume = is_string($tierInput['max_volume'] ?? null) ? trim((string) $tierInput['max_volume']) : ($tierInput['max_volume'] ?? null);
            $rawPrice = is_string($tierInput['price'] ?? null) ? trim((string) $tierInput['price']) : ($tierInput['price'] ?? null);

            $label = self::normalizeTierLabel($rawLabel);
            $maxWeight = self::toNullableFloat($rawWeight);
            $maxVolume = self::toNullableFloat($rawVolume);
            $price = self::toNullableFloat($rawPrice);

            $displayTiers[] = [
                'label' => $label !== '' ? $label : $rawLabel,
                'max_weight' => $maxWeight !== null ? $this->formatNumberForInput($maxWeight, 3) : (is_string($rawWeight) ? $rawWeight : ''),
                'max_volume' => $maxVolume !== null ? $this->formatNumberForInput($maxVolume, 4) : (is_string($rawVolume) ? $rawVolume : ''),
                'price' => $price !== null ? $this->formatNumberForInput($price, 2) : (is_string($rawPrice) ? $rawPrice : ''),
            ];

            $isRowEmpty = ($label === '' && $maxWeight === null && $maxVolume === null && ($price === null && ($rawPrice === null || (is_string($rawPrice) && trim((string) $rawPrice) === ''))));
            if ($isRowEmpty) {
                continue;
            }

            $rowNumber = $index + 1;

            if ($price === null || $price <= 0) {
                $errors[] = sprintf('Indica un prezzo valido e maggiore di zero per lo scaglione #%d.', $rowNumber);
                continue;
            }

            if ($maxWeight !== null && $maxWeight <= 0) {
                $errors[] = sprintf('Il limite di peso dello scaglione #%d deve essere maggiore di zero oppure lasciato vuoto.', $rowNumber);
                continue;
            }

            if ($maxVolume !== null && $maxVolume <= 0) {
                $errors[] = sprintf('Il limite di volume dello scaglione #%d deve essere maggiore di zero oppure lasciato vuoto.', $rowNumber);
                continue;
            }

            $weightComparable = $maxWeight === null ? INF : $maxWeight;
            $volumeComparable = $maxVolume === null ? INF : $maxVolume;

            if ($previousWeight !== null) {
                if ($previousWeight === INF && $weightComparable !== INF) {
                    $errors[] = 'Gli scaglioni devono essere ordinati per peso crescente. Sposta gli scaglioni senza limite di peso alla fine.';
                } elseif ($weightComparable + 1e-6 < $previousWeight) {
                    $errors[] = 'Gli scaglioni devono essere ordinati per peso crescente.';
                }
            }

            if ($previousVolume !== null) {
                if ($previousVolume === INF && $volumeComparable !== INF) {
                    $errors[] = 'Gli scaglioni devono essere ordinati per volume crescente. Sposta gli scaglioni senza limite di volume alla fine.';
                } elseif ($volumeComparable + 1e-6 < $previousVolume) {
                    $errors[] = 'Gli scaglioni devono essere ordinati per volume crescente.';
                }
            }

            if ($maxWeight === null && $maxVolume === null) {
                if ($hasUnlimitedBoth) {
                    $errors[] = 'È consentito un solo scaglione senza limiti di peso e volume.';
                }
                $hasUnlimitedBoth = true;
            }

            $previousWeight = $weightComparable;
            $previousVolume = $volumeComparable;

            $preparedTiers[] = [
                'label' => $label,
                'max_weight' => $maxWeight === null ? null : round($maxWeight, 3),
                'max_volume' => $maxVolume === null ? null : round($maxVolume, 4),
                'price' => round($price, 2),
            ];
        }

        $displayTiers = $this->normalizeDisplayTiers($displayTiers);

        if ($preparedTiers === []) {
            if ($displayTiers === []) {
                $displayTiers[] = ['label' => '', 'max_weight' => '', 'max_volume' => '', 'price' => ''];
            }

            return [
                'success' => false,
                'errors' => ['Aggiungi almeno uno scaglione tariffario con prezzo valido.'],
                'config' => [
                    'currency' => $currency,
                    'tiers' => $displayTiers,
                ],
            ];
        }

        if ($errors) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors)),
                'config' => [
                    'currency' => $currency,
                    'tiers' => $displayTiers,
                ],
            ];
        }

        $sanitized = [
            'currency' => $currency,
            'tiers' => array_values($preparedTiers),
        ];

        try {
            $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            error_log('Portal BRT pricing serialize failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare la configurazione tariffaria.'],
                'config' => [
                    'currency' => $currency,
                    'tiers' => $displayTiers,
                ],
            ];
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore)
                 ON DUPLICATE KEY UPDATE valore = VALUES(valore)'
            );
            $stmt->execute([
                ':chiave' => self::PORTAL_BRT_PRICING_KEY,
                ':valore' => $encoded,
            ]);

            $this->logActivity($userId, 'Aggiornamento tariffe BRT portale', [
                'currency' => $sanitized['currency'],
                'tiers' => $sanitized['tiers'],
            ]);

            return [
                'success' => true,
                'errors' => [],
                'config' => $this->normalizeDisplayTiersForSuccess($sanitized),
            ];
        } catch (Throwable $exception) {
            error_log('Portal BRT pricing save failed: ' . $exception->getMessage());
            return [
                'success' => false,
                'errors' => ['Impossibile salvare la configurazione tariffaria.'],
                'config' => [
                    'currency' => $currency,
                    'tiers' => $displayTiers,
                ],
            ];
        }
    }

    public function updateCompanySettings(
        array $payload,
        array $vatCountries,
        array $currentConfig,
        ?array $logoFile,
        bool $removeLogo,
        int $userId
    ): array {
        $errors = $this->validateCompanyPayload($payload, $vatCountries);
        $logoPath = $currentConfig['company_logo'] ?? '';

        if ($logoFile && $logoFile['error'] !== UPLOAD_ERR_NO_FILE) {
            $logoResult = $this->processLogoUpload($logoFile, $logoPath);
            $errors = array_merge($errors, $logoResult['errors']);
            $logoPath = $logoResult['path'];
        }

        if ($removeLogo && $logoPath !== '') {
            $this->deleteExistingLogo($logoPath);
            $logoPath = '';
        }

        if ($errors) {
            return [
                'success' => false,
                'errors' => $errors,
                'config' => array_merge($currentConfig, $payload, ['company_logo' => $logoPath]),
            ];
        }

        $payload['company_logo'] = $logoPath;

        try {
            $this->pdo->beginTransaction();
            $stmt = $this->pdo->prepare(
                'INSERT INTO configurazioni (chiave, valore) VALUES (:chiave, :valore_inserimento)
                 ON DUPLICATE KEY UPDATE valore = :valore_aggiornamento'
            );

            foreach ($payload as $key => $value) {
                $stmt->execute([
                    'chiave' => $key,
                    'valore_inserimento' => $value,
                    'valore_aggiornamento' => $value,
                ]);
                $currentConfig[$key] = $value;
            }

            $this->pdo->commit();
            $this->logActivity($userId, 'Aggiornamento dati aziendali', $payload);

            return [
                'success' => true,
                'errors' => [],
                'config' => $currentConfig,
            ];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            error_log('Company settings update failed: ' . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Errore durante il salvataggio dei dati aziendali. ' . $e->getMessage()],
                'config' => array_merge($currentConfig, $payload),
            ];
        }
    }

    public function generateBackup(int $userId): array
    {
        if (!is_dir($this->backupPath) && !mkdir($concurrentDirectory = $this->backupPath, 0775, true) && !is_dir($concurrentDirectory)) {
            return ['success' => false, 'error' => 'Impossibile creare la cartella dei backup.'];
        }

        if (!is_writable($this->backupPath)) {
            return ['success' => false, 'error' => 'La cartella dei backup non è scrivibile.'];
        }

        $timestamp = date('Ymd_His');
        $backupFile = $this->backupPath . DIRECTORY_SEPARATOR . 'backup_' . $timestamp . '.sql';

        try {
            set_time_limit(0);
            $charsetResult = $this->pdo->query('SELECT @@character_set_database');
            $charset = $charsetResult ? $charsetResult->fetchColumn() : null;
            if ($charset && preg_match('/^[a-zA-Z0-9_]+$/', (string) $charset)) {
                $this->pdo->exec('SET NAMES ' . $charset);
            }

            $tablesStmt = $this->pdo->query('SHOW FULL TABLES');
            $tables = [];
            while ($row = $tablesStmt->fetch(PDO::FETCH_NUM)) {
                if (($row[1] ?? '') === 'BASE TABLE') {
                    $tables[] = $row[0];
                }
            }

            $dump = "-- Backup Coresuite Business\n";
            $dump .= '-- Generato il ' . date('Y-m-d H:i:s') . "\n\n";
            foreach ($tables as $table) {
                $dump .= '-- Struttura per tabella `' . $table . "`\n";
                $dump .= 'DROP TABLE IF EXISTS `' . $table . "`;\n";
                $createStmt = $this->pdo->query('SHOW CREATE TABLE `' . $table . '`');
                $create = $createStmt->fetch(PDO::FETCH_ASSOC);
                $dump .= ($create['Create Table'] ?? '') . ";\n\n";

                $rowsStmt = $this->pdo->query('SELECT * FROM `' . $table . '`');
                while ($row = $rowsStmt->fetch(PDO::FETCH_ASSOC)) {
                    $columns = array_map(static fn($col) => '`' . str_replace('`', '``', $col) . '`', array_keys($row));
                    $values = [];
                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $this->pdo->quote((string) $value);
                        }
                    }
                    $dump .= 'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
                }
                $dump .= "\n";
            }

            if (file_put_contents($backupFile, $dump) === false) {
                throw new RuntimeException('Scrittura file di backup fallita.');
            }

            if ($this->backupPassphrase) {
                $backupFile = $this->encryptBackup($backupFile);
            }

            $this->logActivity($userId, 'Backup manuale', ['file' => basename($backupFile)]);

            return ['success' => true, 'file' => basename($backupFile)];
        } catch (Throwable $e) {
            error_log('Backup generation failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Errore durante la generazione del backup.'];
        }
    }

    private function validateCompanyPayload(array &$payload, array $vatCountries): array
    {
        $errors = [];

        $payload['ragione_sociale'] = trim($payload['ragione_sociale'] ?? '');
        $payload['indirizzo'] = trim($payload['indirizzo'] ?? '');
        $payload['cap'] = trim($payload['cap'] ?? '');
        $payload['citta'] = trim($payload['citta'] ?? '');
        $payload['provincia'] = strtoupper(trim($payload['provincia'] ?? ''));
        $payload['telefono'] = trim($payload['telefono'] ?? '');
        $payload['email'] = trim($payload['email'] ?? '');
        $payload['pec'] = trim($payload['pec'] ?? '');
        $payload['sdi'] = strtoupper(trim($payload['sdi'] ?? ''));
        $payload['vat_country'] = strtoupper(trim($payload['vat_country'] ?? 'IT'));
        $payload['piva'] = strtoupper(preg_replace('/\s+/', '', $payload['piva'] ?? ''));
        $payload['iban'] = strtoupper(str_replace(' ', '', $payload['iban'] ?? ''));
        $payload['note'] = trim($payload['note'] ?? '');

        if ($payload['ragione_sociale'] === '') {
            $errors[] = 'La ragione sociale è obbligatoria.';
        }
        if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Inserisci un indirizzo email valido.';
        }
        if ($payload['pec'] !== '' && !filter_var($payload['pec'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Inserisci una PEC valida.';
        }
        if ($payload['telefono'] !== '' && !preg_match('/^[0-9+()\s-]{6,}$/', $payload['telefono'])) {
            $errors[] = 'Inserisci un numero di telefono valido.';
        }
        if ($payload['cap'] !== '' && !preg_match('/^[0-9]{5}$/', $payload['cap'])) {
            $errors[] = 'Inserisci un CAP a 5 cifre.';
        }
        if ($payload['provincia'] !== '' && !preg_match('/^[A-Z]{2}$/', $payload['provincia'])) {
            $errors[] = 'Inserisci la sigla della provincia (es. MI).';
        }
        if ($payload['sdi'] !== '' && !preg_match('/^[A-Z0-9]{7}$/', $payload['sdi'])) {
            $errors[] = 'Il codice SDI deve contenere 7 caratteri alfanumerici.';
        }
        if (!array_key_exists($payload['vat_country'], $vatCountries)) {
            $errors[] = 'Seleziona un paese IVA valido.';
        }
        if ($payload['piva'] !== '') {
            if (!preg_match('/^[A-Z0-9]{8,15}$/', $payload['piva'])) {
                $errors[] = 'La partita IVA deve contenere tra 8 e 15 caratteri alfanumerici.';
            } elseif ($payload['vat_country'] === 'IT' && !preg_match('/^[0-9]{11}$/', $payload['piva'])) {
                $errors[] = "Per l'Italia la partita IVA deve contenere 11 cifre.";
            }
        }
        if ($payload['iban'] !== '' && !preg_match('/^[A-Z0-9]{15,34}$/', $payload['iban'])) {
            $errors[] = 'Inserisci un IBAN valido (15-34 caratteri alfanumerici).';
        }
        if (mb_strlen($payload['note']) > 2000) {
            $errors[] = 'Le note non possono superare i 2000 caratteri.';
        }

        return $errors;
    }

    private function processLogoUpload(array $file, string $currentLogoPath): array
    {
        $errors = [];
        $path = $currentLogoPath;

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Errore durante il caricamento del logo.';
            return ['errors' => $errors, 'path' => $path];
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Il logo non può superare i 2MB.';
        }

        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            $errors[] = 'Carica un file immagine valido per il logo.';
        } else {
            $allowedFormats = ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'];
            if (!in_array($info['mime'], $allowedFormats, true)) {
                $errors[] = 'Formato logo non supportato. Usa PNG, JPG, WEBP o SVG.';
            }
        }

        if ($errors) {
            return ['errors' => $errors, 'path' => $path];
        }

        if (!is_dir($this->brandingPath) && !mkdir($concurrentDirectory = $this->brandingPath, 0775, true) && !is_dir($concurrentDirectory)) {
            $errors[] = 'Impossibile creare la cartella per il logo aziendale.';
            return ['errors' => $errors, 'path' => $path];
        }

        if (!is_writable($this->brandingPath)) {
            $errors[] = 'La cartella per il logo aziendale non è scrivibile.';
            return ['errors' => $errors, 'path' => $path];
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $safeName = function_exists('sanitize_filename')
            ? sanitize_filename('logo_' . date('Ymd_His') . '.' . $extension)
            : preg_replace('/[^A-Za-z0-9._-]/', '_', 'logo_' . date('Ymd_His') . '.' . $extension);

        $destination = $this->brandingPath . DIRECTORY_SEPARATOR . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            $errors[] = 'Impossibile salvare il file del logo.';
            return ['errors' => $errors, 'path' => $path];
        }

        if ($currentLogoPath) {
            $this->deleteExistingLogo($currentLogoPath);
        }

        $relativePath = 'assets/uploads/branding/' . $safeName;

        return [
            'errors' => [],
            'path' => $relativePath,
        ];
    }

    private function encryptBackup(string $filePath): string
    {
        if (!$this->backupPassphrase) {
            return $filePath;
        }

        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new RuntimeException('Lettura file di backup fallita prima della cifratura.');
        }

        $cipher = $this->backupCipher;
        $ivLength = openssl_cipher_iv_length($cipher);
        if (!is_int($ivLength) || $ivLength <= 0) {
            throw new RuntimeException('Cipher per la cifratura del backup non valido.');
        }

        $iv = random_bytes($ivLength);
        $ciphertext = openssl_encrypt($contents, $cipher, $this->backupPassphrase, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Cifratura del backup non riuscita.');
        }

        $payload = base64_encode($iv . $ciphertext);
        $encryptedPath = $filePath . '.enc';

        if (file_put_contents($encryptedPath, $payload) === false) {
            throw new RuntimeException('Scrittura del backup cifrato non riuscita.');
        }

        @unlink($filePath);

        return $encryptedPath;
    }

    private function deleteExistingLogo(string $relativePath): void
    {
        $absoluteRoot = realpath($this->rootPath);
        $candidate = realpath($this->rootPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
        if (!$absoluteRoot || !$candidate) {
            return;
        }

        if (strpos($candidate, $absoluteRoot) === 0 && is_file($candidate)) {
            @unlink($candidate);
        }
    }

    /**
     * @param array<int, string> $types
     * @return array<int, string>
     */
    private function enforceAppointmentTypeLength(array $types): array
    {
        $maxLength = 60;
        $filtered = [];

        foreach ($types as $type) {
            if (mb_strlen($type) <= $maxLength) {
                $filtered[] = $type;
            }
        }

        return $filtered;
    }

    private function isSequentialArray(array $array): bool
    {
        if (function_exists('array_is_list')) {
            return array_is_list($array);
        }

        return $array === [] || array_keys($array) === range(0, count($array) - 1);
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function sanitizeStatusList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $label = $this->sanitizeStatusLabel($value);
            if ($label === '') {
                continue;
            }

            $key = $this->statusKey($label);
            if (!isset($clean[$key])) {
                $clean[$key] = $label;
            }
        }

        return array_values($clean);
    }

    private function sanitizeStatusLabel(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = preg_replace('/\s+/', ' ', trim($value));
        return $normalized === null ? '' : $normalized;
    }

    /**
     * @param array<int, string> $available
     * @return array<string, string>
     */
    private function buildStatusMap(array $available): array
    {
        $map = [];
        foreach ($available as $label) {
            $map[$this->statusKey($label)] = $label;
        }

        return $map;
    }

    /**
     * @param array<int, string> $subset
     * @param array<string, string> $availableMap
     * @return array{values:array<int,string>,missing:array<int,string>}
     */
    private function filterStatusesAgainstAvailable(array $subset, array $availableMap): array
    {
        $values = [];
        $missing = [];

        foreach ($subset as $label) {
            $key = $this->statusKey($label);
            if (isset($availableMap[$key])) {
                $values[$key] = $availableMap[$key];
            } else {
                $missing[] = $label;
            }
        }

        return [
            'values' => array_values($values),
            'missing' => $missing,
        ];
    }

    private function statusKey(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{available:array<int,string>,active:array<int,string>,completed:array<int,string>,cancelled:array<int,string>,confirmation:string}
     */
    private function normalizeAppointmentStatuses(array $input): array
    {
        $defaults = self::defaultAppointmentStatuses();

        $availableInput = isset($input['available']) && is_array($input['available']) ? $input['available'] : [];
        $available = $this->sanitizeStatusList($availableInput);
        if (!$available) {
            $available = $defaults['available'];
        }

        $tooLong = false;
        foreach ($available as $status) {
            if (mb_strlen($status) > 40) {
                $tooLong = true;
                break;
            }
        }
        if ($tooLong) {
            $available = $defaults['available'];
        }

        $availableMap = $this->buildStatusMap($available);

        $activeInput = isset($input['active']) && is_array($input['active']) ? $input['active'] : $defaults['active'];
        $active = $this->filterStatusesAgainstAvailable($this->sanitizeStatusList($activeInput), $availableMap)['values'];
        if (!$active) {
            $active = $this->filterStatusesAgainstAvailable($defaults['active'], $availableMap)['values'];
        }
        if (!$active) {
            $active = array_values($available);
        }

        $completedInput = isset($input['completed']) && is_array($input['completed']) ? $input['completed'] : $defaults['completed'];
        $completed = $this->filterStatusesAgainstAvailable($this->sanitizeStatusList($completedInput), $availableMap)['values'];

        $cancelledInput = isset($input['cancelled']) && is_array($input['cancelled']) ? $input['cancelled'] : $defaults['cancelled'];
        $cancelled = $this->filterStatusesAgainstAvailable($this->sanitizeStatusList($cancelledInput), $availableMap)['values'];

        $confirmation = '';
        if (isset($input['confirmation']) && is_string($input['confirmation'])) {
            $candidate = $this->sanitizeStatusLabel($input['confirmation']);
            $key = $this->statusKey($candidate);
            if ($candidate !== '' && isset($availableMap[$key])) {
                $confirmation = $availableMap[$key];
            }
        }

        if ($confirmation === '') {
            $defaultConfirmation = $this->sanitizeStatusLabel($defaults['confirmation']);
            $confirmationKey = $this->statusKey($defaultConfirmation);
            if ($defaultConfirmation !== '' && isset($availableMap[$confirmationKey])) {
                $confirmation = $availableMap[$confirmationKey];
            } else {
                $confirmation = $available[0] ?? $defaults['confirmation'];
            }
        }

        return [
            'available' => array_values($available),
            'active' => array_values($active),
            'completed' => array_values($completed),
            'cancelled' => array_values($cancelled),
            'confirmation' => $confirmation,
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array{currency:string,tiers:array<int,array{label:string,max_weight:float|null,max_volume:float|null,price:float}>}
     */
    public static function normalizePortalBrtPricing(array $raw): array
    {
        $currency = self::normalizeCurrency($raw['currency'] ?? null);
        $tiers = [];

        if (isset($raw['tiers']) && is_array($raw['tiers'])) {
            foreach ($raw['tiers'] as $tier) {
                if (!is_array($tier)) {
                    continue;
                }

                $label = self::normalizeTierLabel($tier['label'] ?? null);
                $maxWeight = self::toNullableFloat($tier['max_weight'] ?? null);
                $maxVolume = self::toNullableFloat($tier['max_volume'] ?? null);
                $price = self::toNullableFloat($tier['price'] ?? null);

                if ($price === null || $price < 0) {
                    continue;
                }

                if ($maxWeight !== null && $maxWeight < 0) {
                    continue;
                }

                if ($maxVolume !== null && $maxVolume < 0) {
                    continue;
                }

                $tiers[] = [
                    'label' => $label,
                    'max_weight' => $maxWeight === null ? null : round($maxWeight, 3),
                    'max_volume' => $maxVolume === null ? null : round($maxVolume, 4),
                    'price' => round($price, 2),
                ];
            }
        }

        return [
            'currency' => $currency,
            'tiers' => array_values($tiers),
        ];
    }

    private static function normalizeCurrency($value): string
    {
        if (!is_string($value)) {
            $value = $value === null ? '' : (string) $value;
        }

        $candidate = strtoupper(trim($value));
        if (preg_match('/^[A-Z]{3}$/', $candidate)) {
            return $candidate;
        }

        return 'EUR';
    }

    private static function normalizeTierLabel($value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            $trimmed = mb_substr($trimmed, 0, 60, 'UTF-8');
        } else {
            $trimmed = substr($trimmed, 0, 60);
        }

        return $trimmed;
    }

    /**
     * @param mixed $value
     */
    private static function toNullableFloat($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return (float) $normalized;
    }

    private function formatNumberForInput(float $value, int $decimals): string
    {
        $decimals = max(0, $decimals);
        return number_format($value, $decimals, '.', '');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{label:string,max_weight:string,max_volume:string,price:string}>
     */
    private function normalizeDisplayTiers(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'label' => isset($row['label']) ? trim((string) $row['label']) : '',
                'max_weight' => isset($row['max_weight']) ? trim((string) $row['max_weight']) : '',
                'max_volume' => isset($row['max_volume']) ? trim((string) $row['max_volume']) : '',
                'price' => isset($row['price']) ? trim((string) $row['price']) : '',
            ];
        }

        if ($normalized === []) {
            $normalized[] = ['label' => '', 'max_weight' => '', 'max_volume' => '', 'price' => ''];
        }

        return $normalized;
    }

    /**
     * @param array{currency:string,tiers:array<int,array{label:string,max_weight:float|null,max_volume:float|null,price:float}>} $config
     * @return array{currency:string,tiers:array<int,array{label:string,max_weight:string,max_volume:string,price:string}>}
     */
    private function normalizeDisplayTiersForSuccess(array $config): array
    {
        $tiers = [];
        foreach ($config['tiers'] as $tier) {
            $tiers[] = [
                'label' => $tier['label'],
                'max_weight' => $tier['max_weight'] !== null ? $this->formatNumberForInput((float) $tier['max_weight'], 3) : '',
                'max_volume' => $tier['max_volume'] !== null ? $this->formatNumberForInput((float) $tier['max_volume'], 4) : '',
                'price' => $this->formatNumberForInput((float) $tier['price'], 2),
            ];
        }

        if ($tiers === []) {
            $tiers[] = ['label' => '', 'max_weight' => '', 'max_volume' => '', 'price' => ''];
        }

        return [
            'currency' => $config['currency'],
            'tiers' => $tiers,
        ];
    }

    private function sanitizeDescriptions(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }
            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }
            $clean[$trimmed] = $trimmed;
        }

        return array_values($clean);
    }

    private function validateDescriptions(array $values): array
    {
        $errors = [];
        foreach ($values as $value) {
            if (mb_strlen($value) > 180) {
                $errors[] = 'Le descrizioni non possono superare i 180 caratteri.';
                break;
            }
        }

        return $errors;
    }

    /**
     * @param mixed $types
     * @return array<int, array{key:string,label:string,prefix:string}>
     */
    private function normalizeCafPatronatoTypes($types): array
    {
        if (!is_array($types)) {
            return self::defaultCafPatronatoTypes();
        }

        $normalized = [];
        $seen = [];

        foreach ($types as $entry) {
            if (is_string($entry)) {
                $entry = ['key' => $entry, 'label' => $entry];
            }

            if (!is_array($entry)) {
                continue;
            }

            $key = strtoupper(trim((string) ($entry['key'] ?? ($entry['code'] ?? ''))));
            $label = trim((string) ($entry['label'] ?? ($entry['name'] ?? $key)));
            $prefix = strtoupper(trim((string) ($entry['prefix'] ?? substr($key, 0, 3))));

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                continue;
            }

            if ($label === '') {
                $label = $key;
            }

            $prefix = strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $prefix) ?: substr($key, 0, 3), 0, 6));
            if ($prefix === '') {
                $prefix = 'CAF';
            }

            $seen[$key] = true;
            $normalized[] = [
                'key' => $key,
                'label' => mb_substr($label, 0, 80),
                'prefix' => $prefix,
            ];
        }

        return $normalized ?: self::defaultCafPatronatoTypes();
    }

    /**
     * @param mixed $statuses
     * @return array<int, array{value:string,label:string,category:string}>
     */
    private function normalizeCafPatronatoStatuses($statuses): array
    {
        if (!is_array($statuses)) {
            return self::defaultCafPatronatoStatuses();
        }

        $normalized = [];
        $seen = [];

        foreach ($statuses as $entry) {
            if (is_string($entry)) {
                $entry = ['value' => $entry, 'label' => $entry];
            }

            if (!is_array($entry)) {
                continue;
            }

            $value = trim((string) ($entry['value'] ?? ($entry['label'] ?? '')));
            $label = trim((string) ($entry['label'] ?? $value));
            $category = strtolower(trim((string) ($entry['category'] ?? '')));

            if ($value === '') {
                continue;
            }

            $hash = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$hash])) {
                continue;
            }

            if ($label === '') {
                $label = $value;
            }

            if (!isset(self::CAF_PATRONATO_STATUS_CATEGORIES[$category])) {
                $category = 'pending';
            }

            $seen[$hash] = true;
            $normalized[] = [
                'value' => mb_substr($value, 0, 80),
                'label' => mb_substr($label, 0, 80),
                'category' => $category,
            ];
        }

        return $normalized ?: self::defaultCafPatronatoStatuses();
    }

    /**
     * @param mixed $services
     * @param array<int,string>|null $allowedKeys
     * @return array<string, array<int, array{name:string,price:float|null}>>
     */
    private function normalizeCafPatronatoServices($services, ?array $allowedKeys = null): array
    {
        if (!is_array($services)) {
            $services = self::defaultCafPatronatoServices();
        }

        $normalized = [];
        $allowedLookup = null;
        if ($allowedKeys) {
            $allowedLookup = [];
            foreach ($allowedKeys as $key) {
                $allowedLookup[strtoupper($key)] = true;
            }
        }

        $input = $services;
        if (!$this->isAssociativeArray($input)) {
            $input = ['CAF' => $services];
        }

        foreach ($input as $typeKey => $rows) {
            $typeKey = strtoupper(trim((string) $typeKey));
            if ($typeKey === '') {
                continue;
            }

            if ($allowedLookup !== null && !isset($allowedLookup[$typeKey])) {
                $fallback = $allowedKeys[0] ?? null;
                if ($fallback !== null) {
                    $typeKey = $fallback;
                }
            }

            if ($allowedLookup !== null && !isset($allowedLookup[$typeKey])) {
                continue;
            }

            if (!is_array($rows)) {
                $rows = [$rows];
            }

            $seen = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $value = $row['name'] ?? ($row['label'] ?? ($row['value'] ?? null));
                } else {
                    $value = $row;
                }

                if (!is_string($value)) {
                    continue;
                }

                $trimmed = trim($value);
                if ($trimmed === '') {
                    continue;
                }

                $hash = mb_strtolower($trimmed, 'UTF-8');
                if (isset($seen[$hash])) {
                    continue;
                }

                $price = null;
                if (is_array($row) && array_key_exists('price', $row)) {
                    $price = $this->sanitizeServicePriceValue($row['price']);
                }

                $seen[$hash] = true;
                $normalized[$typeKey][] = [
                    'name' => mb_substr($trimmed, 0, 120),
                    'price' => $price,
                ];
            }
        }

        foreach ($normalized as $typeKey => $list) {
            if (!$list) {
                continue;
            }
            usort($list, static function (array $a, array $b): int {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });
            $normalized[$typeKey] = array_values($list);
        }

        if ($allowedLookup !== null) {
            foreach ($allowedLookup as $typeKey => $_) {
                if (!isset($normalized[$typeKey])) {
                    $normalized[$typeKey] = [];
                }
            }
        }

        return $normalized ?: self::defaultCafPatronatoServices();
    }

    /**
     * @param mixed $value
     */
    private function sanitizeServicePriceValue($value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $normalized = str_replace(["\xc2\xa0", '€'], '', $value);
            $normalized = trim($normalized);
            if ($normalized === '') {
                return null;
            }
            $hasComma = strpos($normalized, ',') !== false;
            $hasDot = strpos($normalized, '.') !== false;
            if ($hasComma && $hasDot) {
                $normalized = str_replace('.', '', $normalized);
                $normalized = str_replace(',', '.', $normalized);
            } elseif ($hasComma) {
                $normalized = str_replace(',', '.', $normalized);
            }
            $normalized = str_replace(' ', '', $normalized);
            $value = $normalized;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = round((float) $value, 2);
        if (!is_finite($number) || $number < 0) {
            return null;
        }

        return $number;
    }

    /**
     * @param mixed $value
     */
    private function hasServicePricePayload($value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return is_numeric($value);
    }

    private function isAssociativeArray($value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        if ($value === []) {
            return false;
        }

        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function logActivity(int $userId, string $action, array $payload): void
    {
        try {
            $filtered = array_filter(
                $payload,
                static fn($value, $key) => $key !== 'note' && $key !== 'company_logo',
                ARRAY_FILTER_USE_BOTH
            );

            $stmt = $this->pdo->prepare(
                'INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                 VALUES (:user_id, :modulo, :azione, :dettagli, NOW())'
            );

            $stmt->execute([
                ':user_id' => $userId,
                ':modulo' => 'Impostazioni',
                ':azione' => $action,
                ':dettagli' => json_encode($filtered, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) {
            error_log('Activity log failed: ' . $e->getMessage());
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     sender_name:string,
     *     sender_email:string,
     *     reply_to_email:string,
     *     resend_api_key:string,
     *     unsubscribe_base_url:string,
     *     webhook_secret:string,
     *     test_address:string,
     *     has_resend_api_key:bool,
     *     resend_api_key_hint:string
     * }
     */
    private function formatEmailMarketingSettings(array $config, bool $maskSecrets = true): array
    {
        $defaults = [
            'sender_name' => 'Coresuite Business',
            'sender_email' => 'marketing@example.com',
            'reply_to_email' => '',
            'resend_api_key' => '',
            'unsubscribe_base_url' => $this->defaultUnsubscribeBaseUrl(),
            'webhook_secret' => '',
            'test_address' => '',
        ];

        $merged = array_merge($defaults, array_intersect_key($config, $defaults));

        foreach ($merged as $key => $value) {
            if (is_string($value)) {
                $merged[$key] = trim($value);
            }
        }

        if ($merged['unsubscribe_base_url'] !== '') {
            $merged['unsubscribe_base_url'] = rtrim($merged['unsubscribe_base_url'], '/');
        } else {
            $merged['unsubscribe_base_url'] = $defaults['unsubscribe_base_url'];
        }

        $hasKey = $merged['resend_api_key'] !== '';
        $hint = $hasKey ? $this->maskSecret($merged['resend_api_key']) : '';

        if ($maskSecrets && $hasKey) {
            $merged['resend_api_key'] = '';
        }

        $merged['has_resend_api_key'] = $hasKey;
        $merged['resend_api_key_hint'] = $hint;

        return $merged;
    }

    private function defaultUnsubscribeBaseUrl(): string
    {
        $appUrl = function_exists('env') ? (string) env('APP_URL', '') : '';
        if ($appUrl !== '') {
            return rtrim($appUrl, '/');
        }

        if (function_exists('base_url')) {
            return rtrim((string) base_url(), '/');
        }

        return 'https://example.com';
    }

    private function maskSecret(string $secret): string
    {
        $length = strlen($secret);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($secret, -4);
    }
}
