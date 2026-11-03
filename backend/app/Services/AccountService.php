<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\AuthContext;
use LeenTrack\Http\TenantContext;
use LeenTrack\Mail\Mailer;
use LeenTrack\Repositories\UserRepository;
use LeenTrack\Security\PasswordHasher;
use PDO;

/**
 * Account lifecycle: data export, deletion with a grace period, and the purge job.
 * Deletion (the source deleted everything instantly in the browser, left the sign-in account behind and ignored staff):
 *   1. POST /me/deletion  (password + typed phrase)  -> sign-in disabled, all sessions revoked, 14-day grace, e-mail sent.
 *   2. POST /auth/cancel-deletion (e-mail + password) within the grace period restores the account untouched.
 *   3. `bin/cron.php purge-accounts` after the grace period: the user's own businesses and ALL their data are deleted, memberships in
 *      other businesses are removed, and the user row is ANONYMISED (kept, blank) because sales.user_id and audit actors reference it.
 * A user who owns a business that still has other team members cannot request deletion (there is no ownership transfer yet): the team
 * would lose their workplace and records without warning.
 */
final class AccountService
{
    public const GRACE_DAYS = 14;
    public const PHRASE = 'DELETE';

    /** Child tables first. Every table that has a business_id column MUST be listed (AccountTest enforces this against information_schema). */
    public const PURGE_ORDER = [
        'sale_edits', 'loyalty_ledger', 'debt_repayments', 'sale_items', 'sales',
        'purchase_receipt_items', 'purchase_receipts', 'purchase_order_items', 'supplier_payments', 'purchase_orders', 'suppliers',
        'transfer_items', 'transfers', 'stock_movements', 'stock_levels',
        'expenses', 'expense_categories', 'products', 'categories',
        'customers', 'customer_groups', 'loyalty_settings', 'stock_alert_settings', 'business_branding', 'document_sequences',
        'member_outlets', 'business_members', 'audit_logs', 'locations',
    ];
    /** Business-scoped tables that are deliberately KEPT when a business is deleted. `payments` stays (pseudonymised: business_id → NULL by the foreign key, user_id blanked here) for tax/accounting; the housekeeping job deletes it after PAYMENT_RETENTION_YEARS. */
    public const RETAINED = ['payments'];
    /** Tables reached through a parent instead of their own business_id. */
    private const VIA_PARENT = [
        'purchase_receipt_items' => 'DELETE x FROM purchase_receipt_items x JOIN purchase_receipts r ON r.id = x.receipt_id WHERE r.business_id = ?',
        'member_outlets' => 'DELETE x FROM member_outlets x JOIN business_members m ON m.id = x.member_id WHERE m.business_id = ?',
    ];

    /** section => [table, ORDER BY, columns to leave out] */
    public const EXPORT_SECTIONS = [
        'locations' => ['locations', 'id', []], 'members' => ['business_members', 'id', ['invite_token_hash']],
        'categories' => ['categories', 'id', []], 'products' => ['products', 'id', []], 'stock_levels' => ['stock_levels', 'product_id, location_id', []], 'stock_movements' => ['stock_movements', 'id', []],
        'customer_groups' => ['customer_groups', 'id', []], 'customers' => ['customers', 'id', []], 'loyalty_ledger' => ['loyalty_ledger', 'id', []],
        'sales' => ['sales', 'created_at, id', []], 'sale_items' => ['sale_items', 'id', []], 'debt_repayments' => ['debt_repayments', 'id', []], 'sale_edits' => ['sale_edits', 'id', []],
        'expense_categories' => ['expense_categories', 'id', []], 'expenses' => ['expenses', 'id', []],
        'suppliers' => ['suppliers', 'id', []], 'purchase_orders' => ['purchase_orders', 'id', []], 'purchase_order_items' => ['purchase_order_items', 'id', []],
        'transfers' => ['transfers', 'id', []], 'transfer_items' => ['transfer_items', 'id', []],
        'purchase_receipts' => ['purchase_receipts', 'id', []], 'supplier_payments' => ['supplier_payments', 'id', []], 'payments' => ['payments', 'created_at, id', []], 'stock_alert_settings' => ['stock_alert_settings', 'business_id', []],
        'purchase_receipt_items' => ['purchase_receipt_items', 'receipt_id, item_id', [], 'x.* FROM purchase_receipt_items x JOIN purchase_receipts r ON r.id = x.receipt_id WHERE r.business_id = ?'],
        'member_outlets' => ['member_outlets', 'member_id, location_id', [], 'x.* FROM member_outlets x JOIN business_members m ON m.id = x.member_id WHERE m.business_id = ?'],
    ];
    private const EXPORT_PAGE = 1000;

    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly SessionService $sessions,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly AuditService $audit,
        private readonly \LeenTrack\Core\RateLimiter $limiter,
        private readonly bool $rateLimit = true,
        private readonly string $avatarDir = '',
    ) {
    }

    // ================================================================================================ export
    /** Everything personal about the caller (not business records — those are exported per business). @return array<string,mixed> */
    public function exportMe(AuthContext $a, Request $req): array
    {
        $u = $this->users->findById($a->userId) ?? throw ApiException::unauthenticated();
        $this->audit->log('me.export', $req, $a->userId);
        $q = fn (string $sql, array $args) => (function () use ($sql, $args) { $st = $this->pdo->prepare($sql); $st->execute($args); return $st->fetchAll(); })();
        return [
            'exported_at' => Clock::now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'user' => ['id' => $u['id'], 'email' => $u['email'], 'phone' => $u['phone'], 'email_verified_at' => $u['email_verified_at'], 'created_at' => $u['created_at'], 'last_login_at' => $u['last_login_at']],
            'profile' => $this->users->profile($a->userId),
            'memberships' => $q('SELECT m.id, m.business_id, b.name AS business_name, m.role, m.status, m.joined_at FROM business_members m JOIN businesses b ON b.id = m.business_id WHERE m.user_id = ? ORDER BY m.created_at', [$a->userId]),
            'owned_businesses' => $q('SELECT id, name FROM businesses WHERE owner_user_id = ? AND deleted_at IS NULL ORDER BY created_at', [$a->userId]),
            'devices' => $q('SELECT id, device_name, platform, ip, user_agent, created_at, last_used_at, revoked_at, revoked_reason FROM auth_sessions WHERE user_id = ? ORDER BY created_at DESC LIMIT 200', [$a->userId]),
            'activity' => $q('SELECT action, entity_type, entity_id, ip, created_at FROM audit_logs WHERE actor_user_id = ? ORDER BY id DESC LIMIT 1000', [$a->userId]),
            'note' => 'Business records are exported per business by its owner: GET /businesses/{id}/export.',
        ];
    }

    /**
     * Owner-only, one section per call, 1000 rows per page (keeps memory flat on shared hosting). Without `section` it lists the sections.
     * @param array<string,mixed> $q @return array{data:mixed,meta:array<string,mixed>}
     */
    public function exportBusiness(TenantContext $t, array $q, Request $req): array
    {
        $t->require('business.update');
        if ($extra = array_diff(array_keys($q), ['section', 'page'])) { throw ApiException::validation([(string) reset($extra) => ['Unknown filter.']]); }
        $this->throttle('bizexport|' . $t->businessId, 120, 3600);
        if (!isset($q['section'])) {
            return ['data' => array_merge(['business'], array_keys(self::EXPORT_SECTIONS)), 'meta' => ['page_size' => self::EXPORT_PAGE, 'hint' => 'Request each section with ?section=NAME&page=N until it returns no rows.']];
        }
        $section = is_string($q['section']) ? $q['section'] : '';
        $page = isset($q['page']) ? (is_string($q['page']) && preg_match('/^[1-9]\d{0,5}$/', $q['page']) ? (int) $q['page'] : throw ApiException::validation(['page' => ['Must be a positive whole number.']])) : 1;
        if ($section === 'business') {
            $st = $this->pdo->prepare('SELECT * FROM businesses WHERE id = ?');
            $st->execute([$t->businessId]);
            $this->audit->log('business.export', $req, $t->userId, $t->businessId, 'business', $t->businessId, null, ['section' => 'business']);
            return ['data' => $st->fetchAll(), 'meta' => ['section' => 'business', 'page' => 1, 'has_more' => false]];
        }
        if (!isset(self::EXPORT_SECTIONS[$section])) { throw ApiException::validation(['section' => ['Unknown section.']]); }
        [$table, $order, $skip] = self::EXPORT_SECTIONS[$section];
        $custom = self::EXPORT_SECTIONS[$section][3] ?? null;
        $cols = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $cols->execute([$table]);
        $list = implode(',', array_map(fn ($c) => "`$c`", array_diff($cols->fetchAll(PDO::FETCH_COLUMN), $skip)));
        $limit = self::EXPORT_PAGE + 1;
        $from = $custom !== null ? $custom : "$list FROM `$table` WHERE business_id = ?";
        $st = $this->pdo->prepare("SELECT $from ORDER BY $order LIMIT $limit OFFSET " . (($page - 1) * self::EXPORT_PAGE));
        $st->execute([$t->businessId]);
        $rows = $st->fetchAll();
        $more = count($rows) > self::EXPORT_PAGE;
        if ($more) { array_pop($rows); }
        if ($page === 1) { $this->audit->log('business.export', $req, $t->userId, $t->businessId, 'business', $t->businessId, null, ['section' => $section]); }
        return ['data' => $rows, 'meta' => ['section' => $section, 'page' => $page, 'has_more' => $more]];
    }

    // ================================================================================================ deletion
    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function requestDeletion(AuthContext $a, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['password', 'confirm']);
        $password = $v->string('password', 1, 1024);
        $confirm = $v->string('confirm', 1, 40);
        if ($confirm !== null && $confirm !== self::PHRASE) { $v->addError('confirm', 'Type ' . self::PHRASE . ' to confirm.'); }
        $v->done();
        $this->throttle('deleteacct|' . $a->userId, 5, 900);
        $u = $this->users->findById($a->userId) ?? throw ApiException::unauthenticated();
        if ($u['password_hash'] === null || !$this->hasher->verify((string) $password, (string) $u['password_hash'])) {
            throw new ApiException('invalid_credentials', 'Password is incorrect.', 403);
        }
        return $this->scheduleDeletion($a->userId, (string) $u['email'], $a->userId, $req, null);
    }

    /**
     * Shared by the account holder (above) and by platform admins: refuses platform admins and owners with a team, disables sign-in,
     * revokes sessions, starts the grace period and e-mails the person. @return array{status:string,scheduled_for:string,grace_days:int}
     */
    public function scheduleDeletion(string $userId, string $email, string $actorId, ?Request $req, ?string $adminReason): array
    {
        if ($this->users->isPlatformAdmin($userId)) { throw ApiException::conflict('platform_admin', 'Platform administrators must give up admin rights (CLI) before the account can be deleted.'); }
        $blocking = $this->pdo->prepare("SELECT b.name FROM businesses b WHERE b.owner_user_id = ? AND b.deleted_at IS NULL AND EXISTS (SELECT 1 FROM business_members m WHERE m.business_id = b.id AND m.role <> 'owner' AND m.status IN ('active','invited')) ORDER BY b.name");
        $blocking->execute([$userId]);
        if ($names = $blocking->fetchAll(PDO::FETCH_COLUMN)) {
            throw new ApiException('owns_business_with_staff', 'Remove your team members first. These businesses still have staff: ' . implode(', ', $names) . '.', 409, ['businesses' => array_map('strval', $names)]);
        }
        $now = Clock::now();
        $due = $now->modify('+' . self::GRACE_DAYS . ' days');
        Db::transaction($this->pdo, function () use ($userId, $now, $due) {
            $this->users->requestDeletion($userId, Clock::sql($now), Clock::sql($due));
            $this->sessions->logoutAll($userId, 'account_deletion');
        });
        $this->audit->log($actorId === $userId ? 'account.deletion_requested' : 'admin.account_deletion_requested', $req, $actorId, null, 'user', $userId, null, ['scheduled' => Clock::sql($due)] + ($adminReason !== null ? ['reason' => $adminReason] : []));
        $date = $due->format('Y-m-d');
        $who = $actorId === $userId ? 'We received a request to delete your LeenTrack account.' : 'A LeenTrack administrator has scheduled your account for deletion.';
        $this->mailer->send($email, 'Your LeenTrack account will be deleted', "$who\n\nOn $date your account and the businesses you own, with all their records, will be permanently deleted.\n\nIf this was not you, or you changed your mind, sign in is disabled but you can cancel before $date from the sign-in page (\"Cancel account deletion\"), using your e-mail and password.");
        return ['status' => 'pending_deletion', 'scheduled_for' => $date, 'grace_days' => self::GRACE_DAYS];
    }

    /** Public, throttled. Needs the password, so only the account holder can do it. @param array<string,mixed> $body @return array{message:string} */
    public function cancelDeletion(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['email', 'password']);
        $email = $v->email('email');
        $password = $v->string('password', 1, 1024);
        $v->done();
        $this->throttle('login-acct|' . hash('sha256', (string) $email), 8, 900);       // shares the sign-in budget: it is a password check
        $u = $this->users->findByEmail((string) $email);
        $ok = false;
        if ($u === null || $u['status'] !== 'pending_deletion' || $u['password_hash'] === null) {
            $this->hasher->dummyVerify((string) $password);
        } else {
            $ok = $this->hasher->verify((string) $password, (string) $u['password_hash']);
        }
        if (!$ok || $u === null) { throw new ApiException('invalid_credentials', 'Invalid e-mail or password, or the account is not scheduled for deletion.', 401); }
        $this->users->cancelDeletion((string) $u['id']);
        $this->audit->log('account.deletion_cancelled', $req, (string) $u['id'], null, 'user', (string) $u['id']);
        return ['message' => 'Deletion cancelled. You can sign in again.'];
    }

    // ================================================================================================ purge job
    /** @return array{accounts:int,businesses:int,failed:int} */
    public function purgeDue(): array
    {
        $out = ['accounts' => 0, 'businesses' => 0, 'failed' => 0];
        foreach ($this->users->dueDeletions(Clock::sql()) as $uid) {
            try {
                $out['businesses'] += Db::transaction($this->pdo, fn () => $this->purgeUser($uid));
                $out['accounts']++;
            } catch (\Throwable $e) {
                $out['failed']++;
                error_log('purge failed for a user: ' . get_class($e));
            }
        }
        return $out;
    }

    /** Deletes one business and all its records (payments excepted, see RETAINED). Must run inside a transaction. */
    public function purgeBusiness(string $bid): void
    {
        foreach (self::PURGE_ORDER as $table) {
            $this->pdo->prepare(self::VIA_PARENT[$table] ?? "DELETE FROM `$table` WHERE business_id = ?")->execute([$bid]);
        }
        $this->pdo->prepare('UPDATE payments SET user_id = NULL WHERE business_id = ?')->execute([$bid]);
        $this->pdo->prepare('DELETE FROM businesses WHERE id = ?')->execute([$bid]);
    }

    private function purgeUser(string $uid): int
    {
        $lock = $this->pdo->prepare("SELECT status FROM users WHERE id = ? AND anonymised_at IS NULL FOR UPDATE");
        $lock->execute([$uid]);
        if ($lock->fetchColumn() !== 'pending_deletion') { return 0; }            // cancelled in the meantime
        $biz = $this->pdo->prepare('SELECT id FROM businesses WHERE owner_user_id = ?');
        $biz->execute([$uid]);
        $ids = array_map('strval', $biz->fetchAll(PDO::FETCH_COLUMN));
        $this->pdo->prepare('UPDATE payments SET user_id = NULL WHERE user_id = ?')->execute([$uid]);       // keep the payment record, drop the person
        foreach ($ids as $bid) {
            $others = $this->pdo->prepare("SELECT COUNT(*) FROM business_members WHERE business_id = ? AND role <> 'owner' AND status IN ('active','invited')");
            $others->execute([$bid]);
            if ((int) $others->fetchColumn() > 0) { throw new \RuntimeException('business gained staff after the request'); }
            $this->purgeBusiness($bid);
        }
        $this->pdo->prepare('DELETE FROM business_members WHERE user_id = ?')->execute([$uid]);           // memberships elsewhere (member_outlets cascade)
        $avatar = $this->users->profile($uid)['avatar_path'] ?? null;
        $this->users->anonymise($uid, Clock::sql());
        if ($avatar !== null && $this->avatarDir !== '' && preg_match('/^[0-9a-f-]{36}-[0-9a-f]{12}\.(jpg|png|webp)$/', (string) $avatar)) { @unlink($this->avatarDir . '/' . $avatar); }
        $this->audit->log('account.purged', null, $uid, null, 'user', $uid, null, ['businesses' => count($ids)]);
        return count($ids);
    }

    private function throttle(string $key, int $max, int $window): void
    {
        if (!$this->rateLimit) { return; }
        $r = $this->limiter->hit($key, $max, $window);
        if (!$r['allowed']) { throw ApiException::tooMany($r['retry_after']); }
    }
}
