<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pickup_service.php';

require_method('GET');

// Autenticazione richiesta
$customer = require_authentication();
$pickupService = new PickupService();

try {
    // Ottieni statistiche del cliente
    $stats = $pickupService->getCustomerStats($customer['id']);
    
    // Ottieni numero notifiche non lette
    $unreadNotifications = portal_count(
        'SELECT COUNT(*) FROM pickup_customer_notifications WHERE customer_id = ? AND read_at IS NULL',
        [$customer['id']]
    );
    
    $stats['unread_notifications'] = $unreadNotifications;
    
    api_success(['stats' => $stats]);
    
} catch (Exception $e) {
    portal_error_log('Stats API error: ' . $e->getMessage(), [
        'customer_id' => $customer['id']
    ]);
    
    api_error('Errore durante il caricamento delle statistiche');
}