<?php
declare(strict_types=1);

if (!function_exists('run_background_maintenance')) {
    function run_background_maintenance(PDO $pdo): void
    {
        require_once __DIR__ . '/appointment_scheduler.php';
        maybe_dispatch_appointment_reminders($pdo);

        require_once __DIR__ . '/daily_report_scheduler.php';
        maybe_generate_daily_financial_reports($pdo);

        require_once __DIR__ . '/energia_reminder_scheduler.php';
        maybe_send_energia_reminders($pdo);

        require_once __DIR__ . '/telegrammi_sync_scheduler.php';
        maybe_sync_telegrammi($pdo);

        require_once __DIR__ . '/posta_telematica_sync_scheduler.php';
        maybe_sync_posta_telematica_pec($pdo);

        require_once __DIR__ . '/brt_tracking_scheduler.php';
        maybe_refresh_brt_tracking($pdo);

        require_once __DIR__ . '/morosita_scheduler.php';
        maybe_refresh_morosita($pdo);
    }
}
