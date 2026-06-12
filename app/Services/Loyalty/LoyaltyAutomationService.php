<?php
declare(strict_types=1);

namespace App\Services\Loyalty;

use PDO;
use Throwable;

final class LoyaltyAutomationService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function awardForCompletedPractice(int $clienteId, int $practiceId, string $practiceTitle): bool
    {
        return $this->awardOnce($clienteId, 'caf_practice_' . $practiceId, 50, 'Pratica CAF completata: ' . $practiceTitle);
    }

    public function awardForPickupCompleted(int $clienteId, int $packageId, string $tracking): bool
    {
        return $this->awardOnce($clienteId, 'pickup_' . $packageId, 10, 'Ritiro pacco ' . $tracking);
    }

    public function awardForBrtPayment(int $clienteId, int $shipmentId): bool
    {
        return $this->awardOnce($clienteId, 'brt_' . $shipmentId, 25, 'Spedizione BRT #' . $shipmentId);
    }

    public function awardForVisuraReady(int $clienteId, string $visuraId): bool
    {
        return $this->awardOnce($clienteId, 'visura_' . $visuraId, 15, 'Visura catastale pronta');
    }

    private function awardOnce(int $clienteId, string $reference, int $points, string $note): bool
    {
        if ($clienteId <= 0 || $points <= 0) {
            return false;
        }

        if (!function_exists('recalculate_loyalty_balances')) {
            $helpers = dirname(__DIR__, 3) . '/modules/servizi/fedelta/loyalty_helpers.php';
            if (is_file($helpers)) {
                require_once $helpers;
            }
        }

        try {
            $check = $this->pdo->prepare('SELECT id FROM fedelta_movimenti WHERE cliente_id = :cliente_id AND note = :note LIMIT 1');
            $check->execute([
                ':cliente_id' => $clienteId,
                ':note' => $note,
            ]);
            if ($check->fetchColumn()) {
                return false;
            }

            $stmt = $this->pdo->prepare('INSERT INTO fedelta_movimenti (cliente_id, tipo_movimento, punti, note, data_movimento, saldo_post_movimento) VALUES (:cliente_id, :tipo, :punti, :note, NOW(), 0)');
            $stmt->execute([
                ':cliente_id' => $clienteId,
                ':tipo' => 'Acquisto Servizio',
                ':punti' => $points,
                ':note' => $note,
            ]);

            if (function_exists('recalculate_loyalty_balances')) {
                recalculate_loyalty_balances($this->pdo, $clienteId);
            }

            return true;
        } catch (Throwable $exception) {
            error_log('Loyalty automation failed [' . $reference . ']: ' . $exception->getMessage());
            return false;
        }
    }
}
