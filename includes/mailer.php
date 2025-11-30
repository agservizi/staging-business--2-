<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';

function send_system_mail(string $to, string $subject, string $htmlBody, array $options = []): bool
{
    load_env(__DIR__ . '/../.env');
    configure_timezone();

    $channel = 'system';
    if (isset($options['channel'])) {
        $channelValue = strtolower(trim((string) $options['channel']));
        if ($channelValue !== '') {
            $channel = $channelValue;
        }
        unset($options['channel']);
    }

    $fromAddress = trim((string) env('MAIL_FROM_ADDRESS', 'no-reply@example.com'));
    $fromName = trim((string) env('MAIL_FROM_NAME', 'Coresuite Business'));
    $replyToAddress = $fromAddress;
    $apiKey = trim((string) env('RESEND_API_KEY', ''));

    $hasStructuredOptions = isset($options['attachments']) || isset($options['metadata']) || isset($options['headers']);
    if ($hasStructuredOptions) {
        $attachments = isset($options['attachments']) && is_array($options['attachments']) ? $options['attachments'] : [];
        $metadata = isset($options['metadata']) && is_array($options['metadata']) ? $options['metadata'] : [];
    } else {
        $attachments = $options;
        $metadata = [];
    }

    if ($channel === 'marketing') {
        $marketingFrom = trim((string) env('MAIL_MARKETING_ADDRESS', ''));
        if ($marketingFrom !== '') {
            $fromAddress = $marketingFrom;
        }
        $marketingName = trim((string) env('MAIL_MARKETING_NAME', ''));
        if ($marketingName !== '') {
            $fromName = $marketingName;
        }
        $marketingReplyTo = trim((string) env('MAIL_MARKETING_REPLY_TO', ''));
        if ($marketingReplyTo !== '') {
            $replyToAddress = $marketingReplyTo;
        }
        $marketingApiKey = trim((string) env('RESEND_MARKETING_API_KEY', ''));
        if ($marketingApiKey !== '') {
            $apiKey = $marketingApiKey;
        }
    }

    $preparedAttachments = prepare_mail_attachments($attachments);

    if ($channel === 'marketing' && function_exists('get_email_marketing_config') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        try {
            $config = get_email_marketing_config($GLOBALS['pdo']);
            $configuredFrom = trim((string) ($config['sender_email'] ?? ''));
            $configuredName = trim((string) ($config['sender_name'] ?? ''));
            $configuredReplyTo = trim((string) ($config['reply_to_email'] ?? ''));
            $configuredApiKey = trim((string) ($config['resend_api_key'] ?? ''));

            if ($configuredFrom !== '') {
                $fromAddress = $configuredFrom;
            }
            if ($configuredName !== '') {
                $fromName = $configuredName;
            }
            if ($configuredReplyTo !== '') {
                $replyToAddress = $configuredReplyTo;
            }
            if ($configuredApiKey !== '') {
                $apiKey = $configuredApiKey;
            }
        } catch (\Throwable $exception) {
            error_log('Email marketing settings unavailable, fallback to environment: ' . $exception->getMessage());
        }
    }

    if ($replyToAddress === '') {
        $replyToAddress = $fromAddress;
    }

    if ($apiKey !== '') {
        $resendChannel = $channel === 'marketing' ? 'resend_marketing' : 'resend';
        $resendResult = send_mail_via_resend($apiKey, $fromAddress, $fromName, $replyToAddress, $to, $subject, $htmlBody, $preparedAttachments, $metadata, $resendChannel);
        if ($resendResult === true) {
            return true;
        }
    }

    return send_mail_via_php_mail($fromAddress, $fromName, $replyToAddress, $to, $subject, $htmlBody, $preparedAttachments);
}

function send_mail_via_resend(string $apiKey, string $fromAddress, string $fromName, string $replyTo, string $to, string $subject, string $htmlBody, array $attachments = [], array $metadata = [], string $logChannel = 'resend'): bool
{
    if (!function_exists('curl_init')) {
        log_mail_failure($logChannel, $to, $subject, 'cURL non disponibile sul server.');
        return false;
    }

    $payload = [
        'from' => trim($fromName) !== '' ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress,
        'to' => [$to],
        'subject' => $subject,
        'html' => $htmlBody,
    ];

    $replyToHeader = $replyTo !== '' ? $replyTo : $fromAddress;
    if ($replyToHeader !== '') {
        $payload['reply_to'] = $replyToHeader;
    }

    if ($attachments) {
        $payload['attachments'] = [];
        foreach ($attachments as $attachment) {
            $payload['attachments'][] = [
                'filename' => $attachment['name'],
                'content' => base64_encode($attachment['content']),
                'content_type' => $attachment['mime'],
            ];
        }
    }

    if ($metadata) {
        $tags = [];
        foreach ($metadata as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $tags[(string) $key] = (string) $value;
        }

        if ($tags) {
            $payload['tags'] = $tags;
        }
    }

    try {
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    } catch (JsonException $exception) {
        log_mail_failure($logChannel, $to, $subject, 'Serializzazione JSON fallita: ' . $exception->getMessage());
        return false;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 10,
    ]);

    $caBundle = trim((string) env('RESEND_CA_BUNDLE', ''));
    if ($caBundle === '') {
        $defaultCa = __DIR__ . '/../certs/cacert.pem';
        if (is_file($defaultCa)) {
            $resolved = realpath($defaultCa);
            $caBundle = $resolved !== false ? $resolved : $defaultCa;
        }
    }

    if ($caBundle !== '' && is_file($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
    }

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false || $curlError !== '') {
        log_mail_failure($logChannel, $to, $subject, 'Errore cURL: ' . ($curlError !== '' ? $curlError : 'risposta vuota'));
        return false;
    }

    if ($statusCode >= 200 && $statusCode < 300) {
        return true;
    }

    $errorMessage = 'Status HTTP ' . $statusCode;
    $decoded = json_decode($responseBody, true);
    if (is_array($decoded)) {
        $message = $decoded['error']['message'] ?? $decoded['message'] ?? null;
        if ($message) {
            $errorMessage .= ' - ' . $message;
        }
    }

    log_mail_failure($logChannel, $to, $subject, $errorMessage);
    return false;
}

function send_mail_via_php_mail(string $fromAddress, string $fromName, string $replyTo, string $to, string $subject, string $htmlBody, array $attachments = []): bool
{
    $headers = [];
    $headers[] = 'From: ' . sprintf('"%s" <%s>', addslashes($fromName), $fromAddress);
    $replyToHeader = $replyTo !== '' ? $replyTo : $fromAddress;
    $headers[] = 'Reply-To: ' . $replyToHeader;
    $headers[] = 'MIME-Version: 1.0';

    if ($attachments) {
        $boundary = '=_MailPart_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

        $body = '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
        $body .= $htmlBody . "\r\n";

        foreach ($attachments as $attachment) {
            $encodedContent = chunk_split(base64_encode($attachment['content']));
            $body .= '--' . $boundary . "\r\n";
            $body .= sprintf("Content-Type: %s; name=\"%s\"\r\n", $attachment['mime'], addslashes($attachment['name']));
            $body .= sprintf("Content-Disposition: attachment; filename=\"%s\"\r\n", addslashes($attachment['name']));
            $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
            $body .= $encodedContent . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";
    } else {
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $body = $htmlBody;
    }

    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $success = mail($to, $encodedSubject, $body, implode("\r\n", $headers));

    if (!$success) {
        log_mail_failure('mail', $to, $subject, 'La funzione mail() ha restituito false.');
    }

    return $success;
}

function log_mail_failure(string $channel, string $recipient, string $subject, string $message): void
{
    $logDir = __DIR__ . '/../backups';
    if (!is_dir($logDir) && !mkdir($logDir, 0775, true) && !is_dir($logDir)) {
        return;
    }

    $logMessage = sprintf(
        '[%s][%s] Mail fallita verso %s (oggetto: %s) - %s%s',
        date('c'),
        strtoupper($channel),
        $recipient,
        $subject,
        $message,
        PHP_EOL
    );

    file_put_contents($logDir . '/email.log', $logMessage, FILE_APPEND);
}

function render_mail_template(string $title, string $content): string
{
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{$title}</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f6f6f6; padding: 24px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <div style="background: #0b2f6b; color: #ffffff; padding: 16px 24px; border-bottom: 4px solid #12468f;">
            <h1 style="margin: 0; font-size: 20px; letter-spacing: 0.04em;">Coresuite Business</h1>
        </div>
        <div style="padding: 24px; color: #1c2534; line-height: 1.5;">
            {$content}
        </div>
        <div style="padding: 16px 24px; font-size: 12px; color: #6c7d93; background: #f1f3f5;">
            &copy; {$year} Coresuite Business. Questo è un messaggio automatico, non rispondere a questa email.
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * @return array<int, array{name:string,mime:string,content:string}>
 */
function prepare_mail_attachments(array $attachments): array
{
    $prepared = [];

    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $name = trim((string) ($attachment['name'] ?? ''));
        if ($name === '') {
            continue;
        }

        $mime = trim((string) ($attachment['mime'] ?? 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $content = null;

        if (isset($attachment['content'])) {
            $content = (string) $attachment['content'];
        } elseif (isset($attachment['path'])) {
            $path = (string) $attachment['path'];
            if (is_file($path) && is_readable($path)) {
                $data = file_get_contents($path);
                if ($data !== false) {
                    $content = $data;
                }
            }
        }

        if ($content === null) {
            continue;
        }

        $prepared[] = [
            'name' => $name,
            'mime' => $mime,
            'content' => $content,
        ];
    }

    return $prepared;
}
