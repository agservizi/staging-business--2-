<?php
http_response_code(410);
exit;
__halt_compiler();

use PDO;
use RuntimeException;

final class SpreadsheetPresetService
{
    private const VISIBILITY_PRIVATE = 'private';
    private const VISIBILITY_ROLE = 'role';
    private const VISIBILITY_GLOBAL = 'global';

    /**
     * Handsontable filter operators allowed.
     * @var array<int,string>
     */
    private const ALLOWED_OPERATORS = ['contains', 'starts_with', 'ends_with', 'eq', 'neq', 'gt', 'lt'];

    /**
     * CRM roles available in the platform.
     * @var array<int,string>
     */
    private const KNOWN_ROLES = ['Admin', 'Manager', 'Operatore', 'Patronato', 'Cliente'];

    /**
     * Roles allowed to manage shared presets.
     * @var array<int,string>
     */
    private const SHARE_ROLES = ['Admin', 'Manager'];

    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listPresets(?int $sheetId, int $userId, string $userRole): array
    {
        $conditions = [];
        $params = [
            ':user_id' => $userId,
            ':user_role' => $userRole,
        ];

        if ($sheetId !== null && $sheetId > 0) {
            $conditions[] = '(spreadsheet_id IS NULL OR spreadsheet_id = :sheet_id)';
            $params[':sheet_id'] = $sheetId;
        } else {
            $conditions[] = 'spreadsheet_id IS NULL';
        }

        $conditions[] = "(
            visibility = :vis_global
            OR (visibility = :vis_role AND (allowed_roles IS NULL OR allowed_roles = '' OR FIND_IN_SET(:user_role, allowed_roles)))
            OR (visibility = :vis_private AND owner_id = :user_id)
        )";

        $params[':vis_global'] = self::VISIBILITY_GLOBAL;
        $params[':vis_role'] = self::VISIBILITY_ROLE;
        $params[':vis_private'] = self::VISIBILITY_PRIVATE;

        $sql = 'SELECT * FROM office_spreadsheet_presets WHERE ' . implode(' AND ', $conditions)
            . ' ORDER BY visibility DESC, name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $presets = [];
        foreach ($rows as $row) {
            $presets[] = $this->mapPreset($row, $userId, $userRole);
        }

        return $presets;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function savePreset(array $payload, int $userId, string $userRole): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $visibility = $this->normalizeVisibility((string) ($payload['visibility'] ?? self::VISIBILITY_PRIVATE));
        $sheetId = isset($payload['sheet_id']) ? (int) $payload['sheet_id'] : null;
        $allowedRoles = $visibility === self::VISIBILITY_ROLE
            ? $this->normalizeRoleList($payload['allowed_roles'] ?? null)
            : null;
        $columns = $this->normalizeStringArray($payload['columns'] ?? null, true);
        $tags = $this->normalizeStringArray($payload['tags'] ?? null, false);
        $filters = $this->normalizeFilters($payload['filters'] ?? null);

        if ($sheetId !== null && $sheetId <= 0) {
            $sheetId = null;
        }

        if ($name === '') {
            throw new RuntimeException('Il nome del preset è obbligatorio.');
        }

        if ($visibility !== self::VISIBILITY_PRIVATE && !$this->userCanShare($userRole)) {
            throw new RuntimeException('Non hai i permessi per creare preset condivisi.');
        }

        if ($visibility === self::VISIBILITY_ROLE && !$allowedRoles) {
            throw new RuntimeException('Specificare almeno un ruolo per i preset condivisi con il team.');
        }

        $allowedRolesValue = $allowedRoles ? implode(',', $allowedRoles) : null;
        $columnsJson = $columns ? json_encode($columns, JSON_UNESCAPED_UNICODE) : null;
        $tagsJson = $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null;
        $filtersJson = $filters ? json_encode($filters, JSON_UNESCAPED_UNICODE) : null;

        $stmt = $this->pdo->prepare(
            'INSERT INTO office_spreadsheet_presets (spreadsheet_id, name, owner_id, visibility, allowed_roles, filters, columns, tags, created_by, updated_by) '
            . 'VALUES (:sheet_id, :name, :owner_id, :visibility, :allowed_roles, :filters, :columns, :tags, :created_by, :updated_by)'
        );

        $stmt->execute([
            ':sheet_id' => $sheetId,
            ':name' => $name,
            ':owner_id' => $userId > 0 ? $userId : null,
            ':visibility' => $visibility,
            ':allowed_roles' => $allowedRolesValue,
            ':filters' => $filtersJson,
            ':columns' => $columnsJson,
            ':tags' => $tagsJson,
            ':created_by' => $userId > 0 ? $userId : null,
            ':updated_by' => $userId > 0 ? $userId : null,
        ]);

        $presetId = (int) $this->pdo->lastInsertId();
        $presetRow = $this->fetchPresetRow($presetId);
        if ($presetRow === null) {
            throw new RuntimeException('Preset non trovato dopo il salvataggio.');
        }

        return $this->mapPreset($presetRow, $userId, $userRole);
    }

    public function deletePreset(int $presetId, int $userId, string $userRole): void
    {
        $preset = $this->fetchPresetRow($presetId);
        if ($preset === null) {
            throw new RuntimeException('Preset non trovato.');
        }

        if (!$this->userCanManagePreset($preset, $userId, $userRole)) {
            throw new RuntimeException('Non hai i permessi per eliminare questo preset.');
        }

        $stmt = $this->pdo->prepare('DELETE FROM office_spreadsheet_presets WHERE id = :id');
        $stmt->execute([':id' => $presetId]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchPresetRow(int $presetId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM office_spreadsheet_presets WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $presetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapPreset(array $row, int $userId, string $userRole): array
    {
        $allowedRoles = $row['allowed_roles'] ?? null;
        $allowedRolesArray = [];
        if (is_string($allowedRoles) && $allowedRoles !== '') {
            $allowedRolesArray = array_values(array_filter(array_map('trim', explode(',', $allowedRoles))));
        }

        $filters = $this->decodeJsonField($row['filters'] ?? null);
        $columns = $this->decodeJsonField($row['columns'] ?? null);
        $tags = $this->decodeJsonField($row['tags'] ?? null);

        return [
            'id' => (int) $row['id'],
            'sheet_id' => isset($row['spreadsheet_id']) ? (int) $row['spreadsheet_id'] : null,
            'name' => (string) $row['name'],
            'owner_id' => isset($row['owner_id']) ? (int) $row['owner_id'] : null,
            'visibility' => (string) $row['visibility'],
            'allowed_roles' => $allowedRolesArray,
            'filters' => is_array($filters) ? $filters : [],
            'columns' => is_array($columns) ? $columns : [],
            'tags' => is_array($tags) ? $tags : [],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'can_delete' => $this->userCanManagePreset($row, $userId, $userRole),
        ];
    }

    private function userCanManagePreset(array $row, int $userId, string $userRole): bool
    {
        if ($userId > 0 && isset($row['owner_id']) && (int) $row['owner_id'] === $userId) {
            return true;
        }

        return in_array($userRole, self::SHARE_ROLES, true);
    }

    private function userCanShare(string $userRole): bool
    {
        return in_array($userRole, self::SHARE_ROLES, true);
    }

    /**
     * @return array<int,string>|null
     */
    private function normalizeStringArray(null|string|array $value, bool $uppercase): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            $value = array_filter(array_map('trim', explode(',', (string) $value)));
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $normalized[] = $uppercase ? strtoupper($item) : $item;
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized ?: null;
    }

    /**
     * @return array<int,string>|null
     */
    private function normalizeRoleList(null|string|array $roles): ?array
    {
        $list = $this->normalizeStringArray($roles, false);
        if ($list === null) {
            return null;
        }

        $valid = array_values(array_filter($list, static function (string $role): bool {
            return in_array($role, self::KNOWN_ROLES, true);
        }));

        return $valid ?: null;
    }

    /**
     * @return array<int,array{column:string,operator:string,value:string}>
     */
    private function normalizeFilters(null|string|array $filters): array
    {
        if ($filters === null) {
            return [];
        }

        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            if (is_array($decoded)) {
                $filters = $decoded;
            }
        }

        if (!is_array($filters)) {
            throw new RuntimeException('Formato dei filtri non valido.');
        }

        $normalized = [];
        foreach ($filters as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $column = strtoupper(trim((string) ($filter['column'] ?? '')));
            $operator = strtolower(trim((string) ($filter['operator'] ?? '')));
            $value = trim((string) ($filter['value'] ?? ''));

            if ($column === '' || $operator === '' || $value === '') {
                continue;
            }

            if (!in_array($operator, self::ALLOWED_OPERATORS, true)) {
                throw new RuntimeException('Operatore filtro non supportato: ' . $operator);
            }

            $normalized[] = [
                'column' => $column,
                'operator' => $operator,
                'value' => $value,
            ];
        }

        return $normalized;
    }

    private function normalizeVisibility(string $visibility): string
    {
        $visibility = strtolower($visibility);
        switch ($visibility) {
            case self::VISIBILITY_PRIVATE:
            case self::VISIBILITY_ROLE:
            case self::VISIBILITY_GLOBAL:
                return $visibility;
            default:
                return self::VISIBILITY_PRIVATE;
        }
    }

    /**
     * @return array<int,mixed>|null
     */
    private function decodeJsonField(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return null;
        }

        return is_array($value) ? $value : null;
    }
}
