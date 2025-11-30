<?php
declare(strict_types=1);

namespace App\Services\Customs;

use CurlHandle;
use JsonException;

use function array_key_exists;
use function array_merge;
use function curl_close;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function fclose;
use function feof;
use function fgets;
use function file_exists;
use function file_put_contents;
use function fopen;
use function is_array;
use function json_decode;
use function json_encode;
use function rawurlencode;
use function sprintf;
use function str_replace;
use function strtolower;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;

final class HsCodeTranslator
{
    private const CACHE_FILE = __DIR__ . '/../../../data/hs-translation-cache.json';

    /**
     * @var array<string, array<string, string>>
     */
    private array $cache = [
    'auto-it' => [],
    'auto-en' => [],
    'en-it' => [],
    'it-en' => [],
    'code-it' => [],
    ];

    private bool $dirty = false;

    public function __construct()
    {
        $this->loadCache();
    }

    public function __destruct()
    {
        $this->persistCache();
    }

    public function translateToEnglish(string $text): string
    {
        return $this->translateGeneric($text, 'en', 'auto');
    }

    public function translateDescription(string $code, string $englishDescription): string
    {
        $key = 'code-' . $code;
        if (isset($this->cache['code-it'][$key])) {
            return $this->cache['code-it'][$key];
        }

        $italian = $this->translateGeneric($englishDescription, 'it', 'en');
        $this->cache['code-it'][$key] = $italian;
        $this->dirty = true;

        return $italian;
    }

    public function translateGeneric(string $text, string $target, string $source = 'auto'): string
    {
        $normalized = trim($text);
        if ($normalized === '') {
            return '';
        }

        $sourceKey = strtolower($source);
        $targetKey = strtolower($target);
        $cacheKey = $sourceKey . '-' . $targetKey;

        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = [];
        }

        if (isset($this->cache[$cacheKey][$normalized])) {
            return $this->cache[$cacheKey][$normalized];
        }

        $translation = $this->performTranslation($normalized, $targetKey, $sourceKey);
        $this->cache[$cacheKey][$normalized] = $translation;
        $this->dirty = true;

        return $translation;
    }

    private function performTranslation(string $text, string $target, string $source): string
    {
        $client = 'gtx';
        $encoded = rawurlencode($text);
        $url = sprintf('https://translate.googleapis.com/translate_a/single?client=%s&sl=%s&tl=%s&dt=t&q=%s', $client, $source, $target, $encoded);

        $handle = curl_init($url);
        if (!($handle instanceof CurlHandle)) {
            return $text;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($handle, CURLOPT_FAILONERROR, false);
    curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);

        $raw = curl_exec($handle);
        if ($raw === false) {
            curl_close($handle);
            return $text;
        }

        curl_close($handle);

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $text;
        }

        if (!is_array($decoded) || !isset($decoded[0]) || !is_array($decoded[0])) {
            return $text;
        }

        $segments = [];
        foreach ($decoded[0] as $part) {
            if (is_array($part) && isset($part[0]) && is_string($part[0])) {
                $segments[] = $part[0];
            }
        }

        if ($segments === []) {
            return $text;
        }

        $translation = trim(str_replace(' ,', ',', implode('', $segments)));
        if ($translation === '') {
            return $text;
        }

        return $translation;
    }

    private function loadCache(): void
    {
        $path = self::CACHE_FILE;
        if (!file_exists($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return;
        }

        $contents = '';
        while (!feof($handle)) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $contents .= $line;
        }
        fclose($handle);

        if ($contents === '') {
            return;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return;
        }

        if (!is_array($decoded)) {
            return;
        }

        $this->cache = array_merge($this->cache, $decoded);
    }

    private function persistCache(): void
    {
        if (!$this->dirty) {
            return;
        }
        try {
            $encoded = json_encode($this->cache, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            file_put_contents(self::CACHE_FILE, $encoded);
            $this->dirty = false;
        } catch (JsonException $exception) {
            // Lascia la cache in memoria; l'errore non deve bloccare l'esecuzione della ricerca
        }
    }
}
