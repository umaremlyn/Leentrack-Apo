<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Db;
use LeenTrack\Core\Request;
use LeenTrack\Core\Uuid;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\TenantRepository;
use PDO;

final class BusinessService
{
    public const CURRENCIES = ['NGN', 'GHS', 'KES', 'ZAR', 'EGP', 'USD', 'GBP', 'EUR', 'XOF'];

    public function __construct(private readonly PDO $pdo, private readonly TenantRepository $repo, private readonly AuditService $audit)
    {
    }

    /** Creates business (free plan) + owner membership + default "Main" outlet. Caller owns the transaction. */
    public function createFor(string $userId, string $ownerEmail, ?string $ownerName, string $name, ?string $type, string $currency): string
    {
        $id = Uuid::v4();
        $this->repo->insertBusiness(['id' => $id, 'name' => $name, 'business_type' => $type, 'currency' => $currency, 'owner_user_id' => $userId]);
        $this->repo->insertMember(['id' => Uuid::v4(), 'business_id' => $id, 'user_id' => $userId, 'email' => $ownerEmail, 'name' => $ownerName, 'role' => 'owner', 'status' => 'active', 'joined_at' => \LeenTrack\Core\Clock::sql()]);
        $this->repo->insertLocation(['id' => Uuid::v4(), 'business_id' => $id, 'name' => 'Main', 'is_default' => 1]);
        return $id;
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function create(string $userId, string $email, array $body, Request $req): array
    {
        $v = new Validator($body);
        $v->only(['name', 'business_type', 'currency']);
        $name = $v->string('name', 2, 150);
        $type = $v->string('business_type', 1, 60, false);
        $cur = $v->enum('currency', self::CURRENCIES, false, 'NGN');
        $v->done();
        $profile = $this->pdo->prepare('SELECT full_name FROM user_profiles WHERE user_id = ?');
        $profile->execute([$userId]);
        $id = Db::transaction($this->pdo, fn () => $this->createFor($userId, $email, ($profile->fetchColumn() ?: null), (string) $name, $type, (string) $cur));
        $this->audit->log('business.create', $req, $userId, $id, 'business', $id, null, ['name' => $name]);
        return $this->view($this->repo->business($id) ?? []);
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    public function update(TenantContext $t, array $body, Request $req): array
    {
        $t->require('business.update');
        $v = new Validator($body);
        $v->only(['name', 'business_type', 'currency', 'tax_rate']);   // plan / plan_status / plan_expiry are rejected by only()
        $f = [];
        if ($v->has('name')) { $f['name'] = $v->string('name', 2, 150); }
        if ($v->has('business_type')) { $f['business_type'] = $v->string('business_type', 1, 60, false); }
        if ($v->has('currency')) { $f['currency'] = $v->enum('currency', self::CURRENCIES); }
        if ($v->has('tax_rate')) { $f['tax_rate'] = $v->percent('tax_rate'); }
        $v->done();
        $before = $this->repo->business($t->businessId) ?? throw ApiException::notFound();
        $this->repo->updateBusiness($t->businessId, $f);
        $after = $this->repo->business($t->businessId) ?? [];
        $this->audit->log('business.update', $req, $t->userId, $t->businessId, 'business', $t->businessId, array_intersect_key($before, $f), array_intersect_key($after, $f));
        return $this->view($after);
    }

    /** @return array<string,mixed> */
    public function get(TenantContext $t): array
    {
        return $this->view($this->repo->business($t->businessId) ?? throw ApiException::notFound());
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private function view(array $b): array
    {
        return [
            'id' => $b['id'], 'name' => $b['name'], 'business_type' => $b['business_type'], 'currency' => $b['currency'],
            'tax_rate' => $b['tax_rate'], 'plan' => $b['plan'], 'plan_status' => $b['plan_status'], 'plan_expiry' => $b['plan_expiry'],
            'effective_plan' => PlanCatalog::effective($b), 'created_at' => $b['created_at'],
        ];
    }
}
