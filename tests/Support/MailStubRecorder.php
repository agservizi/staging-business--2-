<?php
declare(strict_types=1);

namespace Tests\Support;

final class MailStubRecorder
{
    /**
     * @var array<int,array{recipient:string,subject:string,body:string}>
     */
    private static array $messages = [];

    public static function reset(): void
    {
        self::$messages = [];
    }

    public static function record(string $recipient, string $subject, string $body): void
    {
        self::$messages[] = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * @return array<int,array{recipient:string,subject:string,body:string}>
     */
    public static function messages(): array
    {
        return self::$messages;
    }

    /**
     * @return array{recipient:string,subject:string,body:string}|null
     */
    public static function last(): ?array
    {
        if (!self::$messages) {
            return null;
        }

        return self::$messages[array_key_last(self::$messages)];
    }
}
