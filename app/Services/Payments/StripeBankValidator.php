<?php

declare(strict_types=1);

namespace App\Services\Payments;

use RuntimeException;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeBankValidator
{
    private StripeClient $client;

    public function __construct(?StripeClient $client = null)
    {
        if ($client instanceof StripeClient) {
            $this->client = $client;
            return;
        }

        $secret = (string) (\env('STRIPE_SECRET_KEY') ?? '');
        if ($secret === '') {
            throw new RuntimeException('Stripe non configurato: STRIPE_SECRET_KEY mancante.');
        }

        $this->client = new StripeClient(['api_key' => $secret]);
    }

    /**
     * Validates an IBAN through Stripe by creating a SEPA payment method.
     * Returns lightweight metadata (bank code/last4) for logging/audit.
     *
     * @return array{payment_method_id:string,last4:?string,bank_code:?string,country:?string}
     */
    public function validateIban(string $iban, string $accountHolderName = '', ?string $email = null): array
    {
        $normalizedIban = strtoupper(str_replace(' ', '', trim($iban)));
        if ($normalizedIban === '') {
            throw new RuntimeException('IBAN non fornito.');
        }

        try {
            $payload = [
                'type' => 'sepa_debit',
                'sepa_debit' => ['iban' => $normalizedIban],
                'billing_details' => array_filter([
                    'name' => trim($accountHolderName) ?: null,
                    'email' => $email ?: null,
                ]),
            ];

            $paymentMethod = $this->client->paymentMethods->create($payload);
        } catch (ApiErrorException $exception) {
            $message = $exception->getMessage();
            throw new RuntimeException($message !== '' ? $message : 'Errore Stripe durante la validazione IBAN.');
        }

        $sepa = $paymentMethod->sepa_debit ?? null;

        return [
            'payment_method_id' => (string) $paymentMethod->id,
            'last4' => $sepa->last4 ?? null,
            'bank_code' => $sepa->bank_code ?? null,
            'country' => $sepa->country ?? null,
        ];
    }
}
