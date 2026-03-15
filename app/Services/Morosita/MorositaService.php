<?php
declare(strict_types=1);

namespace App\Services\Morosita;

use PDO;
use RuntimeException;

final class MorositaService
{
    private const MAX_DEFAULT_BATCH = 50;

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Valuta e salva lo score per un cliente partendo dal CF/P.IVA.
     *
     * @return array{customer_id:int,tax_code:string,score:string,flag:int,note:string|null,fonte:string,metrics:array<string,int|float|null>}
     */
    public function evaluateAndPersistByTaxCode(string $taxCode, ?int $userId = null, string $fonte = 'verifica-manuale', ?string $forcedScore = null, ?string $noteOverride = null, ?array $manualMetrics = null, ?int $providerId = null): array
    {
        $normalized = $this->normalizeTaxCode($taxCode);
        if ($normalized === '') {
            throw new RuntimeException('Codice fiscale/partita IVA mancante.');
        }

        $customerId = $this->ensureCustomerExists($normalized);
        if ($customerId === null) {
            throw new RuntimeException('Cliente non trovato o non creato.');
        }

        $metrics = null;
        if (is_array($manualMetrics)) {
            $metrics = $this->sanitizeMetrics($manualMetrics);
        }

        if ($forcedScore !== null) {
            $score = $this->validateScore($forcedScore);
            $note = $noteOverride !== null && $noteOverride !== '' ? $noteOverride : 'Override manuale';
            $result = $this->persistResult($customerId, $normalized, $score, $fonte, $note, $userId, $metrics ?? [
                'pending_count' => null,
                'overdue_count' => null,
                'overdue_amount' => null,
                'max_overdue_days' => null,
            ]);
            if ($providerId !== null) {
                $this->persistProviderResult($customerId, $providerId, $score, $note, $fonte);
            }

            return $result;
        }

        if ($metrics === null) {
            $metrics = $this->calculateMetrics($customerId);
        }
        $score = $this->calculateScoreFromMetrics($metrics);
        $note = $this->buildNoteFromMetrics($metrics, $score);

        $result = $this->persistResult($customerId, $normalized, $score, $fonte, $note, $userId, $metrics);
        if ($providerId !== null) {
            $this->persistProviderResult($customerId, $providerId, $score, $note, $fonte);
        }

        return $result;
    }

    /**
     * Aggiorna in batch i clienti con morosità scaduta.
     *
     * @return array{processed:int}
     */
    public function refreshStale(int $staleDays = 30, int $limit = self::MAX_DEFAULT_BATCH): array
    {
        $staleDays = max(1, $staleDays);
        $limit = max(1, $limit);

        $stmt = $this->pdo->prepare(
            'SELECT id, cf_piva FROM clienti
             WHERE (morosita_aggiornato_il IS NULL OR morosita_aggiornato_il < DATE_SUB(NOW(), INTERVAL :days DAY))
             ORDER BY (morosita_aggiornato_il IS NULL) DESC, morosita_aggiornato_il ASC, id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':days', $staleDays, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $processed = 0;
        foreach ($rows as $row) {
            $customerId = (int) ($row['id'] ?? 0);
            $taxCode = (string) ($row['cf_piva'] ?? '');
            if ($customerId <= 0 || $taxCode === '') {
                continue;
            }

            try {
                $this->evaluateAndPersistByTaxCode($taxCode, null, 'morosita-job');
                $processed++;
            } catch (RuntimeException) {
                continue;
            }
        }

        return ['processed' => $processed];
    }

    private function calculateMetrics(int $customerId): array
    {
        $pendingStmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS pending_count,
                SUM(importo * quantita) AS pending_amount
             FROM entrate_uscite
             WHERE cliente_id = :id
               AND data_pagamento IS NULL
               AND stato NOT IN ('Completato', 'Pagato', 'Rimborsato')"
        );
        $pendingStmt->execute([':id' => $customerId]);
        $pending = $pendingStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $overdueStmt = $this->pdo->prepare(
            "SELECT
                COUNT(*) AS overdue_count,
                SUM(importo * quantita) AS overdue_amount,
                MAX(DATEDIFF(CURDATE(), data_scadenza)) AS max_overdue_days
             FROM entrate_uscite
             WHERE cliente_id = :id
               AND data_pagamento IS NULL
               AND data_scadenza IS NOT NULL
               AND data_scadenza < CURDATE()
               AND stato NOT IN ('Completato', 'Pagato', 'Rimborsato')"
        );
        $overdueStmt->execute([':id' => $customerId]);
        $overdue = $overdueStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'pending_count' => (int) ($pending['pending_count'] ?? 0),
            'pending_amount' => (float) ($pending['pending_amount'] ?? 0.0),
            'overdue_count' => (int) ($overdue['overdue_count'] ?? 0),
            'overdue_amount' => (float) ($overdue['overdue_amount'] ?? 0.0),
            'max_overdue_days' => $overdue['max_overdue_days'] !== null ? (int) $overdue['max_overdue_days'] : 0,
        ];
    }

    private function calculateScoreFromMetrics(array $metrics): string
    {
        $overdueCount = $metrics['overdue_count'] ?? 0;
        $overdueAmount = $metrics['overdue_amount'] ?? 0.0;
        $maxDelay = $metrics['max_overdue_days'] ?? 0;
        $pendingCount = $metrics['pending_count'] ?? 0;

        $overdueAmountBlock = (float) env('MOROSITA_OVERDUE_AMOUNT_BLOCK', 500);
        $overdueCountBlock = (int) env('MOROSITA_OVERDUE_COUNT_BLOCK', 3);
        $maxDelayBlock = (int) env('MOROSITA_MAX_DELAY_BLOCK', 60);

        $pendingWarn = (int) env('MOROSITA_PENDING_COUNT_WARN', 2);
        $maxDelayWarn = (int) env('MOROSITA_MAX_DELAY_WARN', 15);

        if ($overdueAmount >= $overdueAmountBlock || $overdueCount >= $overdueCountBlock || $maxDelay >= $maxDelayBlock) {
            return 'bloccato';
        }

        if ($overdueCount > 0 || $pendingCount >= $pendingWarn || $maxDelay >= $maxDelayWarn) {
            return 'attenzione';
        }

        return 'ok';
    }

    private function buildNoteFromMetrics(array $metrics, string $score): string
    {
        $parts = [];

        if (($metrics['overdue_count'] ?? 0) > 0) {
            $parts[] = 'Pendenze scadute: ' . (int) $metrics['overdue_count'] . ' (totale € ' . number_format((float) ($metrics['overdue_amount'] ?? 0), 2, ',', '.') . ')';
        }
        if (($metrics['pending_count'] ?? 0) > 0 && ($metrics['overdue_count'] ?? 0) === 0) {
            $parts[] = 'Pendenze aperte: ' . (int) $metrics['pending_count'];
        }
        if (($metrics['max_overdue_days'] ?? 0) > 0) {
            $parts[] = 'Ritardo massimo: ' . (int) $metrics['max_overdue_days'] . ' giorni';
        }

        if (!$parts) {
            $parts[] = 'Nessuna pendenza rilevata';
        }

        if ($score === 'ok') {
            $parts[] = 'Cliente regolare';
        }

        return implode(' · ', $parts);
    }

    /**
     * @param array<string,int|float|string|null> $metrics
     * @return array{pending_count:int,pending_amount:float,overdue_count:int,overdue_amount:float,max_overdue_days:int}
     */
    private function sanitizeMetrics(array $metrics): array
    {
        return [
            'pending_count' => (int) ($metrics['pending_count'] ?? 0),
            'pending_amount' => (float) ($metrics['pending_amount'] ?? 0),
            'overdue_count' => (int) ($metrics['overdue_count'] ?? 0),
            'overdue_amount' => (float) ($metrics['overdue_amount'] ?? 0),
            'max_overdue_days' => (int) ($metrics['max_overdue_days'] ?? 0),
        ];
    }

    private function persistResult(int $customerId, string $taxCode, string $score, string $fonte, ?string $note, ?int $userId, array $metrics): array
    {
        $flag = $score === 'ok' ? 0 : 1;
        $note = $note === '' ? null : $note;
        $fonte = $fonte === '' ? 'verifica-manuale' : $fonte;

        $update = $this->pdo->prepare(
            'UPDATE clienti
             SET morosita_flag = :flag,
                 morosita_score = :score,
                 morosita_note = :note,
                 morosita_fonte = :fonte,
                 morosita_aggiornato_il = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->bindValue(':flag', $flag, PDO::PARAM_INT);
        $update->bindValue(':score', $score, PDO::PARAM_STR);
        $update->bindValue(':note', $note, $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $update->bindValue(':fonte', $fonte, PDO::PARAM_STR);
        $update->bindValue(':id', $customerId, PDO::PARAM_INT);
        $update->execute();

        $log = $this->pdo->prepare(
            'INSERT INTO customer_morosita_logs (customer_id, user_id, esito, fonte, note)
             VALUES (:customer, :user, :esito, :fonte, :note)'
        );
        $log->bindValue(':customer', $customerId, PDO::PARAM_INT);
        $log->bindValue(':user', $userId ?? null, $userId !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
        $log->bindValue(':esito', $score, PDO::PARAM_STR);
        $log->bindValue(':fonte', $fonte, PDO::PARAM_STR);
        $log->bindValue(':note', $note, $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $log->execute();

        return [
            'customer_id' => $customerId,
            'tax_code' => $taxCode,
            'score' => $score,
            'flag' => $flag,
            'note' => $note,
            'fonte' => $fonte,
            'metrics' => $metrics,
        ];
    }

    private function persistProviderResult(int $customerId, int $providerId, string $score, ?string $note, string $fonte): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO customer_morosita_providers (customer_id, provider_id, esito, note, fonte, updated_at)
             VALUES (:customer, :provider, :esito, :note, :fonte, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE esito = VALUES(esito), note = VALUES(note), fonte = VALUES(fonte), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bindValue(':customer', $customerId, PDO::PARAM_INT);
        $stmt->bindValue(':provider', $providerId, PDO::PARAM_INT);
        $stmt->bindValue(':esito', $score, PDO::PARAM_STR);
        $stmt->bindValue(':note', $note, $note === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':fonte', $fonte, PDO::PARAM_STR);
        $stmt->execute();
    }

    private function validateScore(string $score): string
    {
        $score = strtolower(trim($score));
        $allowed = ['ok', 'attenzione', 'bloccato'];
        if (!in_array($score, $allowed, true)) {
            throw new RuntimeException('Valore score non valido.');
        }
        return $score;
    }

    private function ensureCustomerExists(string $taxCode): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM clienti WHERE UPPER(cf_piva) = :cf LIMIT 1');
        $stmt->execute([':cf' => $taxCode]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO clienti (ragione_sociale, nome, cognome, cf_piva, morosita_flag, morosita_score, morosita_aggiornato_il, morosita_fonte)
             VALUES (:ragione, :nome, :cognome, :cf, 0, "ok", CURRENT_TIMESTAMP, :fonte)'
        );
        $insert->bindValue(':ragione', '', PDO::PARAM_STR);
        $insert->bindValue(':nome', '', PDO::PARAM_STR);
        $insert->bindValue(':cognome', '', PDO::PARAM_STR);
        $insert->bindValue(':cf', $taxCode, PDO::PARAM_STR);
        $insert->bindValue(':fonte', 'inserimento-automatico', PDO::PARAM_STR);

        if (!$insert->execute()) {
            return null;
        }

        return (int) $this->pdo->lastInsertId();
    }

    private function normalizeTaxCode(string $value): string
    {
        return strtoupper(trim($value));
    }
}
