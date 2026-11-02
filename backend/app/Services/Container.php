<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\App;
use LeenTrack\Core\RateLimiter;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Mail\Mailer;
use LeenTrack\Mail\NativeMailer;
use LeenTrack\Repositories\SessionRepository;
use LeenTrack\Repositories\TenantRepository;
use LeenTrack\Repositories\UserRepository;
use LeenTrack\Security\PasswordHasher;

/** Explicit lazy wiring (no reflection/autowiring magic — easy to read and to override in tests). */
final class Container
{
    /** @var array<string,object> */
    private array $m = [];
    private ?Mailer $mailer = null;

    public function __construct(private readonly App $app)
    {
    }

    public function setMailer(Mailer $m): void { $this->mailer = $m; }

    private function once(string $k, callable $f): object { return $this->m[$k] ??= $f(); }

    public function app(): App { return $this->app; }
    public function users(): UserRepository { return $this->once('users', fn () => new UserRepository($this->app->pdo())); }
    public function sessionsRepo(): SessionRepository { return $this->once('sessRepo', fn () => new SessionRepository($this->app->pdo())); }
    public function tenants(): TenantRepository { return $this->once('tenants', fn () => new TenantRepository($this->app->pdo())); }
    public function hasher(): PasswordHasher { return $this->once('hasher', fn () => new PasswordHasher()); }
    public function limiter(): RateLimiter { return $this->once('limiter', fn () => new RateLimiter($this->app->pdo())); }
    public function audit(): AuditService { return $this->once('audit', fn () => new AuditService($this->app->pdo())); }

    public function mailer(): Mailer
    {
        return $this->mailer ??= ($this->app->config['mail_driver'] ?? ($this->app->isProduction() ? 'native' : 'file')) === 'native'
            ? new NativeMailer($this->app->config['mail_from'], $this->app->logger())
            : new FileMailer($this->app->config['log_dir'] . '/mail.log');
    }

    public function sessions(): SessionService
    {
        $c = $this->app->config['auth'];
        return $this->once('sessions', fn () => new SessionService($this->app->pdo(), $this->sessionsRepo(), $this->users(), $this->audit(), (int) $c['access_ttl'], (int) $c['refresh_ttl']));
    }

    public function businesses(): BusinessService { return $this->once('biz', fn () => new BusinessService($this->app->pdo(), $this->tenants(), $this->audit())); }

    public function auth(): AuthService
    {
        return $this->once('auth', fn () => new AuthService(
            $this->app->pdo(), $this->users(), $this->tenants(), $this->businesses(), $this->sessions(), $this->hasher(),
            $this->mailer(), $this->audit(), $this->limiter(), $this->app->config['auth'], $this->app->config['web_url'],
            (bool) $this->app->config['rate_limit_enabled'],
        ));
    }

    public function staff(): StaffService
    {
        return $this->once('staff', fn () => new StaffService($this->app->pdo(), $this->tenants(), $this->users(), $this->sessions(), $this->hasher(), $this->mailer(), $this->audit(), (int) $this->app->config['auth']['invite_ttl'], $this->app->config['web_url']));
    }

    public function locations(): LocationService { return $this->once('loc', fn () => new LocationService($this->tenants(), $this->audit())); }
    public function me(): MeService { return $this->once('me', fn () => new MeService($this->users(), $this->tenants(), $this->sessionsRepo(), $this->auth(), $this->audit())); }
}
