<?php
declare(strict_types=1);

namespace App\Services\Automata;

use RuntimeException;

final class AutomataService
{
    private AutomataClient $client;

    public function __construct(?AutomataClient $client = null)
    {
        $this->client = $client ?? new AutomataClient();
    }

    public function isEnabled(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * @param array<string,mixed> $context
     */
    public function assist(string $task, array $context = [], ?string $systemPrompt = null): string
    {
        $system = $systemPrompt ?? 'Sei Automata, assistente AI di Coresuite Business per agenzie CAF, patronato, logistica e servizi. Rispondi in italiano, in modo operativo e conciso.';
        $userPayload = json_encode(['task' => $task, 'context' => $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->client->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => (string) $userPayload],
        ]);
    }

    /**
     * @return array<int,array{key:string,label:string,required:bool}>
     */
    public function suggestCafDocumentChecklist(string $practiceType, string $serviceName, array $existingDocuments = []): array
    {
        if (!$this->isEnabled()) {
            return $this->fallbackCafChecklist($practiceType, $serviceName);
        }

        try {
            $raw = $this->assist('caf_document_checklist', [
                'tipo_pratica' => $practiceType,
                'servizio' => $serviceName,
                'documenti_presenti' => $existingDocuments,
            ], 'Restituisci SOLO JSON array di oggetti {key,label,required} per documenti CAF/Patronato mancanti o consigliati.');

            $decoded = json_decode($this->extractJson($raw), true);
            if (is_array($decoded)) {
                return $this->normalizeChecklist($decoded);
            }
        } catch (RuntimeException $exception) {
            error_log('Automata CAF checklist: ' . $exception->getMessage());
        }

        return $this->fallbackCafChecklist($practiceType, $serviceName);
    }

    /**
     * @return array{days:int,confidence:string,summary:string}
     */
    public function estimatePracticeCompletion(array $historySamples, string $currentStatus, string $practiceType): array
    {
        if (!$historySamples) {
            return ['days' => 7, 'confidence' => 'low', 'summary' => 'Dati storici insufficienti.'];
        }

        if (!$this->isEnabled()) {
            $median = $this->medianDays($historySamples);
            return [
                'days' => $median,
                'confidence' => 'medium',
                'summary' => 'Stima basata sulla mediana storica (' . $median . ' giorni).',
            ];
        }

        try {
            $raw = $this->assist('caf_completion_estimate', [
                'stato' => $currentStatus,
                'tipo' => $practiceType,
                'campioni_giorni' => $historySamples,
            ], 'Restituisci SOLO JSON {days:int,confidence:low|medium|high,summary:string} in italiano.');

            $decoded = json_decode($this->extractJson($raw), true);
            if (is_array($decoded) && isset($decoded['days'])) {
                return [
                    'days' => max(1, (int) $decoded['days']),
                    'confidence' => (string) ($decoded['confidence'] ?? 'medium'),
                    'summary' => (string) ($decoded['summary'] ?? 'Stima generata da Automata.'),
                ];
            }
        } catch (RuntimeException $exception) {
            error_log('Automata completion estimate: ' . $exception->getMessage());
        }

        $median = $this->medianDays($historySamples);
        return ['days' => $median, 'confidence' => 'medium', 'summary' => 'Stima mediana: ' . $median . ' giorni.'];
    }

    /**
     * @return array<int,array{code:string,label:string,score:float}>
     */
    public function suggestHsCodes(string $description, string $destinationCountry = 'IT'): array
    {
        if (trim($description) === '') {
            return [];
        }

        if (!$this->isEnabled()) {
            return [];
        }

        try {
            $raw = $this->assist('brt_hs_code_suggest', [
                'descrizione' => $description,
                'paese' => $destinationCountry,
            ], 'Restituisci SOLO JSON array di {code,label,score} per codici HS doganali BRT.');

            $decoded = json_decode($this->extractJson($raw), true);
            if (!is_array($decoded)) {
                return [];
            }

            $items = [];
            foreach ($decoded as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $code = trim((string) ($row['code'] ?? ''));
                if ($code === '') {
                    continue;
                }
                $items[] = [
                    'code' => $code,
                    'label' => trim((string) ($row['label'] ?? $code)),
                    'score' => (float) ($row['score'] ?? 0.5),
                ];
            }

            return $items;
        } catch (RuntimeException $exception) {
            error_log('Automata HS suggest: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @return array{subject:string,body:string}
     */
    public function draftCustomerMessage(string $channel, string $purpose, array $context): array
    {
        $fallbackSubject = 'Aggiornamento Coresuite';
        $fallbackBody = 'Gentile cliente, abbiamo un aggiornamento per te. Accedi alla tua area clienti per i dettagli.';

        if (!$this->isEnabled()) {
            return ['subject' => $fallbackSubject, 'body' => $fallbackBody];
        }

        try {
            $raw = $this->assist('customer_message_draft', array_merge($context, [
                'canale' => $channel,
                'scopo' => $purpose,
            ]), 'Restituisci SOLO JSON {subject,body} messaggio cliente breve in italiano.');

            $decoded = json_decode($this->extractJson($raw), true);
            if (is_array($decoded)) {
                return [
                    'subject' => trim((string) ($decoded['subject'] ?? $fallbackSubject)),
                    'body' => trim((string) ($decoded['body'] ?? $fallbackBody)),
                ];
            }
        } catch (RuntimeException $exception) {
            error_log('Automata message draft: ' . $exception->getMessage());
        }

        return ['subject' => $fallbackSubject, 'body' => $fallbackBody];
    }

    /**
     * @param array<string,mixed> $documentMeta
     */
    public function analyzeUploadedDocument(array $documentMeta, ?string $extractedText = null): array
    {
        if (!$this->isEnabled()) {
            return [
                'document_type' => 'unknown',
                'confidence' => 0.0,
                'notes' => 'Automata non configurato.',
            ];
        }

        try {
            $response = $this->client->post('/api/v1/vision/analyze', [
                'metadata' => $documentMeta,
                'text' => $extractedText,
            ]);

            return is_array($response['data'] ?? null) ? $response['data'] : $response;
        } catch (RuntimeException $exception) {
            error_log('Automata document analyze: ' . $exception->getMessage());
            return [
                'document_type' => 'unknown',
                'confidence' => 0.0,
                'notes' => $exception->getMessage(),
            ];
        }
    }

    private function extractJson(string $raw): string
    {
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '```')) {
            $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed) ?? $trimmed;
            $trimmed = preg_replace('/\s*```$/', '', $trimmed) ?? $trimmed;
        }

        $start = strpos($trimmed, '{');
        $arrayStart = strpos($trimmed, '[');
        if ($arrayStart !== false && ($start === false || $arrayStart < $start)) {
            $end = strrpos($trimmed, ']');
            if ($end !== false) {
                return substr($trimmed, $arrayStart, $end - $arrayStart + 1);
            }
        }

        if ($start !== false) {
            $end = strrpos($trimmed, '}');
            if ($end !== false) {
                return substr($trimmed, $start, $end - $start + 1);
            }
        }

        return $trimmed;
    }

    /**
     * @return array<int,array{key:string,label:string,required:bool}>
     */
    private function normalizeChecklist(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                $key = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($label)) ?? 'doc';
            }
            $items[] = [
                'key' => $key,
                'label' => $label,
                'required' => (bool) ($row['required'] ?? true),
            ];
        }

        return $items;
    }

    /**
     * @return array<int,array{key:string,label:string,required:bool}>
     */
    private function fallbackCafChecklist(string $practiceType, string $serviceName): array
    {
        $base = [
            ['key' => 'documento_identita', 'label' => 'Documento di identità', 'required' => true],
            ['key' => 'codice_fiscale', 'label' => 'Codice fiscale / tessera sanitaria', 'required' => true],
        ];

        $service = mb_strtolower($serviceName, 'UTF-8');
        if (str_contains($service, 'isee')) {
            $base[] = ['key' => 'redditi', 'label' => 'Modello redditi / CU', 'required' => true];
        }
        if (str_contains($service, '730') || str_contains($service, 'dichiarazione')) {
            $base[] = ['key' => 'cu', 'label' => 'Certificazioni uniche', 'required' => true];
        }
        if (strcasecmp($practiceType, 'Patronato') === 0) {
            $base[] = ['key' => 'delega', 'label' => 'Delega firmata', 'required' => false];
        }

        return $base;
    }

    /**
     * @param array<int,int|float> $samples
     */
    private function medianDays(array $samples): int
    {
        $values = array_values(array_filter(array_map('intval', $samples), static fn(int $v): bool => $v > 0));
        if (!$values) {
            return 7;
        }
        sort($values);
        $count = count($values);
        $middle = (int) floor($count / 2);
        if ($count % 2 === 1) {
            return max(1, $values[$middle]);
        }

        return max(1, (int) round(($values[$middle - 1] + $values[$middle]) / 2));
    }
}
