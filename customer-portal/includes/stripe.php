<?php
declare(strict_types=1);

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

use RuntimeException;
use Stripe\StripeClient;

function portal_stripe_client(): StripeClient
{
    static $client = null;
    if ($client instanceof StripeClient) {
        return $client;
    }

    $secret = env('STRIPE_SECRET_KEY');
    if (!is_string($secret) || $secret === '') {
        throw new RuntimeException('Configurazione Stripe assente: impostare STRIPE_SECRET_KEY.');
    }

    $client = new StripeClient(['api_key' => $secret]);
    return $client;
}

function portal_stripe_success_url(string $reference): string
{
    $baseUrl = rtrim(env('PORTAL_URL', ''), '/');
    if ($baseUrl === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/customer-portal';
    }

    return $baseUrl . '/brt-payment-complete.php?ref=' . urlencode($reference);
}

function portal_stripe_cancel_url(): string
{
    $baseUrl = rtrim(env('PORTAL_URL', ''), '/');
    if ($baseUrl === '') {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = $protocol . '://' . $host . '/customer-portal';
    }

    return $baseUrl . '/brt-shipment-create.php?payment_cancel=1';
}
