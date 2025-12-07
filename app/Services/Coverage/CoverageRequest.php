<?php
declare(strict_types=1);

namespace App\Services\Coverage;

use InvalidArgumentException;

final class CoverageRequest
{
    private string $providerKey;
    /**
     * @var array<string,string>
     */
    private array $fields;

    private function __construct(string $providerKey, array $fields)
    {
        $this->providerKey = $providerKey;
        $this->fields = $fields;
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function fromArray(array $input): self
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        if ($provider === '') {
            throw new InvalidArgumentException('Seleziona un gestore valido.');
        }

        $fields = [
            'address' => self::sanitizeLine($input['address'] ?? ''),
            'civic' => self::sanitizeLine($input['civic'] ?? ''),
            'city' => self::sanitizeLine($input['city'] ?? ''),
            'province' => self::sanitizeLine($input['province'] ?? ''),
            'cap' => self::sanitizeLine($input['cap'] ?? ''),
            'notes' => self::sanitizeMultiline($input['notes'] ?? ''),
            'reference' => self::sanitizeLine($input['reference'] ?? ''),
        ];

        foreach ($input as $key => $value) {
            if (isset($fields[$key])) {
                continue;
            }
            if (!is_string($key)) {
                continue;
            }
            $fields[$key] = self::sanitizeLine($value ?? '');
        }

        return new self($provider, $fields);
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function field(string $name): string
    {
        return $this->fields[$name] ?? '';
    }

    /**
     * @return array<string,string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    private static function sanitizeLine(mixed $value): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }
        $string = preg_replace('/\s+/', ' ', $string) ?? '';
        $string = mb_substr($string, 0, 160, 'UTF-8');

        return trim($string);
    }

    private static function sanitizeMultiline(mixed $value): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return '';
        }
        $string = preg_replace('/\r\n?/', "\n", $string) ?? '';
        $string = preg_replace('/\s+/', ' ', $string) ?? '';
        $string = mb_substr($string, 0, 500, 'UTF-8');

        return trim($string);
    }
}
