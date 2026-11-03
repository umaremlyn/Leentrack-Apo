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
        private readonly string $avatarDir = '',
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

    private const AVATAR_MAX = 1_000_000;
    private const AVATAR_TYPES = [IMAGETYPE_JPEG => ['image/jpeg', 'jpg'], IMAGETYPE_PNG => ['image/png', 'png'], IMAGETYPE_WEBP => ['image/webp', 'webp']];

    /** The body IS the image (JPEG, PNG or WebP, up to 1 MB). The type is decided from the bytes, never from the client's header or file name. @return array{has_avatar:bool} */
    public function setAvatar(AuthContext $a, string $bytes, Request $req): array
    {
        if ($this->avatarDir === '') { throw new ApiException('not_configured', 'Avatar storage is not configured.', 503); }
        if ($bytes === '' || strlen($bytes) > self::AVATAR_MAX) { throw ApiException::validation(['avatar' => ['Send an image of at most 1 MB as the request body.']]); }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || !isset(self::AVATAR_TYPES[$info[2]]) || $info[0] < 16 || $info[1] < 16 || $info[0] > 4096 || $info[1] > 4096) {
            throw ApiException::validation(['avatar' => ['Must be a JPEG, PNG or WebP image between 16 and 4096 pixels on each side.']]);
        }
        if (!is_dir($this->avatarDir) && !@mkdir($this->avatarDir, 0750, true) && !is_dir($this->avatarDir)) { throw new ApiException('storage_unavailable', 'Could not store the image.', 503); }
        $name = $a->userId . '-' . bin2hex(random_bytes(6)) . '.' . self::AVATAR_TYPES[$info[2]][1];
        if (file_put_contents($this->avatarDir . '/' . $name, $bytes, LOCK_EX) === false) { throw new ApiException('storage_unavailable', 'Could not store the image.', 503); }
        $old = $this->users->profile($a->userId)['avatar_path'] ?? null;
        $this->users->setAvatar($a->userId, $name);
        if ($old !== null) { $this->removeFile((string) $old); }
        $this->audit->log('me.avatar_set', $req, $a->userId);
        return ['has_avatar' => true];
    }

    public function deleteAvatar(AuthContext $a, Request $req): void
    {
        $old = $this->users->profile($a->userId)['avatar_path'] ?? null;
        $this->users->setAvatar($a->userId, null);
        if ($old !== null) { $this->removeFile((string) $old); }
        $this->audit->log('me.avatar_removed', $req, $a->userId);
    }

    /** @return array{0:string,1:string} [bytes, mime] */
    public function avatar(AuthContext $a): array
    {
        $name = $this->users->profile($a->userId)['avatar_path'] ?? null;
        if ($name === null || !preg_match('/^[0-9a-f-]{36}-[0-9a-f]{12}\.(jpg|png|webp)$/', (string) $name) || !is_file($this->avatarDir . '/' . $name)) { throw ApiException::notFound('No avatar.'); }
        $ext = substr((string) $name, -3) === 'png' ? 'image/png' : (str_ends_with((string) $name, 'webp') ? 'image/webp' : 'image/jpeg');
        return [(string) file_get_contents($this->avatarDir . '/' . $name), $ext];
    }

    private function removeFile(string $name): void
    {
        if (preg_match('/^[0-9a-f-]{36}-[0-9a-f]{12}\.(jpg|png|webp)$/', $name) && is_file($this->avatarDir . '/' . $name)) { @unlink($this->avatarDir . '/' . $name); }
    }
}
