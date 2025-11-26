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
    private array $input;

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
        $this->input = $input;
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

        // Salva la conversazione per apprendimento e ottieni ID
        $conversationId = $this->saveConversation($input['user_id'] ?? null, $question, $answer, json_encode($contextLines));

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
            'conversation_id' => $conversationId,
        ];
    }

    private function generateAnswer(string $question, array $period, array $contextLines, array $history): string
    {
        $userId = $this->input['user_id'] ?? null;
        $preferences = $userId ? $this->getUserPreferences($userId) : [];
        $pastInsights = $this->analyzePastConversations($userId, $question);
        $style = $preferences['response_style'] ?? 'dettagliato';
        $questionLower = strtolower($question);

        // Riconoscimento intelligente delle categorie
        $category = $this->classifyQuestion($questionLower);

        switch ($category) {
            case 'clients':
                $answer = $this->answerAboutClients($contextLines, $period, $preferences, $pastInsights, $questionLower);
                break;
            case 'services':
                $answer = $this->answerAboutServices($contextLines, $period, $preferences, $pastInsights, $questionLower);
                break;
            case 'reports':
                $answer = $this->answerAboutReports($contextLines, $period, $preferences, $pastInsights, $questionLower);
                break;
            case 'tickets':
                $answer = $this->answerAboutTickets($contextLines, $period, $preferences, $pastInsights, $questionLower);
                break;
            case 'modules':
                $answer = $this->answerAboutModules($questionLower, $preferences);
                break;
            case 'statistics':
                $answer = $this->answerStatistics($questionLower, $period, $preferences);
                break;
            case 'howto':
                $answer = $this->answerHowTo($questionLower, $preferences);
                break;
            default:
                // Apprendimento da conversazioni passate
                $pastAnswers = $this->getPastAnswers($question);
                if (!empty($pastAnswers)) {
                    $this->updatePreferences($userId, 'frequent_topics', 'general');
                    $answer = "Basandomi sulle tue precedenti interazioni: " . implode(' ', array_slice($pastAnswers, 0, 2));
                } else {
                    // Suggerimento basato su preferenze
                    $suggestedTopic = $preferences['frequent_topics'] ?? 'clienti';
                    $answer = "Non ho informazioni specifiche per questa domanda. Basandomi sulle tue preferenze, potresti essere interessato a: " . $suggestedTopic . ". Puoi fornire più dettagli sui moduli del gestionale che ti interessano?";
                }
                break;
        }

        // Adatta stile
        if ($style === 'conciso') {
            $answer = $this->makeConcise($answer);
        }

        return $answer;
    }

    private function classifyQuestion(string $question): string
    {
        // Usa regex per riconoscimento più intelligente
        if (preg_match('/\b(clienti?|customer|anagrafica)\b/i', $question)) {
            return 'clients';
        }
        if (preg_match('/\b(servizi?|service|logistica)\b/i', $question)) {
            return 'services';
        }
        if (preg_match('/\b(report|statistiche?|kpi|dashboard)\b/i', $question)) {
            return 'reports';
        }
        if (preg_match('/\b(ticket|supporto?|assistenza)\b/i', $question)) {
            return 'tickets';
        }
        if (preg_match('/\b(modulo?|sezione|funzione)\b/i', $question)) {
            return 'modules';
        }
        if (preg_match('/\b(quanti?|numero|conteggio|totale)\b/i', $question)) {
            return 'statistics';
        }
        if (preg_match('/\b(come|come faccio|istruzioni?|guida)\b/i', $question)) {
            return 'howto';
        }
        return 'unknown';
    }

    private function generateThinking(string $question, array $period, array $contextLines): string
    {
        return "Analizzo la domanda '{$question}' nel periodo {$period['label']}. Considero i dati contestuali e le interazioni passate per fornire una risposta personalizzata.";
    }

    private function answerAboutClients(array $contextLines, array $period, array $preferences, array $pastInsights, string $question): string
    {
        $clients = $this->queryClientsData($period);
        $count = count($clients);
        $frequentTopic = $preferences['frequent_topics'] ?? 'clienti';
        $insight = $pastInsights['client_insight'] ?? 'Controlla i contratti in scadenza.';

        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'clienti');

        // Risposta più intelligente basata sulla domanda specifica
        if (preg_match('/\bquanti?\b/i', $question)) {
            return "Hai gestito {$count} clienti nel periodo {$period['label']}. {$insight}";
        }
        if (preg_match('/\bstato\b/i', $question)) {
            $active = count(array_filter($clients, fn($c) => isset($c['stato']) && $c['stato'] === 'attivo'));
            return "Su {$count} clienti totali, {$active} sono attivi. {$insight}";
        }
        return "Nel periodo {$period['label']}, hai gestito {$count} clienti. {$insight} Suggerisco di controllare i contratti in scadenza e le opportunità di upselling.";
    }

    private function answerAboutServices(array $contextLines, array $period, array $preferences, array $pastInsights, string $question): string
    {
        $services = $this->queryServicesData($period);
        $count = count($services);
        $insight = $pastInsights['service_insight'] ?? 'Coordina i follow-up.';

        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'servizi');

        if (preg_match('/\bquanti?\b/i', $question)) {
            return "Hai coordinato {$count} servizi nel periodo {$period['label']}. {$insight}";
        }
        return "Hai coordinato {$count} servizi nel periodo {$period['label']}. {$insight} Priorità: follow-up sui servizi in corso e pianificazione logistica.";
    }

    private function answerAboutReports(array $contextLines, array $period, array $preferences, array $pastInsights, string $question): string
    {
        $insight = $pastInsights['report_insight'] ?? 'Analizza i KPI chiave.';

        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'report');

        if (preg_match('/\bcome\b/i', $question)) {
            return "Per generare report, vai alla sezione Report nel menu laterale. Puoi scegliere tra report operativi, finanziari o personalizzati. {$insight}";
        }
        return "Per la reportistica nel periodo {$period['label']}, puoi scaricare report operativi e finanziari dalla sezione Report. {$insight}";
    }

    private function answerAboutTickets(array $contextLines, array $period, array $preferences, array $pastInsights, string $question): string
    {
        $tickets = $this->queryTicketsData($period);
        $open = count(array_filter($tickets, fn($t) => $t['stato'] === 'aperto'));
        $insight = $pastInsights['ticket_insight'] ?? 'Risolvi entro SLA.';

        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'ticket');

        if (preg_match('/\baperti?\b/i', $question)) {
            return "Hai {$open} ticket aperti nel periodo {$period['label']}. {$insight}";
        }
        return "Hai {$open} ticket aperti nel periodo {$period['label']}. {$insight}";
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
            // Cerca risposte simili basate su parole chiave
            $keywords = preg_split('/\s+/', strtolower($question));
            $likeConditions = [];
            $params = [];
            foreach ($keywords as $keyword) {
                if (strlen($keyword) > 2) { // Ignora parole corte
                    $likeConditions[] = "question LIKE ?";
                    $params[] = '%' . $keyword . '%';
                }
            }
            if (empty($likeConditions)) return [];

            $stmt = $this->pdo->prepare("SELECT answer FROM ai_conversations WHERE (" . implode(' OR ', $likeConditions) . ") AND rating >= 3 ORDER BY created_at DESC LIMIT 3");
            $stmt->execute($params);
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'answer');
        } catch (PDOException $e) {
            return [];
        }
    }

    private function saveConversation(?int $userId, string $question, string $answer, string $context): ?int
    {
        if (!$userId) return null;
        try {
            $stmt = $this->pdo->prepare("INSERT INTO ai_conversations (user_id, session_id, question, answer, context, rating) VALUES (?, ?, ?, ?, ?, NULL)");
            $stmt->execute([$userId, session_id(), $question, $answer, $context]);
            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // Log error silently
            return null;
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

    private function getUserPreferences(?int $userId): array
    {
        if (!$userId) return [];
        try {
            $stmt = $this->pdo->prepare("SELECT preference_key, preference_value FROM ai_user_preferences WHERE user_id = ?");
            $stmt->execute([$userId]);
            $results = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            return $results ?: [];
        } catch (PDOException $e) {
            return [];
        }
    }

    private function updatePreferences(?int $userId, string $key, string $value): void
    {
        if (!$userId) return;
        try {
            $stmt = $this->pdo->prepare("INSERT INTO ai_user_preferences (user_id, preference_key, preference_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE preference_value = VALUES(preference_value)");
            $stmt->execute([$userId, $key, $value]);
        } catch (PDOException $e) {
            // Silently ignore
        }
    }

    private function analyzePastConversations(?int $userId, string $question): array
    {
        if (!$userId) return [];
        try {
            $stmt = $this->pdo->prepare("SELECT question, answer FROM ai_conversations WHERE user_id = ? AND (rating IS NULL OR rating >= 3) ORDER BY created_at DESC LIMIT 10");
            $stmt->execute([$userId]);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $insights = [];
            $clientCount = 0;
            $serviceCount = 0;
            $ticketCount = 0;
            $reportCount = 0;

            foreach ($conversations as $conv) {
                if (strpos(strtolower($conv['question']), 'cliente') !== false) $clientCount++;
                if (strpos(strtolower($conv['question']), 'servizio') !== false) $serviceCount++;
                if (strpos(strtolower($conv['question']), 'ticket') !== false) $ticketCount++;
                if (strpos(strtolower($conv['question']), 'report') !== false) $reportCount++;
            }

            if ($clientCount > $serviceCount && $clientCount > $ticketCount) {
                $insights['client_insight'] = 'Hai mostrato interesse per i clienti recentemente. Considera campagne di fidelizzazione.';
            }
            if ($serviceCount > $clientCount) {
                $insights['service_insight'] = 'Focus sui servizi: ottimizza i processi logistici.';
            }
            if ($ticketCount > 0) {
                $insights['ticket_insight'] = 'Priorità alta sui ticket: assegna risorse per risoluzione rapida.';
            }
            if ($reportCount > 0) {
                $insights['report_insight'] = 'Interesse per report: configura dashboard personalizzate.';
            }

            return $insights;
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Fornisce feedback su una conversazione per migliorare l'apprendimento.
     */
    public function giveFeedback(int $conversationId, int $rating): bool
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('Il rating deve essere tra 1 e 5.');
        }
        try {
            $stmt = $this->pdo->prepare("UPDATE ai_conversations SET rating = ? WHERE id = ?");
            $stmt->execute([$rating, $conversationId]);

            // Adatta preferenze basate su feedback
            if ($rating <= 2) {
                // Rating basso: passa a stile conciso
                $stmt2 = $this->pdo->prepare("SELECT user_id FROM ai_conversations WHERE id = ?");
                $stmt2->execute([$conversationId]);
                $userId = $stmt2->fetchColumn();
                if ($userId) {
                    $this->updatePreferences($userId, 'response_style', 'conciso');
                }
            } elseif ($rating >= 4) {
                // Rating alto: mantieni o passa a dettagliato
                $stmt2 = $this->pdo->prepare("SELECT user_id FROM ai_conversations WHERE id = ?");
                $stmt2->execute([$conversationId]);
                $userId = $stmt2->fetchColumn();
                if ($userId) {
                    $this->updatePreferences($userId, 'response_style', 'dettagliato');
                }
            }

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    private function makeConcise(string $answer): string
    {
        // Rimuovi parti ridondanti per rendere conciso
        $sentences = explode('.', $answer);
        return trim($sentences[0] . '. ' . ($sentences[1] ?? ''));
    }

    private function answerAboutModules(string $question, array $preferences): string
    {
        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'moduli');

        if (preg_match('/\benergia\b/i', $question)) {
            return "Il modulo Energia gestisce i contratti energetici, i reminder automatici e le notifiche. Puoi configurarlo in Impostazioni > Energia.";
        }
        if (preg_match('/\bclienti\b/i', $question)) {
            return "Il modulo Clienti permette di gestire l'anagrafica, i contratti e le comunicazioni. Accedi da Menu > Clienti.";
        }
        if (preg_match('/\bservizi\b/i', $question)) {
            return "Il modulo Servizi coordina logistica, trasporti e follow-up. Trovi tutto in Menu > Servizi.";
        }
        if (preg_match('/\bticket\b/i', $question)) {
            return "Il modulo Ticket gestisce il supporto clienti con priorità e SLA. Vai a Menu > Ticket.";
        }
        return "Il gestionale ha moduli per Clienti, Servizi, Energia, Ticket, Report e altro. Quale modulo ti interessa?";
    }

    private function answerStatistics(string $question, array $period, array $preferences): string
    {
        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'statistiche');

        if (preg_match('/\bclienti\b/i', $question)) {
            $clients = $this->queryClientsData($period);
            $count = count($clients);
            return "Statistiche clienti: {$count} gestiti nel periodo {$period['label']}.";
        }
        if (preg_match('/\bservizi\b/i', $question)) {
            $services = $this->queryServicesData($period);
            $count = count($services);
            return "Statistiche servizi: {$count} coordinati nel periodo {$period['label']}.";
        }
        if (preg_match('/\bticket\b/i', $question)) {
            $tickets = $this->queryTicketsData($period);
            $open = count(array_filter($tickets, fn($t) => $t['stato'] === 'aperto'));
            $total = count($tickets);
            return "Statistiche ticket: {$total} totali, {$open} aperti nel periodo {$period['label']}.";
        }
        return "Per statistiche dettagliate, consulta la sezione Report. Posso aiutarti con statistiche specifiche su clienti, servizi o ticket?";
    }

    private function answerHowTo(string $question, array $preferences): string
    {
        $this->updatePreferences($this->input['user_id'] ?? null, 'frequent_topics', 'istruzioni');

        if (preg_match('/\bcliente\b/i', $question)) {
            return "Per creare un cliente: Vai a Menu > Clienti > Nuovo Cliente. Compila i campi obbligatori (nome, email, telefono) e salva.";
        }
        if (preg_match('/\bservizio\b/i', $question)) {
            return "Per creare un servizio: Menu > Servizi > Nuovo Servizio. Seleziona tipo, cliente e dettagli logistici.";
        }
        if (preg_match('/\bticket\b/i', $question)) {
            return "Per aprire un ticket: Menu > Ticket > Nuovo Ticket. Descrivi il problema e assegna priorità.";
        }
        if (preg_match('/\breport\b/i', $question)) {
            return "Per generare un report: Menu > Report > Seleziona tipo (operativo/finanziario) > Filtra periodo > Esporta.";
        }
        return "Per istruzioni dettagliate, consulta la Guida in linea o contatta il supporto. Quale operazione vuoi imparare?";
    }
}