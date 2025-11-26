<?php
declare(strict_types=1);

namespace App\Services\AI;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class CustomAdvisor
{
    private const DEFAULT_HISTORY_LIMIT = 8;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function generate(array $input): array
    {
        $question = trim((string) ($input['question'] ?? ''));
        if ($question === '') {
            throw new InvalidArgumentException('Inserisci una domanda per l’assistente.');
        }

        $period = $this->resolvePeriod($input);
        $snapshot = $this->buildSnapshot($period);
        $pageMeta = $input['page'] ?? [];
        $contextLines = $this->summarizeSnapshot($snapshot, $period, is_array($pageMeta) ? $pageMeta : []);
        $history = $this->sanitizeHistory($input['history'] ?? []);

        // Genera risposta basata su regole e apprendimento
        $answer = $this->generateAnswer($question, $period, $contextLines, $history);
        $thinking = $this->generateThinking($question, $period, $contextLines);

        // Salva la conversazione per apprendimento
        $this->saveConversation($input['user_id'] ?? null, $question, $answer, json_encode($contextLines));

        $history[] = ['role' => 'user', 'content' => $question];
        $history[] = ['role' => 'assistant', 'content' => $answer];
        $history = $this->sanitizeHistory($history);

        return [
            'answer' => $answer,
            'thinking' => $thinking,
            'period' => $period,
            'snapshot' => $snapshot,
            'contextLines' => $contextLines,
            'history' => $history,
        ];
    }

    private function generateAnswer(string $question, array $period, array $contextLines, array $history): string
    {
        // Logica semplice basata su parole chiave
        $questionLower = strtolower($question);

        if (strpos($questionLower, 'cliente') !== false || strpos($questionLower, 'clienti') !== false) {
            return $this->answerAboutClients($contextLines, $period);
        }

        if (strpos($questionLower, 'servizio') !== false || strpos($questionLower, 'servizi') !== false) {
            return $this->answerAboutServices($contextLines, $period);
        }

        if (strpos($questionLower, 'report') !== false || strpos($questionLower, 'reportistica') !== false) {
            return $this->answerAboutReports($contextLines, $period);
        }

        if (strpos($questionLower, 'ticket') !== false) {
            return $this->answerAboutTickets($contextLines, $period);
        }

        // Apprendimento da conversazioni passate
        $pastAnswers = $this->getPastAnswers($question);
        if (!empty($pastAnswers)) {
            return "Basandomi sulle tue precedenti interazioni: " . implode(' ', array_slice($pastAnswers, 0, 2));
        }

        return "Non ho informazioni specifiche per questa domanda. Puoi fornire più dettagli sui moduli del gestionale che ti interessano?";
    }

    private function generateThinking(string $question, array $period, array $contextLines): string
    {
        return "Analizzo la domanda '{$question}' nel periodo {$period['label']}. Considero i dati contestuali e le interazioni passate per fornire una risposta personalizzata.";
    }

    private function answerAboutClients(array $contextLines, array $period): string
    {
        $clients = $this->queryClientsData($period);
        $count = count($clients);
        return "Nel periodo {$period['label']}, hai gestito {$count} clienti. Suggerisco di controllare i contratti in scadenza e le opportunità di upselling.";
    }

    private function answerAboutServices(array $contextLines, array $period): string
    {
        $services = $this->queryServicesData($period);
        $count = count($services);
        return "Hai coordinato {$count} servizi nel periodo {$period['label']}. Priorità: follow-up sui servizi in corso e pianificazione logistica.";
    }

    private function answerAboutReports(array $contextLines, array $period): string
    {
        return "Per la reportistica nel periodo {$period['label']}, puoi scaricare report operativi e finanziari dalla sezione Report. Analizza trend e KPI chiave.";
    }

    private function answerAboutTickets(array $contextLines, array $period): string
    {
        $tickets = $this->queryTicketsData($period);
        $open = count(array_filter($tickets, fn($t) => $t['stato'] === 'aperto'));
        return "Hai {$open} ticket aperti nel periodo {$period['label']}. Concentrati sulla risoluzione entro SLA.";
    }

    private function queryClientsData(array $period): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, nome, cognome FROM clienti WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    private function queryServicesData(array $period): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, descrizione FROM servizi WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    private function queryTicketsData(array $period): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, stato FROM ticket WHERE created_at BETWEEN ? AND ?");
            $stmt->execute([$period['start']->format('Y-m-d'), $period['end']->format('Y-m-d')]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return [];
        }
    }

    private function getPastAnswers(string $question): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT answer FROM ai_conversations WHERE question LIKE ? ORDER BY created_at DESC LIMIT 3");
            $stmt->execute(['%' . $question . '%']);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'answer');
        } catch (PDOException $e) {
            return [];
        }
    }

    private function saveConversation(?int $userId, string $question, string $answer, string $context): void
    {
        if (!$userId) return;
        try {
            $stmt = $this->pdo->prepare("INSERT INTO ai_conversations (user_id, session_id, question, answer, context) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$userId, session_id(), $question, $answer, $context]);
        } catch (PDOException $e) {
            // Log error silently
        }
    }

    // Copia metodi da ThinkingAdvisor per period, snapshot, etc.
    private function resolvePeriod(array $input): array
    {
        $today = new DateTimeImmutable('today');
        $periodKey = (string) ($input['period'] ?? 'last30');
        $start = $today->modify('-29 days');
        $end = $today->setTime(23, 59, 59);
        $label = 'Ultimi 30 giorni';

        switch ($periodKey) {
            case 'today':
                $start = $today->setTime(0, 0, 0);
                $end = $today->setTime(23, 59, 59);
                $label = 'Oggi';
                break;
            case 'last7':
                $start = $today->modify('-6 days')->setTime(0, 0, 0);
                $label = 'Ultimi 7 giorni';
                break;
            case 'thisMonth':
                $start = $today->modify('first day of this month')->setTime(0, 0, 0);
                $label = 'Mese in corso';
                break;
            case 'lastMonth':
                $start = $today->modify('first day of last month')->setTime(0, 0, 0);
                $end = $today->modify('last day of last month')->setTime(23, 59, 59);
                $label = 'Mese precedente';
                break;
            case 'thisQuarter':
                $start = $today->modify('first day of this quarter')->setTime(0, 0, 0);
                $label = 'Trimestre in corso';
                break;
            case 'year':
                $start = $today->modify('first day of january this year')->setTime(0, 0, 0);
                $label = 'Anno in corso';
                break;
            case 'custom':
                $customStart = $input['customStart'] ?? null;
                $customEnd = $input['customEnd'] ?? null;
                if ($customStart && $customEnd) {
                    $start = new DateTimeImmutable($customStart);
                    $end = new DateTimeImmutable($customEnd);
                    $label = 'Periodo personalizzato';
                }
                break;
        }

        $days = (int) $start->diff($end)->format('%a') + 1;

        return [
            'key' => $periodKey,
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'days' => $days,
        ];
    }

    private function buildSnapshot(array $period): array
    {
        // Implementa snapshot simile a ThinkingAdvisor
        return [
            'clients' => $this->queryClientsData($period),
            'services' => $this->queryServicesData($period),
            'tickets' => $this->queryTicketsData($period),
        ];
    }

    private function summarizeSnapshot(array $snapshot, array $period, array $pageMeta): array
    {
        $lines = [];
        $lines[] = "Periodo: {$period['label']} ({$period['days']} giorni)";
        $lines[] = "Clienti gestiti: " . count($snapshot['clients']);
        $lines[] = "Servizi coordinati: " . count($snapshot['services']);
        $lines[] = "Ticket: " . count($snapshot['tickets']);
        return $lines;
    }

    private function sanitizeHistory(array $history): array
    {
        $sanitized = [];
        foreach ($history as $item) {
            if (is_array($item) && isset($item['role'], $item['content'])) {
                $sanitized[] = [
                    'role' => (string) $item['role'],
                    'content' => trim((string) $item['content']),
                ];
            }
        }
        return array_slice($sanitized, -self::DEFAULT_HISTORY_LIMIT);
    }
}