<?php
declare(strict_types=1);

namespace App\Services\CAFPatronato;

use Tests\Support\MailStubRecorder;

if (!function_exists(__NAMESPACE__ . '\\send_system_mail')) {
    function send_system_mail(string $recipient, string $subject, string $body): bool
    {
        MailStubRecorder::record($recipient, $subject, $body);

        return true;
    }
}

if (!function_exists(__NAMESPACE__ . '\\render_mail_template')) {
    function render_mail_template(string $title, string $body): string
    {
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        return '<h1>' . $safeTitle . '</h1>' . $body;
    }
}

if (!function_exists(__NAMESPACE__ . '\\base_url')) {
    function base_url(string $path = ''): string
    {
        $normalized = ltrim($path, '/');

        return 'https://test.local/' . $normalized;
    }
}
