<?php
declare(strict_types=1);

namespace App\Services\Certi;

use App\Services\Certi\Clients\CatastoClient;
use App\Services\Certi\Clients\DocuEngineClient;
use App\Services\Certi\Clients\VisEngineClient;
use App\Services\Certi\CertiApiLogger;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class CertiWorkflowService
{
    private CertiRequestRepository $repository;
    private CertiLogService $logService;
    private DocuEngineClient $docuEngineClient;
    private VisEngineClient $visEngineClient;
    private CatastoClient $catastoClient;
    private CertiApiLogger $apiLogger;
    private PDO $pdo;
    private string $storagePath;
    private string $projectRoot;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(PDO $pdo, array $options = [])
    {
        $this->pdo = $pdo;
        $this->repository = $options['repository'] ?? new CertiRequestRepository($pdo);
        $this->logService = $options['logService'] ?? new CertiLogService($pdo);
        $this->docuEngineClient = $options['docuEngineClient'] ?? new DocuEngineClient();
        $this->visEngineClient = $options['visEngineClient'] ?? new VisEngineClient();
        $this->catastoClient = $options['catastoClient'] ?? new CatastoClient();
        $this->apiLogger = $options['apiLogger'] ?? new CertiApiLogger($pdo);
        $this->projectRoot = rtrim($options['projectRoot'] ?? getcwd(), DIRECTORY_SEPARATOR) ?: getcwd();
        $defaultStorage = $this->projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'certi';
        $storageBase = $options['storagePath'] ?? $defaultStorage;
        $storageBase = $this->normalizeBasePath($storageBase);
        $this->storagePath = rtrim($storageBase, DIRECTORY_SEPARATOR);
        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0775, true) && !is_dir($this->storagePath)) {
            throw new RuntimeException('Impossibile creare la directory certificati: ' . $this->storagePath);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function createRequest(array $payload, int $userId): array
    {
        $this->validatePayload($payload);

        $assigned = $payload['assegnato_a'] ?? $this->resolveNextOperator();
        $payload['assegnato_a'] = $assigned;
        $payload['created_by'] = $userId;
        $payload['updated_by'] = $userId;
        $payload['dati_intestatario'] = $payload['dati_intestatario'] ?? [];

        $this->pdo->beginTransaction();
        try {
            $request = $this->repository->create($payload);
            $this->logService->log((int) $request['id'], 'create', $userId, $this->resolveActorName($userId), [
                'details' => 'Richiesta creata',
                'new' => $request,
            ]);
            $this->pdo->commit();
        } catch (RuntimeException $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        return $request;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function updateRequest(int $id, array $payload, int $userId): array
    {
        $request = $this->repository->findById($id);
        $updated = $this->repository->update($id, array_merge($request, $payload, ['updated_by' => $userId]));
        $this->logService->log($id, 'update', $userId, $this->resolveActorName($userId), [
            'details' => 'Richiesta aggiornata',
            'old' => $request,
            'new' => $updated,
        ]);

        return $updated;
    }

    public function assignOperator(int $id, int $operatorId, int $userId): array
    {
        $request = $this->repository->update($id, [
            'assegnato_a' => $operatorId,
            'updated_by' => $userId,
        ]);
        $this->logService->log($id, 'assign_operator', $userId, $this->resolveActorName($userId), [
            'details' => 'Assegnato a operatore #' . $operatorId,
        ]);

        return $request;
    }

    public function updateStatus(int $id, string $status, int $userId): array
    {
        $valid = ['nuova','in_validazione','in_lavorazione','in_attesa_api','completata','respinta','errore_api'];
        if (!in_array($status, $valid, true)) {
            throw new RuntimeException('Stato richiesta non valido.');
        }

        $completedAt = null;
        if ($status === 'completata') {
            $completedAt = new DateTimeImmutable();
        }

        $request = $this->repository->updateStatus($id, $status, $userId, $completedAt);
        $this->logService->log($id, 'status_change', $userId, $this->resolveActorName($userId), [
            'details' => 'Cambio stato: ' . $status,
        ]);

        return $request;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function submitToProvider(int $id, array $payload, int $userId): array
    {
        $request = $this->repository->findById($id);
        $provider = $this->resolveProvider($request['categoria'], (string) $request['tipo_certificato']);

        try {
            $response = null;
            switch ($provider) {
                case 'docuengine':
                    $response = $this->docuEngineClient->createRequest($payload);
                    $request = $this->repository->update($id, [
                        'docuengine_request_id' => $response['id'] ?? null,
                        'stato' => 'in_attesa_api',
                        'updated_by' => $userId,
                    ]);
                    break;
                case 'visengine':
                    $response = $this->visEngineClient->createRequest($payload);
                    $request = $this->repository->update($id, [
                        'visengine_request_id' => $response['id'] ?? null,
                        'stato' => 'in_attesa_api',
                        'updated_by' => $userId,
                    ]);
                    break;
                case 'catasto':
                    $response = $this->catastoClient->createRequest($payload);
                    $request = $this->repository->update($id, [
                        'catasto_request_id' => $response['id'] ?? null,
                        'stato' => 'in_attesa_api',
                        'updated_by' => $userId,
                    ]);
                    break;
                default:
                    throw new RuntimeException('Provider non supportato per questa richiesta.');
            }
            $this->apiLogger->log($id, $provider, 'create_request', $payload, $response ?? null, null, true, null, 0);
            $this->logService->log($id, 'submit_provider', $userId, $this->resolveActorName($userId), [
                'details' => 'Richiesta inviata a ' . $provider,
                'new' => $request,
            ]);
            return $request;
        } catch (Throwable $exception) {
            $this->apiLogger->log($id, $provider, 'create_request', $payload, null, null, false, $exception->getMessage(), 0);
            throw $exception;
        }
    }

    public function fetchProviderDocument(int $id, int $userId): array
    {
        $request = $this->repository->findById($id);
        $provider = $this->resolveProvider($request['categoria'], (string) $request['tipo_certificato']);
        $providerRequestId = $this->getProviderRequestId($request, $provider);
        if ($providerRequestId === null) {
            throw new RuntimeException('La richiesta non è ancora stata inviata al provider.');
        }

        $response = null;
        switch ($provider) {
            case 'docuengine':
                $response = $this->docuEngineClient->downloadDocument($providerRequestId);
                break;
            case 'visengine':
                $response = $this->visEngineClient->downloadDocument($providerRequestId);
                break;
            case 'catasto':
                $response = $this->catastoClient->downloadDocument($providerRequestId);
                break;
            default:
                throw new RuntimeException('Provider non disponibile per il download.');
        }

        $this->apiLogger->log($id, $provider, 'download_document', [], ['stored' => true], null, true, null, 0);

        $filename = $this->generateFilename($provider . '-document.pdf');
        $content = $response['content'] ?? null;
        if ($content === null || $content === '') {
            throw new RuntimeException('Il provider non ha restituito alcun documento.');
        }

        $path = $this->storeBinary($id, $filename, $content);

        $updated = $this->repository->update($id, [
            'file_certificato' => $path,
            'stato' => 'completata',
            'updated_by' => $userId,
            'completata_il' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->logService->log($id, 'download_document', $userId, $this->resolveActorName($userId), [
            'details' => 'Documento scaricato da ' . $provider,
        ]);

        return $updated;
    }

    /**
     * @param array{name:string,tmp_name:string,error:int,size:int} $file
     */
    public function storeUploadedCertificate(int $id, array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Caricamento documento non riuscito.');
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('File temporaneo non valido.');
        }

        $filename = $this->generateFilename($file['name'] ?? 'certificato.pdf');
        $target = $this->buildStoragePath($id, $filename);
        $this->ensureDirectory(dirname($target));

        if (!@move_uploaded_file($tmp, $target)) {
            if (!@rename($tmp, $target)) {
                throw new RuntimeException('Impossibile salvare il certificato caricato.');
            }
        }

        $relative = $this->relativePath($target);

        $updated = $this->repository->update($id, [
            'file_certificato' => $relative,
            'stato' => 'completata',
            'updated_by' => $userId,
            'completata_il' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        $this->logService->log($id, 'upload_document', $userId, $this->resolveActorName($userId), [
            'details' => 'Documento caricato manualmente',
        ]);

        return $updated;
    }

    public function getCertificateFile(int $id): array
    {
        $request = $this->repository->findById($id);
        $relative = $request['file_certificato'] ?? null;
        if (!$relative) {
            throw new RuntimeException('Nessun certificato disponibile per questa richiesta.');
        }

        $absolute = $this->absoluteFromRelative($relative);
        if (!is_file($absolute)) {
            throw new RuntimeException('Il file del certificato non è più disponibile.');
        }

        return [
            'path' => $absolute,
            'name' => basename($absolute),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function validatePayload(array $payload): void
    {
        if (empty($payload['tipo_certificato'])) {
            throw new RuntimeException('Tipo certificato obbligatorio.');
        }

        $validCategories = ['comunale','camerale','catastale'];
        $categoria = (string) ($payload['categoria'] ?? '');
        if (!in_array($categoria, $validCategories, true)) {
            throw new RuntimeException('Categoria richiesta non valida.');
        }

        if (empty($payload['dati_intestatario']) || !is_array($payload['dati_intestatario'])) {
            throw new RuntimeException('Dati intestatario obbligatori.');
        }
    }

    private function resolveNextOperator(): ?int
    {
        $stmt = $this->pdo->query('SELECT id FROM users WHERE ruolo IN ("Admin","Manager","Operatore") ORDER BY last_login_at DESC LIMIT 1');
        $operatorId = $stmt ? $stmt->fetchColumn() : false;

        return $operatorId !== false ? (int) $operatorId : null;
    }

    private function resolveActorName(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT CONCAT(COALESCE(cognome, \'\'), \' \' , COALESCE(nome, \'\')) AS nome FROM users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $name = (string) $stmt->fetchColumn();

        return trim($name) !== '' ? $name : 'Operatore #' . $userId;
    }

    private function resolveProvider(string $categoria, string $tipo): string
    {
        if ($categoria === 'comunale') {
            return 'docuengine';
        }

        if ($categoria === 'camerale') {
            return 'visengine';
        }

        if ($categoria === 'catastale') {
            return 'catasto';
        }

        throw new RuntimeException('Categoria non supportata');
    }

    /**
     * @param array<string,mixed> $request
     */
    private function getProviderRequestId(array $request, string $provider): ?string
    {
        return match ($provider) {
            'docuengine' => $request['docuengine_request_id'] ?? null,
            'visengine' => $request['visengine_request_id'] ?? null,
            'catasto' => $request['catasto_request_id'] ?? null,
            default => null,
        };
    }

    private function generateFilename(string $original): string
    {
        $name = preg_replace('/[^A-Za-z0-9_\-.]+/', '_', strtolower($original)) ?: 'certificato.pdf';
        return date('Ymd_His') . '_' . $name;
    }

    private function buildStoragePath(int $requestId, string $filename): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . $requestId . DIRECTORY_SEPARATOR . $filename;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossibile creare la directory ' . $directory);
        }
    }

    private function storageDirectory(int $requestId): string
    {
        $dir = $this->storagePath . DIRECTORY_SEPARATOR . $requestId;
        $this->ensureDirectory($dir);
        return $dir;
    }

    private function storeBinary(int $requestId, string $filename, string $content): string
    {
        $dir = $this->storageDirectory($requestId);
        $path = $dir . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Impossibile salvare il documento certificato.');
        }

        return $this->relativePath($path);
    }

    private function relativePath(string $absolute): string
    {
        $normalizedRoot = $this->projectRoot . DIRECTORY_SEPARATOR;
        $prefixLength = strlen($normalizedRoot);
        if (strncmp($absolute, $normalizedRoot, $prefixLength) === 0) {
            return ltrim(substr($absolute, $prefixLength), DIRECTORY_SEPARATOR);
        }

        return $absolute;
    }

    private function absoluteFromRelative(string $relative): string
    {
        $sanitized = ltrim(str_replace(['../', '..\\'], '', $relative), DIRECTORY_SEPARATOR);
        if ($sanitized === '') {
            throw new RuntimeException('Percorso certificato non valido.');
        }

        if (preg_match('#^(?:[A-Za-z]:\\|/)#', $sanitized) === 1) {
            return $sanitized;
        }

        return $this->projectRoot . DIRECTORY_SEPARATOR . $sanitized;
    }

    private function normalizeBasePath(string $path): string
    {
        $trimmed = trim($path);
        if ($trimmed === '') {
            return $this->projectRoot;
        }

        if (preg_match('#^(?:[A-Za-z]:\\|/)#', $trimmed) === 1) {
            return $trimmed;
        }

        return $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($trimmed, DIRECTORY_SEPARATOR);
    }
}
