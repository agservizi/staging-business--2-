<?php
declare(strict_types=1);

namespace App\Services;

use DateInterval;
use DateTimeImmutable;
use JsonException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

use function render_mail_template;

class AppointmentReminderService
{
    private const MODULE_NAME = 'Servizi/Appuntamenti';
    private const REMINDER_ALLOWED_STATUSES = ['Programmato', 'Confermato', 'In corso'];

    private PDO $pdo;

    /** @var callable */
    private $mailer;

    private string $logFile;

    public function __construct(PDO $pdo, callable $mailer, string $logFile)
    {
        $this->pdo = $pdo;
        $this->mailer = $mailer;
        $this->logFile = $logFile;
    }

    /**
     * Dispatch due reminders within the provided grace window.
     *
     * @return array{total:int,sent:int,skipped:int,errors:int,dryRun:bool}
     */
    public function dispatch(int $graceMinutes = 30, bool $dryRun = false): array
    {
        $graceMinutes = max(1, min($graceMinutes, 720));

        $now = new DateTimeImmutable('now');
        $windowStart = $now->sub(new DateInterval('PT' . $graceMinutes . 'M'));

        $appointments = $this->fetchDueAppointments($now, $windowStart);

        $result = [
            'total' => count($appointments),
            'sent' => 0,
            'skipped' => 0,
            'errors' => 0,
            'dryRun' => $dryRun,
        ];

        if (!$appointments) {
            $this->log('Nessun promemoria in scadenza da inviare.');
            return $result;
        }

        foreach ($appointments as $appointment) {
            if ($dryRun) {
                $result['skipped']++;
                $this->log(sprintf(
                    'Dry-run: promemoria pronto per appuntamento #%d programmato il %s.',
                    (int) $appointment['id'],
                    $appointment['data_inizio']
                ));
                continue;
            }

            try {
                $this->sendReminderForAppointment($appointment, $now);
                $result['sent']++;
            } catch (Throwable $exception) {
                $result['errors']++;
                $this->log(sprintf(
                    'Errore durante invio promemoria per appuntamento #%d: %s',
                    (int) $appointment['id'],
                    $exception->getMessage()
                ));
            }
        }

        return $result;
    }

    /**
     * @return array{sentAt:DateTimeImmutable,email:string}
     */
    public function sendReminderNow(int $appointmentId, bool $allowResend = false): array
    {
        $stmt = $this->pdo->prepare('SELECT sa.*, c.email, c.nome, c.cognome, c.ragione_sociale FROM servizi_appuntamenti sa INNER JOIN clienti c ON c.id = sa.cliente_id WHERE sa.id = :id LIMIT 1');
        $stmt->execute([':id' => $appointmentId]);
        /** @var array<string, mixed>|false $appointment */
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) {
            throw new RuntimeException('Appuntamento non trovato.');
        }

        $email = (string) ($appointment['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Il cliente non dispone di un indirizzo email valido.');
        }

        if (!$allowResend && !empty($appointment['reminder_sent_at'])) {
            try {
                $sentAt = new DateTimeImmutable((string) $appointment['reminder_sent_at']);
                $message = 'Promemoria già inviato il ' . $sentAt->format('d/m/Y H:i') . '.';
            } catch (Throwable $exception) {
                $message = 'Promemoria già inviato in precedenza.';
            }
            throw new RuntimeException($message);
        }

        $status = (string) ($appointment['stato'] ?? '');
        if (!in_array($status, self::REMINDER_ALLOWED_STATUSES, true)) {
            throw new RuntimeException('Il promemoria è previsto solo per appuntamenti attivi.');
        }

        $startAtRaw = (string) ($appointment['data_inizio'] ?? '');
        if ($startAtRaw === '') {
            throw new RuntimeException('Data di inizio appuntamento non disponibile.');
        }

        $now = new DateTimeImmutable('now');
        try {
            $startAt = new DateTimeImmutable($startAtRaw);
        } catch (Throwable $exception) {
            throw new RuntimeException('Data di inizio appuntamento non valida.');
        }
        if ($startAt <= $now) {
            throw new RuntimeException('Impossibile inviare un promemoria per un appuntamento già iniziato o concluso.');
        }

        if ($allowResend) {
            $reset = $this->pdo->prepare('UPDATE servizi_appuntamenti SET reminder_sent_at = NULL WHERE id = :id');
            $reset->execute([':id' => $appointmentId]);
            $appointment['reminder_sent_at'] = null;
        }

        $this->sendReminderForAppointment($appointment, $now);

        return [
            'sentAt' => $now,
            'email' => $email,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchDueAppointments(DateTimeImmutable $now, DateTimeImmutable $windowStart): array
    {
        $sql = <<<SQL
SELECT sa.id,
       sa.titolo,
       sa.tipo_servizio,
       sa.responsabile,
       sa.luogo,
       sa.data_inizio,
       sa.data_fine,
       sa.stato,
       c.email,
       c.nome,
       c.cognome,
       c.ragione_sociale
FROM servizi_appuntamenti sa
INNER JOIN clienti c ON c.id = sa.cliente_id
WHERE sa.reminder_sent_at IS NULL
    AND sa.stato IN ('Programmato', 'Confermato', 'In corso')
  AND c.email IS NOT NULL
  AND c.email <> ''
  AND sa.data_inizio > :now
  AND DATE_SUB(sa.data_inizio, INTERVAL 2 HOUR) BETWEEN :window_start AND :now
ORDER BY sa.data_inizio ASC
SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                ':now' => $now->format('Y-m-d H:i:s'),
                ':window_start' => $windowStart->format('Y-m-d H:i:s'),
            ]);
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return $rows ?: [];
        } catch (PDOException $exception) {
            $this->log('Query promemoria fallita: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function sendReminderForAppointment(array $appointment, DateTimeImmutable $now): void
    {
        $email = (string) ($appointment['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Indirizzo email non valido: ' . $email);
        }

        $clientName = $this->buildClientName($appointment);
        $appointmentDate = $this->formatDateTime($appointment['data_inizio'] ?? '');
        $subject = sprintf('Promemoria appuntamento: %s', (string) $appointment['titolo']);

        $contentSections = [];
        $contentSections[] = sprintf(
            '<p>Ciao %s,</p>',
            htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8')
        );
        $contentSections[] = sprintf(
            '<p>ti ricordiamo l\'appuntamento <strong>%s</strong> programmato per <strong>%s</strong>.</p>',
            htmlspecialchars((string) $appointment['titolo'], ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($appointmentDate, ENT_QUOTES, 'UTF-8')
        );

        $details = [];
        $details[] = sprintf('<li><strong>Tipologia:</strong> %s</li>', htmlspecialchars((string) $appointment['tipo_servizio'], ENT_QUOTES, 'UTF-8'));
        if (!empty($appointment['responsabile'])) {
            $details[] = sprintf('<li><strong>Referente:</strong> %s</li>', htmlspecialchars((string) $appointment['responsabile'], ENT_QUOTES, 'UTF-8'));
        }
        if (!empty($appointment['luogo'])) {
            $details[] = sprintf('<li><strong>Luogo:</strong> %s</li>', htmlspecialchars((string) $appointment['luogo'], ENT_QUOTES, 'UTF-8'));
        }
        if ($details) {
            $contentSections[] = '<ul style="padding-left:18px; margin-top:12px;">' . implode('', $details) . '</ul>';
        }
        $contentSections[] = '<p>Per eventuali modifiche o necessit&agrave; puoi rispondere a questa email o contattare il tuo referente.</p>';

        $htmlBody = render_mail_template(
            'Promemoria appuntamento',
            implode("\n", $contentSections)
        );

        $mailer = $this->mailer;
        $sent = $mailer($email, $subject, $htmlBody);
        if (!$sent) {
            throw new RuntimeException('Invio email fallito (mailer ha restituito false).');
        }

        $this->markReminderAsSent((int) $appointment['id'], $now);
        $this->logReminderEvent($appointment, $now);
        $this->log(sprintf(
            'Promemoria inviato a %s per appuntamento #%d (%s).',
            $email,
            (int) $appointment['id'],
            $appointmentDate
        ));
    }

    private function markReminderAsSent(int $appointmentId, DateTimeImmutable $sentAt): void
    {
        $stmt = $this->pdo->prepare('UPDATE servizi_appuntamenti SET reminder_sent_at = :sent_at WHERE id = :id');
        $stmt->execute([
            ':sent_at' => $sentAt->format('Y-m-d H:i:s'),
            ':id' => $appointmentId,
        ]);
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function logReminderEvent(array $appointment, DateTimeImmutable $sentAt): void
    {
        $details = [
            'appuntamento_id' => (int) $appointment['id'],
            'titolo' => (string) $appointment['titolo'],
            'cliente_email' => (string) $appointment['email'],
            'programmato_per' => (string) $appointment['data_inizio'],
            'inviato_il' => $sentAt->format('c'),
        ];

        try {
            $payload = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $payload = json_encode(['errore' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
        }

        try {
            $stmt = $this->pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at) VALUES (NULL, :modulo, :azione, :dettagli, NOW())');
            $stmt->execute([
                ':modulo' => self::MODULE_NAME,
                ':azione' => 'Promemoria inviato',
                ':dettagli' => $payload,
            ]);
        } catch (PDOException $exception) {
            $this->log('Impossibile registrare il log promemoria: ' . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $appointment
     */
    private function buildClientName(array $appointment): string
    {
        $company = trim((string) ($appointment['ragione_sociale'] ?? ''));
        if ($company !== '') {
            return $company;
        }

        $first = trim((string) ($appointment['nome'] ?? ''));
        $last = trim((string) ($appointment['cognome'] ?? ''));
        $full = trim($first . ' ' . $last);
        return $full !== '' ? $full : 'Cliente';
    }

    private function formatDateTime(string $value): string
    {
        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            return $value;
        }

        return $date->format('d/m/Y H:i');
    }

    private function log(string $message): void
    {
        $directory = dirname($this->logFile);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            return;
        }

        $entry = sprintf('[%s] %s%s', date('c'), $message, PHP_EOL);
        file_put_contents($this->logFile, $entry, FILE_APPEND);
    }
}
