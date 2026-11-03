<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Services\AccountService;

final class AccountTest extends ApiTestCase
{
    private string $avatarDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->avatarDir = sys_get_temp_dir() . '/lt-avatars-' . bin2hex(random_bytes(4));
        $this->boot(['avatar_dir' => $this->avatarDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->avatarDir . '/*') ?: [] as $f) { @unlink($f); }
        @rmdir($this->avatarDir);
        parent::tearDown();
    }

    private function shop(string $email = 'owner@example.com'): array
    {
        $o = $this->signup($email);
        $this->setPlan($o['business_id'], 'growth');
        return $o;
    }

    private function main(array $o): string
    {
        return (string) $this->pdo->query("SELECT id FROM locations WHERE business_id='{$o['business_id']}' AND is_default=1")->fetchColumn();
    }

    private function account(): AccountService
    {
        return (new \LeenTrack\Services\Container($this->app))->account();
    }

    private function purge(): array
    {
        return $this->account()->purgeDue();
    }

    private function png(int $w = 32, int $h = 32): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start(); imagepng($im); return (string) ob_get_clean();
    }

    // ================================================================================================ outlets for staff
    public function testOutletAssignmentRestrictsAndLiftsRestrictions(): void
    {
        $o = $this->shop();
        $main = $this->main($o);
        [, $l] = $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id']);
        $br = $l['data']['id'];
        $this->call('POST', '/expenses', ['description' => 'HQ', 'amount' => '10', 'expense_date' => '2026-03-01', 'outlet_id' => $main], $o['token'], $o['business_id']);
        $this->call('POST', '/expenses', ['description' => 'Branch', 'amount' => '5', 'expense_date' => '2026-03-01', 'outlet_id' => $br], $o['token'], $o['business_id']);
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        $count = fn () => $this->call('GET', '/expenses', null, $m['token'], $o['business_id'])[1]['meta']['total'];
        $this->assertSame(2, $count(), 'unrestricted by default');
        [$s, $j] = $this->call('PUT', "/staff/{$m['member_id']}/outlets", ['outlet_ids' => [$br]], $o['token'], $o['business_id']);
        $this->assertSame([200, [$br]], [$s, $j['data']['outlet_ids']], json_encode($j));
        $this->assertSame(1, $count(), 'takes effect on the very next request');
        $this->assertSame([$br], array_column($this->call('GET', '/staff', null, $o['token'], $o['business_id'])[1]['data'], 'outlet_ids', 'email')['mgr@example.com']);
        [, $j] = $this->call('PUT', "/staff/{$m['member_id']}/outlets", ['outlet_ids' => [$br, $br, $main]], $o['token'], $o['business_id']);
        $this->assertEqualsCanonicalizing([$br, $main], $j['data']['outlet_ids'], 'duplicates collapse');
        [, $j] = $this->call('PUT', "/staff/{$m['member_id']}/outlets", ['outlet_ids' => []], $o['token'], $o['business_id']);
        $this->assertSame([[], 2], [$j['data']['outlet_ids'], $count()], 'empty list = all outlets');
        $this->assertSame(3, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='staff.outlets'")->fetchColumn(), 'each change is audited');
        $ownerMember = (string) $this->pdo->query("SELECT id FROM business_members WHERE business_id='{$o['business_id']}' AND role='owner'")->fetchColumn();
        foreach ([['outlet_ids' => 'x'], ['outlet_ids' => ['nope']], ['outlet_ids' => [Uuid::v4()]], ['outlet_ids' => [$br], 'role' => 'owner'], []] as $bad) {
            $this->assertSame(422, $this->call('PUT', "/staff/{$m['member_id']}/outlets", $bad, $o['token'], $o['business_id'])[0], json_encode($bad));
        }
        $this->assertSame(409, $this->call('PUT', "/staff/$ownerMember/outlets", ['outlet_ids' => [$br]], $o['token'], $o['business_id'])[0], 'the owner is never restricted');
        $this->assertSame(403, $this->call('PUT', "/staff/{$m['member_id']}/outlets", ['outlet_ids' => [$br]], $m['token'], $o['business_id'])[0], 'a manager cannot restrict staff');
        $b = $this->shop('b@example.com');
        $bl = $this->call('POST', '/locations', ['name' => 'B-branch'], $b['token'], $b['business_id'])[1]['data']['id'];
        $this->assertSame(404, $this->call('PUT', "/staff/{$m['member_id']}/outlets", ['outlet_ids' => [$br]], $b['token'], $b['business_id'])[0], "B cannot touch A's staff");
        $this->assertSame(422, $this->call('PUT', "/staff/{$m['member_id']}/outlets", ['outlet_ids' => [$bl]], $o['token'], $o['business_id'])[0], "B's outlet is not A's");
        $f = $this->signup('free@example.com');
        $this->assertSame(402, $this->call('PUT', '/staff/' . Uuid::v4() . '/outlets', ['outlet_ids' => []], $f['token'], $f['business_id'])[0], 'multi-outlet is a paid feature');
    }

    // ================================================================================================ legacy password flag
    public function testWeakLegacyPasswordSetsFlagAndChangingItClearsIt(): void
    {
        $mk = function (string $email, string $pw) {
            $id = Uuid::v4();
            $h = '$2a$' . substr(password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]), 4);
            $this->pdo->prepare('INSERT INTO users (id,email,legacy_bcrypt_hash,email_verified_at) VALUES (?,?,?,NOW())')->execute([$id, $email, $h]);
            $this->pdo->prepare('INSERT INTO user_profiles (user_id, full_name) VALUES (?,?)')->execute([$id, 'Legacy']);
            return $id;
        };
        $weak = $mk('weak@example.com', 'abc12'); $strong = $mk('strong@example.com', 'a-perfectly-fine-one-9');
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => 'weak@example.com', 'password' => 'abc12']);
        $this->assertSame([200, true], [$s, $j['data']['user']['must_change_password']], 'signed in (never locked out) but told to change it');
        $this->assertTrue((bool) $j['data']['user']['must_change_password']);
        $this->assertFalse($this->call('POST', '/auth/login', ['email' => 'strong@example.com', 'password' => 'a-perfectly-fine-one-9'])[1]['data']['user']['must_change_password']);
        // a second sign-in (now on the new hash) still reports it; /me too
        $again = $this->call('POST', '/auth/login', ['email' => 'weak@example.com', 'password' => 'abc12'])[1]['data'];
        $this->assertTrue($again['user']['must_change_password']);
        $this->assertTrue($this->call('GET', '/me', null, $again['access_token'])[1]['data']['user']['must_change_password']);
        $this->assertSame(422, $this->call('POST', '/me/change-password', ['current_password' => 'abc12', 'new_password' => 'abc123'], $again['access_token'])[0], 'the new one must meet the policy');
        $this->assertSame(200, $this->call('POST', '/me/change-password', ['current_password' => 'abc12', 'new_password' => 'a-much-better-pass-7'], $again['access_token'])[0]);
        $this->assertFalse($this->call('POST', '/auth/login', ['email' => 'weak@example.com', 'password' => 'a-much-better-pass-7'])[1]['data']['user']['must_change_password']);
        $this->assertSame('0', (string) $this->pdo->query("SELECT must_change_password FROM users WHERE id='$weak'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.legacy_hash_upgraded' AND JSON_EXTRACT(after_json,'\$.weak_password') = true")->fetchColumn());
        // a normal signed-up user is never flagged, and a silent re-hash must not clear a real flag
        $this->pdo->exec("UPDATE users SET must_change_password = 1 WHERE id='$strong'");
        $this->pdo->prepare('UPDATE users SET password_hash = ?, legacy_bcrypt_hash = NULL WHERE id = ?')->execute([password_hash('a-perfectly-fine-one-9', PASSWORD_BCRYPT, ['cost' => 4]), $strong]);
        $this->assertTrue($this->call('POST', '/auth/login', ['email' => 'strong@example.com', 'password' => 'a-perfectly-fine-one-9'])[1]['data']['user']['must_change_password']);
        $this->assertSame('1', (string) $this->pdo->query("SELECT must_change_password FROM users WHERE id='$strong'")->fetchColumn(), 'a silent upgrade of the stored hash is not a password change, so the flag stays');
        $this->assertStringStartsWith('$argon2id$', (string) $this->pdo->query("SELECT password_hash FROM users WHERE id='$strong'")->fetchColumn(), 'the hash really was upgraded (so the test exercises that path)');
    }

    // ================================================================================================ export
    public function testPersonalExportContainsTheUsersDataAndNoSecrets(): void
    {
        $o = $this->shop();
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        [$s, $j] = $this->call('GET', '/me/export', null, $o['token']);
        $this->assertSame(200, $s);
        $d = $j['data'];
        $this->assertSame(['owner@example.com', 'Acme Stores', 1], [$d['user']['email'], $d['owned_businesses'][0]['name'], count($d['memberships'])]);
        $blob = json_encode($d);
        foreach (['password_hash', 'legacy_bcrypt_hash', 'access_hash', 'refresh_hash', 'token_hash', 'invite_token_hash'] as $secret) { $this->assertStringNotContainsString($secret, $blob, $secret); }
        $this->assertNotEmpty($d['devices']); $this->assertNotEmpty($d['activity']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='me.export'")->fetchColumn());
        $theirs = $this->call('GET', '/me/export', null, $m['token'])[1]['data'];
        $this->assertSame('mgr@example.com', $theirs['user']['email']);
        $this->assertStringNotContainsString('owner@example.com', json_encode($theirs['memberships'] + $theirs['owned_businesses']), 'a member does not receive the owner\'s data');
        $this->assertSame(401, $this->call('GET', '/me/export')[0]);
    }

    public function testBusinessExportIsOwnerOnlyPagedAndCoversEveryRecordType(): void
    {
        $o = $this->shop(); $b = $this->shop('b@example.com');
        $bid = $o['business_id'];
        $mgr = $this->addStaff($o, 'mgr@example.com', 'manager');
        $this->assertSame(403, $this->call('GET', "/businesses/$bid/export", null, $mgr['token'], $bid)[0], 'only the owner exports the business');
        $this->assertSame(404, $this->call('GET', "/businesses/$bid/export", null, $b['token'])[0], "B cannot export A's business");
        [$s, $j] = $this->call('GET', "/businesses/$bid/export", null, $o['token'], $bid);
        $this->assertSame(200, $s);
        $this->assertContains('sales', $j['data']); $this->assertContains('business', $j['data']);
        // 1,005 customers -> two pages
        $ins = $this->pdo->prepare("INSERT INTO customers (id, business_id, name) VALUES (?,?,?)");
        $this->pdo->beginTransaction();
        for ($i = 0; $i < 1005; $i++) { $ins->execute([Uuid::v4(), $bid, "C$i"]); }
        $ins->execute([Uuid::v4(), $b['business_id'], 'B-secret']);
        $this->pdo->commit();
        [, $p1] = $this->call('GET', "/businesses/$bid/export?section=customers", null, $o['token'], $bid);
        [, $p2] = $this->call('GET', "/businesses/$bid/export?section=customers&page=2", null, $o['token'], $bid);
        $this->assertSame([1000, true, 5, false], [count($p1['data']), $p1['meta']['has_more'], count($p2['data']), $p2['meta']['has_more']]);
        $this->assertStringNotContainsString('B-secret', json_encode([$p1, $p2]), "another business's rows never appear");
        $mem = $this->call('GET', "/businesses/$bid/export?section=members", null, $o['token'], $bid)[1]['data'];
        $this->assertStringNotContainsString('invite_token_hash', json_encode($mem));
        $this->assertSame('Acme Stores', $this->call('GET', "/businesses/$bid/export?section=business", null, $o['token'], $bid)[1]['data'][0]['name']);
        foreach ($j['data'] as $sec) { $this->assertSame(200, $this->call('GET', "/businesses/$bid/export?section=$sec", null, $o['token'], $bid)[0], "section $sec"); }
        foreach (['?section=users', '?section=sales;DROP', '?page=0&section=sales', '?bogus=1', '?section[]=sales'] as $bad) { $this->assertSame(422, $this->call('GET', "/businesses/$bid/export$bad", null, $o['token'], $bid)[0], $bad); }
    }

    // ================================================================================================ deletion
    public function testDeletionRequestRulesAndGracePeriod(): void
    {
        $o = $this->shop();
        $req = fn (array $b, ?string $tok = null) => $this->call('POST', '/me/deletion', $b, $tok ?? $o['token']);
        $this->assertSame(403, $req(['password' => 'wrong-password-1', 'confirm' => 'DELETE'])[0]);
        $this->assertSame(422, $req(['password' => self::PW, 'confirm' => 'delete'])[0]);
        $this->assertSame(422, $req(['password' => self::PW])[0]);
        $this->assertSame(422, $req(['password' => self::PW, 'confirm' => 'DELETE', 'user_id' => 'x'])[0]);
        $m = $this->addStaff($o, 'mgr@example.com', 'manager');
        [$s, $j] = $req(['password' => self::PW, 'confirm' => 'DELETE']);
        $this->assertSame([409, 'owns_business_with_staff'], [$s, $j['error']['code']], 'the owner of a business with a team must remove the team first');
        $this->assertStringContainsString('Acme Stores', $j['error']['message']);
        $this->assertSame('active', $this->pdo->query("SELECT status FROM users WHERE id='{$o['user_id']}'")->fetchColumn(), 'nothing changed');
        $this->call('DELETE', "/staff/{$m['member_id']}", null, $o['token'], $o['business_id']);
        $second = $this->login('owner@example.com');        // another device
        
        [$s, $j] = $req(['password' => self::PW, 'confirm' => 'DELETE']);
        $this->assertSame([202, 'pending_deletion', 14], [$s, $j['data']['status'], $j['data']['grace_days']], json_encode($j));
        $this->assertSame(gmdate('Y-m-d', time() + 14 * 86400), $j['data']['scheduled_for']);
        $this->assertSame(401, $this->call('GET', '/me', null, $o['token'])[0], 'every session is revoked immediately');
        $this->assertSame(401, $this->call('GET', '/me', null, $second['token'])[0]);
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => 'owner@example.com', 'password' => self::PW]);
        $this->assertSame([403, 'account_pending_deletion'], [$s, $j['error']['code']]);
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => 'owner@example.com', 'password' => 'wrong-password-1']);
        $this->assertSame([401, 'invalid_credentials'], [$s, $j['error']['code']], 'the pending state is only revealed to someone who knows the password');
        $this->assertSame(1, count(array_filter(\LeenTrack\Mail\FileMailer::$outbox, fn ($m) => str_contains($m['subject'] ?? '', 'will be deleted'))), 'a notice is e-mailed');
        $this->assertSame(0, $this->purge()['accounts'], 'not due yet');
        // cancelling
        $this->assertSame(401, $this->call('POST', '/auth/cancel-deletion', ['email' => 'owner@example.com', 'password' => 'wrong-password-1'])[0]);
        $this->assertSame(401, $this->call('POST', '/auth/cancel-deletion', ['email' => 'nobody@example.com', 'password' => self::PW])[0]);
        $this->assertSame(200, $this->call('POST', '/auth/cancel-deletion', ['email' => 'owner@example.com', 'password' => self::PW])[0]);
        $this->assertSame(401, $this->call('POST', '/auth/cancel-deletion', ['email' => 'owner@example.com', 'password' => self::PW])[0], 'nothing left to cancel');
        $back = $this->login('owner@example.com');
        $this->assertSame(200, $this->call('GET', '/me', null, $back['token'])[0]);
        $this->assertSame([401, 401], [$this->call('GET', '/me', null, $o['token'])[0], $this->call('GET', '/me', null, $second['token'])[0]], 'cancelling must not bring the old sessions back to life');
        $this->assertNull($this->pdo->query("SELECT deletion_scheduled_at FROM users WHERE id='{$o['user_id']}'")->fetchColumn());
        // a cancelled account is not purged even if its old date passes
        $this->pdo->exec("UPDATE users SET deletion_scheduled_at = '2000-01-01' WHERE id='{$o['user_id']}'");
        $this->assertSame(0, $this->purge()['accounts']);
    }

    public function testPurgeRechecksTheStateUnderLockSoACancelledRequestSurvivesARace(): void
    {
        $o = $this->shop();
        $this->call('POST', '/products', ['name' => 'Rice', 'selling_price' => '10', 'cost_price' => '5', 'stock_quantity' => 3], $o['token'], $o['business_id']);
        // the job selected this user as due, but the owner cancelled in the meantime -> the user is 'active' when the transaction starts
        $m = new \ReflectionMethod(AccountService::class, 'purgeUser');
        $m->setAccessible(true);
        $this->assertSame(0, $m->invoke($this->account(), $o['user_id']));
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE business_id='{$o['business_id']}'")->fetchColumn(), 'nothing was deleted');
        $this->assertSame('active', $this->pdo->query("SELECT status FROM users WHERE id='{$o['user_id']}'")->fetchColumn());
    }

    public function testPlatformAdminCannotDeleteThemselves(): void
    {
        $o = $this->shop();
        $this->pdo->prepare('INSERT INTO platform_admins (user_id) VALUES (?)')->execute([$o['user_id']]);
        $r = $this->call('POST', '/me/deletion', ['password' => self::PW, 'confirm' => 'DELETE'], $o['token']);
        $this->assertSame([409, 'platform_admin'], [$r[0], $r[1]['error']['code']]);
    }

    // ================================================================================================ purge
    /** @return array<string,int> rows per business-scoped table */
    private function rowsOf(string $bid): array
    {
        $out = [];
        $q = $this->pdo->prepare("SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'business_id' ORDER BY 1");
        $q->execute();
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $c = $this->pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE business_id = ?"); $c->execute([$bid]); $out[$t] = (int) $c->fetchColumn();
        }
        $c = $this->pdo->prepare('SELECT COUNT(*) FROM member_outlets x JOIN business_members m ON m.id = x.member_id WHERE m.business_id = ?'); $c->execute([$bid]); $out['member_outlets'] = (int) $c->fetchColumn();
        $c = $this->pdo->prepare('SELECT COUNT(*) FROM purchase_receipt_items x JOIN purchase_receipts r ON r.id = x.receipt_id WHERE r.business_id = ?'); $c->execute([$bid]); $out['purchase_receipt_items'] = (int) $c->fetchColumn();
        $c = $this->pdo->prepare('SELECT COUNT(*) FROM businesses WHERE id = ?'); $c->execute([$bid]); $out['businesses'] = (int) $c->fetchColumn();
        return $out;
    }

    /** exercises (almost) every module so the purge has something to chew on */
    private function populate(array $o): void
    {
        $t = $o['token']; $b = $o['business_id'];
        $call = function (string $m, string $p, ?array $body = null) use ($t, $b) { $r = $this->call($m, $p, $body, $t, $b); $this->assertLessThan(300, $r[0], "$m $p " . json_encode($r[1])); return $r[1]['data'] ?? []; };
        $main = $this->main($o);
        $br = $call('POST', '/locations', ['name' => 'Branch'])['id'];
        $cat = $call('POST', '/categories', ['name' => 'Food']);
        $p1 = $call('POST', '/products', ['name' => 'Rice', 'selling_price' => '100', 'cost_price' => '60', 'stock_quantity' => 50, 'category_id' => $cat['id']]);
        $p2 = $call('POST', '/products', ['name' => 'Beans', 'selling_price' => '50', 'cost_price' => '30', 'stock_quantity' => 50]);
        $grp = $call('POST', '/customer-groups', ['name' => 'VIP', 'discount_percentage' => 5]);
        $c = $call('POST', '/customers', ['name' => 'Ada', 'group_id' => $grp['id']]);
        $call('PUT', "/businesses/$b/loyalty-settings", ['enabled' => true, 'spend_per_point' => '100', 'point_value' => '1']);
        $call('PUT', "/businesses/$b/branding", ['phone' => '0800', 'footer_note' => 'thanks']);
        $sale = $call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'customer_id' => $c['id'], 'items' => [['product_id' => $p1['id'], 'quantity' => 3]], 'payment' => ['mode' => 'credit', 'amount' => '50']]);
        $call('POST', "/sales/{$sale['id']}/repayments", ['client_operation_id' => Uuid::v4(), 'amount' => '20']);
        $s2 = $call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $p2['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '50']]);
        $call('POST', "/sales/{$s2['id']}/void", ['reason' => 'test']);
        $cat2 = $call('POST', '/expense-categories', ['name' => 'Rent']);
        $call('POST', '/expenses', ['description' => 'Rent', 'amount' => '10', 'expense_date' => '2026-03-01', 'category_id' => $cat2['id'], 'outlet_id' => $br]);
        $sup = $call('POST', '/suppliers', ['name' => 'Acme']);
        $po = $call('POST', '/purchase-orders', ['supplier_id' => $sup['id'], 'items' => [['product_id' => $p1['id'], 'quantity' => 5, 'unit_cost' => '55']]]);
        $call('POST', "/purchase-orders/{$po['id']}/order");
        $call('POST', "/purchase-orders/{$po['id']}/receive", ['client_operation_id' => Uuid::v4(), 'items' => [['item_id' => $po['items'][0]['id'], 'quantity' => 2]]]);
        $tr = $call('POST', '/transfers', ['from_location_id' => $main, 'to_location_id' => $br, 'items' => [['product_id' => $p2['id'], 'quantity' => 4]]]);
        $call('POST', "/transfers/{$tr['id']}/dispatch", ['client_operation_id' => Uuid::v4()]);
    }

    public function testPurgeRemovesTheOwnersBusinessEverywhereAndLeavesOthersIntact(): void
    {
        $a = $this->shop('a@example.com'); $b = $this->shop('b@example.com');
        // A is also a cashier-turned-manager in B's business and sells there
        $this->call('POST', '/staff', ['name' => 'A in B', 'email' => 'a@example.com', 'role' => 'manager'], $b['token'], $b['business_id']);
        $this->call('POST', '/invites/accept', ['token' => $this->tokenFromMail('a@example.com')], $a['token']);
        $bp = $this->call('POST', '/products', ['name' => 'B item', 'selling_price' => '10', 'cost_price' => '5', 'stock_quantity' => 20], $b['token'], $b['business_id'])[1]['data'];
        $sale = $this->call('POST', '/sales', ['client_operation_id' => Uuid::v4(), 'items' => [['product_id' => $bp['id'], 'quantity' => 1]], 'payment' => ['mode' => 'full', 'method' => 'cash', 'amount' => '10']], $a['token'], $b['business_id']);
        $this->assertSame(201, $sale[0], json_encode($sale[1]));
        $this->populate($a);
        $this->populate($b);
        $beforeB = $this->rowsOf($b['business_id']);
        $this->assertGreaterThan(0, $beforeB['sales']); $this->assertGreaterThan(0, $beforeB['purchase_receipt_items']); $this->assertGreaterThan(0, $beforeB['transfer_items']);
        $before = $this->rowsOf($a['business_id']);
        foreach (['sales', 'sale_items', 'debt_repayments', 'loyalty_ledger', 'stock_movements', 'purchase_orders', 'purchase_receipts', 'purchase_receipt_items', 'transfers', 'expenses', 'customers', 'products', 'business_branding', 'document_sequences'] as $t) { $this->assertGreaterThan(0, $before[$t], "scenario must cover $t"); }
        // A removes themself from B first? No: a staff membership elsewhere is removed by the purge
        $a2 = $this->call('POST', '/me/deletion', ['password' => self::PW, 'confirm' => 'DELETE'], $a['token']);
        $this->assertSame(202, $a2[0], json_encode($a2[1]));
        $this->assertSame(0, $this->purge()['accounts']);
        $this->pdo->exec("UPDATE users SET deletion_scheduled_at = '2000-01-01' WHERE id='{$a['user_id']}'");
        $res = $this->purge();
        $this->assertSame(['accounts' => 1, 'businesses' => 1, 'failed' => 0], $res);
        $this->assertSame(array_fill_keys(array_keys($before), 0), $this->rowsOf($a['business_id']), 'not one row of A\'s business remains in any table');
        $expectB = $beforeB; $expectB['business_members']--;                                      // only A's membership there is removed
        $this->assertSame($expectB, $this->rowsOf($b['business_id']), "B's business is untouched apart from A's membership");
        $u = $this->pdo->query("SELECT email,status,password_hash,legacy_bcrypt_hash,phone,anonymised_at,deleted_at FROM users WHERE id='{$a['user_id']}'")->fetch();
        $this->assertSame(['deleted-' . $a['user_id'] . '@deleted.invalid', 'disabled', null, null, null], [$u['email'], $u['status'], $u['password_hash'], $u['legacy_bcrypt_hash'], $u['phone']]);
        $this->assertNotNull($u['anonymised_at']);
        $this->assertSame([null, null], array_values($this->pdo->query("SELECT full_name, address FROM user_profiles WHERE user_id='{$a['user_id']}'")->fetch(\PDO::FETCH_NUM)));
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM business_members WHERE user_id='{$a['user_id']}'")->fetchColumn(), 'membership in B removed');
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM auth_sessions WHERE user_id='{$a['user_id']}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM sales WHERE id='{$sale[1]['data']['id']}' AND user_id='{$a['user_id']}'")->fetchColumn(), "A's sale in B's books survives (the user row is kept, blanked)");
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => 'a@example.com', 'password' => self::PW])[0]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='account.purged' AND business_id IS NULL")->fetchColumn(), 'the purge itself is on record');
        $this->assertSame(['accounts' => 0, 'businesses' => 0, 'failed' => 0], $this->purge(), 'idempotent');
        // the e-mail address can be used again for a brand new account
        $this->assertSame(202, $this->call('POST', '/auth/register', ['email' => 'a@example.com', 'password' => self::PW, 'full_name' => 'New A', 'business_name' => 'Again', 'business_type' => 'retail', 'currency' => 'NGN'])[0]);
    }

    public function testEveryTableWithABusinessIdIsCoveredByThePurge(): void
    {
        $tables = $this->pdo->query("SELECT DISTINCT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'business_id'")->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([], array_values(array_diff($tables, AccountService::PURGE_ORDER, AccountService::RETAINED)), 'a new business-scoped table must be added to AccountService::PURGE_ORDER (or RETAINED with a reason) and to the export');
        $this->assertSame([], array_values(array_diff(array_diff($tables, ['business_branding', 'document_sequences', 'loyalty_settings', 'audit_logs', 'business_members', 'locations']), array_column(AccountService::EXPORT_SECTIONS, 0))), 'a new business-scoped table must be exportable');
        foreach (AccountService::PURGE_ORDER as $t) { $this->assertContains($t, array_merge($tables, ['member_outlets', 'purchase_receipt_items']), "unknown table $t"); }
    }

    // ================================================================================================ avatar
    public function testAvatarUploadValidationReplacementAndRemoval(): void
    {
        $o = $this->shop();
        $put = fn (string $body, ?string $tok = null) => $this->rawCall('PUT', '/me/avatar', $body, $tok ?? $o['token'], 'application/octet-stream');
        $this->assertSame(404, $this->call('GET', '/me/avatar', null, $o['token'])[0]);
        $png = $this->png();
        [$s, $j] = $put($png);
        $this->assertSame([200, true], [$s, $j['data']['has_avatar']]);
        $files = glob($this->avatarDir . '/*');
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('~/' . $o['user_id'] . '-[0-9a-f]{12}\.png$~', $files[0]);
        $res = $this->rawCall('GET', '/me/avatar', '', $o['token'], 'application/json')[2];
        $this->assertSame([$png, 'image/png', 'nosniff'], [$res->body, $res->headers['Content-Type'], $res->headers['X-Content-Type-Options']]);
        $this->assertTrue($this->call('GET', '/me', null, $o['token'])[1]['data']['user']['has_avatar']);
        // the type comes from the bytes: non-images, SVG, GIF, PHP in disguise, tiny/huge are all refused and nothing is stored
        ob_start(); imagejpeg(imagecreatetruecolor(20, 20)); $jpg = (string) ob_get_clean();
        ob_start(); imagegif(imagecreatetruecolor(32, 32)); $gif = (string) ob_get_clean();      // a valid, big-enough GIF: refused only because GIF is not an allowed type
        foreach (['' => 'empty', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>' => 'svg', '<?php system($_GET[0]); ?>' => 'php', $gif => 'gif', $this->png(4, 4) => 'too small', $png . str_repeat('x', 1_000_100) => 'too big', 'GIF89a' . $png => 'bad header'] as $bytes => $why) {
            $this->assertSame(422, $put((string) $bytes)[0], $why);
        }
        $this->assertCount(1, glob($this->avatarDir . '/*'), 'refused uploads leave no file');
        $this->assertSame(200, $put($jpg)[0]);
        $files = glob($this->avatarDir . '/*');
        $this->assertCount(1, $files, 'the old file is replaced, not kept');
        $this->assertStringEndsWith('.jpg', $files[0]);
        $this->assertSame('image/jpeg', $this->rawCall('GET', '/me/avatar', '', $o['token'], 'application/json')[2]->headers['Content-Type']);
        $other = $this->shop('other@example.com');
        $this->assertSame(404, $this->call('GET', '/me/avatar', null, $other['token'])[0], 'you only ever see your own');
        $this->assertSame(200, $this->call('DELETE', '/me/avatar', null, $o['token'])[0]);
        $this->assertSame([], glob($this->avatarDir . '/*'));
        $this->assertSame(404, $this->call('GET', '/me/avatar', null, $o['token'])[0]);
        $this->assertSame(401, $put($png, 'garbage')[0]);
    }

    /** @return array{0:int,1:array<string,mixed>,2:\LeenTrack\Core\Response} */
    private function rawCall(string $method, string $path, string $body, string $token, string $ct): array
    {
        $req = \LeenTrack\Core\Request::fromGlobals(['REQUEST_METHOD' => $method, 'REQUEST_URI' => '/api/v1' . $path, 'REMOTE_ADDR' => $this->ip, 'CONTENT_TYPE' => $ct, 'HTTP_AUTHORIZATION' => "Bearer $token"], $body);
        $res = $this->kernel->handle($req);
        return [$res->status, json_decode($res->body, true) ?? [], $res];
    }
}
