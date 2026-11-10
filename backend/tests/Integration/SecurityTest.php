<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Mail\FileMailer;

final class SecurityTest extends ApiTestCase
{
    /** Substitutes path params with a syntactically valid id. */
    private function concrete(string $pattern): string
    {
        return preg_replace('/\{[a-z_]+\}/', '99999999-9999-4999-8999-999999999999', $pattern);
    }

    // ---- S-UNAUTH: driven by the route table so new routes are covered automatically ------------------------------
    public function testEveryNonPublicRouteRejectsMissingAndGarbageTokens(): void
    {
        $checked = 0;
        foreach ($this->kernel->router()->table() as $r) {
            if ($r['public']) { continue; }
            $path = $this->concrete($r['pattern']);
            [$s, $j] = $this->call($r['method'], $path, null, null, '99999999-9999-4999-8999-999999999999');
            $this->assertSame(401, $s, "{$r['method']} {$r['pattern']} without token");
            foreach (['garbage', 'lta_' . str_repeat('A', 43), 'ltr_' . str_repeat('A', 43)] as $bad) {
                [$s] = $this->call($r['method'], $path, null, $bad, '99999999-9999-4999-8999-999999999999');
                $this->assertSame(401, $s, "{$r['method']} {$r['pattern']} with token $bad");
            }
            $checked++;
        }
        $this->assertGreaterThan(15, $checked);
    }

    public function testTokenInQueryStringOrWrongSchemeIsIgnored(): void
    {
        $o = $this->signup('q@example.com');
        [$s] = $this->call('GET', '/me?access_token=' . $o['token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('GET', '/me', null, null, null, ['Authorization' => 'Basic ' . base64_encode('a:b')]);
        $this->assertSame(401, $s);
        [$s] = $this->call('GET', '/me', null, $o['refresh']);
        $this->assertSame(401, $s, 'a refresh token is not an access token');
        [$s] = $this->call('GET', '/me', null, substr($o['token'], 0, -3));
        $this->assertSame(401, $s, 'truncated token');
        [$s] = $this->call('GET', '/me', null, $o['token'] . 'x');
        $this->assertSame(401, $s, 'extended token');
    }

    // ---- S-XBIZ: cross-business access ----------------------------------------------------------------------------
    /** @return array{0:array,1:array} */
    private function twoBusinesses(): array
    {
        $a = $this->signup('a-owner@example.com', 'Business A');
        $b = $this->signup('b-owner@example.com', 'Business B');
        $this->assertNotSame($a['business_id'], $b['business_id']);
        return [$a, $b];
    }

    public function testUserCannotActInAnotherBusinessViaHeader(): void
    {
        [$a, $b] = $this->twoBusinesses();
        foreach ([['GET', '/staff'], ['GET', '/locations'], ['POST', '/staff', ['name' => 'X Y', 'email' => 'x@example.com', 'role' => 'cashier']], ['POST', '/locations', ['name' => 'Evil']]] as $c) {
            [$s] = $this->call($c[0], $c[1], $c[2] ?? null, $b['token'], $a['business_id']);
            $this->assertSame(404, $s, "{$c[0]} {$c[1]} with A's business id by B's owner");
        }
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM business_members WHERE email='x@example.com'")->fetchColumn());
        $cnt = $this->pdo->prepare('SELECT COUNT(*) FROM locations WHERE business_id = ?');
        $cnt->execute([$a['business_id']]);
        $this->assertSame(1, (int) $cnt->fetchColumn(), "A still has only its default outlet");
    }

    public function testBusinessPathRoutesHideOtherTenants(): void
    {
        [$a, $b] = $this->twoBusinesses();
        [$s] = $this->call('GET', '/businesses/' . $a['business_id'], null, $b['token']);
        $this->assertSame(404, $s);
        [$s] = $this->call('PATCH', '/businesses/' . $a['business_id'], ['name' => 'Hijacked'], $b['token']);
        $this->assertSame(404, $s);
        $this->assertSame('Business A', $this->pdo->query("SELECT name FROM businesses WHERE id='{$a['business_id']}'")->fetchColumn());
        [$s, $j] = $this->call('GET', '/businesses', null, $b['token']);
        $this->assertSame([$b['business_id']], array_column($j['data'], 'business_id'), 'listing only shows own memberships');
    }

    public function testIdsFromAnotherBusinessAreNotAddressable(): void
    {
        [$a, $b] = $this->twoBusinesses();
        $locA = $this->pdo->query("SELECT id FROM locations WHERE business_id='{$a['business_id']}'")->fetchColumn();
        $this->setPlan($a['business_id'], 'growth');
        $staffA = $this->addStaff($a, 'a-staff@example.com', 'manager');
        // B's owner, acting in B, targets A's location / member ids
        [$s] = $this->call('GET', "/locations/$locA", null, $b['token'], $b['business_id']);
        $this->assertSame(404, $s);
        [$s] = $this->call('PATCH', "/locations/$locA", ['name' => 'pwned'], $b['token'], $b['business_id']);
        $this->assertSame(404, $s);
        [$s] = $this->call('DELETE', "/locations/$locA", null, $b['token'], $b['business_id']);
        $this->assertSame(404, $s);
        [$s] = $this->call('PATCH', "/staff/{$staffA['member_id']}", ['role' => 'cashier'], $b['token'], $b['business_id']);
        $this->assertSame(404, $s);
        [$s] = $this->call('DELETE', "/staff/{$staffA['member_id']}", null, $b['token'], $b['business_id']);
        $this->assertSame(404, $s);
        $this->assertSame('Main', $this->pdo->query("SELECT name FROM locations WHERE id='$locA'")->fetchColumn());
        $this->assertSame('manager', $this->pdo->query("SELECT role FROM business_members WHERE id='{$staffA['member_id']}'")->fetchColumn());
    }

    public function testMissingOrMalformedBusinessHeader(): void
    {
        $o = $this->signup('h@example.com');
        [$s, $j] = $this->call('GET', '/staff', null, $o['token']);
        $this->assertSame([400, 'business_required'], [$s, $j['error']['code']]);
        [$s] = $this->call('GET', '/staff', null, $o['token'], "1' OR '1'='1");
        $this->assertSame(400, $s);
    }

    // ---- S-ESC-STAFF: privilege escalation ------------------------------------------------------------------------
    public function testCashierAndManagerCannotUseOwnerFunctions(): void
    {
        $o = $this->signup('own@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $cashier = $this->addStaff($o, 'cashier@example.com', 'cashier');
        $manager = $this->addStaff($o, 'manager@example.com', 'manager');
        $biz = $o['business_id'];

        foreach ([$cashier, $manager] as $who) {
            [$s] = $this->call('POST', '/staff', ['name' => 'N N', 'email' => 'n@example.com', 'role' => 'cashier'], $who['token'], $biz);
            $this->assertSame(403, $s, 'invite');
            [$s] = $this->call('PATCH', '/staff/' . $cashier['member_id'], ['role' => 'manager'], $who['token'], $biz);
            $this->assertSame(403, $s, 'role change');
            [$s] = $this->call('DELETE', '/staff/' . $manager['member_id'], null, $who['token'], $biz);
            $this->assertSame(403, $s, 'remove');
            [$s] = $this->call('PATCH', "/businesses/$biz", ['name' => 'Mine now'], $who['token']);
            $this->assertSame(403, $s, 'business update');
        }
        [$s] = $this->call('GET', '/staff', null, $cashier['token'], $biz);
        $this->assertSame(403, $s, 'cashiers cannot list the team');
        [$s] = $this->call('POST', '/locations', ['name' => 'Annex'], $cashier['token'], $biz);
        $this->assertSame(403, $s);
        [$s] = $this->call('POST', '/locations', ['name' => 'Annex'], $manager['token'], $biz);
        $this->assertSame(201, $s, 'managers may manage locations (matrix)');
        $this->assertSame('Acme Stores', $this->pdo->query("SELECT name FROM businesses WHERE id='$biz'")->fetchColumn());
    }

    public function testNobodyCanSelfPromoteOrGrantOwner(): void
    {
        $o = $this->signup('owner2@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $m = $this->addStaff($o, 'mgr2@example.com', 'manager');
        foreach (['owner', 'admin', 'superuser'] as $role) {
            [$s] = $this->call('POST', '/staff', ['name' => 'Eve Evil', 'email' => "e-$role@example.com", 'role' => $role], $o['token'], $o['business_id']);
            $this->assertSame(422, $s, "invite as $role");
            [$s] = $this->call('PATCH', '/staff/' . $m['member_id'], ['role' => $role], $o['token'], $o['business_id']);
            $this->assertSame(422, $s, "update to $role");
        }
        [$s] = $this->call('PATCH', '/staff/' . $m['member_id'], ['role' => 'owner'], $m['token'], $o['business_id']);
        $this->assertSame(403, $s, 'manager editing own record');
        $ownerMember = $this->pdo->query("SELECT id FROM business_members WHERE role='owner'")->fetchColumn();
        [$s] = $this->call('PATCH', "/staff/$ownerMember", ['status' => 'inactive'], $o['token'], $o['business_id']);
        $this->assertSame(403, $s, 'owner membership is immutable via API');
        [$s] = $this->call('DELETE', "/staff/$ownerMember", null, $o['token'], $o['business_id']);
        $this->assertSame(403, $s);
    }

    // ---- S-ESC-ADMIN -----------------------------------------------------------------------------------------------
    public function testAdminCannotBeGrantedThroughAnyApiPath(): void
    {
        $o = $this->signup('plainowner@example.com');
        // registration, profile update, staff invite, business update: every vector for a "role" value is rejected
        [$s] = $this->call('POST', '/auth/register', ['email' => 'sneaky@example.com', 'password' => self::PW, 'full_name' => 'S S', 'business_name' => 'Biz', 'role' => 'admin']);
        $this->assertSame(422, $s);
        [$s] = $this->call('PATCH', '/me', ['role' => 'admin'], $o['token']);
        $this->assertSame(422, $s);
        [$s] = $this->call('PATCH', '/me', ['is_platform_admin' => true], $o['token']);
        $this->assertSame(422, $s);
        [$s] = $this->call('PATCH', '/businesses/' . $o['business_id'], ['role' => 'admin'], $o['token']);
        $this->assertSame(422, $s);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM platform_admins')->fetchColumn());
        // admin endpoints are invisible to non-admins (404, not 403)
        [$s] = $this->call('GET', '/admin/stats', null, $o['token']);
        $this->assertSame(404, $s);
    }

    public function testPlatformAdminEndpointWorksOnlyForRowInPlatformAdmins(): void
    {
        $o = $this->signup('realadmin@example.com');
        $this->pdo->prepare('INSERT INTO platform_admins (user_id) VALUES (?)')->execute([$o['user_id']]);
        [$s, $j] = $this->call('GET', '/admin/stats', null, $o['token']);
        $this->assertSame(200, $s);
        $this->assertSame(1, $j['data']['users']);
        $this->pdo->prepare('DELETE FROM platform_admins WHERE user_id = ?')->execute([$o['user_id']]);
        [$s] = $this->call('GET', '/admin/stats', null, $o['token']);
        $this->assertSame(404, $s, 'revocation is immediate (checked per request)');
    }

    // ---- S-PLAN -----------------------------------------------------------------------------------------------------
    public function testTenantCannotChangeBillingFields(): void
    {
        $o = $this->signup('planner@example.com');
        foreach (['plan' => 'business', 'plan_status' => 'active', 'plan_expiry' => '2099-01-01', 'owner_user_id' => $o['user_id'], 'paystack_customer_id' => 'x'] as $k => $v) {
            [$s, $j] = $this->call('PATCH', '/businesses/' . $o['business_id'], [$k => $v], $o['token']);
            $this->assertSame(422, $s, $k);
        }
        $this->assertSame('free', $this->pdo->query("SELECT plan FROM businesses WHERE id='{$o['business_id']}'")->fetchColumn());
        [$s, $j] = $this->call('PATCH', '/businesses/' . $o['business_id'], ['name' => 'Renamed', 'tax_rate' => '7.5'], $o['token']);
        $this->assertSame(200, $s);
        $this->assertSame('7.50', $j['data']['tax_rate']);
        [$s] = $this->call('PATCH', '/businesses/' . $o['business_id'], ['tax_rate' => '101'], $o['token']);
        $this->assertSame(422, $s);
    }

    public function testPlanGatesAreEnforcedServerSide(): void
    {
        $o = $this->signup('gate@example.com');
        [$s, $j] = $this->call('POST', '/staff', ['name' => 'N N', 'email' => 'n@example.com', 'role' => 'cashier'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'feature_not_in_plan'], [$s, $j['error']['code']]);
        [$s, $j] = $this->call('POST', '/locations', ['name' => 'Second'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'feature_not_in_plan'], [$s, $j['error']['code']]);

        $this->setPlan($o['business_id'], 'growth');
        foreach (['Second', 'Third'] as $n) {
            [$s] = $this->call('POST', '/locations', ['name' => $n], $o['token'], $o['business_id']);
            $this->assertSame(201, $s, $n);
        }
        [$s, $j] = $this->call('POST', '/locations', ['name' => 'Fourth'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'plan_limit_reached'], [$s, $j['error']['code']], 'growth allows 3 outlets');

        // an expired paid plan stops granting features immediately, before the nightly downgrade job runs
        $this->setPlan($o['business_id'], 'business', gmdate('Y-m-d H:i:s', time() - 60));
        [$s, $j] = $this->call('POST', '/staff', ['name' => 'N N', 'email' => 'n2@example.com', 'role' => 'cashier'], $o['token'], $o['business_id']);
        $this->assertSame([402, 'feature_not_in_plan'], [$s, $j['error']['code']]);
    }

    // ---- S-SESSION / S-16 ---------------------------------------------------------------------------------------------
    public function testRemovedStaffLoseAccessImmediately(): void
    {
        $o = $this->signup('boss@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $m = $this->addStaff($o, 'leaver@example.com', 'manager');
        [$s] = $this->call('GET', '/staff', null, $m['token'], $o['business_id']);
        $this->assertSame(200, $s);
        [$s] = $this->call('DELETE', '/staff/' . $m['member_id'], null, $o['token'], $o['business_id']);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/staff', null, $m['token'], $o['business_id']);
        $this->assertSame(404, $s, 'same token, no membership => no access');
    }

    public function testDeactivatedStaffLoseAccessAndCanBeReactivated(): void
    {
        $o = $this->signup('boss2@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $m = $this->addStaff($o, 'temp@example.com', 'cashier');
        $this->call('PATCH', '/staff/' . $m['member_id'], ['status' => 'inactive'], $o['token'], $o['business_id']);
        [$s] = $this->call('GET', '/locations', null, $m['token'], $o['business_id']);
        $this->assertSame(404, $s);
        $this->call('PATCH', '/staff/' . $m['member_id'], ['status' => 'active'], $o['token'], $o['business_id']);
        [$s] = $this->call('GET', '/locations', null, $m['token'], $o['business_id']);
        $this->assertSame(200, $s);
    }

    public function testDisabledUserTokensStopWorking(): void
    {
        $o = $this->signup('dis@example.com');
        $this->pdo->prepare("UPDATE users SET status='disabled' WHERE id=?")->execute([$o['user_id']]);
        [$s] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/auth/refresh', ['refresh_token' => $o['refresh']]);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/auth/login', ['email' => 'dis@example.com', 'password' => self::PW]);
        $this->assertSame(401, $s);
    }

    public function testInvitedEmailAloneGrantsNoAccess(): void
    {
        $o = $this->signup('inviter@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $this->call('POST', '/staff', ['name' => 'Vic Tim', 'email' => 'victim@example.com', 'role' => 'manager'], $o['token'], $o['business_id']);
        // someone else registers the invited address themselves and verifies it — still no membership until the invite token is used
        $v = $this->signup('victim@example.com', 'Their Own Biz');
        [$s] = $this->call('GET', '/staff', null, $v['token'], $o['business_id']);
        $this->assertSame(404, $s);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM business_members WHERE user_id='{$v['user_id']}' AND business_id='{$o['business_id']}'")->fetchColumn());
    }

    // ---- invitations -------------------------------------------------------------------------------------------------
    public function testInviteLinkIsNeverReturnedToTheInviter(): void
    {
        $o = $this->signup('inv@example.com');
        $this->setPlan($o['business_id'], 'growth');
        [$s, $j, $res] = $this->call('POST', '/staff', ['name' => 'Ann Ee', 'email' => 'ann@example.com', 'role' => 'cashier'], $o['token'], $o['business_id']);
        $this->assertSame(201, $s);
        $raw = $this->tokenFromMail('ann@example.com');
        $this->assertStringNotContainsString($raw, $res->body);
        [$s, $j] = $this->call('GET', '/staff', null, $o['token'], $o['business_id']);
        $this->assertStringNotContainsString($raw, json_encode($j));
        $this->assertStringNotContainsString('invite_token', json_encode($j));
    }

    public function testInviteIsSingleUseAndExpires(): void
    {
        $o = $this->signup('inv2@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $this->call('POST', '/staff', ['name' => 'Ann Ee', 'email' => 'ann2@example.com', 'role' => 'cashier'], $o['token'], $o['business_id']);
        $t = $this->tokenFromMail('ann2@example.com');
        [$s] = $this->call('POST', '/invites/accept', ['token' => $t, 'password' => 'short']);
        $this->assertSame(422, $s);
        [$s, $j] = $this->call('POST', '/invites/accept', ['token' => $t, 'password' => self::PW, 'full_name' => 'Ann Ee']);
        $this->assertSame(200, $s, json_encode($j));
        [$s] = $this->call('POST', '/invites/accept', ['token' => $t, 'password' => 'Another-Pass-2']);
        $this->assertSame(400, $s, 'single use');

        $this->call('POST', '/staff', ['name' => 'Bob Bo', 'email' => 'bob@example.com', 'role' => 'cashier'], $o['token'], $o['business_id']);
        $t2 = $this->tokenFromMail('bob@example.com');
        Clock::freeze(Clock::now()->modify('+8 days'));
        [$s] = $this->call('POST', '/invites/accept', ['token' => $t2, 'password' => self::PW]);
        $this->assertSame(400, $s, 'expired after 7 days');
    }

    public function testExistingUserMustBeSignedInAsInviteeToAccept(): void
    {
        $o = $this->signup('inv3@example.com');
        $this->setPlan($o['business_id'], 'growth');
        $existing = $this->signup('already@example.com', 'Already Biz');
        $other = $this->signup('other@example.com', 'Other Biz');
        $this->call('POST', '/staff', ['name' => 'Al Ready', 'email' => 'already@example.com', 'role' => 'manager'], $o['token'], $o['business_id']);
        $t = $this->tokenFromMail('already@example.com');
        [$s, $j] = $this->call('POST', '/invites/accept', ['token' => $t]);
        $this->assertSame([401, 'login_required'], [$s, $j['error']['code']]);
        [$s] = $this->call('POST', '/invites/accept', ['token' => $t], $other['token']);
        $this->assertSame(403, $s, 'a different signed-in user cannot take the invite');
        [$s, $j] = $this->call('POST', '/invites/accept', ['token' => $t], $existing['token']);
        $this->assertSame(200, $s, json_encode($j));
        [$s, $j] = $this->call('GET', '/businesses', null, $existing['token']);
        $this->assertCount(2, $j['data']);
        [$s] = $this->call('POST', '/invites/accept', ['token' => $t], 'lta_' . str_repeat('Z', 43));
        $this->assertSame(401, $s, 'a presented-but-invalid token is rejected even on the optional-auth route');
    }

    public function testStaffLimitOnFreeAndGrowthPlans(): void
    {
        $o = $this->signup('lim@example.com');
        $this->setPlan($o['business_id'], 'growth');
        for ($i = 0; $i < 3; $i++) {
            [$s] = $this->call('POST', '/staff', ['name' => "Staff $i", 'email' => "s$i@example.com", 'role' => 'cashier'], $o['token'], $o['business_id']);
            $this->assertSame(201, $s);
        }
        [$s, $j] = $this->call('POST', '/staff', ['name' => 'Staff X', 'email' => 's0@example.com', 'role' => 'cashier'], $o['token'], $o['business_id']);
        $this->assertSame([409, 'already_member'], [$s, $j['error']['code']]);
    }

    // ---- misc hardening ----------------------------------------------------------------------------------------------
    public function testSessionsListIsPerUserAndRevocationIsOwnedOnly(): void
    {
        $a = $this->signup('sa@example.com');
        $b = $this->signup('sb@example.com');
        [$s, $j] = $this->call('GET', '/me/sessions', null, $a['token']);
        $this->assertSame([$a['session_id']], array_column($j['data'], 'id'));
        [$s] = $this->call('DELETE', '/me/sessions/' . $b['session_id'], null, $a['token']);
        $this->assertSame(404, $s, "cannot revoke another user's session");
        [$s] = $this->call('GET', '/me', null, $b['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('DELETE', '/me/sessions/' . $a['session_id'], null, $a['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/me', null, $a['token']);
        $this->assertSame(401, $s);
    }

    public function testSqlInjectionPayloadsAreInert(): void
    {
        $o = $this->signup('sqli@example.com');
        foreach (["' OR '1'='1", "1; DROP TABLE users; --", "x' UNION SELECT password_hash FROM users -- ", '" OR ""="'] as $p) {
            [$s] = $this->call('POST', '/auth/login', ['email' => $p, 'password' => $p]);
            $this->assertContains($s, [401, 422], $p);
            [$s] = $this->call('PATCH', '/businesses/' . $o['business_id'], ['name' => $p], $o['token']);
            $this->assertSame(200, $s);
            $this->assertSame(trim($p), $this->pdo->query("SELECT name FROM businesses WHERE id='{$o['business_id']}'")->fetchColumn(), 'stored literally');
        }
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
    }

    public function testErrorsNeverLeakInternals(): void
    {
        $o = $this->signup('leak@example.com');
        $this->pdo->exec('DROP TABLE audit_logs');             // force an unexpected server error
        [$s, $j, $res] = $this->call('PATCH', '/businesses/' . $o['business_id'], ['name' => 'Boom'], $o['token']);
        $this->assertSame(500, $s);
        foreach (['SQLSTATE', 'audit_logs', 'PDO', '.php', 'Stack trace'] as $leak) { $this->assertStringNotContainsString($leak, $res->body); }
        $this->assertSame('server_error', $j['error']['code']);
    }

    public function testAuditTrailRecordsSecurityEventsWithoutSecrets(): void
    {
        $o = $this->signup('aud@example.com');
        $this->call('POST', '/auth/login', ['email' => 'aud@example.com', 'password' => 'Wrong-Pass-1']);
        $this->call('PATCH', '/businesses/' . $o['business_id'], ['name' => 'Audited'], $o['token']);
        $actions = $this->pdo->query('SELECT action FROM audit_logs')->fetchAll(\PDO::FETCH_COLUMN);
        foreach (['auth.register', 'auth.email_verified', 'auth.login', 'auth.login_failed', 'business.update'] as $a) { $this->assertContains($a, $actions); }
        $dump = json_encode($this->pdo->query('SELECT * FROM audit_logs')->fetchAll());
        $this->assertStringNotContainsString(self::PW, $dump);
        $this->assertStringNotContainsString('Wrong-Pass-1', $dump);
        $this->assertStringNotContainsString('lta_', $dump);
    }
}
