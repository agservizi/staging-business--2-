<?php
declare(strict_types=1);

namespace App\Services\Marketing;

use PDO;
use Throwable;

final class BehaviorSegmentService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<int,array{segment:string,cliente_id:int,email:string,nome:string,score:int,hint:string}>
     */
    public function buildSegments(int $limitPerSegment = 50): array
    {
        $segments = [];
        $segments = array_merge($segments, $this->inactivePickupClients($limitPerSegment));
        $segments = array_merge($segments, $this->cafPendingDocuments($limitPerSegment));
        $segments = array_merge($segments, $this->brtAbandonedPayments($limitPerSegment));

        return $segments;
    }

    /**
     * @return array<int,array{segment:string,cliente_id:int,email:string,nome:string,score:int,hint:string}>
     */
    private function inactivePickupClients(int $limit): array
    {
        try {
            $sql = <<<SQL
SELECT c.id AS cliente_id, c.email, COALESCE(NULLIF(c.ragione_sociale,''), CONCAT(c.nome,' ',c.cognome)) AS nome
FROM clienti c
INNER JOIN pickup_packages p ON LOWER(p.customer_email) = LOWER(c.email)
WHERE c.email IS NOT NULL AND c.email <> ''
GROUP BY c.id, c.email, c.nome, c.cognome, c.ragione_sociale
HAVING MAX(p.updated_at) < DATE_SUB(NOW(), INTERVAL 60 DAY)
ORDER BY MAX(p.updated_at) ASC
LIMIT :limit
SQL;
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn(array $row): array => [
                'segment' => 'pickup_inactive_60d',
                'cliente_id' => (int) ($row['cliente_id'] ?? 0),
                'email' => (string) ($row['email'] ?? ''),
                'nome' => trim((string) ($row['nome'] ?? '')),
                'score' => 70,
                'hint' => 'Cliente pickup senza attività da 60+ giorni',
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,array{segment:string,cliente_id:int,email:string,nome:string,score:int,hint:string}>
     */
    private function cafPendingDocuments(int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT p.id, p.cliente_id, c.email, COALESCE(NULLIF(c.ragione_sociale,''), CONCAT(c.nome,' ',c.cognome)) AS nome
                FROM pratiche p
                INNER JOIN clienti c ON c.id = p.cliente_id
                WHERE p.stato = 'sospesa' AND c.email IS NOT NULL AND c.email <> ''
                ORDER BY p.data_aggiornamento DESC
                LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn(array $row): array => [
                'segment' => 'caf_docs_pending',
                'cliente_id' => (int) ($row['cliente_id'] ?? 0),
                'email' => (string) ($row['email'] ?? ''),
                'nome' => trim((string) ($row['nome'] ?? '')),
                'score' => 90,
                'hint' => 'Pratica CAF in attesa documenti',
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int,array{segment:string,cliente_id:int,email:string,nome:string,score:int,hint:string}>
     */
    private function brtAbandonedPayments(int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT pc.customer_id, c.email, COALESCE(NULLIF(c.ragione_sociale,''), CONCAT(c.nome,' ',c.cognome)) AS nome
                FROM pickup_portal_payments pp
                INNER JOIN pickup_customers pc ON pc.id = pp.customer_id
                INNER JOIN clienti c ON LOWER(c.email) = LOWER(pc.email)
                WHERE pp.status IN ('pending','processing') AND pp.created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
                ORDER BY pp.created_at ASC
                LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            return array_map(static fn(array $row): array => [
                'segment' => 'brt_payment_abandoned',
                'cliente_id' => (int) ($row['customer_id'] ?? 0),
                'email' => (string) ($row['email'] ?? ''),
                'nome' => trim((string) ($row['nome'] ?? '')),
                'score' => 80,
                'hint' => 'Pagamento BRT non completato',
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }
}
