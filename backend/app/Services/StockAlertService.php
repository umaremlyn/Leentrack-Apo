<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Request;
use LeenTrack\Core\RateLimiter;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Mail\Mailer;
use PDO;

/**
 * "E-mail me when stock runs low" (port of the low-stock-alert edge function, minus its flaws).
 * Same rule as everywhere else: an active stocked product is low when stock <= its reorder level (0 = out of stock).
 * The job honours the cool-down (daily = 20 h, hourly = 1 h) and only counts a mail as sent when there was something to report.
 * Flaws fixed: the source let any owner send test mails to any address without limit (now rate-limited), computed stock from a global
 * number (now the real total), and wrote the "last sent" time before knowing anything was sent.
 */
final class StockAlertService
{
    private const COOLDOWN = ['daily' => 72000, 'hourly' => 3600];
    private const MAX_LINES = 100;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Mailer $mailer,
        private readonly AuditService $audit,
        private readonly RateLimiter $limiter,
        private readonly string $webUrl,
        private readonly bool $rateLimit = true,
    ) {
    }

    /** @return array<string,mixed> */
    public function settings(TenantContext $t): array
    {
        $t->require('business.update');
        return $this->view($t->businessId);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function save(TenantContext $t, array $body, Request $req): array
    {
        $t->require('business.update');
        $v = new Validator($body);
        $v->only(['enabled', 'email', 'frequency']);
        $cur = $this->row($t->businessId);
        $enabled = $v->has('enabled') ? (bool) $v->bool('enabled', true) : (bool) $cur['enabled'];
        $email = $v->has('email') ? $v->email('email', false) : $cur['email'];
        $freq = $v->has('frequency') ? $v->enum('frequency', array_keys(self::COOLDOWN)) : (string) $cur['frequency'];
        if ($enabled && ($email === null || $email === '')) { $v->addError('email', 'Enter the address the alerts should go to.'); }
        $v->done();
        $this->pdo->prepare('INSERT INTO stock_alert_settings (business_id,enabled,email,frequency,updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), email = VALUES(email), frequency = VALUES(frequency), updated_by = VALUES(updated_by)')
            ->execute([$t->businessId, (int) $enabled, $email, $freq, $t->userId]);
        $this->audit->log('alerts.settings', $req, $t->userId, $t->businessId, 'business', $t->businessId, ['enabled' => (bool) $cur['enabled'], 'frequency' => $cur['frequency']], ['enabled' => $enabled, 'frequency' => $freq]);
        return $this->view($t->businessId);
    }

    /** The "Send test alert now" button: ignores the switch and the cool-down, but not the rate limit. @return array<string,mixed> */
    public function test(TenantContext $t, Request $req): array
    {
        $t->require('business.update');
        if ($this->rateLimit) {
            $r = $this->limiter->hit('alert-test|' . $t->businessId, 5, 3600);
            if (!$r['allowed']) { throw ApiException::tooMany($r['retry_after']); }
        }
        $cur = $this->row($t->businessId);
        $to = $cur['email'] ?: $this->ownerEmail($t->businessId);
        if ($to === null) { throw ApiException::validation(['email' => ['No address to send to. Save one first.']]); }
        $out = $this->sendFor($t->businessId, $to, true);
        $this->audit->log('alerts.test', $req, $t->userId, $t->businessId, 'business', $t->businessId, null, ['sent' => $out['sent'], 'count' => $out['count']]);
        return $out;
    }

    /** Cron entry. @return array{checked:int,sent:int,skipped:int,failed:int} */
    public function runDue(): array
    {
        $out = ['checked' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        $now = Clock::now();
        $st = $this->pdo->query("SELECT s.business_id, s.email, s.frequency, s.last_sent_at FROM stock_alert_settings s JOIN businesses b ON b.id = s.business_id AND b.deleted_at IS NULL
            JOIN users u ON u.id = b.owner_user_id AND u.status = 'active' WHERE s.enabled = 1 ORDER BY s.business_id");
        foreach ($st->fetchAll() as $s) {
            $out['checked']++;
            try {
                if ($s['last_sent_at'] !== null && $now->getTimestamp() - strtotime($s['last_sent_at'] . ' UTC') < self::COOLDOWN[$s['frequency']]) { $out['skipped']++; continue; }
                $to = $s['email'] ?: $this->ownerEmail((string) $s['business_id']);
                if ($to === null) { $out['skipped']++; continue; }
                $r = $this->sendFor((string) $s['business_id'], $to, false);
                $r['sent'] ? $out['sent']++ : $out['skipped']++;
            } catch (\Throwable $e) {
                $out['failed']++;
                error_log('stock alert failed: ' . get_class($e));
            }
        }
        return $out;
    }

    // ================================================================================================ internals
    /** @return array{sent:bool,count:int,to:?string,reason?:string} */
    private function sendFor(string $bid, string $to, bool $force): array
    {
        $b = $this->pdo->prepare('SELECT name FROM businesses WHERE id = ? AND deleted_at IS NULL');
        $b->execute([$bid]);
        $name = (string) ($b->fetchColumn() ?: throw ApiException::notFound());
        $items = $this->lowStock($bid);
        if (!$items) { return ['sent' => false, 'count' => 0, 'to' => null, 'reason' => 'No low-stock products — all good!']; }
        $out = count(array_filter($items, fn ($i) => (int) $i['stock'] <= 0));
        $subject = $out > 0 ? "$out product(s) OUT OF STOCK — $name" : count($items) . " product(s) running low — $name";
        $lines = [];
        foreach (array_slice($items, 0, self::MAX_LINES) as $i) {
            $lines[] = '- ' . $this->oneLine((string) $i['name']) . ' — ' . ((int) $i['stock'] <= 0 ? 'OUT OF STOCK' : (int) $i['stock'] . ' left') . ' (reorder at ' . (int) $i['reorder_level'] . ')';
        }
        if (count($items) > self::MAX_LINES) { $lines[] = '… and ' . (count($items) - self::MAX_LINES) . ' more.'; }
        $text = "Stock alert for $name\n\n" . implode("\n", $lines) . "\n\nRestock soon so you don't miss any sales.\n" . ($this->webUrl !== '' ? $this->webUrl . "/purchases\n" : '') .
            "\nYou receive this because stock alerts are on for $name. Turn them off in Settings → Alerts.\n";
        $this->mailer->send($to, $subject, $text);
        $this->pdo->prepare('INSERT INTO stock_alert_settings (business_id,last_sent_at) VALUES (?,?) ON DUPLICATE KEY UPDATE last_sent_at = VALUES(last_sent_at)')->execute([$bid, Clock::sql()]);
        return ['sent' => true, 'count' => count($items), 'to' => $to];
    }

    /** @return list<array<string,mixed>> lowest stock first */
    private function lowStock(string $bid): array
    {
        $st = $this->pdo->prepare("SELECT name, stock_quantity AS stock, reorder_level FROM products WHERE business_id = ? AND archived_at IS NULL AND is_active = 1 AND item_type = 'product' AND stock_quantity <= reorder_level ORDER BY stock_quantity, name LIMIT 1000");
        $st->execute([$bid]);
        return $st->fetchAll();
    }

    private function oneLine(string $s): string
    {
        return mb_substr(preg_replace('/[\r\n\t]+/', ' ', $s) ?? '', 0, 120);
    }

    private function ownerEmail(string $bid): ?string
    {
        $st = $this->pdo->prepare('SELECT u.email FROM businesses b JOIN users u ON u.id = b.owner_user_id WHERE b.id = ?');
        $st->execute([$bid]);
        return $st->fetchColumn() ?: null;
    }

    /** @return array<string,mixed> */
    private function row(string $bid): array
    {
        $st = $this->pdo->prepare('SELECT enabled,email,frequency,last_sent_at FROM stock_alert_settings WHERE business_id = ?');
        $st->execute([$bid]);
        return $st->fetch() ?: ['enabled' => 0, 'email' => null, 'frequency' => 'daily', 'last_sent_at' => null];
    }

    /** @return array<string,mixed> */
    private function view(string $bid): array
    {
        $r = $this->row($bid);
        return ['enabled' => (bool) $r['enabled'], 'email' => $r['email'], 'default_email' => $this->ownerEmail($bid), 'frequency' => $r['frequency'],
            'last_sent_at' => $r['last_sent_at'] === null ? null : (new \DateTimeImmutable($r['last_sent_at'], new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')];
    }
}
