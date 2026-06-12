<?php
declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\CAFPatronato\PracticesService;
use PDO;
use Throwable;

final class UnifiedNotificationService
{
    private PDO $pdo;
    private string $projectRoot;

    public function __construct(PDO $pdo, string $projectRoot)
    {
        $this->pdo = $pdo;
        $this->projectRoot = rtrim($projectRoot, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,unread:int,lastId:int}
     */
    public function listForStaff(int $userId, string $role, int $sinceId = 0, int $limit = 25): array
    {
        $items = [];
        $items = array_merge($items, $this->pickupReportEvents($sinceId, $limit));
        $items = array_merge($items, $this->openTickets($limit));
        $items = array_merge($items, $this->cafOperatorNotifications($userId, $role, $limit));
        $items = array_merge($items, $this->dueFinanceMovements($limit));
        $items = array_merge($items, $this->storageAlerts($limit));

        usort($items, static function (array $a, array $b): int {
            return strcmp((string) ($b['createdAt'] ?? ''), (string) ($a['createdAt'] ?? ''));
        });

        $items = array_slice($items, 0, $limit);
        $lastId = $sinceId;
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            if ($id > $lastId) {
                $lastId = $id;
            }
        }

        return [
            'items' => $items,
            'unread' => count($items),
            'lastId' => $lastId,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pickupReportEvents(int $sinceId, int $limit): array
    {
        try {
            $sql = 'SELECT r.id, r.tracking_code, r.recipient_name, r.created_at, c.name AS customer_name
                    FROM pickup_customer_reports r
                    LEFT JOIN pickup_customers c ON c.id = r.customer_id
                    WHERE r.id > :since_id
                    ORDER BY r.id DESC
                    LIMIT :limit';
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':since_id', $sinceId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $events[] = [
                    'id' => (int) $row['id'],
                    'source' => 'pickup',
                    'severity' => 'info',
                    'title' => 'Segnalazione pickup',
                    'message' => trim((string) ($row['customer_name'] ?? 'Cliente')) . ' — ' . trim((string) ($row['tracking_code'] ?? 'tracking')),
                    'url' => function_exists('base_url') ? base_url('modules/servizi/logistici/report.php?id=' . (int) $row['id']) : '',
                    'createdAt' => (string) ($row['created_at'] ?? ''),
                ];
            }

            return $events;
        } catch (Throwable $exception) {
            error_log('Unified notifications pickup: ' . $exception->getMessage());
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function openTickets(int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, titolo, stato, created_at FROM ticket WHERE stato IN ('Aperto', 'In corso') ORDER BY created_at DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $events[] = [
                    'id' => 1_000_000 + (int) $row['id'],
                    'source' => 'ticket',
                    'severity' => 'warning',
                    'title' => 'Ticket aperto',
                    'message' => (string) ($row['titolo'] ?? 'Ticket'),
                    'url' => function_exists('base_url') ? base_url('modules/ticket/view.php?id=' . (int) $row['id']) : '',
                    'createdAt' => (string) ($row['created_at'] ?? ''),
                ];
            }

            return $events;
        } catch (Throwable $exception) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function cafOperatorNotifications(int $userId, string $role, int $limit): array
    {
        try {
            $service = new PracticesService($this->pdo, $this->projectRoot);
            $operatorId = $service->findOperatorIdByUser($userId);
            $canViewAll = in_array($role, ['Admin', 'Manager', 'Operatore'], true);
            $rows = $service->listNotifications($userId, $operatorId, $canViewAll);

            $events = [];
            foreach (array_slice($rows, 0, $limit) as $index => $row) {
                $events[] = [
                    'id' => 2_000_000 + (int) ($row['id'] ?? $index),
                    'source' => 'caf',
                    'severity' => 'info',
                    'title' => 'CAF & Patronato',
                    'message' => (string) ($row['message'] ?? $row['titolo'] ?? 'Aggiornamento pratica'),
                    'url' => function_exists('base_url') ? base_url('modules/servizi/caf-patronato/index.php') : '',
                    'createdAt' => (string) ($row['created_at'] ?? $row['data'] ?? ''),
                ];
            }

            return $events;
        } catch (Throwable $exception) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function dueFinanceMovements(int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, descrizione, data_scadenza FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa') AND data_scadenza IS NOT NULL AND data_scadenza <= DATE_ADD(CURDATE(), INTERVAL 3 DAY) ORDER BY data_scadenza ASC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $events[] = [
                    'id' => 3_000_000 + (int) $row['id'],
                    'source' => 'finance',
                    'severity' => 'warning',
                    'title' => 'Scadenza movimento',
                    'message' => (string) ($row['descrizione'] ?? 'Movimento'),
                    'url' => function_exists('base_url') ? base_url('modules/servizi/entrate-uscite/view.php?id=' . (int) $row['id']) : '',
                    'createdAt' => (string) ($row['data_scadenza'] ?? ''),
                ];
            }

            return $events;
        } catch (Throwable $exception) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function storageAlerts(int $limit): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id, tracking, customer_name, updated_at FROM pickup_packages WHERE status = 'in_giacenza_scaduto' ORDER BY updated_at DESC LIMIT :limit");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $events = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $events[] = [
                    'id' => 4_000_000 + (int) $row['id'],
                    'source' => 'pickup_storage',
                    'severity' => 'danger',
                    'title' => 'Giacenza scaduta',
                    'message' => (string) ($row['tracking'] ?? '') . ' — ' . (string) ($row['customer_name'] ?? ''),
                    'url' => function_exists('base_url') ? base_url('modules/servizi/logistici/view.php?id=' . (int) $row['id']) : '',
                    'createdAt' => (string) ($row['updated_at'] ?? ''),
                ];
            }

            return $events;
        } catch (Throwable $exception) {
            return [];
        }
    }
}
