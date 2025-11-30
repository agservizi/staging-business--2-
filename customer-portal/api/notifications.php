<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pickup_service.php';

// Autenticazione richiesta
$customer = require_authentication();
$pickupService = new PickupService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Ottieni notifiche
        $unreadOnly = isset($_GET['unread']) && $_GET['unread'] === '1';
        $limit = min((int) ($_GET['limit'] ?? 50), portal_config('max_page_size'));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        
        $notifications = $pickupService->getCustomerNotifications($customer['id'], [
            'unread_only' => $unreadOnly,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        // Conta notifiche non lette
        $unreadCount = portal_count(
            'SELECT COUNT(*) FROM pickup_customer_notifications WHERE customer_id = ? AND read_at IS NULL',
            [$customer['id']]
        );
        
        // Formatta le notifiche per l'output
        $formattedNotifications = array_map(function($notification) use ($pickupService) {
            return [
                'id' => $notification['id'],
                'type' => $notification['type'],
                'title' => $notification['title'],
                'message' => $notification['message'],
                'tracking_code' => $notification['tracking_code'],
                'read_at' => $notification['read_at'],
                'created_at' => $notification['created_at'],
                'icon' => $pickupService->getNotificationIcon($notification['type']),
                'is_read' => !empty($notification['read_at'])
            ];
        }, $notifications);
        
        api_success([
            'notifications' => $formattedNotifications,
            'count' => $unreadCount,
            'total' => count($notifications),
            'has_more' => count($notifications) === $limit
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
        // Marca notifica come letta
        $data = get_json_input();
        validate_csrf_token($data);
        
        $notificationId = (int) ($data['notification_id'] ?? 0);
        
        if ($notificationId <= 0) {
            api_error('ID notifica non valido');
        }
        
        $success = $pickupService->markNotificationAsRead($customer['id'], $notificationId);
        
        if ($success) {
            api_success(null, 'Notifica marcata come letta');
        } else {
            api_error('Notifica non trovata o già letta');
        }
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Marca tutte le notifiche come lette
        $data = get_json_input();
        validate_csrf_token($data);
        
        $updated = portal_update(
            'pickup_customer_notifications',
            ['read_at' => date('Y-m-d H:i:s')],
            ['customer_id' => $customer['id'], 'read_at' => null]
        );
        
        api_success(['updated' => $updated], 'Tutte le notifiche sono state marcate come lette');
        
    } else {
        api_error('Metodo non consentito', 405);
    }
    
} catch (Exception $e) {
    portal_error_log('Notifications API error: ' . $e->getMessage(), [
        'customer_id' => $customer['id'],
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
    
    api_error('Errore durante la gestione delle notifiche');
}