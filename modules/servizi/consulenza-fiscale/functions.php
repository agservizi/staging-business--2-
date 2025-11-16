<?php
declare(strict_types=1);

use App\Services\ConsulenzaFiscale\ConsulenzaFiscaleService;

require_once __DIR__ . '/../../../includes/helpers.php';

function consulenza_fiscale_service(PDO $pdo): ConsulenzaFiscaleService
{
    static $service = null;
    if ($service === null) {
        $service = new ConsulenzaFiscaleService($pdo, project_root_path());
    }

    return $service;
}

/**
 * @return array<string,string>
 */
function consulenza_fiscale_status_options(): array
{
    return ConsulenzaFiscaleService::availableStatuses();
}

/**
 * @return array<string,string>
 */
function consulenza_fiscale_model_options(): array
{
    return ConsulenzaFiscaleService::availableModelTypes();
}

/**
 * @return array<string,string>
 */
function consulenza_fiscale_frequency_options(): array
{
    return ConsulenzaFiscaleService::availableFrequencies();
}

/**
 * @return array<string,string>
 */
function consulenza_fiscale_rate_status_options(): array
{
    return ConsulenzaFiscaleService::availableRateStatuses();
}

function consulenza_fiscale_status_label(?string $status): string
{
    $options = consulenza_fiscale_status_options();
    return $options[$status ?? ''] ?? ucfirst(str_replace('_', ' ', (string) $status));
}

function consulenza_fiscale_rate_status_label(?string $status): string
{
    $options = consulenza_fiscale_rate_status_options();
    return $options[$status ?? ''] ?? ($status === 'paid' ? 'Pagata' : 'Da pagare');
}
