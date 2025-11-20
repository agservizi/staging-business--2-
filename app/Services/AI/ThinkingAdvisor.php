<?php
declare(strict_types=1);

namespace App\Services\AI;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

final class ThinkingAdvisor
{
    private const DEFAULT_HISTORY_LIMIT = 8;

    private PDO $pdo;
    private OpenRouterClient $client;

    public function __construct(PDO $pdo, ?OpenRouterClient $client = null)
    {
        $this->pdo = $pdo;
        $this->client = $client ?? new OpenRouterClient();
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
        $contextLines = $this->summarizeSnapshot($snapshot, $period);
        $history = $this->sanitizeHistory($input['history'] ?? []);

        $systemPrompt = $this->buildSystemPrompt($period);
        $userPrompt = $this->buildUserPrompt($question, $period, $contextLines, $input);

        $messages = array_merge([
            ['role' => 'system', 'content' => $systemPrompt],
        ], $history, [
            ['role' => 'user', 'content' => $userPrompt],
        ]);

        $response = $this->requestWithFallback($messages, [
            'temperature' => 0.35,
            'top_p' => 0.9,
        ]);

        $answer = $this->extractAnswer($response);
        $thinking = $this->extractThinking($response);

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

    /**
     * @param array<string, mixed> $input
     * @return array{key:string,label:string,start:DateTimeImmutable,end:DateTimeImmutable,days:int}
     */
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
                $month = (int) $today->format('n');
                $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3) + 1;
                $start = $today->setDate((int) $today->format('Y'), $quarterStartMonth, 1)->setTime(0, 0, 0);
                $label = 'Trimestre in corso';
                break;
            case 'year':
                $start = $today->setDate((int) $today->format('Y'), 1, 1)->setTime(0, 0, 0);
                $label = 'Anno in corso';
                break;
            case 'custom':
                $customStart = trim((string) ($input['customStart'] ?? ''));
                $customEnd = trim((string) ($input['customEnd'] ?? ''));
                if ($customStart === '' || $customEnd === '') {
                    throw new InvalidArgumentException('Per il periodo personalizzato servono data di inizio e fine.');
                }
                $start = new DateTimeImmutable($customStart . ' 00:00:00');
                $end = new DateTimeImmutable($customEnd . ' 23:59:59');
                $label = sprintf('Periodo personalizzato %s - %s', $start->format('d/m/Y'), $end->format('d/m/Y'));
                break;
            default:
                break;
        }

        if ($start > $end) {
            [$start, $end] = [$end->setTime(0, 0, 0), $start->setTime(23, 59, 59)];
        }

        $days = (int) $start->diff($end)->format('%a');
        $days = max(1, $days + 1);

        return [
            'key' => $periodKey,
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'days' => $days,
        ];
    }

    /**
     * @param array{start:DateTimeImmutable,end:DateTimeImmutable} $period
     * @return array<string, mixed>
     */
    private function buildSnapshot(array $period): array
    {
        $bounds = [
            ':start' => $period['start']->format('Y-m-d H:i:s'),
            ':end' => $period['end']->format('Y-m-d H:i:s'),
        ];

        return [
            'finance' => $this->collectFinance($bounds),
            'operations' => $this->collectOperations($bounds),
            'support' => $this->collectSupport($bounds),
            'marketing' => $this->collectMarketing($bounds),
            'risks' => $this->collectRisks($bounds, $period),
        ];
    }

    /**
     * @param array<string, string> $bounds
     * @return array<string, mixed>
     */
    private function collectFinance(array $bounds): array
    {
        $totals = [
            'entrate' => 0.0,
            'uscite' => 0.0,
            'movimenti' => 0,
            'inLavorazione' => 0,
            'overdue' => 0,
            'overdueValue' => 0.0,
            'topClients' => [],
        ];

        try {
            $stmt = $this->pdo->prepare('SELECT
                SUM(CASE WHEN tipo_movimento = "Entrata" THEN importo ELSE 0 END) AS entrate,
                SUM(CASE WHEN tipo_movimento = "Uscita" THEN importo ELSE 0 END) AS uscite,
                COUNT(*) AS totale,
                SUM(CASE WHEN stato IN ("In lavorazione", "In attesa") THEN 1 ELSE 0 END) AS pending
            FROM entrate_uscite
            WHERE COALESCE(data_pagamento, data_scadenza, updated_at, created_at) BETWEEN :start AND :end');
            $stmt->execute($bounds);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totals['entrate'] = (float) ($row['entrate'] ?? 0);
            $totals['uscite'] = (float) ($row['uscite'] ?? 0);
            $totals['movimenti'] = (int) ($row['totale'] ?? 0);
            $totals['inLavorazione'] = (int) ($row['pending'] ?? 0);
        } catch (PDOException $exception) {
            error_log('AI advisor finance totals failed: ' . $exception->getMessage());
        }

        try {
            $dueStmt = $this->pdo->prepare('SELECT
                COUNT(*) AS overdue_count,
                SUM(importo) AS overdue_value
            FROM entrate_uscite
            WHERE stato IN ("In lavorazione", "In attesa")
              AND tipo_movimento = "Entrata"
              AND data_scadenza IS NOT NULL
              AND data_scadenza < CURRENT_DATE()
              AND COALESCE(data_pagamento, created_at) BETWEEN :start AND :end');
            $dueStmt->execute($bounds);
            $due = $dueStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $totals['overdue'] = (int) ($due['overdue_count'] ?? 0);
            $totals['overdueValue'] = (float) ($due['overdue_value'] ?? 0);
        } catch (PDOException $exception) {
            error_log('AI advisor overdue totals failed: ' . $exception->getMessage());
        }

        try {
            $clientsStmt = $this->pdo->prepare("SELECT
                COALESCE(NULLIF(c.ragione_sociale, ''), NULLIF(CONCAT(TRIM(c.nome), ' ', TRIM(c.cognome)), ''), 'Cliente non assegnato') AS cliente,
                SUM(CASE WHEN eu.tipo_movimento = 'Entrata' THEN eu.importo ELSE 0 END) AS entrate,
                SUM(CASE WHEN eu.tipo_movimento = 'Uscita' THEN eu.importo ELSE 0 END) AS uscite
            FROM entrate_uscite eu
            LEFT JOIN clienti c ON c.id = eu.cliente_id
            WHERE COALESCE(eu.data_pagamento, eu.updated_at, eu.created_at) BETWEEN :start AND :end
            GROUP BY cliente
            ORDER BY (entrate - uscite) DESC
            LIMIT 5");
            $clientsStmt->execute($bounds);
            $totals['topClients'] = array_map(static function (array $row): array {
                $entrate = (float) ($row['entrate'] ?? 0);
                $uscite = (float) ($row['uscite'] ?? 0);
                return [
                    'cliente' => (string) ($row['cliente'] ?? 'Cliente non assegnato'),
                    'entrate' => $entrate,
                    'uscite' => $uscite,
                    'netto' => $entrate - $uscite,
                ];
            }, $clientsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        } catch (PDOException $exception) {
            error_log('AI advisor top clients failed: ' . $exception->getMessage());
        }

        return $totals;
    }

    /**
     * @param array<string, string> $bounds
     * @return array<string, mixed>
     */
    private function collectOperations(array $bounds): array
    {
        $summary = [
            'appointments' => [
                'totals' => 0,
                'statuses' => [],
                'next' => null,
            ],
            'energy' => [
                'created' => 0,
                'statuses' => [],
            ],
            'logistics' => [
                'spedizioni' => 0,
                'brt' => 0,
                'issues' => 0,
            ],
        ];

        try {
            $appStmt = $this->pdo->prepare('SELECT stato, COUNT(*) AS totale
                FROM servizi_appuntamenti
                WHERE data_inizio BETWEEN :start AND :end
                GROUP BY stato');
            $appStmt->execute($bounds);
            $statuses = $appStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $summary['appointments']['statuses'] = array_map(static function (array $row): array {
                return [
                    'stato' => (string) ($row['stato'] ?? 'N/D'),
                    'totale' => (int) ($row['totale'] ?? 0),
                ];
            }, $statuses);
            $summary['appointments']['totals'] = array_reduce($summary['appointments']['statuses'], static function (int $carry, array $item): int {
                return $carry + $item['totale'];
            }, 0);

            $nextStmt = $this->pdo->prepare('SELECT titolo, data_inizio, stato
                FROM servizi_appuntamenti
                WHERE data_inizio >= NOW()
                ORDER BY data_inizio ASC
                LIMIT 1');
            $nextStmt->execute();
            $summary['appointments']['next'] = $nextStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $exception) {
            error_log('AI advisor appointments failed: ' . $exception->getMessage());
        }

        try {
            $energyStmt = $this->pdo->prepare('SELECT stato, COUNT(*) AS totale
                FROM energia_contratti
                WHERE created_at BETWEEN :start AND :end
                GROUP BY stato');
            $energyStmt->execute($bounds);
            $energyStatuses = $energyStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $summary['energy']['statuses'] = array_map(static function (array $row): array {
                return [
                    'stato' => (string) ($row['stato'] ?? 'N/D'),
                    'totale' => (int) ($row['totale'] ?? 0),
                ];
            }, $energyStatuses);
            $summary['energy']['created'] = array_reduce($summary['energy']['statuses'], static function (int $carry, array $item): int {
                return $carry + $item['totale'];
            }, 0);
        } catch (PDOException $exception) {
            error_log('AI advisor energy stats failed: ' . $exception->getMessage());
        }

        try {
            $spedStmt = $this->pdo->prepare('SELECT COUNT(*) FROM spedizioni WHERE created_at BETWEEN :start AND :end');
            $spedStmt->execute($bounds);
            $summary['logistics']['spedizioni'] = (int) $spedStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor spedizioni stats failed: ' . $exception->getMessage());
        }

        try {
            $brtStmt = $this->pdo->prepare('SELECT COUNT(*) FROM brt_shipments WHERE COALESCE(created_at, confirmed_at) BETWEEN :start AND :end');
            $brtStmt->execute($bounds);
            $summary['logistics']['brt'] = (int) $brtStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor BRT stats failed: ' . $exception->getMessage());
        }

        try {
            $issuesStmt = $this->pdo->prepare("SELECT COUNT(*) FROM spedizioni WHERE stato IN ('Problema','In attesa di ritiro')");
            $issuesStmt->execute();
            $summary['logistics']['issues'] = (int) $issuesStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor logistics issues failed: ' . $exception->getMessage());
        }

        return $summary;
    }

    /**
     * @param array<string, string> $bounds
     * @return array<string, mixed>
     */
    private function collectSupport(array $bounds): array
    {
        $stats = [
            'open' => 0,
            'created' => 0,
            'oldestOpen' => null,
        ];

        try {
            $openStmt = $this->pdo->query("SELECT COUNT(*) FROM ticket WHERE stato IN ('Aperto','In corso')");
            if ($openStmt) {
                $stats['open'] = (int) $openStmt->fetchColumn();
            }
        } catch (PDOException $exception) {
            error_log('AI advisor open ticket count failed: ' . $exception->getMessage());
        }

        try {
            $createdStmt = $this->pdo->prepare('SELECT COUNT(*) FROM ticket WHERE created_at BETWEEN :start AND :end');
            $createdStmt->execute($bounds);
            $stats['created'] = (int) $createdStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor ticket period stats failed: ' . $exception->getMessage());
        }

        try {
            $oldestStmt = $this->pdo->query("SELECT id, titolo, created_at FROM ticket WHERE stato IN ('Aperto','In corso') ORDER BY created_at ASC LIMIT 1");
            if ($oldestStmt) {
                $stats['oldestOpen'] = $oldestStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (PDOException $exception) {
            error_log('AI advisor oldest ticket failed: ' . $exception->getMessage());
        }

        return $stats;
    }

    /**
     * @param array<string, string> $bounds
     * @return array<string, mixed>
     */
    private function collectMarketing(array $bounds): array
    {
        $marketing = [
            'campaigns' => [
                'scheduled' => 0,
                'sent' => 0,
            ],
            'subscribers' => [
                'new' => 0,
                'inactive' => 0,
            ],
        ];

        try {
            $scheduledStmt = $this->pdo->prepare("SELECT COUNT(*) FROM email_campaigns WHERE status = 'scheduled' AND COALESCE(scheduled_at, created_at) BETWEEN :start AND :end");
            $scheduledStmt->execute($bounds);
            $marketing['campaigns']['scheduled'] = (int) $scheduledStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor scheduled campaign stats failed: ' . $exception->getMessage());
        }

        try {
            $sentStmt = $this->pdo->prepare("SELECT COUNT(*) FROM email_campaign_recipients WHERE status = 'sent' AND COALESCE(sent_at, updated_at) BETWEEN :start AND :end");
            $sentStmt->execute($bounds);
            $marketing['campaigns']['sent'] = (int) $sentStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor sent email stats failed: ' . $exception->getMessage());
        }

        try {
            $newSubStmt = $this->pdo->prepare("SELECT COUNT(*) FROM email_subscribers WHERE status = 'active' AND created_at BETWEEN :start AND :end");
            $newSubStmt->execute($bounds);
            $marketing['subscribers']['new'] = (int) $newSubStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor new subscriber stats failed: ' . $exception->getMessage());
        }

        try {
            $inactiveStmt = $this->pdo->prepare("SELECT COUNT(*) FROM email_subscribers WHERE status = 'unsubscribed' AND updated_at BETWEEN :start AND :end");
            $inactiveStmt->execute($bounds);
            $marketing['subscribers']['inactive'] = (int) $inactiveStmt->fetchColumn();
        } catch (PDOException $exception) {
            error_log('AI advisor inactive subscriber stats failed: ' . $exception->getMessage());
        }

        return $marketing;
    }

    /**
     * @param array<string, string> $bounds
     * @param array{end:DateTimeImmutable} $period
     * @return array<string, mixed>
     */
    private function collectRisks(array $bounds, array $period): array
    {
        $risks = [
            'overduePayments' => [],
            'deadlines' => [],
        ];

        try {
            $overdueStmt = $this->pdo->prepare('SELECT id, descrizione, importo, data_scadenza, stato
                FROM entrate_uscite
                WHERE stato IN ("In lavorazione", "In attesa")
                  AND tipo_movimento = "Entrata"
                  AND data_scadenza IS NOT NULL
                  AND data_scadenza < NOW()
                  AND COALESCE(created_at, data_scadenza) BETWEEN :start AND :end
                ORDER BY data_scadenza ASC
                LIMIT 5');
            $overdueStmt->execute($bounds);
            $risks['overduePayments'] = $overdueStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $exception) {
            error_log('AI advisor overdue list failed: ' . $exception->getMessage());
        }

        try {
            $deadlineStmt = $this->pdo->prepare('SELECT id, descrizione, data_scadenza, stato
                FROM entrate_uscite
                WHERE stato IN ("In lavorazione", "In attesa")
                  AND data_scadenza BETWEEN CURRENT_DATE() AND DATE_ADD(CURRENT_DATE(), INTERVAL 7 DAY)
                ORDER BY data_scadenza ASC
                LIMIT 5');
            $deadlineStmt->execute();
            $risks['deadlines'] = $deadlineStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $exception) {
            error_log('AI advisor deadlines failed: ' . $exception->getMessage());
        }

        return $risks;
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array{label:string,days:int,start:DateTimeImmutable,end:DateTimeImmutable} $period
     * @return array<int, string>
     */
    private function summarizeSnapshot(array $snapshot, array $period): array
    {
        $lines = [];
        $finance = $snapshot['finance'] ?? [];
        $operations = $snapshot['operations'] ?? [];
        $support = $snapshot['support'] ?? [];
        $marketing = $snapshot['marketing'] ?? [];
        $risks = $snapshot['risks'] ?? [];

        $entrate = (float) ($finance['entrate'] ?? 0.0);
        $uscite = (float) ($finance['uscite'] ?? 0.0);
        $saldo = $entrate - $uscite;
        $lines[] = sprintf(
            '%s (%d giorni) | Entrate %s - Uscite %s = Saldo %s su %d movimenti',
            $period['label'],
            $period['days'],
            $this->formatMoney($entrate),
            $this->formatMoney($uscite),
            $this->formatMoney($saldo),
            (int) ($finance['movimenti'] ?? 0)
        );

        if ((int) ($finance['inLavorazione'] ?? 0) > 0) {
            $lines[] = sprintf('Movimenti in lavorazione: %d (di cui %d scaduti per %s).',
                (int) $finance['inLavorazione'],
                (int) ($finance['overdue'] ?? 0),
                $this->formatMoney((float) ($finance['overdueValue'] ?? 0.0))
            );
        }

        $topClients = $finance['topClients'] ?? [];
        if ($topClients) {
            $clientSummary = array_map(function (array $client): string {
                return sprintf('%s (%s)', $client['cliente'], $this->formatMoney((float) $client['netto']));
            }, $topClients);
            $lines[] = 'Clienti che generano più margine: ' . implode(', ', $clientSummary) . '.';
        }

        $appointments = $operations['appointments'] ?? [];
        if (($appointments['totals'] ?? 0) > 0) {
            $statusPieces = array_map(static function (array $item): string {
                return sprintf('%s: %d', $item['stato'], $item['totale']);
            }, $appointments['statuses'] ?? []);
            $lines[] = 'Appuntamenti nel periodo: ' . implode(', ', $statusPieces) . '.';
        }

        $energyCreated = (int) ($operations['energy']['created'] ?? 0);
        if ($energyCreated > 0) {
            $lines[] = sprintf('Contratti energia creati: %d.', $energyCreated);
        }

        $logistics = $operations['logistics'] ?? [];
        if (($logistics['spedizioni'] ?? 0) > 0 || ($logistics['brt'] ?? 0) > 0) {
            $lines[] = sprintf('Logistica: %d spedizioni interne, %d spedizioni BRT, %d criticità aperte.',
                (int) ($logistics['spedizioni'] ?? 0),
                (int) ($logistics['brt'] ?? 0),
                (int) ($logistics['issues'] ?? 0)
            );
        }

        if (($support['open'] ?? 0) > 0) {
            $lines[] = sprintf('Ticket aperti: %d (nuovi nel periodo: %d).',
                (int) ($support['open'] ?? 0),
                (int) ($support['created'] ?? 0)
            );
        }

        $campaigns = $marketing['campaigns'] ?? [];
        if (($campaigns['scheduled'] ?? 0) > 0 || ($campaigns['sent'] ?? 0) > 0) {
            $lines[] = sprintf('Marketing: %d campagne programmate, %d invii completati.',
                (int) ($campaigns['scheduled'] ?? 0),
                (int) ($campaigns['sent'] ?? 0)
            );
        }

        $risksList = $risks['overduePayments'] ?? [];
        if ($risksList) {
            $lines[] = sprintf('Avvisi: %d incassi scaduti da recuperare.', count($risksList));
        }

        return array_values(array_filter($lines, static fn(string $line): bool => trim($line) !== ''));
    }

    private function buildSystemPrompt(array $period): string
    {
        return sprintf(
            "Sei l'assistente operativo di Coresuite Business. Analizza i dati del CRM per il periodo %s (%s-%s) e restituisci consigli attuabili in italiano. Priorità: cash-flow, operatività, marketing, assistenza clienti. Evidenzia rischi o opportunità con tono professionale ma concreto. Se mancano dati sii trasparente e suggerisci quali informazioni servono.",
            $period['label'],
            $period['start']->format('d/m/Y'),
            $period['end']->format('d/m/Y')
        );
    }

    /**
     * @param array<int,string> $contextLines
     * @param array<string, mixed> $input
     */
    private function buildUserPrompt(string $question, array $period, array $contextLines, array $input): string
    {
        $pageContextLine = $this->formatPageContextLine($input['page'] ?? []);
        $contextLinesExtended = $contextLines;
        if ($pageContextLine !== '') {
            array_unshift($contextLinesExtended, $pageContextLine);
        }

        $focus = trim((string) ($input['focus'] ?? ''));
        if ($focus === '') {
            $focus = $this->inferFocusFromPage($input['page'] ?? []);
        }

        $context = $contextLinesExtended ? "\nContesto sintetico:\n- " . implode("\n- ", $contextLinesExtended) : '';
        $focusLine = $focus !== '' ? "\nFocalizzati anche su: {$focus}." : '';

        return sprintf(
            "Domanda: %s%s%s\nProduci un piano d'azione breve con punti numerati, priorità (Alta/Media/Bassa) e metriche consigliate per monitorare i progressi.",
            $question,
            $context,
            $focusLine
        );
    }

    private function formatPageContextLine($page): string
    {
        if (!is_array($page)) {
            return '';
        }

        $title = trim((string) ($page['title'] ?? ''));
        $section = trim((string) ($page['section'] ?? ''));
        $description = trim((string) ($page['description'] ?? ''));
        $path = trim((string) ($page['path'] ?? ''));

        $parts = [];
        if ($section !== '') {
            $parts[] = $section;
        }
        if ($title !== '') {
            $parts[] = $title;
        }
        if ($path !== '') {
            $parts[] = sprintf('(%s)', $path);
        }

        $header = $parts ? implode(' ', $parts) : '';
        if ($description !== '') {
            return trim($header . ' - ' . $description);
        }

        return $header;
    }

    private function inferFocusFromPage($page): string
    {
        if (!is_array($page)) {
            return '';
        }

        $section = strtolower(trim((string) ($page['section'] ?? '')));
        return match ($section) {
            'clienti' => 'Suggerisci azioni concrete su anagrafiche clienti, follow-up commerciali e prevenzione churn.',
            'servizi' => 'Ottimizza erogazione servizi, logistica e coordinamento operativo con focus su SLA.',
            'reportistica' => 'Aiuta a interpretare KPI e creare insight azionabili dai report in esame.',
            'ticket' => 'Prioritizza la risoluzione ticket e migliora la customer experience.',
            'email marketing' => 'Concentrati su contenuti, segmentazioni e metriche campagne marketing.',
            'documenti' => 'Mantieni la governance documentale e segnala rischi di compliance.',
            'impostazioni' => 'Verifica configurazioni critiche, ruoli e sicurezza applicativa.',
            'customer portal' => 'Supporta i clienti finali nelle procedure self-service e monitora le richieste.',
            'tools' => 'Raccomanda verifiche tecniche o operazioni di manutenzione coerenti con lo strumento aperto.',
            default => '',
        };
    }

    /**
     * @param array<int, array{role:string,content:string}>|mixed $history
     * @return array<int, array{role:string,content:string}>
     */
    private function sanitizeHistory($history): array
    {
        if (!is_array($history)) {
            return [];
        }

        $filtered = [];
        foreach ($history as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = $message['role'] ?? null;
            $content = $message['content'] ?? null;
            if (!is_string($role) || !is_string($content)) {
                continue;
            }
            $role = trim($role);
            $content = trim($content);
            if ($role === '' || $content === '') {
                continue;
            }
            if (!in_array($role, ['user', 'assistant', 'system'], true)) {
                continue;
            }
            $filtered[] = [
                'role' => $role,
                'content' => mb_substr($content, 0, 2000),
            ];
        }

        if (count($filtered) > self::DEFAULT_HISTORY_LIMIT) {
            $filtered = array_slice($filtered, -self::DEFAULT_HISTORY_LIMIT);
        }

        return $filtered;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractAnswer(array $response): string
    {
        $choices = $response['choices'] ?? null;
        if (!is_array($choices) || !isset($choices[0]['message'])) {
            throw new RuntimeException('Risposta OpenRouter priva di messaggi utili.');
        }

        $message = $choices[0]['message'];
        $content = (string) ($message['content'] ?? '');
        if ($content === '') {
            throw new RuntimeException('Contenuto testo vuoto dalla AI.');
        }

        $content = preg_replace('/<think>[\s\S]*?<\/think>\s*/i', '', $content) ?? $content;
        return trim($content);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractThinking(array $response): ?string
    {
        $choices = $response['choices'] ?? null;
        if (!is_array($choices) || !isset($choices[0]['message'])) {
            return null;
        }

        $message = $choices[0]['message'];
        $thinking = $message['thinking'] ?? null;
        if (is_string($thinking) && trim($thinking) !== '') {
            return trim($thinking);
        }

        $content = (string) ($message['content'] ?? '');
        if ($content !== '' && preg_match('/<think>([\s\S]*?)<\/think>/i', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function formatMoney(float $amount): string
    {
        $formatter = numfmt_create('it_IT', \NumberFormatter::CURRENCY);
        if ($formatter === false) {
            return '€ ' . number_format($amount, 2, ',', '.');
        }

        $formatted = numfmt_format_currency($formatter, $amount, 'EUR');
        if ($formatted === false) {
            return '€ ' . number_format($amount, 2, ',', '.');
        }

        return $formatted;
    }

    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestWithFallback(array $messages, array $options): array
    {
        $models = $this->resolveModelChain($options['model'] ?? null);
        $lastRateException = null;

        foreach ($models as $model) {
            try {
                $payload = $options;
                $payload['model'] = $model;
                return $this->client->chat($messages, $payload);
            } catch (RuntimeException $exception) {
                if (!$this->isRateLimitException($exception)) {
                    throw $exception;
                }
                $lastRateException = $exception;
            }
        }

        if ($lastRateException !== null) {
            throw $lastRateException;
        }

        return $this->client->chat($messages, $options);
    }

    /**
     * @return array<int, string>
     */
    private function resolveModelChain(?string $preferred): array
    {
        $candidates = [];
        $primary = trim((string) ($preferred ?? $this->client->getDefaultModel()));
        if ($primary !== '') {
            $candidates[] = $primary;
        }

        $fallbackRaw = (string) env('OPENROUTER_FALLBACK_MODELS', '');
        if ($fallbackRaw !== '') {
            $parts = preg_split('/[\n,]+/', $fallbackRaw) ?: [];
            foreach ($parts as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
            }
        }

        return array_values(array_unique($candidates));
    }

    private function isRateLimitException(RuntimeException $exception): bool
    {
        $message = strtolower($exception->getMessage());
        return str_contains($message, '429')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'rate-limit')
            || str_contains($message, 'temporarily')
            || str_contains($message, 'limite');
    }
}
