<?php
declare(strict_types=1);

/**
 * Split SQL dump into statements respecting quoted strings.
 *
 * @return list<string>
 */
function corehost_split_sql(string $content): array
{
    $statements = [];
    $current = '';
    $inString = false;
    $stringChar = '';
    $escaped = false;
    $len = strlen($content);

    for ($i = 0; $i < $len; $i++) {
        $ch = $content[$i];

        if ($escaped) {
            $current .= $ch;
            $escaped = false;
            continue;
        }

        if ($ch === '\\' && $inString) {
            $current .= $ch;
            $escaped = true;
            continue;
        }

        if ($inString) {
            $current .= $ch;
            if ($ch === $stringChar) {
                if ($i + 1 < $len && $content[$i + 1] === $stringChar) {
                    $current .= $content[++$i];
                } else {
                    $inString = false;
                }
            }
            continue;
        }

        if ($ch === "'" || $ch === '"') {
            $inString = true;
            $stringChar = $ch;
            $current .= $ch;
            continue;
        }

        if ($ch === ';') {
            $stmt = trim($current);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $tail = trim($current);
    if ($tail !== '') {
        $statements[] = $tail;
    }

    return $statements;
}

/**
 * @return list<string>
 */
function corehost_filter_statements(array $statements, ?string $table = null, ?string $type = null): array
{
    $out = [];
    foreach ($statements as $sql) {
        if ($table !== null && !preg_match('/(INSERT|CREATE|ALTER|DROP)\s+.*`' . preg_quote($table, '/') . '`/i', $sql)) {
            continue;
        }
        if ($type !== null && !preg_match('/\b' . preg_quote(strtoupper($type), '/') . '\b/i', $sql)) {
            continue;
        }
        $out[] = $sql;
    }
    return $out;
}

/**
 * CoreHost /query splitta su ";" senza rispettare le stringhe SQL.
 * Converte letterali con ";" in 0xHEX per evitare troncamenti.
 */
function corehost_encode_sql_for_api(string $sql): string
{
    $out = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        if ($ch !== "'") {
            $out .= $ch;
            $i++;
            continue;
        }

        $i++;
        $content = '';
        while ($i < $len) {
            if ($sql[$i] === '\\' && $i + 1 < $len) {
                $content .= $sql[$i] . $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($sql[$i] === "'") {
                if ($i + 1 < $len && $sql[$i + 1] === "'") {
                    $content .= "''";
                    $i += 2;
                    continue;
                }
                $i++;
                $decoded = str_replace("''", "'", $content);
                if (str_contains($decoded, ';')) {
                    $out .= '0x' . bin2hex($decoded);
                } else {
                    $out .= "'" . $content . "'";
                }
                break;
            }
            $content .= $sql[$i];
            $i++;
        }
    }

    return $out;
}
