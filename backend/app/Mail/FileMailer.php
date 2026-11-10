<?php
declare(strict_types=1);

namespace LeenTrack\Mail;

/** Dev/test mailer: appends to a file and keeps an in-memory outbox for assertions. NEVER used in production. */
final class FileMailer implements Mailer
{
    /** @var list<array{to:string,subject:string,text:string}> */
    public static array $outbox = [];

    public function __construct(private readonly ?string $file = null)
    {
    }

    public function send(string $to, string $subject, string $text): void
    {
        self::$outbox[] = ['to' => $to, 'subject' => $subject, 'text' => $text];
        if ($this->file !== null) {
            @file_put_contents($this->file, "To: $to\nSubject: $subject\n\n$text\n----\n", FILE_APPEND | LOCK_EX);
        }
    }

    /** @return array{to:string,subject:string,text:string}|null */
    public static function last(?string $to = null): ?array
    {
        for ($i = count(self::$outbox) - 1; $i >= 0; $i--) {
            if ($to === null || self::$outbox[$i]['to'] === $to) { return self::$outbox[$i]; }
        }
        return null;
    }
}
