<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Migrator;
use PDO;

/**
 * The scheduled-job contract for shared hosting (cron only, no workers):
 *  - one run per job at a time (an exclusive lock file; a second cron tick while the first is still running exits quietly),
 *  - every run is recorded (started, finished, ok/failed, a small JSON summary) so the operator can SEE that the jobs really run,
 *  - a job that stops running is flagged as stale on the admin health page.
 * Exit codes of `bin/cron.php <job>`: 0 ok (or skipped because already running), 1 job failed, 2 usage error, 4 the job ran but reported findings.
 */
final class JobService
{
    /** job => [what it does, suggested schedule, seconds after which a missing run is suspicious] */
    public const JOBS = [
        'housekeeping' => ['Removes expired tokens, sessions, rate-limit counters and old job history', 'every 30 minutes', 7200],
        'expire-plans' => ['Moves businesses whose paid period ended to the free plan; deletes payment records past retention', 'daily, 01:00', 129600],
        'purge-accounts' => ['Permanently deletes accounts whose 14-day deletion grace has ended', 'daily, 02:15', 129600],
        'low-stock-alert' => ['E-mails owners who switched on low-stock alerts', 'hourly', 10800],
        'reconcile' => ['Read-only audit of stock, customer, credit, purchase, transfer and billing totals; reports drift', 'daily, 03:30', 129600],
    ];

    public function __construct(private readonly PDO $pdo, private readonly string $lockDir)
    {
    }

    /**
     * Runs $fn under the job's lock and records the run. Never throws.
     * @param callable():array<string,mixed> $fn
     * @return array{status:string,summary:array<string,mixed>}  status: ok | failed | skipped
     */
    public function run(string $job, callable $fn): array
    {
        if (!is_dir($this->lockDir) && !@mkdir($this->lockDir, 0750, true) && !is_dir($this->lockDir)) {
            return ['status' => 'failed', 'summary' => ['error' => 'lock directory is not writable']];
        }
        $fh = @fopen($this->lockDir . '/' . preg_replace('/[^a-z-]/', '', $job) . '.lock', 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            if (is_resource($fh)) { fclose($fh); }
            return ['status' => 'skipped', 'summary' => ['reason' => 'already_running']];
        }
        $this->pdo->prepare("INSERT INTO job_runs (job, status, started_at) VALUES (?, 'running', ?)")->execute([$job, Clock::sql()]);
        $id = (int) $this->pdo->lastInsertId();
        try {
            $summary = $fn();
            $status = 'ok';
        } catch (\Throwable $e) {
            $status = 'failed';
            $summary = ['error' => get_class($e), 'message' => mb_substr($e->getMessage(), 0, 200)];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        $this->pdo->prepare('UPDATE job_runs SET status = ?, finished_at = ?, summary = ? WHERE id = ?')->execute([$status, Clock::sql(), json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR), $id]);
        return ['status' => $status, 'summary' => $summary];
    }

    /** @return list<array<string,mixed>> one entry per known job with its last run and a staleness flag */
    public function overview(): array
    {
        $now = Clock::now()->getTimestamp();
        $out = [];
        foreach (self::JOBS as $job => [$what, $schedule, $stale]) {
            $st = $this->pdo->prepare('SELECT id,status,started_at,finished_at,summary FROM job_runs WHERE job = ? ORDER BY id DESC LIMIT 1');
            $st->execute([$job]);
            $r = $st->fetch() ?: null;
            $status = $r['status'] ?? 'never_run';
            if ($r !== null && $r['status'] === 'running' && $now - strtotime($r['started_at'] . ' UTC') > 3600) { $status = 'crashed'; }
            $last = $r['finished_at'] ?? $r['started_at'] ?? null;
            $isStale = $r === null || $now - strtotime($last . ' UTC') > $stale || in_array($status, ['crashed', 'failed'], true);
            $out[] = ['job' => $job, 'description' => $what, 'schedule' => $schedule, 'last_status' => $status, 'last_started_at' => $r === null ? null : $this->iso($r['started_at']),
                'last_finished_at' => $r === null || $r['finished_at'] === null ? null : $this->iso($r['finished_at']), 'last_summary' => $r === null || $r['summary'] === null ? null : json_decode((string) $r['summary'], true), 'attention' => $isStale];
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function history(string $job, int $limit = 20): array
    {
        $st = $this->pdo->prepare('SELECT id,status,started_at,finished_at,summary FROM job_runs WHERE job = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limit)));
        $st->execute([$job]);
        return array_map(fn ($r) => ['id' => (int) $r['id'], 'status' => $r['status'], 'started_at' => $this->iso($r['started_at']), 'finished_at' => $r['finished_at'] === null ? null : $this->iso($r['finished_at']),
            'summary' => $r['summary'] === null ? null : json_decode((string) $r['summary'], true)], $st->fetchAll());
    }

    /** Facts the operator needs to know the host is set up correctly. Never includes secrets. @param array<string,mixed> $cfg @return array{ok:bool,checks:list<array{name:string,status:string,detail:string}>} */
    public function health(array $cfg, string $migrationsDir, ?callable $extensionLoaded = null): array
    {
        $has = $extensionLoaded ?? 'extension_loaded';
        $c = [];
        $add = function (string $name, string $status, string $detail) use (&$c) { $c[] = ['name' => $name, 'status' => $status, 'detail' => $detail]; };
        $add('php_version', version_compare(PHP_VERSION, '8.3.0', '>=') ? 'ok' : 'fail', PHP_VERSION . (version_compare(PHP_VERSION, '8.4.0', '<') ? ' (the project targets 8.4; 8.3 is supported)' : ''));
        foreach (['pdo_mysql' => true, 'mbstring' => true, 'openssl' => true, 'curl' => true, 'json' => true, 'sodium' => false, 'gd' => false, 'intl' => false] as $ext => $required) {
            $add("ext_$ext", $has($ext) ? 'ok' : ($required ? 'fail' : 'warn'), $has($ext) ? 'loaded' : ($required ? 'MISSING (required)' : 'not loaded (optional)'));
        }
        $add('argon2id', defined('PASSWORD_ARGON2ID') ? 'ok' : 'warn', defined('PASSWORD_ARGON2ID') ? 'available' : 'not available: bcrypt cost 12 is used instead');
        try {
            $ver = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $add('database', 'ok', "MySQL/MariaDB $ver");
            $pending = array_filter((new Migrator($this->pdo, $migrationsDir))->status(), fn ($m) => $m['state'] === 'pending');
            $add('migrations', $pending ? 'fail' : 'ok', $pending ? count($pending) . ' pending: run php bin/migrate.php up' : 'all applied');
        } catch (\Throwable $e) {
            $add('database', 'fail', 'cannot query: ' . get_class($e));
        }
        foreach (['log_dir' => 'logs', 'avatar_dir' => 'profile pictures'] as $key => $label) {
            $d = (string) ($cfg[$key] ?? '');
            $ok = $d !== '' && ((is_dir($d) && is_writable($d)) || (!is_dir($d) && is_writable(dirname($d))));
            $add("writable_$key", $ok ? 'ok' : 'fail', $label . ($ok ? ' directory is writable' : ' directory is not writable (or not configured)'));
        }
        $add('lock_dir', (is_dir($this->lockDir) && is_writable($this->lockDir)) || (!is_dir($this->lockDir) && is_writable(dirname($this->lockDir))) ? 'ok' : 'fail', 'job lock directory');
        $prod = ($cfg['env'] ?? '') === 'production';
        $add('debug_off', $prod && !empty($cfg['debug']) ? 'fail' : 'ok', $prod && !empty($cfg['debug']) ? 'APP_DEBUG is on in production' : 'debug output is off');
        $add('web_url', ($cfg['web_url'] ?? '') !== '' ? 'ok' : 'warn', ($cfg['web_url'] ?? '') !== '' ? 'set' : 'WEB_URL is empty: links in e-mails will be wrong');
        $driver = (string) (($cfg['mail_driver'] ?? '') ?: ($prod ? 'native' : 'file'));
        $add('mail', $driver === 'smtp' && ($cfg['smtp']['host'] ?? '') !== '' ? 'ok' : ($driver === 'file' && $prod ? 'fail' : 'warn'), "driver: $driver" . ($driver === 'smtp' ? '' : ' (authenticated SMTP is recommended)'));
        $add('payments', ($cfg['paystack']['secret'] ?? '') !== '' ? 'ok' : 'warn', ($cfg['paystack']['secret'] ?? '') !== '' ? 'Paystack key configured' : 'Paystack is not configured: upgrades are disabled');
        $add('cors', !empty($cfg['cors_origins']) ? 'ok' : 'warn', !empty($cfg['cors_origins']) ? count($cfg['cors_origins']) . ' allowed origin(s)' : 'no browser origins allowed (fine for mobile apps only)');
        foreach ($this->overview() as $j) {
            $add('job_' . $j['job'], $j['attention'] ? 'warn' : 'ok', $j['last_status'] . ($j['last_finished_at'] ? ', last finished ' . $j['last_finished_at'] : ''));
        }
        return ['ok' => !array_filter($c, fn ($x) => $x['status'] === 'fail'), 'checks' => $c];
    }

    public function prune(): int
    {
        $st = $this->pdo->prepare('DELETE FROM job_runs WHERE started_at < ? AND id NOT IN (SELECT m FROM (SELECT MAX(id) m FROM job_runs GROUP BY job) k)');
        $st->execute([Clock::sql(Clock::now()->modify('-90 days'))]);
        return $st->rowCount();
    }

    private function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
