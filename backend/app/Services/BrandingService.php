<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Validator;
use LeenTrack\Http\TenantContext;
use LeenTrack\Repositories\SaleRepository;

/** Receipt / invoice branding. Limits are the source's (migration 016). Readable by every member, writable by the owner. */
final class BrandingService
{
    public const MAX_LOGO_CHARS = 200_000;

    public function __construct(private readonly SaleRepository $repo, private readonly AuditService $audit)
    {
    }

    /** @return array<string,?string> */
    public function show(TenantContext $t): array
    {
        $t->require('business.read');
        $b = $this->repo->branding($t->businessId);
        return $b === null
            ? ['logo_data' => null, 'phone' => null, 'email' => null, 'address' => null, 'payment_details' => null, 'footer_note' => null, 'updated_at' => null]
            : $b;
    }

    /** Full replacement (like the source's upsert of the whole form). @param array<string,mixed> $body @return array<string,?string> */
    public function save(TenantContext $t, array $body, Request $req): array
    {
        $t->require('branding.write');
        $v = new Validator($body);
        $v->only(['logo_data', 'phone', 'email', 'address', 'payment_details', 'footer_note']);
        $row = [
            'logo_data' => $this->logo($v, $body),
            'phone' => $v->string('phone', 1, 40, false),
            'email' => $v->email('email', false),
            'address' => $v->string('address', 1, 200, false),
            'payment_details' => $v->string('payment_details', 1, 300, false),
            'footer_note' => $v->string('footer_note', 1, 160, false),
        ];
        if ($row['email'] !== null && strlen($row['email']) > 120) { $v->addError('email', 'Must be at most 120 characters.'); }
        $v->done();
        $this->repo->saveBranding($t->businessId, $row, $t->userId);
        $this->audit->log('branding.save', $req, $t->userId, $t->businessId, 'business', $t->businessId, null, ['logo' => $row['logo_data'] !== null]);
        return $this->show($t);
    }

    /** @param array<string,mixed> $body */
    private function logo(Validator $v, array $body): ?string
    {
        $s = $body['logo_data'] ?? null;
        if ($s === null || $s === '') { return null; }
        if (!is_string($s) || strlen($s) > self::MAX_LOGO_CHARS || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/]+={0,2})$#', $s, $m)) {
            $v->addError('logo_data', 'Use a small PNG, JPEG or WebP image (about 150 KB at most).');
            return null;
        }
        $bin = base64_decode($m[2], true);
        $ok = $bin !== false && match ($m[1]) {
            'png' => str_starts_with($bin, "\x89PNG\r\n\x1a\n"),
            'jpeg' => str_starts_with($bin, "\xFF\xD8\xFF"),
            'webp' => strlen($bin) > 12 && str_starts_with($bin, 'RIFF') && substr($bin, 8, 4) === 'WEBP',
        };
        if (!$ok) { $v->addError('logo_data', 'The file content does not match its image type.'); return null; }
        return $s;
    }
}
