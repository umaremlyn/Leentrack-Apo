<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Money;
use LeenTrack\Core\Pager;
use LeenTrack\Core\RateLimiter;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\AuthContext;
use LeenTrack\Repositories\UserRepository;
use LeenTrack\Security\PasswordHasher;
use PDO;

/**
 * Platform operator tools. Reachable only through the `platform_admin` middleware (rows in platform_admins, created from the command line);
 * these are the ONLY code paths that read across tenants. Every change needs a written reason, is audited with before/after, and the
 * destructive ones also need the admin's own password again (step-up), so a stolen session alone cannot delete or re-plan a business.
 */
final class AdminService
{
    private const PLANS = ['free', 'growth', 'business'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly SessionService $sessions,
        private readonly AccountService $accounts,
        private readonly PasswordHasher $hasher,
        private readonly AuditService $audit,
        private readonly RateLimiter $limiter,
        private readonly bool $rateLimit = true,
    ) {
    }

    // ================================================================================================ overview
    /** @return array<string,mixed> */
    public function stats(): array
    {
        $q = fn (string $sql) => (int) $this->pdo->query($sql)->fetchColumn();
        $rev = [];
        foreach ($this->pdo->query("SELECT currency, SUM(amount_minor) FROM payments WHERE status = 'success' GROUP BY currency ORDER BY currency")->fetchAll(PDO::FETCH_NUM) as [$cur, $sum]) { $rev[(string) $cur] = Money::ofMinor((int) $sum)->toString(); }
        return [
            'users' => $q('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            'businesses' => $q('SELECT COUNT(*) FROM businesses WHERE deleted_at IS NULL'),
            'paid_businesses' => $q("SELECT COUNT(*) FROM businesses WHERE deleted_at IS NULL AND plan <> 'free' AND plan_status = 'active' AND (plan_expiry IS NULL OR plan_expiry > UTC_TIMESTAMP(3))"),
            'suspended_businesses' => $q("SELECT COUNT(*) FROM businesses WHERE deleted_at IS NULL AND plan_status = 'suspended'"),
            'active_sessions' => $q('SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP(3)'),
            'pending_account_deletions' => $q("SELECT COUNT(*) FROM users WHERE status = 'pending_deletion' AND anonymised_at IS NULL"),
            'new_businesses_30d' => $q('SELECT COUNT(*) FROM businesses WHERE deleted_at IS NULL AND created_at >= (UTC_TIMESTAMP(3) - INTERVAL 30 DAY)'),
            'revenue_by_currency' => $rev,
            'platform_admins' => $q('SELECT COUNT(*) FROM platform_admins'),
        ];
    }

    // ================================================================================================ businesses
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function businesses(array $q): array
    {
        $this->only($q, ['search', 'plan', 'status', 'page', 'per_page']);
        $w = ['b.deleted_at IS NULL']; $a = [];
        if (isset($q['search'])) { $like = $this->like($q['search']); $w[] = '(b.name LIKE ? OR o.email LIKE ? OR b.id = ?)'; array_push($a, $like, $like, (string) $q['search']); }
        if (isset($q['plan'])) { $w[] = 'b.plan = ?'; $a[] = $this->oneOf($q['plan'], self::PLANS, 'plan'); }
        if (isset($q['status'])) { $w[] = 'b.plan_status = ?'; $a[] = $this->oneOf($q['status'], ['active', 'cancelled', 'suspended', 'expired'], 'status'); }
        $where = implode(' AND ', $w);
        $pg = Pager::fromQuery($q, 50);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM businesses b JOIN users o ON o.id = b.owner_user_id WHERE $where");
        $c->execute($a);
        $total = (int) $c->fetchColumn();
        $st = $this->pdo->prepare("SELECT b.id,b.name,b.currency,b.plan,b.plan_status,b.plan_expiry,b.created_at,o.id AS owner_id,o.email AS owner_email,
              (SELECT COUNT(*) FROM business_members m WHERE m.business_id = b.id AND m.status = 'active') AS members,
              (SELECT COUNT(*) FROM sales s WHERE s.business_id = b.id) AS sales
            FROM businesses b JOIN users o ON o.id = b.owner_user_id WHERE $where ORDER BY b.created_at DESC, b.id LIMIT {$pg->perPage} OFFSET {$pg->offset()}");
        $st->execute($a);
        return ['rows' => array_map($this->businessView(...), $st->fetchAll()), 'meta' => $pg->meta($total)];
    }

    /** @return array<string,mixed> */
    public function business(string $id): array
    {
        $b = $this->findBusiness($id);
        $cnt = fn (string $t) => (function () use ($t, $id) { $s = $this->pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE business_id = ?"); $s->execute([$id]); return (int) $s->fetchColumn(); })();
        $pay = $this->pdo->prepare('SELECT id,business_id,reference,plan,cycle,currency,amount_minor,status,failure_reason,paid_at,created_at FROM payments WHERE business_id = ? ORDER BY created_at DESC LIMIT 10');
        $pay->execute([$id]);
        return $this->businessView($b) + [
            'counts' => ['products' => $cnt('products'), 'customers' => $cnt('customers'), 'sales' => $cnt('sales'), 'locations' => $cnt('locations'), 'expenses' => $cnt('expenses'), 'suppliers' => $cnt('suppliers')],
            'recent_payments' => array_map($this->paymentView(...), $pay->fetchAll()),
        ];
    }

    /**
     * Manual plan grant (the source's "change plan"): a paid plan MUST have an expiry, because a paid plan without one would never end.
     * @param array<string,mixed> $body plan, plan_expiry (date, required for paid plans), reason, password
     * @return array<string,mixed>
     */
    public function setPlan(AuthContext $a, string $id, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['plan', 'plan_expiry', 'reason', 'password']);
        $plan = $v->enum('plan', self::PLANS);
        $reason = $v->string('reason', 5, 300);
        $password = $v->string('password', 1, 1024);
        $expiry = null;
        if ($plan === 'free') {
            if ($v->has('plan_expiry') && $body['plan_expiry'] !== null) { $v->addError('plan_expiry', 'The free plan has no expiry.'); }
        } else {
            $raw = $body['plan_expiry'] ?? null;
            $d = is_string($raw) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC')) : false;
            if (!$d || $d->format('Y-m-d') !== $raw) { $v->addError('plan_expiry', 'A paid plan needs an expiry date (YYYY-MM-DD).'); }
            elseif ($d <= Clock::now() || $d > Clock::now()->modify('+5 years')) { $v->addError('plan_expiry', 'The expiry must be in the future and within 5 years.'); }
            else { $expiry = Clock::sql($d->modify('+1 day')->modify('-1 millisecond')); }      // valid through the end of that day
        }
        $v->done();
        $this->stepUp($a, (string) $password);
        $b = $this->findBusiness($id);
        $this->pdo->prepare("UPDATE businesses SET plan = ?, plan_status = 'active', plan_expiry = ? WHERE id = ?")->execute([$plan, $expiry, $id]);
        $this->audit->log('admin.plan_set', $req, $a->userId, $id, 'business', $id, ['plan' => $b['plan'], 'plan_status' => $b['plan_status'], 'plan_expiry' => $b['plan_expiry']], ['plan' => $plan, 'plan_status' => 'active', 'plan_expiry' => $expiry, 'reason' => $reason]);
        return $this->business($id);
    }

    /** suspended / cancelled businesses are treated as the free plan; active restores their plan until its expiry. @param array<string,mixed> $body @return array<string,mixed> */
    public function setStatus(AuthContext $a, string $id, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['status', 'reason', 'password']);
        $status = $v->enum('status', ['active', 'suspended', 'cancelled']);
        $reason = $v->string('reason', 5, 300);
        $password = $v->string('password', 1, 1024);
        $v->done();
        $this->stepUp($a, (string) $password);
        $b = $this->findBusiness($id);
        $this->pdo->prepare('UPDATE businesses SET plan_status = ? WHERE id = ?')->execute([$status, $id]);
        $this->audit->log('admin.status_set', $req, $a->userId, $id, 'business', $id, ['plan_status' => $b['plan_status']], ['plan_status' => $status, 'reason' => $reason]);
        return $this->business($id);
    }

    /** Permanently deletes a business and all its records (payment records are kept pseudonymised). Typed name + reason + password. @param array<string,mixed> $body @return array<string,mixed> */
    public function deleteBusiness(AuthContext $a, string $id, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['confirm_name', 'reason', 'password']);
        $confirm = $v->string('confirm_name', 1, 150);
        $reason = $v->string('reason', 5, 300);
        $password = $v->string('password', 1, 1024);
        $v->done();
        $this->stepUp($a, (string) $password);
        $b = $this->findBusiness($id);
        if ($confirm !== $b['name']) { throw ApiException::validation(['confirm_name' => ['Type the business name exactly to confirm.']]); }
        $members = $this->pdo->prepare('SELECT COUNT(*) FROM business_members WHERE business_id = ?');
        $members->execute([$id]);
        Db::transaction($this->pdo, fn () => $this->accounts->purgeBusiness($id));
        $this->audit->log('admin.business_deleted', $req, $a->userId, null, 'business', $id, ['name' => $b['name'], 'owner' => $b['owner_email'], 'plan' => $b['plan']], ['reason' => $reason, 'members_removed' => (int) $members->fetchColumn()]);
        return ['deleted' => true];
    }

    // ================================================================================================ users
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function usersList(array $q): array
    {
        $this->only($q, ['search', 'status', 'page', 'per_page']);
        $w = ['u.anonymised_at IS NULL']; $a = [];
        if (isset($q['search'])) { $like = $this->like($q['search']); $w[] = '(u.email LIKE ? OR p.full_name LIKE ? OR u.id = ?)'; array_push($a, $like, $like, (string) $q['search']); }
        if (isset($q['status'])) { $w[] = 'u.status = ?'; $a[] = $this->oneOf($q['status'], ['active', 'disabled', 'pending_deletion'], 'status'); }
        $where = implode(' AND ', $w);
        $pg = Pager::fromQuery($q, 50);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE $where");
        $c->execute($a);
        $st = $this->pdo->prepare("SELECT u.id,u.email,u.status,u.email_verified_at,u.last_login_at,u.created_at,u.deletion_scheduled_at,u.must_change_password,p.full_name,
              (SELECT COUNT(*) FROM business_members m WHERE m.user_id = u.id AND m.status = 'active') AS memberships,
              EXISTS(SELECT 1 FROM platform_admins pa WHERE pa.user_id = u.id) AS is_admin
            FROM users u LEFT JOIN user_profiles p ON p.user_id = u.id WHERE $where ORDER BY u.created_at DESC, u.id LIMIT {$pg->perPage} OFFSET {$pg->offset()}");
        $st->execute($a);
        return ['rows' => array_map($this->userView(...), $st->fetchAll()), 'meta' => $pg->meta((int) $c->fetchColumn())];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function setUserStatus(AuthContext $a, string $id, string $to, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['reason', 'password']);
        $reason = $v->string('reason', 5, 300);
        $password = $v->string('password', 1, 1024);
        $v->done();
        $this->stepUp($a, (string) $password);
        $u = $this->findUser($id);
        if ($id === $a->userId) { throw ApiException::conflict('self_target', 'You cannot do this to your own account.'); }
        if ($this->users->isPlatformAdmin($id)) { throw ApiException::conflict('platform_admin', 'Platform administrators are managed from the command line.'); }
        if ($to === 'disabled' && $u['status'] !== 'active') { throw ApiException::conflict('invalid_status', 'Only an active account can be disabled.'); }
        if ($to === 'active' && $u['status'] !== 'disabled') { throw ApiException::conflict('invalid_status', 'Only a disabled account can be enabled.'); }
        Db::transaction($this->pdo, function () use ($id, $to) {
            $this->pdo->prepare('UPDATE users SET status = ? WHERE id = ?')->execute([$to, $id]);
            if ($to === 'disabled') { $this->sessions->logoutAll($id, 'admin_disabled'); }
        });
        $this->audit->log($to === 'disabled' ? 'admin.user_disabled' : 'admin.user_enabled', $req, $a->userId, null, 'user', $id, ['status' => $u['status']], ['status' => $to, 'reason' => $reason]);
        return $this->userView($this->findUser($id) + ['memberships' => 0, 'is_admin' => 0, 'full_name' => null]);
    }

    /** Starts the same 14-day deletion the account holder would (owners with a team are refused). @param array<string,mixed> $body @return array<string,mixed> */
    public function deleteUser(AuthContext $a, string $id, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['reason', 'password']);
        $reason = $v->string('reason', 5, 300);
        $password = $v->string('password', 1, 1024);
        $v->done();
        $this->stepUp($a, (string) $password);
        $u = $this->findUser($id);
        if ($id === $a->userId) { throw ApiException::conflict('self_target', 'You cannot do this to your own account.'); }
        if ($u['status'] === 'pending_deletion') { throw ApiException::conflict('already_pending', 'This account is already scheduled for deletion.'); }
        return $this->accounts->scheduleDeletion($id, (string) $u['email'], $a->userId, $req, $reason);
    }

    // ================================================================================================ payments, audit, notices
    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function payments(array $q): array
    {
        $this->only($q, ['status', 'business_id', 'page', 'per_page']);
        $w = ['1 = 1']; $a = [];
        if (isset($q['status'])) { $w[] = 'p.status = ?'; $a[] = $this->oneOf($q['status'], ['pending', 'success', 'failed', 'abandoned', 'refunded'], 'status'); }
        if (isset($q['business_id'])) { if (!is_string($q['business_id']) || !Uuid::isValid($q['business_id'])) { throw ApiException::validation(['business_id' => ['Must be a valid id.']]); } $w[] = 'p.business_id = ?'; $a[] = $q['business_id']; }
        $where = implode(' AND ', $w);
        $pg = Pager::fromQuery($q, 50);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM payments p WHERE $where");
        $c->execute($a);
        $st = $this->pdo->prepare("SELECT p.id,p.business_id,b.name AS business_name,p.reference,p.plan,p.cycle,p.currency,p.amount_minor,p.status,p.failure_reason,p.paid_at,p.created_at FROM payments p LEFT JOIN businesses b ON b.id = p.business_id WHERE $where ORDER BY p.created_at DESC, p.id LIMIT {$pg->perPage} OFFSET {$pg->offset()}");
        $st->execute($a);
        return ['rows' => array_map($this->paymentView(...), $st->fetchAll()), 'meta' => $pg->meta((int) $c->fetchColumn())];
    }

    /** @param array<string,mixed> $q @return array{rows:list<array<string,mixed>>,meta:array<string,mixed>} */
    public function auditLog(array $q): array
    {
        $this->only($q, ['actor_id', 'business_id', 'action', 'from', 'to', 'page', 'per_page']);
        $w = ['1 = 1']; $a = [];
        foreach (['actor_id' => 'l.actor_user_id', 'business_id' => 'l.business_id'] as $k => $col) {
            if (isset($q[$k])) { if (!is_string($q[$k]) || !Uuid::isValid($q[$k])) { throw ApiException::validation([$k => ['Must be a valid id.']]); } $w[] = "$col = ?"; $a[] = $q[$k]; }
        }
        if (isset($q['action'])) { if (!is_string($q['action']) || !preg_match('/^[a-z0-9_.]{1,80}$/', $q['action'])) { throw ApiException::validation(['action' => ['Letters, digits, dot and underscore only.']]); } $w[] = 'l.action LIKE ?'; $a[] = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q['action']) . '%'; }
        foreach (['from' => ['>=', 0], 'to' => ['<', 1]] as $k => [$op, $plus]) {
            if (isset($q[$k])) {
                $d = is_string($q[$k]) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $q[$k], new \DateTimeZone('UTC')) : false;
                if (!$d || $d->format('Y-m-d') !== $q[$k]) { throw ApiException::validation([$k => ['Must be a date as YYYY-MM-DD.']]); }
                $w[] = "l.created_at $op ?"; $a[] = Clock::sql($d->modify("+$plus day"));
            }
        }
        $where = implode(' AND ', $w);
        $pg = Pager::fromQuery($q, 50);
        $c = $this->pdo->prepare("SELECT COUNT(*) FROM audit_logs l WHERE $where");
        $c->execute($a);
        $st = $this->pdo->prepare("SELECT l.id,l.created_at,l.action,l.actor_user_id,u.email AS actor_email,l.business_id,l.entity_type,l.entity_id,l.before_json,l.after_json,l.ip,l.request_id
            FROM audit_logs l LEFT JOIN users u ON u.id = l.actor_user_id WHERE $where ORDER BY l.id DESC LIMIT {$pg->perPage} OFFSET {$pg->offset()}");
        $st->execute($a);
        $rows = array_map(fn ($r) => ['id' => (int) $r['id'], 'created_at' => $this->iso($r['created_at']), 'action' => $r['action'], 'actor_user_id' => $r['actor_user_id'], 'actor_email' => $r['actor_email'], 'business_id' => $r['business_id'],
            'entity_type' => $r['entity_type'], 'entity_id' => $r['entity_id'], 'before' => $r['before_json'] === null ? null : json_decode((string) $r['before_json'], true), 'after' => $r['after_json'] === null ? null : json_decode((string) $r['after_json'], true), 'ip' => $r['ip'], 'request_id' => $r['request_id']], $st->fetchAll());
        return ['rows' => $rows, 'meta' => $pg->meta((int) $c->fetchColumn())];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function createNotice(AuthContext $a, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['title', 'body', 'level', 'ends_at']);
        $title = $v->string('title', 3, 120);
        $text = $v->string('body', 3, 1000);
        $level = $v->has('level') ? $v->enum('level', ['info', 'warning', 'critical']) : 'info';
        $ends = null;
        if ($v->has('ends_at') && $body['ends_at'] !== null) {
            $d = is_string($body['ends_at']) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $body['ends_at'], new \DateTimeZone('UTC')) : false;
            if (!$d || $d->format('Y-m-d') !== $body['ends_at'] || $d <= Clock::now()) { $v->addError('ends_at', 'Must be a future date (YYYY-MM-DD).'); } else { $ends = Clock::sql($d->modify('+1 day')); }
        }
        $v->done();
        $id = Uuid::v4();
        $this->pdo->prepare('INSERT INTO platform_notices (id,title,body,level,starts_at,ends_at,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$id, $title, $text, $level, Clock::sql(), $ends, $a->userId]);
        $this->audit->log('admin.notice_created', $req, $a->userId, null, 'notice', $id, null, ['title' => $title, 'level' => $level]);
        return $this->notice($id);
    }

    /** @return list<array<string,mixed>> */
    public function notices(): array
    {
        $st = $this->pdo->query('SELECT id FROM platform_notices ORDER BY created_at DESC, id LIMIT 200');
        return array_map(fn ($id) => $this->notice((string) $id), $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function endNotice(AuthContext $a, string $id, Request $req): array
    {
        $this->notice($id);
        $this->pdo->prepare('UPDATE platform_notices SET ends_at = ? WHERE id = ? AND (ends_at IS NULL OR ends_at > ?)')->execute([Clock::sql(), $id, Clock::sql()]);
        $this->audit->log('admin.notice_ended', $req, $a->userId, null, 'notice', $id);
        return $this->notice($id);
    }

    /** What any signed-in user sees. @return list<array<string,mixed>> */
    public function activeNotices(): array
    {
        $st = $this->pdo->prepare('SELECT id,title,body,level,starts_at,ends_at FROM platform_notices WHERE starts_at <= ? AND (ends_at IS NULL OR ends_at > ?) ORDER BY FIELD(level,\'critical\',\'warning\',\'info\'), starts_at DESC LIMIT 10');
        $now = Clock::sql();
        $st->execute([$now, $now]);
        return array_map(fn ($n) => ['id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'level' => $n['level'], 'starts_at' => $this->iso($n['starts_at']), 'ends_at' => $n['ends_at'] === null ? null : $this->iso($n['ends_at'])], $st->fetchAll());
    }

    // ================================================================================================ helpers
    /** @return array<string,mixed> */
    private function notice(string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $st = $this->pdo->prepare('SELECT id,title,body,level,starts_at,ends_at,created_by,created_at FROM platform_notices WHERE id = ?');
        $st->execute([$id]);
        $n = $st->fetch() ?: throw ApiException::notFound();
        $active = $n['starts_at'] <= Clock::sql() && ($n['ends_at'] === null || $n['ends_at'] > Clock::sql());
        return ['id' => $n['id'], 'title' => $n['title'], 'body' => $n['body'], 'level' => $n['level'], 'starts_at' => $this->iso($n['starts_at']), 'ends_at' => $n['ends_at'] === null ? null : $this->iso($n['ends_at']), 'active' => $active, 'created_by' => $n['created_by']];
    }

    /** Re-checks the admin's own password for destructive actions. */
    private function stepUp(AuthContext $a, string $password): void
    {
        if ($this->rateLimit) {
            $r = $this->limiter->hit('admin-stepup|' . $a->userId, 10, 900);
            if (!$r['allowed']) { throw ApiException::tooMany($r['retry_after']); }
        }
        $u = $this->users->findById($a->userId) ?? throw ApiException::unauthenticated();
        if ($u['password_hash'] === null || !$this->hasher->verify($password, (string) $u['password_hash'])) {
            $this->audit->log('admin.stepup_failed', null, $a->userId);
            throw new ApiException('invalid_credentials', 'Password is incorrect.', 403);
        }
    }

    /** @return array<string,mixed> */
    private function findBusiness(string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $st = $this->pdo->prepare('SELECT b.id,b.name,b.currency,b.plan,b.plan_status,b.plan_expiry,b.created_at,o.id AS owner_id,o.email AS owner_email,
              (SELECT COUNT(*) FROM business_members m WHERE m.business_id = b.id AND m.status = \'active\') AS members,
              (SELECT COUNT(*) FROM sales s WHERE s.business_id = b.id) AS sales
            FROM businesses b JOIN users o ON o.id = b.owner_user_id WHERE b.id = ? AND b.deleted_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: throw ApiException::notFound();
    }

    /** @return array<string,mixed> */
    private function findUser(string $id): array
    {
        if (!Uuid::isValid($id)) { throw ApiException::notFound(); }
        $st = $this->pdo->prepare('SELECT * FROM users WHERE id = ? AND anonymised_at IS NULL');
        $st->execute([$id]);
        return $st->fetch() ?: throw ApiException::notFound();
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private function businessView(array $b): array
    {
        return ['id' => $b['id'], 'name' => $b['name'], 'currency' => $b['currency'], 'plan' => $b['plan'], 'plan_status' => $b['plan_status'], 'plan_expiry' => $b['plan_expiry'] === null ? null : $this->iso($b['plan_expiry']),
            'effective_plan' => PlanCatalog::effective($b), 'owner_id' => $b['owner_id'], 'owner_email' => $b['owner_email'], 'members' => (int) $b['members'], 'sales' => (int) $b['sales'], 'created_at' => $this->iso($b['created_at'])];
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    private function userView(array $u): array
    {
        return ['id' => $u['id'], 'email' => $u['email'], 'full_name' => $u['full_name'] ?? null, 'status' => $u['status'], 'email_verified' => $u['email_verified_at'] !== null, 'must_change_password' => (bool) $u['must_change_password'],
            'last_login_at' => $u['last_login_at'] === null ? null : $this->iso($u['last_login_at']), 'created_at' => $this->iso($u['created_at']), 'deletion_scheduled_at' => $u['deletion_scheduled_at'] === null ? null : $this->iso($u['deletion_scheduled_at']),
            'memberships' => (int) ($u['memberships'] ?? 0), 'is_platform_admin' => (bool) ($u['is_admin'] ?? false)];
    }

    /** @param array<string,mixed> $p @return array<string,mixed> */
    private function paymentView(array $p): array
    {
        return ['id' => $p['id'], 'business_id' => $p['business_id'], 'business_name' => $p['business_name'] ?? null, 'reference' => $p['reference'], 'plan' => $p['plan'], 'cycle' => $p['cycle'], 'currency' => $p['currency'],
            'amount' => Money::ofMinor((int) $p['amount_minor'])->toString(), 'status' => $p['status'], 'failure_reason' => $p['failure_reason'], 'paid_at' => $p['paid_at'] === null ? null : $this->iso($p['paid_at']), 'created_at' => $this->iso($p['created_at'])];
    }

    /** @param array<string,mixed> $q @param list<string> $allowed */
    private function only(array $q, array $allowed): void
    {
        if ($extra = array_diff(array_keys($q), $allowed)) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
    }

    private function like(mixed $s): string
    {
        if (!is_string($s) || $s === '' || mb_strlen($s) > 100) { throw ApiException::validation(['search' => ['Must be text up to 100 characters.']]); }
        return '%' . addcslashes($s, '\\%_') . '%';
    }

    /** @param list<string> $allowed */
    private function oneOf(mixed $v, array $allowed, string $field): string
    {
        return is_string($v) && in_array($v, $allowed, true) ? $v : throw ApiException::validation([$field => ['Must be one of: ' . implode(', ', $allowed) . '.']]);
    }

    private function iso(string $sql): string
    {
        return (new \DateTimeImmutable($sql, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
