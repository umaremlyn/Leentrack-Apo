<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Core\Uuid;

final class AdminTest extends ApiTestCase
{
    private function admin(): array
    {
        $a = $this->signup('root@example.com', 'Platform HQ');
        $this->pdo->prepare('INSERT INTO platform_admins (user_id) VALUES (?)')->execute([$a['user_id']]);
        return $a;
    }

    private function shop(string $email = 'owner@example.com', string $name = 'Acme Stores'): array
    {
        [$s] = $this->call('POST', '/auth/register', ['email' => $email, 'password' => self::PW, 'full_name' => 'Test Owner', 'business_name' => $name, 'business_type' => 'retail', 'currency' => 'NGN']);
        $this->assertSame(202, $s);
        $this->call('POST', '/auth/verify-email', ['token' => $this->tokenFromMail($email)]);
        return $this->login($email);
    }

    private function post(array $a, string $path, array $body): array
    {
        return $this->call('POST', $path, $body + ['password' => self::PW], $a['token']);
    }

    private function biz(string $id): array
    {
        return $this->pdo->query("SELECT plan, plan_status, plan_expiry FROM businesses WHERE id='$id'")->fetch();
    }

    // ================================================================================================ access
    public function testOnlyPlatformAdminsReachAdminRoutesAndEveryoneElseSeesNothing(): void
    {
        $a = $this->admin(); $o = $this->shop();
        $routes = [['GET', '/admin/stats'], ['GET', '/admin/businesses'], ['GET', "/admin/businesses/{$o['business_id']}"], ['POST', "/admin/businesses/{$o['business_id']}/plan"], ['POST', "/admin/businesses/{$o['business_id']}/status"],
            ['POST', "/admin/businesses/{$o['business_id']}/delete"], ['GET', '/admin/users'], ['POST', "/admin/users/{$o['user_id']}/disable"], ['POST', "/admin/users/{$o['user_id']}/enable"], ['POST', "/admin/users/{$o['user_id']}/delete"],
            ['GET', '/admin/payments'], ['GET', '/admin/audit'], ['GET', '/admin/notices'], ['POST', '/admin/notices'], ['POST', '/admin/notices/' . Uuid::v4() . '/end']];
        foreach ($routes as [$m, $p]) {
            $this->assertSame(404, $this->call($m, $p, $m === 'GET' ? null : ['password' => self::PW], $o['token'])[0], "owner $m $p");
            $this->assertSame(404, $this->call($m, $p, $m === 'GET' ? null : ['password' => self::PW], $o['token'], $o['business_id'])[0], "owner with a business header $m $p");
            $this->assertSame(401, $this->call($m, $p, $m === 'GET' ? null : [])[0], "anonymous $m $p");
        }
        // a role named "admin" in a request or a membership changes nothing
        $this->assertSame(404, $this->call('GET', '/admin/stats', null, $o['token'], $o['business_id'], ['X-Role' => 'admin', 'X-Admin' => '1'])[0]);
        $this->assertSame(200, $this->call('GET', '/admin/stats', null, $a['token'])[0]);
        // an admin is not automatically a member of other businesses
        $this->assertSame(404, $this->call('GET', '/expenses', null, $a['token'], $o['business_id'])[0], 'admin rights do not open tenant data');
    }

    public function testStatsAndLists(): void
    {
        $a = $this->admin(); $o = $this->shop('owner@example.com', 'Acme Stores'); $o2 = $this->shop('two@example.com', 'Beta Traders');
        $this->setPlan($o2['business_id'], 'growth', '2999-01-01 00:00:00');
        $s = $this->call('GET', '/admin/stats', null, $a['token'])[1]['data'];
        $this->assertSame([3, 3, 1, 1], [$s['users'], $s['businesses'], $s['paid_businesses'], $s['platform_admins']], 'admin + two owners');
        [, $j] = $this->call('GET', '/admin/businesses', null, $a['token']);
        $this->assertSame(3, $j['meta']['total']);
        $this->assertSame(['growth'], array_column(array_filter($j['data'], fn ($b) => $b['name'] === 'Beta Traders'), 'plan'));
        $this->assertSame(['Acme Stores'], array_column($this->call('GET', '/admin/businesses?search=acme', null, $a['token'])[1]['data'], 'name'));
        $this->assertSame(['Beta Traders'], array_column($this->call('GET', '/admin/businesses?search=' . rawurlencode('two@example'), null, $a['token'])[1]['data'], 'name'), 'search by owner e-mail');
        $this->assertSame([], $this->call('GET', '/admin/businesses?search=' . rawurlencode('%'), null, $a['token'])[1]['data'], 'wildcards are literal');
        $this->assertSame(1, $this->call('GET', '/admin/businesses?plan=growth', null, $a['token'])[1]['meta']['total']);
        $this->assertSame(2, $this->call('GET', '/admin/users?search=example&per_page=2', null, $a['token'])[1]['meta']['per_page']);
        $users = $this->call('GET', '/admin/users', null, $a['token'])[1]['data'];
        $this->assertSame([true], array_values(array_unique(array_column(array_filter($users, fn ($u) => $u['email'] === 'root@example.com'), 'is_platform_admin'))));
        $this->assertStringNotContainsString('hash', json_encode($users), 'no secrets in user lists');
        $this->assertStringNotContainsString('password', str_replace('must_change_password', '', json_encode($users)));
        $d = $this->call('GET', "/admin/businesses/{$o['business_id']}", null, $a['token'])[1]['data'];
        $this->assertSame(['Acme Stores', 'owner@example.com', 0], [$d['name'], $d['owner_email'], $d['counts']['products']]);
        foreach (['/admin/businesses?plan=gold', '/admin/businesses?status=x', '/admin/businesses?bogus=1', '/admin/users?status=x', '/admin/payments?status=x', '/admin/payments?business_id=zz', '/admin/audit?action=%25', '/admin/audit?from=today', '/admin/audit?actor_id=zz', '/admin/businesses?per_page=0'] as $bad) {
            $this->assertSame(422, $this->call('GET', $bad, null, $a['token'])[0], $bad);
        }
        $this->assertSame(404, $this->call('GET', '/admin/businesses/' . Uuid::v4(), null, $a['token'])[0]);
        $this->assertSame(404, $this->call('GET', '/admin/businesses/not-an-id', null, $a['token'])[0]);
    }

    // ================================================================================================ plan / status
    public function testManualPlanChangeNeedsAReasonAPasswordAndAnExpiryAndIsAudited(): void
    {
        $a = $this->admin(); $o = $this->shop();
        $path = "/admin/businesses/{$o['business_id']}/plan";
        $ok = ['plan' => 'business', 'plan_expiry' => gmdate('Y-m-d', time() + 40 * 86400), 'reason' => 'Goodwill after the outage'];
        foreach ([['password' => 'wrong-password-1'] + $ok, array_diff_key($ok, ['reason' => 1]), ['reason' => 'abc'] + $ok, array_diff_key($ok, ['plan_expiry' => 1]), ['plan_expiry' => '2001-01-01'] + $ok, ['plan_expiry' => gmdate('Y-m-d', time() + 9 * 365 * 86400)] + $ok, ['plan' => 'gold'] + $ok, ['plan' => 'free', 'plan_expiry' => '2999-01-01'] + $ok, ['business_id' => Uuid::v4()] + $ok, ['plan_status' => 'active'] + $ok] as $bad) {
            $r = $this->call('POST', $path, $bad + ($bad['password'] ?? false ? [] : ['password' => self::PW]), $a['token']);
            $this->assertContains($r[0], [403, 422], json_encode($bad));
        }
        $this->assertSame('free', $this->biz($o['business_id'])['plan'], 'every refused attempt changed nothing');
        [$s, $j] = $this->post($a, $path, $ok);
        $this->assertSame([200, 'business', 'business'], [$s, $j['data']['plan'], $j['data']['effective_plan']], json_encode($j));
        $this->assertStringStartsWith(gmdate('Y-m-d', time() + 40 * 86400), $this->biz($o['business_id'])['plan_expiry']);
        $log = $this->pdo->query("SELECT before_json, after_json, actor_user_id FROM audit_logs WHERE action='admin.plan_set'")->fetch();
        $this->assertSame($a['user_id'], $log['actor_user_id']);
        $this->assertSame('free', json_decode($log['before_json'], true)['plan']);
        $this->assertSame(['business', 'Goodwill after the outage'], [json_decode($log['after_json'], true)['plan'], json_decode($log['after_json'], true)['reason']]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='admin.stepup_failed'")->fetchColumn(), 'a wrong step-up password is itself audited');
        [, $j] = $this->post($a, $path, ['plan' => 'free', 'reason' => 'Requested by the owner']);
        $this->assertSame(['free', null], [$j['data']['plan'], $j['data']['plan_expiry']]);
        $this->assertSame(404, $this->post($a, '/admin/businesses/' . Uuid::v4() . '/plan', $ok)[0]);
    }

    public function testSuspendingTreatsTheBusinessAsFreeAndRestoringBringsThePlanBack(): void
    {
        $a = $this->admin(); $o = $this->shop();
        $this->setPlan($o['business_id'], 'business', '2999-01-01 00:00:00');
        $this->assertSame(201, $this->call('POST', '/locations', ['name' => 'Branch'], $o['token'], $o['business_id'])[0]);
        $path = "/admin/businesses/{$o['business_id']}/status";
        $this->assertSame(422, $this->post($a, $path, ['status' => 'expired', 'reason' => 'nope nope'])[0], 'expired is set by the system only');
        $this->assertSame(422, $this->post($a, $path, ['status' => 'suspended', 'reason' => 'x'])[0]);
        $this->assertSame(403, $this->call('POST', $path, ['status' => 'suspended', 'reason' => 'Chargeback dispute', 'password' => 'wrong-password-1'], $a['token'])[0]);
        [$s, $j] = $this->post($a, $path, ['status' => 'suspended', 'reason' => 'Chargeback dispute']);
        $this->assertSame([200, 'suspended', 'free', 'business'], [$s, $j['data']['plan_status'], $j['data']['effective_plan'], $j['data']['plan']], 'plan kept, access limited');
        $this->assertSame(402, $this->call('POST', '/locations', ['name' => 'Second branch'], $o['token'], $o['business_id'])[0], 'limits of the free plan apply at once');
        [, $j] = $this->post($a, $path, ['status' => 'active', 'reason' => 'Dispute resolved']);
        $this->assertSame('business', $j['data']['effective_plan']);
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='admin.status_set'")->fetchColumn());
    }

    // ================================================================================================ delete business
    public function testDeletingABusinessNeedsTheTypedNameAndLeavesOtherBusinessesAndPaymentRecords(): void
    {
        $a = $this->admin(); $o = $this->shop('owner@example.com', 'Acme Stores'); $o2 = $this->shop('two@example.com', 'Beta Traders');
        $this->call('POST', '/products', ['name' => 'Rice', 'selling_price' => '10', 'cost_price' => '5', 'stock_quantity' => 3], $o['token'], $o['business_id']);
        $this->call('POST', '/products', ['name' => 'Beans', 'selling_price' => '10', 'cost_price' => '5', 'stock_quantity' => 3], $o2['token'], $o2['business_id']);
        $this->pdo->prepare("INSERT INTO payments (id,business_id,user_id,reference,plan,cycle,currency,amount_minor,status,paid_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())")->execute([Uuid::v4(), $o['business_id'], $o['user_id'], 'LT-TESTREF', 'growth', 'monthly', 'NGN', 350000, 'success']);
        $path = "/admin/businesses/{$o['business_id']}/delete";
        $this->assertSame(422, $this->post($a, $path, ['confirm_name' => 'acme stores', 'reason' => 'Fraudulent account'])[0], 'the name must match exactly');
        $this->assertSame(403, $this->call('POST', $path, ['confirm_name' => 'Acme Stores', 'reason' => 'Fraudulent account', 'password' => 'wrong-password-1'], $a['token'])[0]);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE business_id='{$o['business_id']}'")->fetchColumn());
        $this->assertSame(200, $this->post($a, $path, ['confirm_name' => 'Acme Stores', 'reason' => 'Fraudulent account'])[0]);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM businesses WHERE id='{$o['business_id']}'")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE business_id='{$o['business_id']}'")->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM products WHERE business_id='{$o2['business_id']}'")->fetchColumn(), "the other business is untouched");
        $p = $this->pdo->query('SELECT business_id, user_id, reference FROM payments')->fetch();
        $this->assertSame([null, null, 'LT-TESTREF'], [$p['business_id'], $p['user_id'], $p['reference']], 'the payment record stays, detached from people');
        $this->assertSame(404, $this->call('GET', '/expenses', null, $o['token'], $o['business_id'])[0], 'the former owner has nothing left to open');
        $log = $this->pdo->query("SELECT business_id, before_json, after_json FROM audit_logs WHERE action='admin.business_deleted'")->fetch();
        $this->assertNull($log['business_id']);
        $this->assertSame(['Acme Stores', 'owner@example.com'], [json_decode($log['before_json'], true)['name'], json_decode($log['before_json'], true)['owner']]);
        $this->assertSame(404, $this->post($a, $path, ['confirm_name' => 'Acme Stores', 'reason' => 'Fraudulent account'])[0], 'already gone');
    }

    // ================================================================================================ users
    public function testDisablingAUserSignsThemOutAndEnablingRestoresAccess(): void
    {
        $a = $this->admin(); $o = $this->shop();
        $other = $this->shop('other@example.com', 'Other Co');
        $this->assertSame(409, $this->post($a, "/admin/users/{$a['user_id']}/disable", ['reason' => 'testing self'])[0], 'not yourself');
        $b = $this->signup('admin2@example.com');
        $this->pdo->prepare('INSERT INTO platform_admins (user_id) VALUES (?)')->execute([$b['user_id']]);
        $this->assertSame(409, $this->post($a, "/admin/users/{$b['user_id']}/disable", ['reason' => 'another admin'])[0], 'admins are managed from the CLI');
        $this->assertSame(200, $this->call('GET', '/me', null, $o['token'])[0]);
        [$s, $j] = $this->post($a, "/admin/users/{$o['user_id']}/disable", ['reason' => 'Abuse reports']);
        $this->assertSame([200, 'disabled'], [$s, $j['data']['status']], json_encode($j));
        $this->assertSame(401, $this->call('GET', '/me', null, $o['token'])[0], 'existing sessions end immediately');
        $this->assertSame(401, $this->call('POST', '/auth/login', ['email' => 'owner@example.com', 'password' => self::PW])[0], 'and they cannot sign in');
        $this->assertSame(409, $this->post($a, "/admin/users/{$o['user_id']}/disable", ['reason' => 'twice over'])[0]);
        $this->assertSame(200, $this->post($a, "/admin/users/{$o['user_id']}/enable", ['reason' => 'Cleared after review'])[0]);
        $this->assertSame(200, $this->call('POST', '/auth/login', ['email' => 'owner@example.com', 'password' => self::PW])[0]);
        $this->assertSame(401, $this->call('GET', '/me', null, $o['token'])[0], 're-enabling must not bring the old sessions back');
        $this->assertSame(409, $this->post($a, "/admin/users/{$o['user_id']}/enable", ['reason' => 'not disabled'])[0]);
        $this->assertSame(['admin.user_disabled', 'admin.user_enabled'], $this->pdo->query("SELECT action FROM audit_logs WHERE action LIKE 'admin.user_%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(404, $this->post($a, '/admin/users/' . Uuid::v4() . '/disable', ['reason' => 'nobody home'])[0]);
        unset($other);
    }

    public function testAdminScheduledDeletionUsesTheSameGraceAndRules(): void
    {
        $a = $this->admin(); $o = $this->shop();
        $team = $this->shop('team@example.com', 'Team Co');
        $this->setPlan($team['business_id'], 'growth', '2999-01-01 00:00:00');
        $this->addStaff($team, 'cash@example.com', 'cashier');
        [$s, $j] = $this->post($a, "/admin/users/{$team['user_id']}/delete", ['reason' => 'Legal request']);
        $this->assertSame([409, 'owns_business_with_staff'], [$s, $j['error']['code']], 'an owner with a team is protected here too');
        [$s, $j] = $this->post($a, "/admin/users/{$o['user_id']}/delete", ['reason' => 'Legal request']);
        $this->assertSame([202, 'pending_deletion', 14], [$s, $j['data']['status'], $j['data']['grace_days']]);
        $this->assertSame(401, $this->call('GET', '/me', null, $o['token'])[0]);
        $this->assertSame(1, count(array_filter(\LeenTrack\Mail\FileMailer::$outbox, fn ($m) => str_contains($m['body'] ?? $m['text'] ?? '', 'administrator'))), 'the person is told');
        $this->assertSame(409, $this->post($a, "/admin/users/{$o['user_id']}/delete", ['reason' => 'again again'])[0]);
        $this->assertSame(409, $this->post($a, "/admin/users/{$a['user_id']}/delete", ['reason' => 'self delete'])[0]);
        $this->assertSame(200, $this->call('POST', '/auth/cancel-deletion', ['email' => 'owner@example.com', 'password' => self::PW])[0], 'the person can still cancel');
        $this->assertSame('admin.account_deletion_requested', $this->pdo->query("SELECT action FROM audit_logs WHERE action LIKE 'admin.account%'")->fetchColumn());
    }

    // ================================================================================================ payments, audit, notices
    public function testPaymentsAndAuditLogViewers(): void
    {
        $a = $this->admin(); $o = $this->shop();
        foreach ([['LT-A', 'success'], ['LT-B', 'failed'], ['LT-C', 'refunded']] as [$ref, $st]) {
            $this->pdo->prepare("INSERT INTO payments (id,business_id,user_id,reference,plan,cycle,currency,amount_minor,status) VALUES (?,?,?,?,?,?,?,?,?)")->execute([Uuid::v4(), $o['business_id'], $o['user_id'], $ref, 'growth', 'monthly', 'NGN', 350000, $st]);
        }
        [, $j] = $this->call('GET', '/admin/payments?status=refunded', null, $a['token']);
        $this->assertSame([1, 'LT-C', '3500.00', 'Acme Stores'], [$j['meta']['total'], $j['data'][0]['reference'], $j['data'][0]['amount'], $j['data'][0]['business_name']]);
        $this->assertSame(3, $this->call('GET', "/admin/payments?business_id={$o['business_id']}", null, $a['token'])[1]['meta']['total']);
        $this->assertSame(['NGN' => '3500.00'], $this->call('GET', '/admin/stats', null, $a['token'])[1]['data']['revenue_by_currency'], 'revenue counts successful payments only (the failed and refunded ones are not revenue)');
        $this->post($a, "/admin/businesses/{$o['business_id']}/status", ['status' => 'suspended', 'reason' => 'Audit trail check']);
        [, $l] = $this->call('GET', '/admin/audit?action=admin.', null, $a['token']);
        $this->assertSame(['admin.status_set'], array_values(array_unique(array_column($l['data'], 'action'))));
        $e = $l['data'][0];
        $this->assertSame(['root@example.com', $o['business_id'], 'active', 'suspended'], [$e['actor_email'], $e['business_id'], $e['before']['plan_status'], $e['after']['plan_status']]);
        $this->assertSame(1, $this->call('GET', "/admin/audit?business_id={$o['business_id']}", null, $a['token'])[1]['meta']['total'], 'only the entries about that business');
        $this->assertSame(0, $this->call('GET', '/admin/audit?from=2999-01-01', null, $a['token'])[1]['meta']['total']);
        $this->assertSame(0, $this->call('GET', '/admin/audit?to=2000-01-01', null, $a['token'])[1]['meta']['total']);
        $this->assertStringNotContainsString('password', strtolower(json_encode($this->call('GET', '/admin/audit?per_page=200', null, $a['token'])[1]['data'])), 'the audit log never contains passwords');
    }

    public function testNoticesAreShownToEveryoneWhileActive(): void
    {
        $a = $this->admin(); $o = $this->shop();
        $this->assertSame([], $this->call('GET', '/notices', null, $o['token'])[1]['data']);
        $this->assertSame(401, $this->call('GET', '/notices')[0]);
        foreach ([['title' => 'ab', 'body' => 'Planned maintenance tonight'], ['title' => 'Maintenance', 'body' => 'x'], ['title' => 'Maintenance', 'body' => 'Planned maintenance tonight', 'level' => 'red'], ['title' => 'Maintenance', 'body' => 'Planned maintenance tonight', 'ends_at' => '2001-01-01'], ['title' => 'Maintenance', 'body' => 'Planned maintenance tonight', 'created_by' => 'x']] as $bad) {
            $this->assertSame(422, $this->call('POST', '/admin/notices', $bad, $a['token'])[0], json_encode($bad));
        }
        $this->assertSame(404, $this->call('POST', '/admin/notices', ['title' => 'Maintenance', 'body' => 'Planned maintenance tonight'], $o['token'])[0], 'only admins publish');
        [$s, $n] = $this->call('POST', '/admin/notices', ['title' => 'Maintenance', 'body' => 'Planned maintenance tonight 22:00-23:00 UTC.', 'level' => 'warning', 'ends_at' => gmdate('Y-m-d', time() + 86400)], $a['token']);
        $this->assertSame([201, true, 'warning'], [$s, $n['data']['active'], $n['data']['level']], json_encode($n));
        $this->call('POST', '/admin/notices', ['title' => 'New pricing', 'body' => 'Plans change next month.', 'level' => 'info'], $a['token']);
        $this->call('POST', '/admin/notices', ['title' => 'Outage', 'body' => 'We are fixing a problem.', 'level' => 'critical'], $a['token']);
        $seen = $this->call('GET', '/notices', null, $o['token'])[1]['data'];
        $this->assertSame(['critical', 'warning', 'info'], array_column($seen, 'level'), 'most urgent first');
        $this->assertArrayNotHasKey('created_by', $seen[0], 'users see only what they need');
        [, $e] = $this->call('POST', "/admin/notices/{$n['data']['id']}/end", [], $a['token']);
        $this->assertFalse($e['data']['active']);
        $this->assertSame(['critical', 'info'], array_column($this->call('GET', '/notices', null, $o['token'])[1]['data'], 'level'));
        Clock::freeze(new \DateTimeImmutable('+3 days', new \DateTimeZone('UTC')));
        $c = $this->login('owner@example.com');
        $this->assertCount(2, $this->call('GET', '/notices', null, $c['token'])[1]['data'], 'open-ended notices stay until ended');
        $this->assertCount(3, $this->call('GET', '/admin/notices', null, $this->login('root@example.com')['token'])[1]['data'], 'admins still see the history');
        $this->assertSame(404, $this->call('POST', '/admin/notices/' . Uuid::v4() . '/end', [], $this->login('root@example.com')['token'])[0]);
    }

    // ================================================================================================ the CLI
    public function testMakeAdminCliGrantsListsAndRefusesToRevokeTheLastAdmin(): void
    {
        $a = $this->signup('root@example.com'); $b = $this->signup('second@example.com');
        $env = ['DB_NAME' => getenv('TEST_DB_NAME'), 'DB_USER' => getenv('TEST_DB_USER'), 'DB_PASS' => getenv('TEST_DB_PASS'), 'DB_SOCKET' => getenv('TEST_DB_SOCKET'), 'DB_HOST' => '', 'APP_ENV' => 'testing', 'PATH' => getenv('PATH')];
        $run = function (array $args) use ($env): array {
            $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../bin/make-admin.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/../..', $env);
            $o = stream_get_contents($pipes[1]); $e = stream_get_contents($pipes[2]);
            return [proc_close($p), $o . $e];
        };
        $this->assertSame(1, $run(['--revoke', 'root@example.com'])[0] , 'revoking a non-admin is refused');
        $this->assertSame([0], [$run(['root@example.com'])[0]]);
        [$c, $out] = $run(['--revoke', 'root@example.com']);
        $this->assertSame(1, $c);
        $this->assertStringContainsString('only platform admin', $out);
        $this->assertSame(0, $run(['second@example.com'])[0]);
        $this->assertStringContainsString('root@example.com', $run(['--list'])[1]);
        $this->assertSame(0, $run(['--revoke', 'root@example.com'])[0]);
        $this->assertSame(404, $this->call('GET', '/admin/stats', null, $a['token'])[0], 'revoked at once');
        $this->assertSame(200, $this->call('GET', '/admin/stats', null, $b['token'])[0]);
        $this->assertSame(['admin.granted_cli', 'admin.granted_cli', 'admin.revoked_cli'], $this->pdo->query("SELECT action FROM audit_logs WHERE action LIKE 'admin.%cli' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertSame(1, $run(['nobody@example.com'])[0]);
    }
}
