<?php
declare(strict_types=1);

namespace App\Services\SystemHealth;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

class SystemDiagnosticsService
{
    private PDO $pdo;

    private string $projectRoot;

    /**
     * @var array<string, array{id:string,label:string,severity:string,description:string,run:callable,fix:(callable|null)}>
     */
    private array $checks = [];

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR) ?: getcwd() ?: '.';
        $this->registerDefaultChecks();
    }

    public function registerCheck(array $definition): void
    {
        $id = isset($definition['id']) ? trim((string) $definition['id']) : '';
        if ($id === '') {
            throw new InvalidArgumentException('ID controllo non valido.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $id)) {
            throw new InvalidArgumentException('L\'ID del controllo può contenere solo lettere, numeri e i caratteri . _ : -');
        }
        if (!isset($definition['run']) || !is_callable($definition['run'])) {
            throw new InvalidArgumentException(sprintf('Il controllo "%s" non ha una callback valida.', $id));
        }

        $this->checks[$id] = [
            'id' => $id,
            'label' => (string) ($definition['label'] ?? $id),
            'severity' => (string) ($definition['severity'] ?? 'info'),
            'description' => (string) ($definition['description'] ?? ''),
            'run' => $definition['run'],
            'fix' => isset($definition['fix']) && is_callable($definition['fix']) ? $definition['fix'] : null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function runAll(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $this->executeCheck($check);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function runSingle(string $id): array
    {
        if (!isset($this->checks[$id])) {
            throw new InvalidArgumentException(sprintf('Controllo "%s" non registrato.', $id));
        }

        return $this->executeCheck($this->checks[$id]);
    }

    /**
     * @return array{fix:array{success:bool,message:string},check:array<string,mixed>}
     */
    public function runFix(string $id): array
    {
        if (!isset($this->checks[$id])) {
            throw new InvalidArgumentException(sprintf('Controllo "%s" non registrato.', $id));
        }

        $check = $this->checks[$id];
        if ($check['fix'] === null) {
            throw new RuntimeException('Nessuna azione di ripristino disponibile per questo controllo.');
        }

        $fixResult = ['success' => true, 'message' => 'Operazione completata.'];
        try {
            $outcome = call_user_func($check['fix'], $this->buildContext());
            if (is_array($outcome)) {
                $fixResult['success'] = isset($outcome['success']) ? (bool) $outcome['success'] : true;
                if (isset($outcome['message'])) {
                    $fixResult['message'] = (string) $outcome['message'];
                }
            }
        } catch (Throwable $exception) {
            $fixResult = [
                'success' => false,
                'message' => 'Ripristino non riuscito: ' . $exception->getMessage(),
            ];
        }

        $newState = $this->executeCheck($check);

        return ['fix' => $fixResult, 'check' => $newState];
    }

    private function registerDefaultChecks(): void
    {
        $this->registerCheck([
            'id' => 'database_connection',
            'label' => 'Connessione database',
            'severity' => 'critical',
            'description' => 'Esegue una query di test per verificare il collegamento al database.',
            'run' => function (): array {
                $start = microtime(true);
                try {
                    $stmt = $this->pdo->query('SELECT 1');
                    if ($stmt === false) {
                        throw new RuntimeException('Query di test non eseguibile.');
                    }
                    $stmt->fetchColumn();
                } catch (Throwable $exception) {
                    return [
                        'status' => 'ko',
                        'details' => 'Connessione assente o non raggiungibile: ' . $exception->getMessage(),
                    ];
                }

                $elapsed = (microtime(true) - $start) * 1000;
                $details = sprintf('Connessione attiva (%.1f ms).', $elapsed);
                $status = $elapsed > 200 ? 'warning' : 'ok';

                return ['status' => $status, 'details' => $details, 'meta' => ['latency_ms' => $elapsed]];
            },
        ]);

        $this->registerCheck([
            'id' => 'storage_health',
            'label' => 'Spazio e permessi archivi',
            'severity' => 'warning',
            'description' => 'Verifica permessi cartelle critiche e spazio libero sul disco.',
            'run' => function (): array {
                $paths = $this->criticalPaths();
                $issues = [];
                foreach ($paths as $label => $path) {
                    if (!is_dir($path)) {
                        $issues[] = sprintf('%s non esiste (%s).', $label, $path);
                        continue;
                    }
                    if (!is_writable($path)) {
                        $issues[] = sprintf('%s non scrivibile (%s).', $label, $path);
                    }
                }

                $freeBytes = @disk_free_space($this->projectRoot) ?: 0;
                $freeGb = $freeBytes > 0 ? $freeBytes / 1_073_741_824 : 0.0;
                $details = sprintf('Spazio libero %.2f GB.', $freeGb);
                $status = 'ok';
                if ($freeGb < 0.25) {
                    $issues[] = 'Spazio inferiore a 250 MB: liberare quanto prima.';
                } elseif ($freeGb < 1) {
                    $status = 'warning';
                }

                if ($issues) {
                    $details = implode(' | ', $issues) . ' ' . $details;
                    $status = 'ko';
                }

                return [
                    'status' => $status,
                    'details' => $details,
                    'meta' => ['free_gb' => round($freeGb, 2)],
                ];
            },
            'fix' => function (): array {
                $paths = $this->criticalPaths();
                $errors = [];
                foreach ($paths as $label => $path) {
                    if (!is_dir($path)) {
                        if (!@mkdir($path, 0775, true)) {
                            $errors[] = sprintf('Impossibile creare %s (%s).', $label, $path);
                            continue;
                        }
                    }
                    if (!is_writable($path)) {
                        if (!@chmod($path, 0775)) {
                            $errors[] = sprintf('Permessi non aggiornati su %s (%s).', $label, $path);
                        }
                    }
                }

                $success = empty($errors);
                $message = $success
                    ? 'Percorsi disponibili e permessi aggiornati (se possibile).'
                    : implode(' | ', $errors);

                return ['success' => $success, 'message' => $message];
            },
        ]);

        $this->registerCheck([
            'id' => 'log_errors',
            'label' => 'Errori recenti nei log',
            'severity' => 'warning',
            'description' => 'Analizza gli ultimi log applicativi alla ricerca di errori.',
            'run' => function (): array {
                $alerts = $this->collectLogAlerts();
                if (!$alerts) {
                    return ['status' => 'ok', 'details' => 'Nessun errore recente individuato.'];
                }

                $samples = array_slice(array_map(static function (array $alert): string {
                    $file = basename($alert['file']);
                    $line = trim($alert['sample']);
                    return sprintf('%s → %s', $file, mb_substr($line, 0, 160));
                }, $alerts), 0, 3);

                return [
                    'status' => 'warning',
                    'details' => implode(' | ', $samples),
                    'meta' => ['alerts' => $alerts],
                ];
            },
            'fix' => function (): array {
                $alerts = $this->collectLogAlerts();
                if (!$alerts) {
                    return ['success' => true, 'message' => 'Nessun log da ripulire.'];
                }

                $archiveDir = $this->projectRoot . '/logs/archive';
                if (!is_dir($archiveDir)) {
                    @mkdir($archiveDir, 0775, true);
                }

                $rotated = [];
                foreach ($alerts as $alert) {
                    $file = $alert['file'];
                    if (!is_file($file)) {
                        continue;
                    }
                    $timestamp = date('Ymd_His');
                    $target = $archiveDir . '/' . basename($file) . '.' . $timestamp;
                    if (@copy($file, $target)) {
                        @file_put_contents($file, '');
                        $rotated[] = basename($file);
                    }
                }

                if (!$rotated) {
                    return ['success' => false, 'message' => 'Nessun log ripulito (permessi insufficienti?).'];
                }

                return ['success' => true, 'message' => 'Log archiviati: ' . implode(', ', $rotated)];
            },
        ]);
    }

    /**
     * @param array{id:string,label:string,severity:string,description:string,run:callable,fix:(callable|null)} $check
     * @return array<string, mixed>
     */
    private function executeCheck(array $check): array
    {
        $status = 'unknown';
        $details = 'Diagnostica non disponibile.';
        $meta = [];

        try {
            $result = call_user_func($check['run'], $this->buildContext());
            if (is_array($result)) {
                $status = $this->normalizeStatus((string) ($result['status'] ?? 'unknown'));
                $details = (string) ($result['details'] ?? $details);
                if (isset($result['meta']) && is_array($result['meta'])) {
                    $meta = $result['meta'];
                }
            }
        } catch (Throwable $exception) {
            $status = 'ko';
            $details = 'Errore durante il controllo: ' . $exception->getMessage();
        }

        return [
            'id' => $check['id'],
            'label' => $check['label'],
            'description' => $check['description'],
            'severity' => $check['severity'],
            'status' => $status,
            'details' => $details,
            'meta' => $meta,
            'can_fix' => $check['fix'] !== null,
        ];
    }

    private function normalizeStatus(string $status): string
    {
        $map = ['ok', 'warning', 'ko'];
        $lower = strtolower(trim($status));
        if (in_array($lower, $map, true)) {
            return $lower;
        }
        if ($lower === 'pass') {
            return 'ok';
        }
        if ($lower === 'fail' || $lower === 'error') {
            return 'ko';
        }

        return 'unknown';
    }

    /**
     * @return array<string, string>
     */
    private function criticalPaths(): array
    {
        return [
            'storage' => $this->projectRoot . '/storage',
            'cache' => $this->projectRoot . '/cache',
            'uploads' => $this->projectRoot . '/assets/uploads',
            'logs' => $this->projectRoot . '/logs',
        ];
    }

    /**
     * @return array<int, array{file:string,sample:string}>
     */
    private function collectLogAlerts(): array
    {
        $directories = [
            $this->projectRoot . '/storage/logs',
            $this->projectRoot . '/logs',
        ];

        $alerts = [];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $files = glob($directory . '/*.log');
            if (!$files) {
                continue;
            }
            foreach ($files as $file) {
                $tail = $this->tailFile($file);
                if ($tail === '') {
                    continue;
                }
                if (preg_match_all('/^.*?(fatal|error|exception).*$/im', $tail, $matches)) {
                    $lines = $matches[0];
                    if (!$lines) {
                        continue;
                    }
                    $alerts[] = [
                        'file' => $file,
                        'sample' => (string) end($lines),
                    ];
                }
            }
        }

        return $alerts;
    }

    private function tailFile(string $path, int $bytes = 4096): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }

        $size = filesize($path);
        if ($size === 0) {
            return '';
        }

        $length = min($bytes, $size);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        if ($length === $size) {
            $data = fread($handle, $length) ?: '';
            fclose($handle);
            return $data;
        }

        fseek($handle, -$length, SEEK_END);
        $data = fread($handle, $length) ?: '';
        fclose($handle);

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(): array
    {
        return [
            'pdo' => $this->pdo,
            'project_root' => $this->projectRoot,
        ];
    }
}
