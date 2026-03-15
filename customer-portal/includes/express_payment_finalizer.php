<?php
declare(strict_types=1);

use RuntimeException;
use Throwable;

require_once __DIR__ . '/express_payments.php';
require_once __DIR__ . '/stripe.php';

function express_portal_finalize_payment(array $paymentRow, int $portalCustomerId): array
{
    $manager = new ExpressPortalPaymentManager();
    $paymentId = (int) ($paymentRow['id'] ?? 0);
    $status = (string) ($paymentRow['status'] ?? 'pending');

    if ($paymentId <= 0) {
        throw new RuntimeException('Pagamento Express non valido.');
    }

    if (!in_array($status, ['pending', 'processing'], true)) {
        return [
            'status' => $status,
            'payment' => $paymentRow,
            'message' => null,
        ];
    }

    if ($status === 'pending') {
        $locked = $manager->transitionStatus($paymentId, 'pending', 'processing');
        if (!$locked) {
            $latest = $manager->findById($paymentId) ?? $paymentRow;
            return [
                'status' => (string) ($latest['status'] ?? $status),
                'payment' => $latest,
                'message' => 'Pagamento già in elaborazione.',
            ];
        }
        $paymentRow['status'] = 'processing';
    }

    $sessionId = (string) ($paymentRow['stripe_session_id'] ?? '');
    if ($sessionId === '') {
        $manager->transitionStatus($paymentId, 'processing', 'pending');
        throw new RuntimeException('Sessione Stripe non disponibile.');
    }

    try {
        $stripe = portal_stripe_client();
        $session = $stripe->checkout->sessions->retrieve($sessionId, []);
    } catch (Throwable $exception) {
        $manager->transitionStatus($paymentId, 'processing', 'pending');
        throw new RuntimeException('Recupero sessione Stripe fallito: ' . $exception->getMessage());
    }

    $paymentStatus = (string) ($session->payment_status ?? '');
    $paymentIntentId = isset($session->payment_intent) ? (string) $session->payment_intent : null;
    if ($paymentStatus !== 'paid') {
        $manager->transitionStatus($paymentId, 'processing', 'pending');
        return [
            'status' => 'pending',
            'payment' => $manager->findById($paymentId) ?? $paymentRow,
            'message' => 'Pagamento non ancora confermato da Stripe.',
        ];
    }

    $requestId = isset($paymentRow['request_id']) ? (int) $paymentRow['request_id'] : 0;
    $businessCustomerId = (int) ($paymentRow['business_customer_id'] ?? 0);
    $title = (string) ($paymentRow['title'] ?? 'Pagamento Express');
    $description = (string) ($paymentRow['description'] ?? $title);
    $amount = ((int) ($paymentRow['amount_cents'] ?? 0)) / 100;

    $pdo = portal_db();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $request = null;
        if ($requestId > 0) {
            $request = portal_fetch_one('SELECT * FROM servizi_express_richieste WHERE id = ? AND cliente_id = ? LIMIT 1', [$requestId, $businessCustomerId]);
            if ($request === null) {
                throw new RuntimeException('Richiesta Express non trovata per il pagamento.');
            }
        }

        $movementId = (int) portal_insert('entrate_uscite', [
            'cliente_id' => $businessCustomerId,
            'tipo_movimento' => 'Entrata',
            'descrizione' => mb_substr('Pagamento Express portale - ' . $description, 0, 180),
            'listino_voce' => mb_substr($title, 0, 180),
            'metodo' => 'Stripe',
            'stato' => 'Pagato',
            'importo' => round($amount, 2),
            'quantita' => 1,
            'prezzo_unitario' => round($amount, 2),
            'data_pagamento' => date('Y-m-d'),
            'note' => $requestId > 0 ? 'Pagamento portale Express richiesta #' . $requestId : 'Pagamento portale Express',
        ]);

        if ($request !== null) {
            $internalNote = trim((string) ($request['nota_interna'] ?? ''));
            $internalNote .= ($internalNote !== '' ? "\n" : '') . 'Pagamento portale confermato il ' . date('d/m/Y H:i') . ' - rif. ' . (string) ($paymentRow['public_reference'] ?? '');

            portal_update('servizi_express_richieste', [
                'stato' => in_array((string) ($request['stato'] ?? ''), ['Completed', 'Cancelled', 'Declined'], true) ? (string) $request['stato'] : 'Confirmed',
                'metodo_pagamento' => 'Stripe',
                'nota_interna' => $internalNote,
                'gestita_il' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $requestId]);
        }

        portal_insert('pickup_customer_notifications', [
            'customer_id' => $portalCustomerId,
            'type' => 'system_message',
            'title' => 'Pagamento Express confermato',
            'message' => 'Il pagamento "' . $title . '" è stato confermato correttamente.',
            'tracking_code' => null,
            'sent_via_email' => 0,
            'sent_via_sms' => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $manager->markPaid($paymentId, $movementId, $paymentIntentId);

        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $manager->markFailed($paymentId, $exception->getMessage());
        throw $exception;
    }

    return [
        'status' => 'paid',
        'payment' => $manager->findById($paymentId) ?? $paymentRow,
        'message' => 'Pagamento Express confermato correttamente.',
    ];
}