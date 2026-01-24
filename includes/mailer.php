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

    if ($channel === 'pec') {
        return send_mail_via_smtp_pec($to, $subject, $htmlBody, $preparedAttachments, $options);
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

function send_mail_via_smtp_pec(string $to, string $subject, string $htmlBody, array $attachments = [], array $options = []): bool
{
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer') && is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
        log_mail_failure('pec', $to, $subject, 'PHPMailer non disponibile. Esegui composer install.');
        return false;
    }

    $host = trim((string) env('PEC_SMTP_HOST', ''));
    $port = (int) env('PEC_SMTP_PORT', 465);
    $username = trim((string) env('PEC_SMTP_USERNAME', ''));
    $password = (string) env('PEC_SMTP_PASSWORD', '');
    $encryption = strtolower(trim((string) env('PEC_SMTP_ENCRYPTION', 'ssl')));
    $fromAddress = trim((string) env('PEC_FROM_ADDRESS', $username));
    $fromName = trim((string) env('PEC_FROM_NAME', env('MAIL_FROM_NAME', 'Coresuite Business')));
    $replyTo = trim((string) env('PEC_REPLY_TO', $fromAddress));
    $verifySsl = filter_var(env('PEC_SMTP_VERIFY_SSL', 'true'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

    if ($host === '' || $fromAddress === '' || $username === '' || $password === '') {
        log_mail_failure('pec', $to, $subject, 'Configurazione PEC SMTP incompleta.');
        return false;
    }

    try {
        $mailerClass = '\\PHPMailer\\PHPMailer\\PHPMailer';
        $mail = new $mailerClass(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPAuth = true;
        $mail->Username = $username;
        $mail->Password = $password;
        if ($encryption === 'tls' || $encryption === 'starttls') {
            $mail->SMTPSecure = (string) constant($mailerClass . '::ENCRYPTION_STARTTLS');
        } elseif ($encryption === 'ssl') {
            $mail->SMTPSecure = (string) constant($mailerClass . '::ENCRYPTION_SMTPS');
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }

        if (!$verifySsl) {
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true,
                ],
            ];
        }

        $mail->CharSet = 'UTF-8';
        $mail->setFrom($fromAddress, $fromName !== '' ? $fromName : $fromAddress);
        if ($replyTo !== '') {
            $mail->addReplyTo($replyTo);
        }
        $mail->addAddress($to);
        $mail->Subject = $subject;
        if (!empty($options['message_id'])) {
            $mail->MessageID = (string) $options['message_id'];
        }

        $mail->isHTML(true);
        $mail->Body = $htmlBody;

        foreach ($attachments as $attachment) {
            $name = $attachment['name'] ?? 'allegato';
            $content = $attachment['content'] ?? '';
            $mime = $attachment['mime'] ?? 'application/octet-stream';
            if ($content !== '') {
                $mail->addStringAttachment($content, $name, 'base64', $mime);
            }
        }

        $mail->send();
        return true;
    } catch (\Throwable $exception) {
        log_mail_failure('pec', $to, $subject, 'Errore SMTP PEC: ' . $exception->getMessage());
        return false;
    }
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

function send_opportunity_confirmation_email(array $payload): bool
{
    $customerEmail = trim((string) ($payload['customer_email'] ?? ''));
    if ($customerEmail === '') {
        return false;
    }

    $category = (string) ($payload['category'] ?? '');
    $code = (string) ($payload['code'] ?? '');
    $categoryLabel = match ($category) {
        'telefonia' => 'contratto telefonico',
        'luce' => 'fornitura luce',
        'gas' => 'fornitura gas',
        default => 'richiesta',
    };

    $customerName = trim(
        sprintf(
            '%s %s',
            (string) ($payload['customer_first_name'] ?? ''),
            (string) ($payload['customer_last_name'] ?? '')
        )
    );

    $providerLabel = (string) ($payload['provider_label'] ?? 'gestore selezionato');
    $offerLabel = (string) ($payload['offer_label'] ?? 'offerta dedicata');

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    $primaryText = $code !== ''
        ? sprintf('Codice richiesta %s', $code)
        : 'Richiesta registrata';

    $content = <<<HTML
        <p style="font-size: 16px; margin-top: 0;">Ciao {$escape($customerName)}.</p>
        <p style="margin-bottom: 16px;">Abbiamo ricevuto la tua richiesta per {$escape($categoryLabel)} con il gestore <strong>{$escape($providerLabel)}</strong> e l'offerta <strong>{$escape($offerLabel)}</strong>. I nostri operatori stanno verificando i dati inviati.</p>
        <div style="background: #0b2f6b; color: #fff; padding: 18px 24px; border-radius: 12px; margin-bottom: 20px;">
            <p style="margin: 0; font-size: 14px; letter-spacing: 0.08em; text-transform: uppercase; opacity: 0.85;">{$escape($categoryLabel)}</p>
            <p style="margin: 4px 0 0; font-size: 24px; font-weight: 600;">{$escape($primaryText)}</p>
        </div>
        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
            <div style="flex: 1 1 160px; min-width: 140px; background: #eef4ff; border-radius: 12px; padding: 16px;">
                <p style="margin: 0; font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: #4b6cb7;">Step 1</p>
                <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: #1c2534;">Verifica documenti</p>
                <p style="margin: 6px 0 0; font-size: 13px; color: #4d5a6d;">Confermeremo i dati identificativi e i consensi caricati.</p>
            </div>
            <div style="flex: 1 1 160px; min-width: 140px; background: #fef6e7; border-radius: 12px; padding: 16px;">
                <p style="margin: 0; font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: #bb6b00;">Step 2</p>
                <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: #1c2534;">Firma OTP</p>
                <p style="margin: 6px 0 0; font-size: 13px; color: #4d5a6d;">Riceverai email/SMS dal gestore per la firma digitale.</p>
            </div>
            <div style="flex: 1 1 160px; min-width: 140px; background: #eafaf0; border-radius: 12px; padding: 16px;">
                <p style="margin: 0; font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; color: #0f7b3d;">Step 3</p>
                <p style="margin: 4px 0 0; font-size: 15px; font-weight: 600; color: #1c2534;">Attivazione</p>
                <p style="margin: 6px 0 0; font-size: 13px; color: #4d5a6d;">Una volta firmato il contratto, riceverai la conferma attivazione.</p>
            </div>
        </div>
        <p style="margin-bottom: 16px;">Ricorda che ogni email o SMS di conferma inviato dai gestori ha validità di <strong>6 ore</strong>. Trascorso questo tempo senza firma, la richiesta viene annullata automaticamente e dovrà essere ripresentata.</p>
        <p style="margin-bottom: 16px;">Per qualsiasi dubbio puoi rispondere a questa comunicazione o contattare il nostro supporto indicando il codice <strong>{$escape($code)}</strong>.</p>
        <p style="margin-bottom: 0;">Grazie per aver scelto Coresuite Business.</p>
    HTML;

    $subject = $code !== ''
        ? sprintf('Richiesta %s ricevuta (%s)', $categoryLabel, $code)
        : sprintf('Richiesta %s ricevuta', $categoryLabel);

    $htmlBody = render_mail_template($subject, $content);

    return send_system_mail($customerEmail, $subject, $htmlBody, [
        'metadata' => array_filter([
            'opportunity_code' => $code,
            'opportunity_category' => $category,
        ]),
    ]);
}

function send_opportunity_status_update_email(array $payload): bool
{
    $recipient = trim((string) ($payload['collaborator_email'] ?? ''));
    if ($recipient === '') {
        return false;
    }

    $collaboratorName = trim((string) ($payload['collaborator_name'] ?? ''));
    $code = (string) ($payload['code'] ?? '');
    $category = (string) ($payload['category'] ?? '');
    $statusLabel = (string) ($payload['status_label'] ?? '');
    $statusCode = (string) ($payload['status_code'] ?? '');
    $statusDisplay = $statusLabel !== '' ? $statusLabel : ($statusCode !== '' ? strtoupper($statusCode) : 'Aggiornamento stato');
    $customerName = trim(
        sprintf(
            '%s %s',
            (string) ($payload['customer_first_name'] ?? ''),
            (string) ($payload['customer_last_name'] ?? '')
        )
    );
    $adminNotes = trim((string) ($payload['admin_notes'] ?? ''));
    $updatedAt = $payload['updated_at'] ?? null;

    $categoryLabel = match ($category) {
        'telefonia' => 'Telefonia',
        'luce' => 'Luce',
        'gas' => 'Gas',
        default => 'Opportunity',
    };

    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $timestampLabel = '';
    if ($updatedAt) {
        $timestampLabel = function_exists('format_datetime_locale')
            ? format_datetime_locale($updatedAt)
            : date('d/m/Y H:i', strtotime((string) $updatedAt));
    }

    $notesBlock = '';
    if ($adminNotes !== '') {
        $notesBlock = '<p style="margin: 0; font-size: 13px; color: #0f172a;">Nota interna:</p>' .
            '<blockquote style="margin: 8px 0 0; padding: 12px 16px; background: #f8fafc; border-left: 4px solid #0b2f6b; border-radius: 8px; color: #1e293b;">' . $escape($adminNotes) . '</blockquote>';
    }

    $content = <<<HTML
        <p style="font-size: 16px; margin-top: 0;">Ciao {$escape($collaboratorName ?: 'collega')}.</p>
        <p style="margin-bottom: 16px;">Abbiamo aggiornato lo stato della opportunity {$escape($code !== '' ? ('#' . $code) : '')} per il cliente <strong>{$escape($customerName ?: '—')}</strong>.</p>
        <div style="background: #0b2f6b; color: #fff; padding: 18px 24px; border-radius: 12px; margin-bottom: 20px;">
            <p style="margin: 0; font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; opacity: 0.85;">{$escape($categoryLabel)}</p>
            <p style="margin: 4px 0 0; font-size: 24px; font-weight: 600;">{$escape($statusDisplay)}</p>
            <p style="margin: 6px 0 0; font-size: 13px; color: rgba(255,255,255,0.8);">Aggiornato {$escape($timestampLabel ?: 'ora')}</p>
        </div>
        {$notesBlock}
        <p style="margin: 20px 0 0;">Per ulteriori modifiche puoi accedere all'area Opportunity e completare gli step richiesti.</p>
    HTML;

    $subject = $code !== ''
        ? sprintf('Opportunity %s aggiornata (%s)', $code, $statusDisplay)
        : sprintf('Opportunity aggiornata (%s)', $statusDisplay);

    $htmlBody = render_mail_template($subject, $content);

    return send_system_mail($recipient, $subject, $htmlBody, [
        'metadata' => array_filter([
            'opportunity_code' => $code,
            'opportunity_category' => $category,
            'opportunity_status' => $statusCode ?: $statusLabel,
        ]),
    ]);
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
