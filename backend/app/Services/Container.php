<?php
declare(strict_types=1);

namespace LeenTrack\Services;

use LeenTrack\Core\App;
use LeenTrack\Core\RateLimiter;
use LeenTrack\Mail\FileMailer;
use LeenTrack\Mail\Mailer;
use LeenTrack\Mail\NativeMailer;
use LeenTrack\Mail\SmtpMailer;
use LeenTrack\Billing\PaymentGateway;
use LeenTrack\Billing\PaystackGateway;
use LeenTrack\Repositories\BillingRepository;
use LeenTrack\Repositories\CatalogRepository;
use LeenTrack\Repositories\CustomerRepository;
use LeenTrack\Repositories\ExpenseRepository;
use LeenTrack\Repositories\PurchaseRepository;
use LeenTrack\Repositories\TransferRepository;
use LeenTrack\Repositories\ReportRepository;
use LeenTrack\Repositories\SaleRepository;
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
        if ($this->mailer !== null) { return $this->mailer; }
        $cfg = $this->app->config;
        $driver = (string) (($cfg['mail_driver'] ?? '') ?: ($this->app->isProduction() ? 'native' : 'file'));
        return $this->mailer = match ($driver) {
            'smtp' => new SmtpMailer((string) $cfg['smtp']['host'], (int) $cfg['smtp']['port'], (string) $cfg['smtp']['secure'], (string) $cfg['smtp']['user'], (string) $cfg['smtp']['pass'], (string) $cfg['mail_from'], $this->app->logger()),
            'native' => new NativeMailer($cfg['mail_from'], $this->app->logger()),
            default => new FileMailer($cfg['log_dir'] . '/mail.log'),
        };
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

    public function catalog(): CatalogRepository { return $this->once('catalog', fn () => new CatalogRepository($this->app->pdo())); }
    public function stock(): StockService { return $this->once('stock', fn () => new StockService($this->app->pdo(), $this->catalog(), $this->audit())); }
    public function categories(): CategoryService { return $this->once('cats', fn () => new CategoryService($this->catalog(), $this->audit())); }
    public function products(): ProductService { return $this->once('prods', fn () => new ProductService($this->app->pdo(), $this->catalog(), $this->stock(), $this->audit())); }
    public function productImport(): ProductImportService { return $this->once('pimport', fn () => new ProductImportService($this->app->pdo(), $this->catalog(), $this->stock(), $this->audit())); }

    public function customerRepo(): CustomerRepository { return $this->once('custRepo', fn () => new CustomerRepository($this->app->pdo())); }
    public function loyalty(): LoyaltyService { return $this->once('loyalty', fn () => new LoyaltyService($this->app->pdo(), $this->customerRepo(), $this->audit())); }
    public function customers(): CustomerService { return $this->once('custs', fn () => new CustomerService($this->customerRepo(), $this->audit(), $this->saleRepo())); }
    public function customerGroups(): CustomerGroupService { return $this->once('groups', fn () => new CustomerGroupService($this->customerRepo(), $this->audit())); }

    public function saleRepo(): SaleRepository { return $this->once('saleRepo', fn () => new SaleRepository($this->app->pdo())); }
    public function branding(): BrandingService { return $this->once('branding', fn () => new BrandingService($this->saleRepo(), $this->audit())); }
    public function sales(): SaleService
    {
        return $this->once('sales', fn () => new SaleService($this->app->pdo(), $this->saleRepo(), $this->catalog(), $this->customerRepo(), $this->stock(), $this->loyalty(), $this->audit(), (bool) ($this->app->config['enforce_sales_limit'] ?? false)));
    }

    public function credit(): CreditService { return $this->once('credit', fn () => new CreditService($this->app->pdo(), $this->saleRepo(), $this->customerRepo(), $this->loyalty(), $this->audit())); }

    public function expenseRepo(): ExpenseRepository { return $this->once('expRepo', fn () => new ExpenseRepository($this->app->pdo())); }
    public function reportRepo(): ReportRepository { return $this->once('repRepo', fn () => new ReportRepository($this->app->pdo(), (int) ($this->app->config['report_max_rows'] ?? ReportRepository::MAX_REPORT_ROWS))); }
    public function expenses(): ExpenseService { return $this->once('expenses', fn () => new ExpenseService($this->expenseRepo(), $this->catalog(), $this->audit())); }
    public function profit(): ProfitService { return $this->once('profit', fn () => new ProfitService($this->reportRepo(), $this->saleRepo())); }
    public function dashboard(): DashboardService { return $this->once('dash', fn () => new DashboardService($this->reportRepo(), $this->catalog(), $this->saleRepo(), $this->profit(), $this->credit(), $this->purchaseRepo())); }

    public function purchaseRepo(): PurchaseRepository { return $this->once('poRepo', fn () => new PurchaseRepository($this->app->pdo())); }
    public function purchases(): PurchaseService { return $this->once('purchases', fn () => new PurchaseService($this->app->pdo(), $this->purchaseRepo(), $this->catalog(), $this->stock(), $this->audit())); }

    public function transfers(): TransferService { return $this->once('transfers', fn () => new TransferService($this->app->pdo(), new TransferRepository($this->app->pdo()), $this->catalog(), $this->stock(), $this->audit())); }

    public function account(): AccountService { return $this->once('account', fn () => new AccountService($this->app->pdo(), $this->users(), $this->sessions(), $this->hasher(), $this->mailer(), $this->audit(), $this->limiter(), (bool) $this->app->config['rate_limit_enabled'], (string) ($this->app->config['avatar_dir'] ?? ''))); }
    private ?PaymentGateway $gateway = null;
    public function setGateway(PaymentGateway $g): void { $this->gateway = $g; }
    public function gateway(): PaymentGateway
    {
        $c = $this->app->config['paystack'] ?? ['secret' => '', 'base_url' => 'https://api.paystack.co'];
        return $this->gateway ??= new PaystackGateway((string) $c['secret'], (string) $c['base_url'], $this->app->logger());
    }
    public function billing(): BillingService
    {
        return $this->once('billing', fn () => new BillingService($this->app->pdo(), new BillingRepository($this->app->pdo()), $this->gateway(), $this->mailer(), $this->audit(),
            (string) ($this->app->config['paystack']['callback_url'] ?? ''), (int) ($this->app->config['payment_retention_years'] ?? 7)));
    }
    public function admin(): AdminService { return $this->once('admin', fn () => new AdminService($this->app->pdo(), $this->users(), $this->sessions(), $this->account(), $this->hasher(), $this->audit(), $this->limiter(), (bool) $this->app->config['rate_limit_enabled'])); }
    public function reports(): ReportService { return $this->once('reports', fn () => new ReportService($this->reportRepo(), $this->saleRepo(), $this->catalog(), $this->profit(), $this->expenses(), $this->credit())); }

    public function locations(): LocationService { return $this->once('loc', fn () => new LocationService($this->tenants(), $this->audit())); }
    public function me(): MeService { return $this->once('me', fn () => new MeService($this->users(), $this->tenants(), $this->sessionsRepo(), $this->auth(), $this->audit(), (string) ($this->app->config['avatar_dir'] ?? ''))); }
}
