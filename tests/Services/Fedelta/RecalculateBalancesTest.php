<?php
declare(strict_types=1);

namespace PHPUnit\Framework {
    if (!class_exists(TestCase::class)) {
        abstract class TestCase
        {
            public function assertSame($expected, $actual, string $message = ''): void {}
            public function assertCount(int $expected, $haystack, string $message = ''): void {}
        }
    }
}

namespace Tests\Services\Fedelta {

use PDO;
use PHPUnit\Framework\TestCase;

final class RecalculateBalancesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../modules/servizi/fedelta/loyalty_helpers.php';
    }

    public function testRecalculateBalancesProducesRunningTotals(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->createTable($pdo);

        $insert = $pdo->prepare('INSERT INTO fedelta_movimenti (cliente_id, punti, saldo_post_movimento, data_movimento) VALUES (:cliente_id, :punti, 0, :data)');

        $insert->execute([':cliente_id' => 1, ':punti' => 10, ':data' => '2025-01-01 09:00:00']);
        $insert->execute([':cliente_id' => 1, ':punti' => 5, ':data' => '2025-01-02 10:00:00']);
        $insert->execute([':cliente_id' => 1, ':punti' => -3, ':data' => '2025-01-03 11:30:00']);
        $insert->execute([':cliente_id' => 2, ':punti' => 8, ':data' => '2025-01-04 08:45:00']);

        recalculate_loyalty_balances($pdo, 1);

        $rows = $pdo->query('SELECT punti, saldo_post_movimento FROM fedelta_movimenti WHERE cliente_id = 1 ORDER BY data_movimento ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(3, $rows);
        $this->assertSame([10, 5, -3], array_map('intval', array_column($rows, 'punti')));
        $this->assertSame([10, 15, 12], array_map('intval', array_column($rows, 'saldo_post_movimento')));

        $otherRows = $pdo->query('SELECT saldo_post_movimento FROM fedelta_movimenti WHERE cliente_id = 2')
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([0], array_map('intval', $otherRows));
    }

    /**
     * @throws PDOException
     */
    private function createTable(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE fedelta_movimenti (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cliente_id INTEGER NOT NULL,
            punti INTEGER NOT NULL,
            saldo_post_movimento INTEGER DEFAULT 0,
            ricompensa TEXT DEFAULT NULL,
            operatore TEXT DEFAULT NULL,
            data_movimento TEXT NOT NULL
        )');
    }
}

}
