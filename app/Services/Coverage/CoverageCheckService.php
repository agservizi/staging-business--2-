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

    public function __construct(?CoverageProviderRegistry $registry = null)
    {
        $this->registry = $registry ?? new CoverageProviderRegistry();
        $this->seleniumEndpoint = (string) env('COVERAGE_SELENIUM_ENDPOINT', 'http://localhost:4444/wd/hub');
        $this->browser = strtolower((string) env('COVERAGE_SELENIUM_BROWSER', 'chrome'));
        $timeout = (int) env('COVERAGE_SELENIUM_TIMEOUT', 45);
        $this->defaultTimeout = $timeout > 0 ? $timeout : 45;
        $this->captureScreenshot = filter_var(env('COVERAGE_SELENIUM_SCREENSHOT', true), FILTER_VALIDATE_BOOL);
    }

    public function check(CoverageRequest $request): array
    {
        $provider = $this->registry->get($request->providerKey());
        $driver = $this->createDriver();

        $stepsLog = [];
        $extracted = [];
        $status = 'completed';
        $message = 'Automazione completata correttamente.';
        $pageTitle = null;
        $screenshot = null;

        try {
            $driver->get($provider['url']);
            usleep(1_500_000);

            if ($provider['actions'] === []) {
                $status = 'manual';
                $message = 'Nessuna ricetta Selenium definita per questo gestore. Usa la sessione aperta per operare manualmente.';
            } else {
                [$stepsLog, $extracted] = $this->runActions($driver, $provider['actions'], $request);
                if ($this->hasStepErrors($stepsLog)) {
                    $status = 'partial';
                    $message = 'Alcuni passi non sono stati completati. Verifica manualmente il risultato.';
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
                $driver->quit();
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

    private function createDriver(): RemoteWebDriver
    {
        $capabilities = $this->buildCapabilities();
        try {
            return RemoteWebDriver::create(
                $this->seleniumEndpoint,
                $capabilities,
                10_000,
                $this->defaultTimeout * 1000
            );
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossibile collegarsi al nodo Selenium: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function buildCapabilities(): DesiredCapabilities
    {
        return match ($this->browser) {
            'firefox' => DesiredCapabilities::firefox(),
            'edge' => DesiredCapabilities::microsoftEdge(),
            default => DesiredCapabilities::chrome(),
        };
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
