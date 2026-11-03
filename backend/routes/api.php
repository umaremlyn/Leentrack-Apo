<?php
declare(strict_types=1);

use LeenTrack\Core\{Lazy, Router};
use LeenTrack\Http\Controllers\{AdminController, AuthController, BusinessController, CatalogController, CustomerController, FinanceController, HealthController, PurchaseController, TransferController, ReportController, BillingController, SaleController, LocationController, MeController, StaffController};
use LeenTrack\Services\Container;

/**
 * Every route is private unless flagged public (4th arg `true`). Middleware order matters: auth -> tenant -> permission checks in services.
 *  throttle:N,S  = N hits per S seconds per IP+route (fails closed)   auth / auth:optional   tenant (X-Business-Id)   tenant:path ({id})   platform_admin
 */
return static function (Router $r, Container $c): void {
    $health = new Lazy(fn () => new HealthController($c->app()));
    $auth = new Lazy(fn () => new AuthController($c->auth(), $c->sessions()));
    $me = new Lazy(fn () => new MeController($c->me(), $c->account()));
    $biz = new Lazy(fn () => new BusinessController($c->businesses(), $c->tenants()));
    $staff = new Lazy(fn () => new StaffController($c->staff()));
    $loc = new Lazy(fn () => new LocationController($c->locations()));
    $cat = new Lazy(fn () => new CatalogController($c->categories(), $c->products(), $c->productImport(), $c->stock()));
    $cust = new Lazy(fn () => new CustomerController($c->customers(), $c->customerGroups(), $c->loyalty()));
    $sale = new Lazy(fn () => new SaleController($c->sales(), $c->branding(), $c->credit()));
    $po = new Lazy(fn () => new PurchaseController($c->purchases()));
    $tr = new Lazy(fn () => new TransferController($c->transfers()));
    $rep = new Lazy(fn () => new ReportController($c->reports()));
    $bill = new Lazy(fn () => new BillingController($c->billing()));
    $fin = new Lazy(fn () => new FinanceController($c->expenses(), $c->profit(), $c->dashboard()));
    $admin = new Lazy(fn () => new AdminController($c->app()));

    $r->get('/health', [$health, 'show'], [], true);

    // --- public auth endpoints (all throttled) ---
    $r->post('/auth/register', [$auth, 'register'], ['throttle:10,3600'], true);
    $r->post('/auth/login', [$auth, 'login'], ['throttle:20,300'], true);
    $r->post('/auth/refresh', [$auth, 'refresh'], ['throttle:60,300'], true);
    $r->post('/auth/cancel-deletion', [$me, 'cancelDeletion'], ['throttle:10,3600'], true);
    $r->post('/auth/forgot-password', [$auth, 'forgot'], ['throttle:10,3600'], true);
    $r->post('/auth/reset-password', [$auth, 'reset'], ['throttle:10,3600'], true);
    $r->post('/auth/verify-email', [$auth, 'verifyEmail'], ['throttle:20,3600'], true);
    $r->post('/auth/resend-verification', [$auth, 'resendVerification'], ['throttle:10,3600'], true);
    $r->post('/invites/accept', [$staff, 'accept'], ['throttle:20,3600', 'auth:optional'], true);

    // --- authenticated, user-level ---
    $r->post('/auth/logout', [$auth, 'logout'], ['auth']);
    $r->post('/me/change-password', [$auth, 'changePassword'], ['auth', 'throttle:10,900']);
    $r->get('/me', [$me, 'show'], ['auth']);
    $r->patch('/me', [$me, 'update'], ['auth']);
    $r->get('/me/export', [$me, 'export'], ['auth', 'throttle:3,3600']);
    $r->post('/me/deletion', [$me, 'requestDeletion'], ['auth', 'throttle:5,3600']);
    $r->put('/me/avatar', [$me, 'avatarSet'], ['auth', 'throttle:20,3600']);
    $r->delete('/me/avatar', [$me, 'avatarDelete'], ['auth']);
    $r->get('/me/avatar', [$me, 'avatarShow'], ['auth']);
    $r->get('/me/sessions', [$me, 'sessions'], ['auth']);
    $r->delete('/me/sessions/{id}', [$me, 'revokeSession'], ['auth']);
    $r->get('/businesses', [$biz, 'index'], ['auth']);
    $r->post('/businesses', [$biz, 'create'], ['auth', 'throttle:10,3600']);

    // --- business-scoped (membership verified on every request) ---
    $r->get('/businesses/{id}/export', [$me, 'businessExport'], ['auth', 'tenant:path']);
    $r->get('/businesses/{id}', [$biz, 'show'], ['auth', 'tenant:path']);
    $r->patch('/businesses/{id}', [$biz, 'update'], ['auth', 'tenant:path']);
    $r->get('/staff', [$staff, 'index'], ['auth', 'tenant']);
    $r->post('/staff', [$staff, 'invite'], ['auth', 'tenant', 'throttle:30,3600']);
    $r->patch('/staff/{id}', [$staff, 'update'], ['auth', 'tenant']);
    $r->put('/staff/{id}/outlets', [$staff, 'outlets'], ['auth', 'tenant']);
    $r->delete('/staff/{id}', [$staff, 'remove'], ['auth', 'tenant']);
    $r->post('/staff/{id}/resend-invite', [$staff, 'resend'], ['auth', 'tenant', 'throttle:30,3600']);
    $r->get('/locations', [$loc, 'index'], ['auth', 'tenant']);
    $r->post('/locations', [$loc, 'create'], ['auth', 'tenant']);
    $r->get('/locations/{id}', [$loc, 'show'], ['auth', 'tenant']);
    $r->patch('/locations/{id}', [$loc, 'update'], ['auth', 'tenant']);
    $r->delete('/locations/{id}', [$loc, 'remove'], ['auth', 'tenant']);

    // --- catalogue & stock (all tenant-scoped; permissions enforced in services) ---
    $r->get('/categories', [$cat, 'categoryIndex'], ['auth', 'tenant']);
    $r->post('/categories', [$cat, 'categoryCreate'], ['auth', 'tenant']);
    $r->patch('/categories/{id}', [$cat, 'categoryUpdate'], ['auth', 'tenant']);
    $r->delete('/categories/{id}', [$cat, 'categoryDelete'], ['auth', 'tenant']);
    $r->get('/products', [$cat, 'productIndex'], ['auth', 'tenant']);
    $r->post('/products', [$cat, 'productCreate'], ['auth', 'tenant']);
    $r->post('/products/import', [$cat, 'productImport'], ['auth', 'tenant', 'throttle:20,3600']);
    $r->get('/products/export', [$cat, 'productExport'], ['auth', 'tenant', 'throttle:30,3600']);
    $r->get('/products/{id}', [$cat, 'productShow'], ['auth', 'tenant']);
    $r->patch('/products/{id}', [$cat, 'productUpdate'], ['auth', 'tenant']);
    $r->delete('/products/{id}', [$cat, 'productArchive'], ['auth', 'tenant']);
    $r->post('/products/{id}/restore', [$cat, 'productRestore'], ['auth', 'tenant']);
    $r->post('/stock/adjustments', [$cat, 'stockAdjust'], ['auth', 'tenant']);
    $r->get('/stock/levels', [$cat, 'stockLevels'], ['auth', 'tenant']);
    $r->get('/stock/movements', [$cat, 'stockMovements'], ['auth', 'tenant']);
    $r->get('/stock/valuation', [$cat, 'stockValuation'], ['auth', 'tenant']);

    // --- customers, groups, loyalty ---
    $r->get('/customers', [$cust, 'index'], ['auth', 'tenant']);
    $r->post('/customers', [$cust, 'create'], ['auth', 'tenant']);
    $r->get('/customers/{id}', [$cust, 'show'], ['auth', 'tenant']);
    $r->patch('/customers/{id}', [$cust, 'update'], ['auth', 'tenant']);
    $r->delete('/customers/{id}', [$cust, 'archive'], ['auth', 'tenant']);
    $r->post('/customers/{id}/restore', [$cust, 'restore'], ['auth', 'tenant']);
    $r->get('/customers/{id}/loyalty-ledger', [$cust, 'ledger'], ['auth', 'tenant']);
    $r->get('/customer-groups', [$cust, 'groupIndex'], ['auth', 'tenant']);
    $r->post('/customer-groups', [$cust, 'groupCreate'], ['auth', 'tenant']);
    $r->patch('/customer-groups/{id}', [$cust, 'groupUpdate'], ['auth', 'tenant']);
    $r->delete('/customer-groups/{id}', [$cust, 'groupDelete'], ['auth', 'tenant']);
    $r->get('/businesses/{id}/loyalty-settings', [$cust, 'loyaltyShow'], ['auth', 'tenant:path']);
    $r->put('/businesses/{id}/loyalty-settings', [$cust, 'loyaltySave'], ['auth', 'tenant:path']);

    // --- sales, documents, branding ---
    $r->post('/sales', [$sale, 'checkout'], ['auth', 'tenant', 'throttle:120,60']);
    $r->get('/sales', [$sale, 'index'], ['auth', 'tenant']);
    $r->get('/sales/{id}', [$sale, 'show'], ['auth', 'tenant']);
    $r->get('/sales/{id}/document', [$sale, 'document'], ['auth', 'tenant']);
    $r->post('/sales/{id}/edit', [$sale, 'edit'], ['auth', 'tenant', 'throttle:60,60']);
    $r->post('/sales/{id}/void', [$sale, 'void'], ['auth', 'tenant', 'throttle:30,60']);
    $r->post('/sales/{id}/repayments', [$sale, 'repay'], ['auth', 'tenant', 'throttle:120,60']);
    $r->get('/credit', [$sale, 'creditList'], ['auth', 'tenant']);
    $r->get('/customers/{id}/debt', [$sale, 'customerDebt'], ['auth', 'tenant']);
    $r->get('/customers/{id}/statement', [$sale, 'customerStatement'], ['auth', 'tenant']);
    $r->get('/customers/{id}/sales', [$sale, 'customerSales'], ['auth', 'tenant']);
    $r->get('/businesses/{id}/branding', [$sale, 'brandingShow'], ['auth', 'tenant:path']);
    $r->put('/businesses/{id}/branding', [$sale, 'brandingSave'], ['auth', 'tenant:path']);

    // --- expenses, profit, dashboard ---
    $r->get('/expenses', [$fin, 'expenseIndex'], ['auth', 'tenant']);
    $r->post('/expenses', [$fin, 'expenseCreate'], ['auth', 'tenant']);
    $r->get('/expenses/{id}', [$fin, 'expenseShow'], ['auth', 'tenant']);
    $r->patch('/expenses/{id}', [$fin, 'expenseUpdate'], ['auth', 'tenant']);
    $r->delete('/expenses/{id}', [$fin, 'expenseDelete'], ['auth', 'tenant']);
    $r->get('/expense-categories', [$fin, 'categoryIndex'], ['auth', 'tenant']);
    $r->post('/expense-categories', [$fin, 'categoryCreate'], ['auth', 'tenant']);
    $r->post('/expense-categories/seed-defaults', [$fin, 'categorySeed'], ['auth', 'tenant']);
    $r->patch('/expense-categories/{id}', [$fin, 'categoryUpdate'], ['auth', 'tenant']);
    $r->delete('/expense-categories/{id}', [$fin, 'categoryDelete'], ['auth', 'tenant']);
    $r->get('/profit', [$fin, 'profit'], ['auth', 'tenant']);
    $r->get('/dashboard', [$fin, 'dashboard'], ['auth', 'tenant']);

    // --- suppliers & purchase orders ---
    $r->get('/suppliers', [$po, 'supplierIndex'], ['auth', 'tenant']);
    $r->post('/suppliers', [$po, 'supplierCreate'], ['auth', 'tenant']);
    $r->get('/suppliers/{id}', [$po, 'supplierShow'], ['auth', 'tenant']);
    $r->patch('/suppliers/{id}', [$po, 'supplierUpdate'], ['auth', 'tenant']);
    $r->delete('/suppliers/{id}', [$po, 'supplierArchive'], ['auth', 'tenant']);
    $r->post('/suppliers/{id}/restore', [$po, 'supplierRestore'], ['auth', 'tenant']);
    $r->get('/purchase-orders', [$po, 'orderIndex'], ['auth', 'tenant']);
    $r->post('/purchase-orders', [$po, 'orderCreate'], ['auth', 'tenant']);
    $r->get('/purchase-orders/{id}', [$po, 'orderShow'], ['auth', 'tenant']);
    $r->patch('/purchase-orders/{id}', [$po, 'orderUpdate'], ['auth', 'tenant']);
    $r->delete('/purchase-orders/{id}', [$po, 'orderDelete'], ['auth', 'tenant']);
    $r->post('/purchase-orders/{id}/order', [$po, 'orderPlace'], ['auth', 'tenant']);
    $r->post('/purchase-orders/{id}/cancel', [$po, 'orderCancel'], ['auth', 'tenant']);
    $r->post('/purchase-orders/{id}/close', [$po, 'orderClose'], ['auth', 'tenant']);
    $r->get('/suppliers/{id}/statement', [$po, 'supplierStatement'], ['auth', 'tenant']);
    $r->post('/purchase-orders/{id}/payments', [$po, 'orderPay'], ['auth', 'tenant']);
    $r->post('/purchase-orders/{id}/receive', [$po, 'orderReceive'], ['auth', 'tenant']);

    // --- stock transfers ---
    $r->get('/transfers', [$tr, 'index'], ['auth', 'tenant']);
    $r->post('/transfers', [$tr, 'create'], ['auth', 'tenant']);
    $r->get('/transfers/{id}', [$tr, 'show'], ['auth', 'tenant']);
    $r->patch('/transfers/{id}', [$tr, 'update'], ['auth', 'tenant']);
    $r->post('/transfers/{id}/dispatch', [$tr, 'dispatch'], ['auth', 'tenant']);
    $r->post('/transfers/{id}/receive', [$tr, 'receive'], ['auth', 'tenant']);
    $r->post('/transfers/{id}/cancel', [$tr, 'cancel'], ['auth', 'tenant']);

    // --- reports ---
    $r->get('/reports', [$rep, 'index'], ['auth', 'tenant']);
    $r->get('/reports/{name}', [$rep, 'show'], ['auth', 'tenant']);

    // --- billing ---
    $r->get('/billing', [$bill, 'summary'], ['auth', 'tenant']);
    $r->get('/billing/plans', [$bill, 'plans'], ['auth', 'tenant']);
    $r->get('/billing/history', [$bill, 'history'], ['auth', 'tenant']);
    $r->post('/billing/checkout', [$bill, 'checkout'], ['auth', 'tenant', 'throttle:20,3600']);
    $r->post('/billing/verify', [$bill, 'verify'], ['auth', 'tenant', 'throttle:60,3600']);
    $r->post('/webhooks/paystack', [$bill, 'paystackWebhook'], ['throttle:600,60'], true);

    // --- platform administration ---
    $r->get('/admin/stats', [$admin, 'stats'], ['auth', 'platform_admin']);
};
