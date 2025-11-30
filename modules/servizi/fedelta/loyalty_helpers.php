<?php

if (!function_exists('recalculate_loyalty_balances')) {
    /**
     * Recalculate running balance for loyalty movements of a given customer.
     */
    function recalculate_loyalty_balances(PDO $pdo, int $clienteId): void
    {
        $movementsStmt = $pdo->prepare('SELECT id, punti FROM fedelta_movimenti WHERE cliente_id = :cliente_id ORDER BY data_movimento ASC, id ASC');
        $movementsStmt->execute([':cliente_id' => $clienteId]);
        $movements = $movementsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$movements) {
            return;
        }

        $runningTotal = 0;
        $updateStmt = $pdo->prepare('UPDATE fedelta_movimenti SET saldo_post_movimento = :saldo WHERE id = :id');

        foreach ($movements as $movement) {
            $runningTotal += (int) $movement['punti'];
            $updateStmt->execute([
                ':saldo' => $runningTotal,
                ':id' => (int) $movement['id'],
            ]);
        }
    }
}

if (!function_exists('loyalty_movement_types')) {
    /**
     * Central registry of movement types used across the loyalty module.
     *
     * @return array<string, array{label: string, direction: string}>
     */
    function loyalty_movement_types(): array
    {
        return [
            'Acquisto Servizio' => ['label' => 'Acquisto servizio', 'direction' => 'credit'],
            'Referral' => ['label' => 'Referral', 'direction' => 'credit'],
            'Rinnovo' => ['label' => 'Rinnovo', 'direction' => 'credit'],
            'Riscatto Promozione' => ['label' => 'Riscatto promozione', 'direction' => 'debit'],
            'Riscatto Consulenza' => ['label' => 'Riscatto consulenza', 'direction' => 'debit'],
        ];
    }
}

if (!function_exists('loyalty_movement_direction')) {
    /**
     * Retrieve the earning/redeeming direction for a given movement type.
     */
    function loyalty_movement_direction(string $movementType): string
    {
        $types = loyalty_movement_types();
        return $types[$movementType]['direction'] ?? 'credit';
    }
}

if (!function_exists('loyalty_fetch_client_balances')) {
    /**
     * Return the current points balance indexed by client id.
     *
     * @return array<int, int>
     */
    function loyalty_fetch_client_balances(PDO $pdo): array
    {
        $balancesStmt = $pdo->query('SELECT cliente_id, COALESCE(SUM(punti), 0) AS saldo FROM fedelta_movimenti GROUP BY cliente_id');

        $balances = [];
        if ($balancesStmt) {
            while ($row = $balancesStmt->fetch(PDO::FETCH_ASSOC)) {
                $clientId = isset($row['cliente_id']) ? (int) $row['cliente_id'] : 0;
                if ($clientId > 0) {
                    $balances[$clientId] = (int) ($row['saldo'] ?? 0);
                }
            }
        }

        return $balances;
    }
}

if (!function_exists('loyalty_format_points')) {
    /**
     * Utility formatter used to keep UI consistent in PHP generated snippets.
     */
    function loyalty_format_points(int $points): string
    {
        return number_format($points, 0, ',', '.');
    }
}

if (!function_exists('current_operator_label')) {
    /**
     * Return the display label for the current operator.
     */
    function current_operator_label(): string
    {
        $displayName = $_SESSION['display_name'] ?? null;
        if ($displayName) {
            return $displayName;
        }

        $username = $_SESSION['username'] ?? null;
        if ($username) {
            return (string) $username;
        }

        $email = $_SESSION['email'] ?? null;
        if ($email) {
            return (string) $email;
        }

        return 'Sistema';
    }
}
