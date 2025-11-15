<?php
declare(strict_types=1);

namespace App\Services\Customs;

use SplFileObject;

use function array_key_exists;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function fclose;
use function fopen;
use function fgetcsv;
use function file_exists;
use function in_array;
use function is_array;
use function is_string;
use function preg_match_all;
use function preg_split;
use function strlen;
use function strcmp;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strtolower;
use function trim;
use function usort;

final class HsCodeDatasetRepository
{
    private const DATASET_PATH = __DIR__ . '/../../../data/harmonized-system.csv';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $itemsByCode = [];

    /**
     * @var array<string, array<int, string>>
     */
    private static array $childrenByCode = [];

    private bool $loaded = false;

    private HsCodeTranslator $translator;

    public function __construct(?HsCodeTranslator $translator = null)
    {
        $this->translator = $translator ?? new HsCodeTranslator();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 10): array
    {
        $this->ensureLoaded();

        $normalizedQuery = trim($query);
        if ($normalizedQuery === '') {
            return [];
        }

        if ($limit <= 0) {
            $limit = 10;
        }
        if ($limit > 50) {
            $limit = 50;
        }

    $normalizedLower = strtolower($normalizedQuery);
    $isCodeSearch = ctype_digit(str_replace(['.', ' '], '', $normalizedQuery));

    $numericTokens = $this->extractNumericTokens($normalizedQuery);
    $normalizedTokens = $this->extractTextTokens($normalizedLower, 4);

    $translatedQuery = $isCodeSearch ? $normalizedQuery : $this->translator->translateToEnglish($normalizedQuery);
    $translatedLower = strtolower($translatedQuery);
    $translatedTokens = $isCodeSearch ? [] : $this->extractTextTokens($translatedLower, 5);

        $results = [];

        foreach (self::$itemsByCode as $code => $item) {
            $code = (string) $code;
            $score = 0;
            $english = $item['description_en'];
            $englishLower = strtolower($english);

            if ($isCodeSearch) {
                if ($code === $normalizedQuery) {
                    $score += 1200;
                } elseif (str_starts_with($code, $normalizedQuery)) {
                    $score += 600 - max(0, (strlen($code) - strlen($normalizedQuery)) * 10);
                }
            }

            if ($numericTokens !== []) {
                foreach ($numericTokens as $token) {
                    if ($code === $token) {
                        $score += 1000;
                    } elseif (str_starts_with($code, $token)) {
                        $score += 450 - max(0, (strlen($code) - strlen($token)) * 5);
                    } elseif (str_contains($code, $token)) {
                        $score += 250;
                    }
                }
            }

            if ($englishLower === $normalizedLower) {
                $score += 800;
            }

            if (!$isCodeSearch && $normalizedTokens !== []) {
                foreach ($normalizedTokens as $token) {
                    if (str_contains($englishLower, $token)) {
                        $score += 220;
                    }
                }
            }

            if (!$isCodeSearch && $translatedTokens !== []) {
                foreach ($translatedTokens as $token) {
                    if (str_contains($englishLower, $token)) {
                        $score += 260;
                    }
                }
            }

            if (!$isCodeSearch && $translatedLower !== $normalizedLower && str_contains($englishLower, $translatedLower)) {
                $score += 350;
            }

            if ($score <= 0) {
                continue;
            }

            $results[] = [
                'code' => $code,
                'score' => $score,
            ];
        }

        if ($results === []) {
            return [];
        }

        usort($results, static function (array $left, array $right): int {
            if ($left['score'] === $right['score']) {
                return strcmp($left['code'], $right['code']);
            }

            return $right['score'] <=> $left['score'];
        });

    $limited = array_slice($results, 0, $limit);

        $normalizedResults = [];
        foreach ($limited as $entry) {
            $code = $entry['code'];
            if (!isset(self::$itemsByCode[$code])) {
                continue;
            }
            $item = self::$itemsByCode[$code];
            $italian = $this->translator->translateDescription($code, $item['description_en']);
            $breadcrumbs = $this->buildBreadcrumbs($code);

            $breadcrumbsIt = array_map(function (string $value): string {
                if (str_starts_with($value, 'Sezione ')) {
                    return $value;
                }
                return $this->translator->translateGeneric($value, 'it');
            }, $breadcrumbs);

            $normalizedResults[] = [
                'code' => $code,
                'description' => $italian,
                'descriptions' => [
                    'it' => $italian,
                    'en' => $item['description_en'],
                ],
                'section' => $item['section'],
                'breadcrumbs' => $breadcrumbsIt,
                'query' => $normalizedQuery,
                'source' => $item,
            ];
        }

        return $normalizedResults;
    }

    /**
     * @return array<int, string>
     */
    private function extractNumericTokens(string $value): array
    {
        $matches = [];
        preg_match_all('/\d{2,}/', $value, $matches);
        if (!isset($matches[0]) || !is_array($matches[0])) {
            return [];
        }

        $tokens = [];
        foreach ($matches[0] as $token) {
            $tokens[] = trim($token);
        }

        return $tokens;
    }

    /**
     * @return array<int, string>
     */
    private function extractTextTokens(string $value, int $minLength = 3): array
    {
        $tokens = preg_split('/[^a-z0-9]+/i', $value) ?: [];
        $result = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || ctype_digit($token)) {
                continue;
            }
            if (strlen($token) < $minLength) {
                continue;
            }
            $result[] = $token;
        }

        return $result;
    }

    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        $path = self::DATASET_PATH;
        if (!file_exists($path)) {
            throw new HsCodeLookupException('Dataset HS non trovato: ' . $path);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new HsCodeLookupException('Impossibile aprire il dataset HS: ' . $path);
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            throw new HsCodeLookupException('Intestazione del dataset HS non valida.');
        }

        $columns = array_map('trim', $header);
        $expected = ['section', 'hscode', 'description', 'parent', 'level'];
        if ($columns !== $expected) {
            fclose($handle);
            throw new HsCodeLookupException('Struttura del dataset HS non riconosciuta.');
        }

        $itemsByCode = [];
        $childrenByCode = [];

        while (($row = fgetcsv($handle)) !== false) {
            if (!is_array($row) || count($row) !== 5) {
                continue;
            }

            [$section, $code, $description, $parent, $level] = array_map('trim', $row);
            if ($code === '') {
                continue;
            }

            $itemsByCode[$code] = [
                'code' => $code,
                'section' => $section,
                'description_en' => $description,
                'parent' => $parent !== '' ? $parent : null,
                'level' => (int) $level,
            ];

            if ($parent !== '' && $parent !== 'TOTAL') {
                $childrenByCode[$parent][] = $code;
            }
        }

        fclose($handle);

        self::$itemsByCode = $itemsByCode;
        self::$childrenByCode = $childrenByCode;
        $this->loaded = true;
    }

    /**
     * @return array<int, string>
     */
    private function buildBreadcrumbs(string $code): array
    {
        $breadcrumbs = [];
        $currentCode = $code;
        $visited = [];

        while (array_key_exists($currentCode, self::$itemsByCode)) {
            if (isset($visited[$currentCode])) {
                break; // Interrupt potential cycles in malformed source data.
            }
            $visited[$currentCode] = true;

            $item = self::$itemsByCode[$currentCode];
            $breadcrumbs[] = $item['description_en'];
            $parent = $item['parent'];
            if ($parent === null || $parent === '' || !array_key_exists($parent, self::$itemsByCode)) {
                break;
            }
            $currentCode = $parent;
        }

        if ($breadcrumbs === []) {
            return [];
        }

        $breadcrumbs = array_values(array_reverse($breadcrumbs));

        $first = self::$itemsByCode[$code] ?? null;
        if ($first !== null) {
            $section = $first['section'];
            if ($section !== '' && $section !== '-' && !in_array($section, $breadcrumbs, true)) {
                array_unshift($breadcrumbs, 'Sezione ' . $section);
            }
        }

        return $breadcrumbs;
    }
}
