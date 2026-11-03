<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Clock;
use LeenTrack\Core\Db;
use LeenTrack\Core\RateLimiter;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\AuthContext;
use LeenTrack\Mail\Mailer;
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Repositories\UserRepository;
use LeenTrack\Security\PasswordHasher;
use LeenTrack\Security\Tokens;
use PDO;

final class AuthService
{
    /** @param array<string,mixed> $cfg config['auth'] */
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly BusinessService $businesses,
        private readonly SessionService $sessions,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly AuditService $audit,
        private readonly RateLimiter $limiter,
        private readonly array $cfg,
        private readonly string $webUrl,
        private readonly bool $rateLimit = true,
    ) {
    }

    /**
     * Always returns the same shape whether or not the e-mail already exists (no account enumeration).
     * @param array<string,mixed> $body
     * @return array{message:string}
     */
    public function register(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['email', 'password', 'full_name', 'business_name', 'business_type', 'currency', 'phone', 'address']);
        $email = $v->email('email');
        $password = $v->string('password', 1, 128);
        $fullName = $v->string('full_name', 2, 150);
        $bizName = $v->string('business_name', 2, 150);
        $bizType = $v->string('business_type', 1, 60, false);
        $currency = $v->enum('currency', BusinessService::CURRENCIES, false, 'NGN');
        $phone = $v->string('phone', 5, 32, false);
        $address = $v->string('address', 1, 300, false);
        if ($phone !== null && !preg_match('/^\+?[0-9 ()\-]{5,32}$/', $phone)) { $v->addError('phone', 'Must be a valid phone number.'); }
        $v->done();
        if ($password !== null) { $this->hasher->assertAcceptable($password, $email); }

        $generic = ['message' => 'If this e-mail can be registered, a verification message has been sent.'];
        $existing = $this->users->findByEmail((string) $email);
        if ($existing !== null) {
            $this->hasher->hash((string) $password);   // comparable CPU cost
            $this->mailer->send((string) $email, 'LeenTrack sign-up attempt', "Someone tried to create a LeenTrack account with this e-mail address, but one already exists.\nIf this was you, sign in or use 'Forgot password'. If not, you can ignore this message.");
            $this->audit->log('auth.register_existing_email', $req, (string) $existing['id']);
            return $generic;
        }

        $userId = Uuid::v4();
        $raw = Tokens::generate();
        try {
            Db::transaction($this->pdo, function () use ($userId, $email, $password, $fullName, $bizName, $bizType, $currency, $phone, $address, $raw) {
                $this->users->insert(['id' => $userId, 'email' => $email, 'password_hash' => $this->hasher->hash((string) $password), 'phone' => $phone]);
                $this->users->insertProfile($userId, $fullName, (string) $currency, $address);
                $this->businesses->createFor($userId, (string) $email, $fullName, (string) $bizName, $bizType, (string) $currency);
                $this->users->createToken($userId, 'verify_email', Tokens::hash($raw), Clock::sql(Clock::plus("+{$this->cfg['verify_ttl']} seconds")));
            });
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { return $generic; }   // lost a registration race for the same e-mail
            throw $e;
        }
        $this->audit->log('auth.register', $req, $userId);
        $this->mailer->send((string) $email, 'Verify your LeenTrack e-mail', "Welcome to LeenTrack!\n\nConfirm your e-mail address:\n{$this->webUrl}/verify-email?token=$raw\n\nThis link expires in 48 hours.");
        return $generic;
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function login(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['email', 'password', 'device']);
        $email = $v->email('email');
        $password = $v->string('password', 1, 1024);
        $device = $this->device($body['device'] ?? null, $v);
        $v->done();

        $this->throttle('login-acct|' . hash('sha256', (string) $email), 8, 900);
        $u = $this->users->findByEmail((string) $email);
        $ok = false;
        if ($u === null) {
            $this->hasher->dummyVerify((string) $password);
        } elseif (!in_array($u['status'], ['active', 'pending_deletion'], true)) {
            $this->hasher->dummyVerify((string) $password);
        } elseif ($u['password_hash'] !== null) {
            $ok = $this->hasher->verify((string) $password, (string) $u['password_hash']);
            if ($ok && $this->hasher->needsRehash((string) $u['password_hash'])) {
                $this->users->setPassword((string) $u['id'], $this->hasher->hash((string) $password), false);
            }
        } elseif ($u['legacy_bcrypt_hash'] !== null) {
            // One-time transition for migrated accounts: verify the old bcrypt hash, store a fresh hash, drop the old one.
            $ok = $this->hasher->verifyLegacyBcrypt((string) $password, (string) $u['legacy_bcrypt_hash']);
            if ($ok) {
                // Deliberately no strength check here: a migrated user must not be locked out by our stricter policy.
                $weak = $this->hasher->problems((string) $password, (string) $email) !== [];
                $this->users->setPassword((string) $u['id'], $this->hasher->hash((string) $password), false);
                // Soft enforcement (G-21): never locked out, but clients are told to ask for a stronger password.
                if ($weak) { $this->users->setMustChangePassword((string) $u['id'], true); $u['must_change_password'] = 1; }
                $this->audit->log('auth.legacy_hash_upgraded', $req, (string) $u['id'], null, null, null, null, ['weak_password' => $weak]);
            }
        } else {
            $this->hasher->dummyVerify((string) $password);   // account with no credential: must use reset flow
        }

        if (!$ok || $u === null) {
            $this->audit->log('auth.login_failed', $req, $u['id'] ?? null, null, null, null, null, ['email_hash' => substr(hash('sha256', (string) $email), 0, 16)]);
            throw new ApiException('invalid_credentials', 'Invalid e-mail or password.', 401);
        }
        if ($u['status'] === 'pending_deletion') {
            throw new ApiException('account_pending_deletion', 'This account is scheduled for deletion on ' . substr((string) $u['deletion_scheduled_at'], 0, 10) . '. You can cancel the deletion before then.', 403);
        }
        if ($this->cfg['require_email_verification'] && $u['email_verified_at'] === null) {
            throw new ApiException('email_not_verified', 'Please verify your e-mail address before signing in.', 403);
        }

        $tokens = $this->sessions->issue((string) $u['id'], $device, $req);
        $this->users->markLogin((string) $u['id']);
        $this->audit->log('auth.login', $req, (string) $u['id']);
        return $tokens + ['user' => $this->userView($u), 'memberships' => $this->tenants->membershipsOf((string) $u['id'])];
    }

    /** @return array{message:string} */
    public function forgotPassword(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['email']);
        $email = $v->email('email');
        $v->done();
        $this->throttle('forgot-acct|' . hash('sha256', (string) $email), 3, 3600);
        $u = $this->users->findByEmail((string) $email);
        if ($u !== null && $u['status'] === 'active') {
            $raw = Tokens::generate();
            $this->users->createToken((string) $u['id'], 'reset_password', Tokens::hash($raw), Clock::sql(Clock::plus("+{$this->cfg['reset_ttl']} seconds")));
            $this->mailer->send((string) $email, 'Reset your LeenTrack password', "We received a request to reset your password.\n\n{$this->webUrl}/reset-password?token=$raw\n\nThis link expires in 1 hour and works once. If you did not ask for this, ignore this message.");
            $this->audit->log('auth.password_reset_requested', $req, (string) $u['id']);
        }
        return ['message' => 'If an account exists for this e-mail, a reset link has been sent.'];
    }

    /** @return array{message:string} */
    public function resetPassword(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['token', 'password']);
        $token = $v->string('token', 20, 200);
        $password = $v->string('password', 1, 128);
        $v->done();
        $this->hasher->assertAcceptable((string) $password);
        $userId = Db::transaction($this->pdo, function () use ($token, $password) {
            $uid = $this->users->consumeToken('reset_password', Tokens::hash((string) $token));
            if ($uid === null) { return null; }
            $this->users->setPassword($uid, $this->hasher->hash((string) $password));   // also clears any legacy hash
            $this->users->markEmailVerified($uid);                                       // they proved control of the inbox
            $this->sessions->logoutAll($uid, 'password_reset');
            return $uid;
        });
        if ($userId === null) {
            throw new ApiException('invalid_token', 'This link is invalid or has expired.', 400);
        }
        $this->audit->log('auth.password_reset', $req, $userId);
        return ['message' => 'Password updated. Please sign in.'];
    }

    /** @return array{message:string} */
    public function verifyEmail(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['token']);
        $token = $v->string('token', 20, 200);
        $v->done();
        $uid = $this->users->consumeToken('verify_email', Tokens::hash((string) $token));
        if ($uid === null) {
            throw new ApiException('invalid_token', 'This link is invalid or has expired.', 400);
        }
        $this->users->markEmailVerified($uid);
        $this->audit->log('auth.email_verified', $req, $uid);
        return ['message' => 'E-mail verified.'];
    }

    /** @return array{message:string} */
    public function resendVerification(array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['email']);
        $email = $v->email('email');
        $v->done();
        $this->throttle('verify-acct|' . hash('sha256', (string) $email), 3, 3600);
        $u = $this->users->findByEmail((string) $email);
        if ($u !== null && $u['status'] === 'active' && $u['email_verified_at'] === null) {
            $raw = Tokens::generate();
            $this->users->createToken((string) $u['id'], 'verify_email', Tokens::hash($raw), Clock::sql(Clock::plus("+{$this->cfg['verify_ttl']} seconds")));
            $this->mailer->send((string) $email, 'Verify your LeenTrack e-mail', "Confirm your e-mail address:\n{$this->webUrl}/verify-email?token=$raw\n\nThis link expires in 48 hours.");
        }
        return ['message' => 'If the account needs verification, a new message has been sent.'];
    }

    /** @return array{message:string} */
    public function changePassword(AuthContext $a, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['current_password', 'new_password']);
        $cur = $v->string('current_password', 1, 1024);
        $new = $v->string('new_password', 1, 128);
        $v->done();
        $this->throttle('chgpw|' . $a->userId, 5, 900);
        $u = $this->users->findById($a->userId) ?? throw ApiException::unauthenticated();
        if ($u['password_hash'] === null || !$this->hasher->verify((string) $cur, (string) $u['password_hash'])) {
            throw new ApiException('invalid_credentials', 'Current password is incorrect.', 403);
        }
        $this->hasher->assertAcceptable((string) $new, $a->email, 'new_password');
        $this->users->setPassword($a->userId, $this->hasher->hash((string) $new));
        $n = $this->sessions->logoutAll($a->userId, 'password_changed', $a->sessionId);
        $this->audit->log('auth.password_changed', $req, $a->userId, null, null, null, null, ['other_sessions_revoked' => $n]);
        return ['message' => 'Password changed. Other devices were signed out.'];
    }

    /** @param mixed $d @return array{id:?string,name:?string,platform:?string} */
    private function device(mixed $d, Validator $v): array
    {
        $out = ['id' => null, 'name' => null, 'platform' => null];
        if ($d === null) { return $out; }
        if (!is_array($d)) { $v->addError('device', 'Must be an object.'); return $out; }
        $dv = new Validator($d);
        $dv->only(['id', 'name', 'platform']);
        $out['id'] = $dv->string('id', 8, 64, false);
        $out['name'] = $dv->string('name', 1, 120, false);
        $out['platform'] = $dv->enum('platform', ['android', 'ios', 'windows', 'macos', 'linux', 'web'], false);
        try { $dv->done(); } catch (ApiException $e) { foreach ($e->fields as $k => $msgs) { foreach ($msgs as $m) { $v->addError("device.$k", $m); } } }
        return $out;
    }

    private function throttle(string $key, int $max, int $window): void
    {
        if (!$this->rateLimit) { return; }
        $r = $this->limiter->hit($key, $max, $window);
        if (!$r['allowed']) { throw ApiException::tooMany($r['retry_after']); }
    }

    /** @param array<string,mixed> $u @return array<string,mixed> */
    public function userView(array $u): array
    {
        $p = $this->users->profile((string) $u['id']) ?? [];
        return [
            'id' => $u['id'], 'email' => $u['email'], 'phone' => $u['phone'],
            'email_verified' => $u['email_verified_at'] !== null,
            'full_name' => $p['full_name'] ?? null, 'address' => $p['address'] ?? null, 'currency' => $p['currency'] ?? 'NGN',
            'is_platform_admin' => $this->users->isPlatformAdmin((string) $u['id']),
            'must_change_password' => (bool) ($u['must_change_password'] ?? 0),
            'has_avatar' => ($p['avatar_path'] ?? null) !== null,
        ];
    }
}
