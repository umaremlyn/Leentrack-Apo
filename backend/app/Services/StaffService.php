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
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Repositories\UserRepository;
use LeenTrack\Security\PasswordHasher;
use LeenTrack\Security\Tokens;
use PDO;

final class StaffService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantRepository $repo,
        private readonly UserRepository $users,
        private readonly SessionService $sessions,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly AuditService $audit,
        private readonly int $inviteTtl,
        private readonly string $webUrl,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function list(TenantContext $t): array
    {
        $t->require('staff.read');
        return array_map(fn ($m) => $this->view($m), $this->repo->members($t->businessId));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function invite(TenantContext $t, array $body, Request $req): array
    {
        $t->require('staff.manage');
        $t->requireFeature('staffManagement');
        $v = new Validator($body);
        $v->only(['name', 'email', 'role']);
        $name = $v->string('name', 2, 150);
        $email = $v->email('email');
        $role = $v->enum('role', ['manager', 'cashier']);       // 'owner' can never be granted through the API
        $v->done();
        $t->requireWithinLimit('staff', $this->repo->countStaff($t->businessId));

        $id = Uuid::v4();
        $raw = Tokens::generate();
        try {
            $this->repo->insertMember(['id' => $id, 'business_id' => $t->businessId, 'email' => $email, 'name' => $name, 'role' => $role, 'status' => 'invited',
                'invited_by' => $t->userId, 'invite_token_hash' => Tokens::hash($raw), 'invite_expires_at' => Clock::sql(Clock::plus("+{$this->inviteTtl} seconds"))]);
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) { throw ApiException::conflict('already_member', 'This e-mail is already on your team.'); }
            throw $e;
        }
        $this->sendInvite((string) $email, (string) $name, $raw, $t);
        $this->audit->log('staff.invite', $req, $t->userId, $t->businessId, 'member', $id, null, ['email' => $email, 'role' => $role]);
        // The invite link is e-mailed ONLY. Returning it to the inviter would let them accept on the invitee's behalf (docs/01 S-16).
        return $this->view($this->repo->member($t->businessId, $id) ?? []);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, string $memberId, array $body, Request $req): array
    {
        $t->require('staff.manage');
        $m = $this->repo->member($t->businessId, $memberId) ?? throw ApiException::notFound();
        if ($m['role'] === 'owner') { throw ApiException::forbidden('The owner cannot be modified.'); }
        if ($m['user_id'] === $t->userId) { throw ApiException::forbidden('You cannot change your own access.'); }
        $v = new Validator($body);
        $v->only(['name', 'role', 'status']);
        $f = [];
        if ($v->has('name')) { $f['name'] = $v->string('name', 2, 150); }
        if ($v->has('role')) { $f['role'] = $v->enum('role', ['manager', 'cashier']); }
        if ($v->has('status')) { $f['status'] = $v->enum('status', ['active', 'inactive']); }
        $v->done();
        if (isset($f['status']) && $f['status'] === 'active' && $m['status'] === 'invited') {
            throw ApiException::conflict('invite_pending', 'This person has not accepted the invitation yet.');
        }
        $this->repo->updateMember($t->businessId, $memberId, $f);
        $this->audit->log('staff.update', $req, $t->userId, $t->businessId, 'member', $memberId, array_intersect_key($m, $f), $f);
        return $this->view($this->repo->member($t->businessId, $memberId) ?? []);
    }

    public function remove(TenantContext $t, string $memberId, Request $req): void
    {
        $t->require('staff.manage');
        $m = $this->repo->member($t->businessId, $memberId) ?? throw ApiException::notFound();
        if ($m['role'] === 'owner') { throw ApiException::forbidden('The owner cannot be removed.'); }
        if ($m['user_id'] === $t->userId) { throw ApiException::forbidden('You cannot remove yourself.'); }
        // Access is resolved from the membership row on every request, so deleting it revokes access immediately.
        $this->repo->deleteMember($t->businessId, $memberId);
        $this->audit->log('staff.remove', $req, $t->userId, $t->businessId, 'member', $memberId, ['email' => $m['email'], 'role' => $m['role']]);
    }

    /** @return array{message:string} */
    public function resendInvite(TenantContext $t, string $memberId, Request $req): array
    {
        $t->require('staff.manage');
        $m = $this->repo->member($t->businessId, $memberId) ?? throw ApiException::notFound();
        if ($m['status'] !== 'invited') { throw ApiException::conflict('not_pending', 'This invitation was already accepted.'); }
        $raw = Tokens::generate();
        $this->repo->setInvite($t->businessId, $memberId, Tokens::hash($raw), Clock::sql(Clock::plus("+{$this->inviteTtl} seconds")));
        $this->sendInvite((string) $m['email'], (string) ($m['name'] ?? ''), $raw, $t);
        $this->audit->log('staff.invite_resent', $req, $t->userId, $t->businessId, 'member', $memberId);
        return ['message' => 'Invitation sent.'];
    }

    /**
     * Public endpoint. New user: token + password creates the account (the e-mailed token proves inbox control).
     * Existing user: must already be signed in AS the invited e-mail address.
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public function accept(array $body, ?AuthContext $caller, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['token', 'password', 'full_name']);
        $token = $v->string('token', 20, 200);
        $password = $v->string('password', 1, 128, false);
        $fullName = $v->string('full_name', 2, 150, false);
        $v->done();

        $m = $this->repo->memberByInviteHash(Tokens::hash((string) $token));
        if ($m === null || $m['invite_expires_at'] <= Clock::sql()) {
            throw new ApiException('invalid_token', 'This invitation is invalid or has expired.', 400);
        }
        $existing = $this->users->findByEmail((string) $m['email']);
        $userId = null;
        $result = Db::transaction($this->pdo, function () use ($m, $existing, $caller, $password, $fullName, &$userId) {
            if ($existing !== null) {
                if ($caller === null) { throw new ApiException('login_required', 'Sign in with the invited e-mail address, then accept the invitation.', 401); }
                if ($caller->userId !== $existing['id']) { throw ApiException::forbidden('This invitation is for a different account.'); }
                $userId = (string) $existing['id'];
            } else {
                if ($password === null) { throw ApiException::validation(['password' => ['This field is required.']]); }
                $this->hasher->assertAcceptable($password, (string) $m['email']);
                $userId = Uuid::v4();
                $this->users->insert(['id' => $userId, 'email' => $m['email'], 'password_hash' => $this->hasher->hash($password), 'email_verified_at' => Clock::sql()]);
                $this->users->insertProfile($userId, $fullName ?? $m['name'], 'NGN', null);
            }
            if (!$this->repo->activateMember((string) $m['id'], $userId, Clock::sql())) {
                throw new ApiException('invalid_token', 'This invitation is invalid or has expired.', 400);   // lost a race: single use
            }
            return true;
        });
        $this->audit->log('staff.invite_accepted', $req, $userId, (string) $m['business_id'], 'member', (string) $m['id']);
        return ['accepted' => $result, 'business_id' => $m['business_id'], 'new_account' => $existing === null];
    }

    private function sendInvite(string $email, string $name, string $rawToken, TenantContext $t): void
    {
        $biz = $this->repo->business($t->businessId);
        $this->mailer->send($email, 'You have been invited to ' . ($biz['name'] ?? 'a business') . ' on LeenTrack',
            "Hello" . ($name !== '' ? " $name" : '') . ",\n\nYou have been invited to join " . ($biz['name'] ?? 'a business') . " on LeenTrack.\n\nAccept the invitation:\n{$this->webUrl}/accept-invite?token=$rawToken\n\nThis link expires in 7 days and can be used once.");
    }

    /** @param array<string,mixed> $m @return array<string,mixed> */
    private function view(array $m): array
    {
        return ['id' => $m['id'], 'name' => $m['name'], 'email' => $m['email'], 'role' => $m['role'], 'status' => $m['status'], 'joined_at' => $m['joined_at'], 'created_at' => $m['created_at']];
    }
}
