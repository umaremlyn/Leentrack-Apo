<?php
declare(strict_types=1);

namespace LeenTrack\Tests\Integration;

use LeenTrack\Core\Clock;
use LeenTrack\Mail\FileMailer;

final class AuthTest extends ApiTestCase
{
    // ---- T-AUTH-1 -------------------------------------------------------------------------------------------------
    public function testRegisterCreatesUserBusinessOwnerMembershipAndDefaultOutlet(): void
    {
        $o = $this->signup('owner@example.com', 'Acme Stores');
        $b = $this->pdo->query("SELECT * FROM businesses")->fetchAll();
        $this->assertCount(1, $b);
        $this->assertSame('free', $b[0]['plan']);
        $this->assertSame($o['user_id'], $b[0]['owner_user_id']);
        $m = $this->pdo->query("SELECT role,status FROM business_members")->fetchAll();
        $this->assertSame([['role' => 'owner', 'status' => 'active']], $m);
        $this->assertSame('Main', $this->pdo->query('SELECT name FROM locations')->fetchColumn());
        $this->assertNotNull($this->pdo->query('SELECT password_hash FROM users')->fetchColumn());
        $this->assertStringNotContainsString(self::PW, (string) $this->pdo->query('SELECT password_hash FROM users')->fetchColumn());
    }

    public function testRegisterRejectsPrivilegedAndUnknownFieldsAndWeakPasswords(): void
    {
        $base = ['email' => 'a@example.com', 'password' => self::PW, 'full_name' => 'A B', 'business_name' => 'Biz'];
        foreach (['role' => 'admin', 'plan' => 'business', 'is_admin' => true, 'status' => 'active', 'email_verified_at' => '2020-01-01', 'user_id' => 'x'] as $k => $v) {
            [$s, $j] = $this->call('POST', '/auth/register', $base + [$k => $v]);
            $this->assertSame(422, $s, $k);
            $this->assertArrayHasKey($k, $j['error']['fields']);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(), 'nothing may be created by rejected requests');
        foreach (['short1', 'password123', 'aaaaaaaa'] as $pw) {
            [$s, $j] = $this->call('POST', '/auth/register', ['password' => $pw] + $base);
            $this->assertSame(422, $s, $pw);
        }
        [$s] = $this->call('POST', '/auth/register', ['email' => 'not-an-email'] + $base);
        $this->assertSame(422, $s);
    }

    public function testRegisterDoesNotRevealExistingEmail(): void
    {
        $this->signup('dup@example.com');
        FileMailer::$outbox = [];
        [$s1, $j1] = $this->call('POST', '/auth/register', ['email' => 'dup@example.com', 'password' => self::PW, 'full_name' => 'X Y', 'business_name' => 'Other']);
        [$s2, $j2] = $this->call('POST', '/auth/register', ['email' => 'brand-new@example.com', 'password' => self::PW, 'full_name' => 'X Y', 'business_name' => 'Other']);
        $this->assertSame($s1, $s2);
        $this->assertSame($j1['data'], $j2['data']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE email='dup@example.com'")->fetchColumn());
        $this->assertStringContainsString('already exists', FileMailer::last('dup@example.com')['text']);
    }

    // ---- T-AUTH-2 -------------------------------------------------------------------------------------------------
    public function testLoginWrongPasswordAndUnknownEmailAreIndistinguishable(): void
    {
        $this->signup('u@example.com');
        [$s1, $j1] = $this->call('POST', '/auth/login', ['email' => 'u@example.com', 'password' => 'Wrong-Pass-1']);
        [$s2, $j2] = $this->call('POST', '/auth/login', ['email' => 'nobody@example.com', 'password' => 'Wrong-Pass-1']);
        $this->assertSame([401, 'invalid_credentials'], [$s1, $j1['error']['code']]);
        $this->assertSame([$s1, $j1['error']['code'], $j1['error']['message']], [$s2, $j2['error']['code'], $j2['error']['message']]);
    }

    public function testUnverifiedEmailCannotLoginButOnlyAfterCorrectPassword(): void
    {
        $this->call('POST', '/auth/register', ['email' => 'unv@example.com', 'password' => self::PW, 'full_name' => 'U V', 'business_name' => 'Biz']);
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => 'unv@example.com', 'password' => self::PW]);
        $this->assertSame([403, 'email_not_verified'], [$s, $j['error']['code']]);
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => 'unv@example.com', 'password' => 'Wrong-Pass-1']);
        $this->assertSame('invalid_credentials', $j['error']['code'], 'wrong password must not reveal verification state');
    }

    public function testPerAccountLoginThrottle(): void
    {
        $this->boot(['rate_limit_enabled' => true]);
        $this->signup('thr@example.com');
        $last = 0;
        for ($i = 0; $i < 9; $i++) {
            [$last] = $this->call('POST', '/auth/login', ['email' => 'thr@example.com', 'password' => 'Wrong-Pass-' . $i]);
        }
        $this->assertSame(429, $last);
        [$s, , $res] = $this->call('POST', '/auth/login', ['email' => 'thr@example.com', 'password' => self::PW]);
        $this->assertSame(429, $s, 'even the right password is refused while throttled');
        $this->assertArrayHasKey('Retry-After', $res->headers);
    }

    public function testLoginRejectsUnknownBodyFields(): void
    {
        [$s] = $this->call('POST', '/auth/login', ['email' => 'a@example.com', 'password' => 'x', 'role' => 'admin']);
        $this->assertSame(422, $s);
    }

    // ---- T-AUTH-3 legacy bcrypt (FIXTURE hash generated locally; proves format compatibility, not real-data compatibility) ----
    public function testLegacyBcryptUserLogsInOnceThenIsUpgraded(): void
    {
        $legacy = password_hash('old-pass', PASSWORD_BCRYPT, ['cost' => 10]);   // $2y$ — GoTrue uses $2a$; also tested below
        $legacyA = '$2a$' . substr($legacy, 4);
        $this->assertTrue(password_verify('old-pass', $legacyA), 'PHP verifies $2a$ hashes');
        $id = '11111111-1111-4111-8111-111111111111';
        $this->pdo->prepare('INSERT INTO users (id,email,legacy_bcrypt_hash,email_verified_at) VALUES (?,?,?,NOW())')->execute([$id, 'legacy@example.com', $legacyA]);
        [$s] = $this->call('POST', '/auth/login', ['email' => 'legacy@example.com', 'password' => 'wrong-pass']);
        $this->assertSame(401, $s);
        $this->assertNotNull($this->pdo->query("SELECT legacy_bcrypt_hash FROM users WHERE id='$id'")->fetchColumn(), 'failed login must not consume the legacy hash');
        [$s, $j] = $this->call('POST', '/auth/login', ['email' => 'legacy@example.com', 'password' => 'old-pass']);
        $this->assertSame(200, $s, json_encode($j));
        $row = $this->pdo->query("SELECT password_hash, legacy_bcrypt_hash FROM users WHERE id='$id'")->fetch();
        $this->assertNull($row['legacy_bcrypt_hash']);
        $this->assertNotNull($row['password_hash']);
        $this->assertTrue(password_verify('old-pass', $row['password_hash']));
        [$s] = $this->call('POST', '/auth/login', ['email' => 'legacy@example.com', 'password' => 'old-pass']);
        $this->assertSame(200, $s, 'second login uses the new hash');
    }

    public function testNonBcryptLegacyHashIsNeverAccepted(): void
    {
        $this->pdo->prepare('INSERT INTO users (id,email,legacy_bcrypt_hash,email_verified_at) VALUES (?,?,?,NOW())')
            ->execute(['22222222-2222-4222-8222-222222222222', 'weird@example.com', md5('x')]);
        [$s] = $this->call('POST', '/auth/login', ['email' => 'weird@example.com', 'password' => 'x']);
        $this->assertSame(401, $s);
    }

    // ---- T-AUTH-4 sessions ----------------------------------------------------------------------------------------
    public function testAccessTokenWorksThenExpires(): void
    {
        $o = $this->signup('exp@example.com');
        [$s] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame(200, $s);
        Clock::freeze(Clock::now()->modify('+901 seconds'));
        [$s, $j] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame([401, 'token_invalid'], [$s, $j['error']['code']]);
    }

    public function testRefreshRotatesAndReuseRevokesTheSession(): void
    {
        $o = $this->signup('rot@example.com');
        [$s, $j] = $this->call('POST', '/auth/refresh', ['refresh_token' => $o['refresh']]);
        $this->assertSame(200, $s);
        $new = $j['data'];
        $this->assertNotSame($o['token'], $new['access_token']);
        [$s] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame(401, $s, 'old access token is dead after rotation');
        [$s] = $this->call('GET', '/me', null, $new['access_token']);
        $this->assertSame(200, $s);

        // attacker (or buggy client) replays the OLD refresh token
        [$s] = $this->call('POST', '/auth/refresh', ['refresh_token' => $o['refresh']]);
        $this->assertSame(401, $s);
        [$s] = $this->call('GET', '/me', null, $new['access_token']);
        $this->assertSame(401, $s, 'reuse detection revokes the whole session, including the newest tokens');
        [$s] = $this->call('POST', '/auth/refresh', ['refresh_token' => $new['refresh_token']]);
        $this->assertSame(401, $s);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='auth.refresh_reuse_detected'")->fetchColumn());
    }

    public function testExpiredRefreshTokenRejected(): void
    {
        $o = $this->signup('rexp@example.com');
        Clock::freeze(Clock::now()->modify('+31 days'));
        [$s] = $this->call('POST', '/auth/refresh', ['refresh_token' => $o['refresh']]);
        $this->assertSame(401, $s);
    }

    public function testLogoutAndLogoutAll(): void
    {
        $a = $this->signup('lo@example.com');
        $b = $this->login('lo@example.com');
        [$s] = $this->call('POST', '/auth/logout', null, $a['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/me', null, $a['token']);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/auth/refresh', ['refresh_token' => $a['refresh']]);
        $this->assertSame(401, $s, 'refresh token of a logged-out session is dead');
        [$s] = $this->call('GET', '/me', null, $b['token']);
        $this->assertSame(200, $s, 'other device unaffected by single logout');
        $c = $this->login('lo@example.com');
        [$s] = $this->call('POST', '/auth/logout?all=1', null, $b['token']);
        [$s1] = $this->call('GET', '/me', null, $b['token']);
        [$s2] = $this->call('GET', '/me', null, $c['token']);
        $this->assertSame([401, 401], [$s1, $s2]);
    }

    // ---- T-AUTH-5 forgot / reset ----------------------------------------------------------------------------------
    public function testForgotPasswordDoesNotEnumerateAndResetIsSingleUse(): void
    {
        $o = $this->signup('fp@example.com');
        FileMailer::$outbox = [];
        [$s1, $j1] = $this->call('POST', '/auth/forgot-password', ['email' => 'fp@example.com']);
        [$s2, $j2] = $this->call('POST', '/auth/forgot-password', ['email' => 'ghost@example.com']);
        $this->assertSame([202, 202], [$s1, $s2]);
        $this->assertSame($j1['data'], $j2['data']);
        $this->assertNull(FileMailer::last('ghost@example.com'));
        $token = $this->tokenFromMail('fp@example.com');

        [$s] = $this->call('POST', '/auth/reset-password', ['token' => $token, 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(200, $s);
        [$s] = $this->call('POST', '/auth/reset-password', ['token' => $token, 'password' => 'Another-Pass-8']);
        $this->assertSame(400, $s, 'token is single use');
        [$s] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame(401, $s, 'reset revokes existing sessions');
        [$s] = $this->call('POST', '/auth/login', ['email' => 'fp@example.com', 'password' => self::PW]);
        $this->assertSame(401, $s);
        [$s] = $this->call('POST', '/auth/login', ['email' => 'fp@example.com', 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(200, $s);
    }

    public function testResetTokenExpiresAndNewRequestInvalidatesOlder(): void
    {
        $this->signup('rx@example.com');
        $this->call('POST', '/auth/forgot-password', ['email' => 'rx@example.com']);
        $old = $this->tokenFromMail('rx@example.com');
        $this->call('POST', '/auth/forgot-password', ['email' => 'rx@example.com']);
        $new = $this->tokenFromMail('rx@example.com');
        [$s] = $this->call('POST', '/auth/reset-password', ['token' => $old, 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(400, $s, 'older token superseded');
        Clock::freeze(Clock::now()->modify('+3601 seconds'));
        [$s] = $this->call('POST', '/auth/reset-password', ['token' => $new, 'password' => 'Brand-New-Pass-7']);
        $this->assertSame(400, $s, 'expired');
    }

    public function testResetRejectsWeakPasswordWithoutConsumingToken(): void
    {
        $this->signup('rw@example.com');
        $this->call('POST', '/auth/forgot-password', ['email' => 'rw@example.com']);
        $t = $this->tokenFromMail('rw@example.com');
        [$s] = $this->call('POST', '/auth/reset-password', ['token' => $t, 'password' => 'password']);
        $this->assertSame(422, $s);
        [$s] = $this->call('POST', '/auth/reset-password', ['token' => $t, 'password' => 'Strong-Enough-1']);
        $this->assertSame(200, $s);
    }

    public function testChangePasswordRequiresCurrentAndRevokesOtherSessions(): void
    {
        $a = $this->signup('cp@example.com');
        $b = $this->login('cp@example.com');
        [$s] = $this->call('POST', '/me/change-password', ['current_password' => 'nope-nope', 'new_password' => 'Brand-New-Pass-7'], $a['token']);
        $this->assertSame(403, $s);
        [$s] = $this->call('POST', '/me/change-password', ['current_password' => self::PW, 'new_password' => 'Brand-New-Pass-7'], $a['token']);
        $this->assertSame(200, $s);
        [$s] = $this->call('GET', '/me', null, $a['token']);
        $this->assertSame(200, $s, 'current session survives');
        [$s] = $this->call('GET', '/me', null, $b['token']);
        $this->assertSame(401, $s, 'other sessions are signed out');
    }

    public function testVerifyEmailTokenSingleUse(): void
    {
        $this->call('POST', '/auth/register', ['email' => 've@example.com', 'password' => self::PW, 'full_name' => 'V E', 'business_name' => 'Biz']);
        $t = $this->tokenFromMail('ve@example.com');
        [$s] = $this->call('POST', '/auth/verify-email', ['token' => $t]);
        $this->assertSame(200, $s);
        [$s] = $this->call('POST', '/auth/verify-email', ['token' => $t]);
        $this->assertSame(400, $s);
    }

    public function testMeShowsMembershipsAndPermissionsButNeverSecrets(): void
    {
        $o = $this->signup('me@example.com');
        [$s, $j, $res] = $this->call('GET', '/me', null, $o['token']);
        $this->assertSame(200, $s);
        $this->assertSame('owner', $j['data']['memberships'][0]['role']);
        $this->assertContains('staff.manage', $j['data']['memberships'][0]['permissions']);
        $this->assertFalse($j['data']['user']['is_platform_admin']);
        $body = str_replace('must_change_password', '', strtolower($res->body));      // a boolean flag, not a secret
        foreach (['password', 'hash', 'legacy'] as $leak) { $this->assertStringNotContainsString($leak, $body); }
    }
}
