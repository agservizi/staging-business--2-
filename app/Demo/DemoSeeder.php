<?php
declare(strict_types=1);

namespace App\Demo;

use PDO;
use PDOException;
use RuntimeException;

final class DemoSeeder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function refresh(): void
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $this->truncateTables();
            $this->insertDataset();
        } catch (PDOException $exception) {
            throw new RuntimeException('Impossibile completare il seeding demo: ' . $exception->getMessage(), 0, $exception);
        } finally {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function truncateTables(): void
    {
        foreach (DemoDataset::truncateOrder() as $table) {
            $this->pdo->exec(sprintf('TRUNCATE TABLE `%s`', $table));
        }
    }

    private function insertDataset(): void
    {
        foreach (DemoDataset::data() as $table => $rows) {
            if ($rows === []) {
                continue;
            }

            foreach ($rows as $row) {
                $this->insertRow($table, $row);
            }
        }
    }

    /**
     * @param array<string, int|float|string|null> $row
     */
    private function insertRow(string $table, array $row): void
    {
        if ($row === []) {
            return;
        }

        $columns = array_keys($row);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn(string $column): string => sprintf('`%s`', $column), $columns)),
            $placeholders
        );

        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Impossibile preparare la query per la tabella ' . $table);
        }

        if ($statement->execute(array_values($row)) === false) {
            $errorInfo = $statement->errorInfo();
            throw new RuntimeException(sprintf('Inserimento fallito per la tabella %s: %s', $table, $errorInfo[2] ?? 'errore sconosciuto'));
        }
    }
}