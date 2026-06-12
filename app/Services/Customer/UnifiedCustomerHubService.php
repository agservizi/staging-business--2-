<?php
declare(strict_types=1);

namespace App\Services\Customer;

use App\Services\CAFPatronato\PracticesService;
use PDO;
use Throwable;

final class UnifiedCustomerHubService
{
    private PDO $pdo;
    private string $projectRoot;

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string,mixed>
     */
    public function buildHub(int $portalCustomerId, string $customerEmail): array
    {
        $email = strtolower(trim($customerEmail));
        $hub = [
            'packages' => $this->packageSummary($portalCustomerId, $customerEmail),
            'brt_shipments' => $this->brtSummary($portalCustomerId),
            'caf_practices' => [],
            'loyalty_points' => 0,
            'next_appointment' => null,
            'tracking_links' => [],
        ];

        $clienteId = $this->resolveClienteIdByEmail($email);
        if ($clienteId > 0) {
            $hub['loyalty_points'] = $this->loyaltyBalance($clienteId);
            $hub['next_appointment'] = $this->nextAppointment($clienteId);
            $hub['caf_practices'] = $this->cafPracticesForCliente($clienteId);
        }

        foreach ($hub['caf_practices'] as $practice) {
            $code = (string) ($practice['tracking_code'] ?? '');
            if ($code !== '') {
                $hub['tracking_links'][] = [
                    'label' => (string) ($practice['titolo'] ?? 'Pratica CAF'),
                    'code' => $code,
                    'url' => function_exists('base_url') ? base_url('tracking.php?code=' . rawurlencode($code)) : ('tracking.php?code=' . rawurlencode($code)),
                ];
            }
        }

        return $hub;
    }

    /**
     * @return array<string,int>
     */
    private function packageSummary(int $customerId, string $customerEmail = ''): array
    {
        try {
            $email = strtolower(trim($customerEmail));
            if ($email !== '') {
                $stmt = $this->pdo->prepare("SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN status IN ('consegnato','in_giacenza') THEN 1 ELSE 0 END) AS ready,
                    SUM(CASE WHEN status = 'in_giacenza_scaduto' THEN 1 ELSE 0 END) AS expired
                    FROM pickup_packages WHERE LOWER(customer_email) = :email");
                $stmt->execute([':email' => $email]);
            } else {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) AS total, 0 AS ready, 0 AS expired FROM pickup_packages WHERE 1=0");
                $stmt->execute();
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            return [
                'total' => (int) ($row['total'] ?? 0),
                'ready' => (int) ($row['ready'] ?? 0),
                'expired' => (int) ($row['expired'] ?? 0),
            ];
        } catch (Throwable) {
            return ['total' => 0, 'ready' => 0, 'expired' => 0];
        }
    }

    /**
     * @return array<string,int>
     */
    private function brtSummary(int $customerId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COUNT(*) AS total FROM pickup_customer_brt_shipments WHERE customer_id = :id');
            $stmt->execute([':id' => $customerId]);
            $total = (int) ($stmt->fetchColumn() ?: 0);

            return ['total' => $total];
        } catch (Throwable) {
            return ['total' => 0];
        }
    }

    private function resolveClienteIdByEmail(string $email): int
    {
        if ($email === '') {
            return 0;
        }

        try {
            $stmt = $this->pdo->prepare('SELECT id FROM clienti WHERE LOWER(email) = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    private function loyaltyBalance(int $clienteId): int
    {
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(SUM(punti),0) FROM fedelta_movimenti WHERE cliente_id = :id');
            $stmt->execute([':id' => $clienteId]);
            return (int) ($stmt->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function nextAppointment(int $clienteId): ?array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, titolo, data_inizio, stato FROM servizi_appuntamenti WHERE cliente_id = :id AND data_inizio >= NOW() ORDER BY data_inizio ASC LIMIT 1");
            $stmt->execute([':id' => $clienteId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            return [
                'id' => (int) $row['id'],
                'titolo' => (string) ($row['titolo'] ?? ''),
                'data' => (string) ($row['data_inizio'] ?? ''),
                'stato' => (string) ($row['stato'] ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function cafPracticesForCliente(int $clienteId): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT id, titolo, stato, tracking_code, data_creazione FROM pratiche WHERE cliente_id = :id ORDER BY data_creazione DESC LIMIT 5');
            $stmt->execute([':id' => $clienteId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'titolo' => (string) ($row['titolo'] ?? ''),
                    'stato' => (string) ($row['stato'] ?? ''),
                    'tracking_code' => (string) ($row['tracking_code'] ?? ''),
                    'data_creazione' => (string) ($row['data_creazione'] ?? ''),
                ];
            }, $rows);
        } catch (Throwable) {
            return [];
        }
    }
}
