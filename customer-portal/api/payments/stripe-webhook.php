<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/payments.php';
require_once __DIR__ . '/../../includes/payment_finalizer.php';
require_once __DIR__ . '/../../includes/stripe.php';

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$webhookSecret = env('STRIPE_WEBHOOK_SECRET');

if ($payload === false) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload non disponibile']);
    exit;
}

$event = null;

if (is_string($webhookSecret) && $webhookSecret !== '') {
    try {
        $webhookClass = '\\Stripe\\Webhook';
        if (!class_exists($webhookClass)) {
            throw new RuntimeException('Stripe\Webhook non disponibile.');
        }
        /** @var callable $constructor */
        $constructor = [$webhookClass, 'constructEvent'];
        $event = $constructor($payload, $signature, $webhookSecret);
    } catch (Throwable $exception) {
        $signatureClass = '\\Stripe\\Exception\\SignatureVerificationException';
        if ($exception instanceof UnexpectedValueException) {
            http_response_code(400);
            echo json_encode(['error' => 'Payload non valido']);
            exit;
        }
        if (is_a($exception, $signatureClass)) {
            http_response_code(400);
            echo json_encode(['error' => 'Firma webhook non valida']);
            exit;
        }

        portal_error_log('Stripe webhook parsing error', [
            'error' => $exception->getMessage(),
        ]);
        http_response_code(400);
        echo json_encode(['error' => 'Impossibile validare il webhook Stripe']);
        exit;
    }
} else {
    portal_error_log('Stripe webhook secret missing', []);
    http_response_code(400);
    echo json_encode(['error' => 'Webhook Stripe non configurato sul server.']);
    exit;
}

$type = is_object($event) ? ($event->type ?? '') : ($event['type'] ?? '');
$dataObject = is_object($event)
    ? ($event->data->object ?? null)
    : ($event['data']['object'] ?? null);

$manager = new PickupPortalPaymentManager();

switch ($type) {
    case 'checkout.session.completed':
        $reference = null;
        if (is_object($dataObject)) {
            $reference = $dataObject->client_reference_id ?? null;
            if ($reference === null && isset($dataObject->metadata->portal_payment_reference)) {
                $reference = $dataObject->metadata->portal_payment_reference;
            }
        } elseif (is_array($dataObject)) {
            $reference = $dataObject['client_reference_id'] ?? ($dataObject['metadata']['portal_payment_reference'] ?? null);
        }

        if (is_string($reference) && $reference !== '') {
            $payment = $manager->findPendingByReference($reference) ?? $manager->findByReference($reference);
            if (is_array($payment)) {
                try {
                    portal_finalize_payment($payment, (int) $payment['customer_id']);
                } catch (\Throwable $exception) {
                    portal_error_log('Stripe webhook finalization error', [
                        'reference' => $reference,
                        'type' => $type,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }
        break;

    case 'checkout.session.expired':
        $reference = null;
        if (is_object($dataObject)) {
            $reference = $dataObject->client_reference_id ?? null;
            if ($reference === null && isset($dataObject->metadata->portal_payment_reference)) {
                $reference = $dataObject->metadata->portal_payment_reference;
            }
        } elseif (is_array($dataObject)) {
            $reference = $dataObject['client_reference_id'] ?? ($dataObject['metadata']['portal_payment_reference'] ?? null);
        }
        if (is_string($reference) && $reference !== '') {
            $manager->markCancelled($reference);
        }
        break;

    case 'payment_intent.payment_failed':
        $reference = null;
        $errorMessage = 'Pagamento non riuscito su Stripe.';
        if (is_object($dataObject)) {
            if (isset($dataObject->metadata->portal_payment_reference)) {
                $reference = $dataObject->metadata->portal_payment_reference;
            }
            if (isset($dataObject->last_payment_error->message) && is_string($dataObject->last_payment_error->message)) {
                $errorMessage = $dataObject->last_payment_error->message;
            }
        } elseif (is_array($dataObject)) {
            $reference = $dataObject['metadata']['portal_payment_reference'] ?? null;
            if (isset($dataObject['last_payment_error']['message'])) {
                $errorMessage = (string) $dataObject['last_payment_error']['message'];
            }
        }
        if (is_string($reference) && $reference !== '') {
            $payment = $manager->findByReference($reference);
            if (is_array($payment)) {
                $manager->markFailed((int) $payment['id'], $errorMessage);
            }
        }
        break;

    default:
        // Ignora altri eventi
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);
