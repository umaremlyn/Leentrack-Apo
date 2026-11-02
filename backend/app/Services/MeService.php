<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Validator;
use LeenTrack\Http\AuthContext;
use LeenTrack\Repositories\SessionRepository;
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Repositories\UserRepository;

final class MeService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly SessionRepository $sessions,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function show(AuthContext $a): array
    {
        $u = $this->users->findById($a->userId) ?? throw ApiException::unauthenticated();
        $memberships = array_map(function ($m) {
            $m['effective_plan'] = PlanCatalog::effective($m);
            $m['permissions'] = Permissions::for((string) $m['role']);
            return $m;
        }, $this->tenants->membershipsOf($a->userId));
        return ['user' => $this->auth->userView($u), 'memberships' => $memberships];
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(AuthContext $a, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['full_name', 'phone', 'address', 'currency']);
        $name = $v->has('full_name') ? $v->string('full_name', 2, 150) : null;
        $address = $v->has('address') ? $v->string('address', 1, 300) : null;
        $currency = $v->has('currency') ? $v->enum('currency', BusinessService::CURRENCIES) : null;
        $phone = null;
        if ($v->has('phone')) {
            $phone = $v->string('phone', 5, 32, false);
            if ($phone !== null && !preg_match('/^\+?[0-9 ()\-]{5,32}$/', $phone)) { $v->addError('phone', 'Must be a valid phone number.'); }
        }
        $v->done();
        $this->users->updateProfile($a->userId, $name, $address, $currency);
        if ($v->has('phone')) { $this->users->updatePhone($a->userId, $phone); }
        $this->audit->log('me.update', $req, $a->userId);
        return $this->show($a)['user'];
    }

    /** @return list<array<string,mixed>> */
    public function sessions(AuthContext $a): array
    {
        return array_map(fn ($s) => $s + ['current' => $s['id'] === $a->sessionId], $this->sessions->listForUser($a->userId));
    }

    public function revokeSession(AuthContext $a, string $id, Request $req): void
    {
        $s = $this->sessions->findOwned($id, $a->userId) ?? throw ApiException::notFound();
        $this->sessions->revoke((string) $s['id'], 'user_revoked');
        $this->audit->log('me.session_revoked', $req, $a->userId, null, 'session', $id);
    }
}
