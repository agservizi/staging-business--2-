<?php
declare(strict_types=1);

namespace App\Services\Coverage;

use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverElement;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverWait;
use RuntimeException;
use Throwable;

final class CoverageCheckService
{
    private CoverageProviderRegistry $registry;
    private string $seleniumEndpoint;
    private string $browser;
    private int $defaultTimeout;
    private bool $captureScreenshot;
    private string $seleniumUsername;
    private string $seleniumAccessKey;
    private string $seleniumOs;
    private string $seleniumOsVersion;
    private string $seleniumBuildName;
    private string $seleniumSessionPrefix;

    public function __construct(?CoverageProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new CoverageProviderRegistry();
        $this->seleniumEndpoint = (string) env('COVERAGE_SELENIUM_ENDPOINT', 'http://localhost:4444/wd/hub');
        $this->browser = strtolower((string) env('COVERAGE_SELENIUM_BROWSER', 'chrome'));
        $timeout = (int) env('COVERAGE_SELENIUM_TIMEOUT', 45);
        $this->defaultTimeout = $timeout > 0 ? $timeout : 45;
        $this->captureScreenshot = filter_var(env('COVERAGE_SELENIUM_SCREENSHOT', true), FILTER_VALIDATE_BOOL);
        $this->seleniumUsername = trim((string) env('COVERAGE_SELENIUM_USERNAME', ''));
        $this->seleniumAccessKey = trim((string) env('COVERAGE_SELENIUM_ACCESS_KEY', ''));
        $this->seleniumOs = trim((string) env('COVERAGE_SELENIUM_OS', 'Windows')) ?: 'Windows';
        $this->seleniumOsVersion = trim((string) env('COVERAGE_SELENIUM_OS_VERSION', '11')) ?: '11';
        $this->seleniumBuildName = trim((string) env('COVERAGE_SELENIUM_BUILD', 'Coverage Automation')) ?: 'Coverage Automation';
        $this->seleniumSessionPrefix = trim((string) env('COVERAGE_SELENIUM_SESSION_PREFIX', 'Coverage run')) ?: 'Coverage run';
    }

    public function check(CoverageRequest $request): array
    {
        $provider = $this->registry->get($request->providerKey());
        $driver = null;
        $stepsLog = [];
        $extracted = [];
        $status = 'completed';
        $message = 'Automazione completata correttamente.';
        $pageTitle = null;
        $screenshot = null;

        try {
            $driver = $this->createDriver($provider, $request);
            $driver->get($provider['url']);
            usleep(1_500_000);

            if ($provider['actions'] === []) {
                $status = 'manual';
                $message = 'Nessuna ricetta Selenium definita per questo gestore. Usa la sessione aperta per operare manualmente.';
            } else {
                [$stepsLog, $extracted] = $this->runActions($driver, $provider['actions'], $request);
                $extracted = $this->postProcessSummary((string) ($provider['key'] ?? ''), $extracted);
                if ($this->hasStepErrors($stepsLog)) {
                    $status = 'partial';
                    $message = 'Alcuni passi non sono stati completati. Verifica manualmente il risultato.';
                } elseif ($status === 'completed') {
                    $classification = $this->classifyCoverageSummary($extracted);
                    if ($classification !== null) {
                        $status = $classification['status'];
                        $message = $classification['message'];
                    } elseif ($extracted === []) {
                        $message = 'Automazione completata, ma il portale non ha restituito dati leggibili.';
                    }
                }
            }

            $pageTitle = $driver->getTitle();
            if ($this->captureScreenshot) {
                $rawScreenshot = $driver->takeScreenshot();
                if (is_string($rawScreenshot) && $rawScreenshot !== '') {
                    $screenshot = base64_encode($rawScreenshot);
                }
            }
        } catch (Throwable $exception) {
            $status = 'failed';
            $message = 'Errore Selenium: ' . $exception->getMessage();
        } finally {
            try {
                $driver?->quit();
            } catch (Throwable $quitError) {
                // Ignoriamo errori in chiusura sessione
            }
        }

        return [
            'provider' => [
                'key' => $provider['key'],
                'label' => $provider['label'],
                'url' => $provider['url'],
                'category' => $provider['category'],
                'automation_status' => $provider['automation_status'],
                'notes' => $provider['notes'] ?? null,
            ],
            'status' => $status,
            'message' => $message,
            'summary' => $extracted,
            'steps' => $stepsLog,
            'page_title' => $pageTitle,
            'screenshot' => $screenshot,
        ];
    }

    /**
     * @param array<string,mixed> $provider
     */
    private function createDriver(array $provider, CoverageRequest $request): RemoteWebDriver
    {
        $capabilities = $this->buildCapabilities($provider, $request);
        try {
            return RemoteWebDriver::create(
                $this->resolvedEndpoint(),
                $capabilities,
                10_000,
                $this->defaultTimeout * 1000
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossibile collegarsi al nodo Selenium: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param array<string,mixed> $provider
     */
    private function buildCapabilities(array $provider, CoverageRequest $request): DesiredCapabilities
    {
        $capabilities = match ($this->browser) {
            'firefox' => DesiredCapabilities::firefox(),
            'edge' => DesiredCapabilities::microsoftEdge(),
            default => DesiredCapabilities::chrome(),
        };

        if ($this->isBrowserStack()) {
            $capabilities->setCapability('browserVersion', 'latest');
            $capabilities->setCapability('bstack:options', [
                'os' => $this->seleniumOs,
                'osVersion' => $this->seleniumOsVersion,
                'projectName' => $this->seleniumBuildName,
                'buildName' => sprintf('%s %s', $this->seleniumBuildName, (string) env('APP_VERSION', '1.0')),
                'sessionName' => $this->composeSessionName($provider, $request),
                'local' => 'false',
            ]);
        }

        return $capabilities;
    }

    /**
     * @param array<string,mixed> $provider
     */
    private function composeSessionName(array $provider, CoverageRequest $request): string
    {
        $parts = [
            $this->seleniumSessionPrefix,
            date('Y-m-d H:i:s'),
        ];

        $providerLabel = trim((string) ($provider['label'] ?? ($provider['key'] ?? '')));
        if ($providerLabel !== '') {
            $parts[] = $providerLabel;
        }

        $addressParts = array_filter([
            $request->field('address'),
            $request->field('city'),
        ], static fn (string $value): bool => $value !== '');
        if ($addressParts !== []) {
            $parts[] = implode(', ', $addressParts);
        }

        $sessionName = implode(' | ', $parts);
        if (mb_strlen($sessionName, 'UTF-8') > 190) {
            $sessionName = mb_substr($sessionName, 0, 190, 'UTF-8');
        }

        return $sessionName;
    }

    /**
     * @param array<string,string> $summary
     * @return array{status:string,message:string}|null
     */
    private function classifyCoverageSummary(array $summary): ?array
    {
        $text = $this->collapseSummaryChunks($summary);
        if ($text === '') {
            return null;
        }

        $normalized = mb_strtolower($text, 'UTF-8');
        $negativeHints = [
            'non disponibile',
            'non coperto',
            'non raggiunto',
            'nessuna copertura',
            'non attivabile',
            'non possiamo procedere',
            'non presente',
            'momentaneamente non',
            'non possiamo offrirti',
            'al momento non',
            'servizio non disponibile',
        ];
        foreach ($negativeHints as $hint) {
            if (str_contains($normalized, $hint)) {
                return [
                    'status' => 'coverage_missing',
                    'message' => $this->buildCoverageMessage(false, $text),
                ];
            }
        }

        $positiveHints = [
            'copertura disponibile',
            'copertura attiva',
            'sei coperto',
            'risulti coperto',
            'servizio disponibile',
            'puoi attivare',
            'puoi avere',
            'puoi navigare',
            'disponibile nella tua zona',
            'ftth',
            'fibra disponibile',
            'rete disponibile',
            'gpon',
        ];
        foreach ($positiveHints as $hint) {
            if (str_contains($normalized, $hint)) {
                return [
                    'status' => 'coverage_found',
                    'message' => $this->buildCoverageMessage(true, $text),
                ];
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $summary
     */
    private function collapseSummaryChunks(array $summary): string
    {
        if ($summary === []) {
            return '';
        }

        $chunks = [];
        foreach ($summary as $value) {
            $chunk = trim((string) $value);
            if ($chunk !== '') {
                $chunks[] = $chunk;
            }
        }

        return trim(implode(' | ', $chunks));
    }

    /**
     * @param array<string,string> $summary
     * @return array<string,string>
     */
    private function postProcessSummary(string $providerKey, array $summary): array
    {
        if ($summary === [] || $providerKey === '') {
            return $summary;
        }

        return match ($providerKey) {
            'fastweb_consumer' => $this->refineFastwebConsumerSummary($summary),
            default => $summary,
        };
    }

    /**
     * @param array<string,string> $summary
     * @return array<string,string>
     */
    private function refineFastwebConsumerSummary(array $summary): array
    {
        $copertura = $summary['copertura'] ?? '';
        if ($copertura !== '') {
            $summary['copertura'] = $this->shortenFastwebMessage($copertura);
            unset($summary['pagina_fastweb']);

            return $summary;
        }

        if (isset($summary['pagina_fastweb'])) {
            $summary['pagina_fastweb'] = $this->shortenFastwebMessage($summary['pagina_fastweb']);
        }

        return $summary;
    }

    private function shortenFastwebMessage(string $text): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $text));
        if ($normalized === '') {
            return '';
        }

        $excerpt = $this->fastwebSentenceExcerpt($normalized);
        if ($excerpt === '') {
            $excerpt = $normalized;
        }

        if (mb_strlen($excerpt, 'UTF-8') > 220) {
            $excerpt = rtrim(mb_substr($excerpt, 0, 220, 'UTF-8')) . '...';
        }

        return $excerpt;
    }

    private function fastwebSentenceExcerpt(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($sentences === []) {
            return '';
        }

        $parts = [];
        foreach ($sentences as $sentence) {
            $parts[] = $sentence;
            if (preg_match('/€|euro/i', $sentence)) {
                break;
            }

            if (count($parts) >= 2) {
                break;
            }
        }

        return trim(implode(' ', $parts));
    }

    private function buildCoverageMessage(bool $positive, string $rawText): string
    {
        $excerpt = $this->excerptText($rawText);
        if ($excerpt === '') {
            return $positive
                ? 'Copertura disponibile secondo il portale.'
                : 'Il portale segnala che la copertura non è disponibile per questo indirizzo.';
        }

        return $positive
            ? sprintf('Copertura disponibile: %s', $excerpt)
            : sprintf('Copertura non disponibile: %s', $excerpt);
    }

    private function excerptText(string $text): string
    {
        $singleLine = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($singleLine === '') {
            return '';
        }

        if (mb_strlen($singleLine, 'UTF-8') > 200) {
            $singleLine = rtrim(mb_substr($singleLine, 0, 200, 'UTF-8')) . '...';
        }

        return $singleLine;
    }

    private function resolvedEndpoint(): string
    {
        if ($this->seleniumUsername === '' || $this->seleniumAccessKey === '') {
            return $this->seleniumEndpoint;
        }

        $parsed = parse_url($this->seleniumEndpoint);
        if ($parsed === false || !isset($parsed['host'])) {
            return $this->seleniumEndpoint;
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'];
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path = $parsed['path'] ?? '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';

        $credentials = rawurlencode($this->seleniumUsername) . ':' . rawurlencode($this->seleniumAccessKey) . '@';

        return sprintf('%s://%s%s%s%s', $scheme, $credentials . $host . $port, $path, $query, $fragment);
    }

    private function isBrowserStack(): bool
    {
        return str_contains($this->seleniumEndpoint, 'browserstack.com');
    }

    /**
     * @param array<int,array<string,mixed>> $actions
     * @return array{0:array<int,array<string,mixed>>,1:array<string,string>}
     */
    private function runActions(RemoteWebDriver $driver, array $actions, CoverageRequest $request): array
    {
        $steps = [];
        $extracted = [];

        foreach ($actions as $index => $action) {
            $name = strtolower(trim((string) ($action['action'] ?? '')));
            if ($name === '') {
                continue;
            }
            $selector = (string) ($action['selector'] ?? '');
            $start = microtime(true);
            $status = 'ok';
            $message = null;

            try {
                switch ($name) {
                    case 'wait_for':
                        $this->waitForSelector($driver, $selector, (int) ($action['timeout'] ?? $this->defaultTimeout));
                        break;
                    case 'type':
                        $value = $this->resolveValue($action, $request);
                        if ($value === '') {
                            $status = 'skipped';
                            $message = 'Valore vuoto, passo ignorato.';
                            break;
                        }
                        $element = $this->findElement($driver, $selector);
                        $element->clear();
                        $element->sendKeys($value);
                        break;
                    case 'click':
                        $element = $this->findElement($driver, $selector);
                        if (!empty($action['scroll'])) {
                            $driver->executeScript('arguments[0].scrollIntoView({block:"center"});', [$element]);
                            usleep(300_000);
                        }
                        $element->click();
                        break;
                    case 'pause':
                        $delay = (int) ($action['milliseconds'] ?? 500);
                        if ($delay > 0) {
                            usleep($delay * 1000);
                        }
                        break;
                    case 'extract_text':
                        $alias = $action['as'] ?? ('field_' . $index);
                        $element = $this->findElement($driver, $selector);
                        $extracted[$alias] = trim($element->getText());
                        break;
                    case 'extract_attribute':
                        $alias = $action['as'] ?? ('field_' . $index);
                        $attribute = (string) ($action['attribute'] ?? 'innerText');
                        $element = $this->findElement($driver, $selector);
                        $extracted[$alias] = trim((string) $element->getAttribute($attribute));
                        break;
                    case 'execute_script':
                        $script = (string) ($action['script'] ?? '');
                        if ($script === '') {
                            $status = 'skipped';
                            $message = 'Script vuoto, passo ignorato.';
                            break;
                        }
                        $driver->executeScript($script);
                        break;
                    case 'switch_to_frame':
                        if ($selector === '') {
                            throw new RuntimeException('Selettore mancante per il cambio frame.');
                        }
                        $timeout = (int) ($action['timeout'] ?? $this->defaultTimeout);
                        $this->waitForSelector($driver, $selector, $timeout);
                        $frameElement = $this->findElement($driver, $selector);
                        $driver->switchTo()->frame($frameElement);
                        break;
                    case 'switch_to_default_content':
                        $driver->switchTo()->defaultContent();
                        break;
                    default:
                        $status = 'skipped';
                        $message = 'Azione non supportata.';
                        break;
                }
            } catch (TimeoutException $timeoutException) {
                $status = 'error';
                $message = 'Timeout: ' . $timeoutException->getMessage();
            } catch (WebDriverException $webDriverException) {
                $status = 'error';
                $message = 'Errore WebDriver: ' . $webDriverException->getMessage();
            } catch (Throwable $unexpected) {
                $status = 'error';
                $message = $unexpected->getMessage();
            }

            $steps[] = [
                'action' => $name,
                'selector' => $selector !== '' ? $selector : null,
                'status' => $status,
                'message' => $message,
                'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            ];

            if ($status === 'error' && empty($action['continue_on_error'])) {
                break;
            }
        }

        return [$steps, $extracted];
    }

    private function waitForSelector(RemoteWebDriver $driver, string $selector, int $timeout): void
    {
        if ($selector === '') {
            return;
        }
        $wait = new WebDriverWait($driver, max(1, $timeout));
        $wait->until(WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector($selector)));
    }

    private function findElement(RemoteWebDriver $driver, string $selector): WebDriverElement
    {
        if ($selector === '') {
            throw new RuntimeException('Selettore mancante per l\'azione Selenium.');
        }

        return $driver->findElement(WebDriverBy::cssSelector($selector));
    }

    private function resolveValue(array $action, CoverageRequest $request): string
    {
        if (array_key_exists('value', $action)) {
            return (string) $action['value'];
        }

        if (!empty($action['concat_fields']) && is_array($action['concat_fields'])) {
            $parts = [];
            foreach ($action['concat_fields'] as $fieldName) {
                $fieldValue = $request->field((string) $fieldName);
                if ($fieldValue !== '') {
                    $parts[] = $fieldValue;
                }
            }
            if ($parts !== []) {
                $separator = array_key_exists('concat_separator', $action)
                    ? (string) $action['concat_separator']
                    : ' ';
                return trim(implode($separator, $parts));
            }
        }

        if (isset($action['field'])) {
            return $request->field((string) $action['field']);
        }

        return '';
    }

    /**
     * @param array<int,array<string,mixed>> $steps
     */
    private function hasStepErrors(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['status'] ?? '') === 'error') {
                return true;
            }
        }

        return false;
    }
}
