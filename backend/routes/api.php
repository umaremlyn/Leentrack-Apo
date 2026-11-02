<?php
declare(strict_types=1);

use LeenTrack\Core\{Lazy, Router};
use LeenTrack\Http\Controllers\{AdminController, AuthController, BusinessController, HealthController, LocationController, MeController, StaffController};
use LeenTrack\Services\Container;

/**
 * Every route is private unless flagged public (4th arg `true`). Middleware order matters: auth -> tenant -> permission checks in services.
 *  throttle:N,S  = N hits per S seconds per IP+route (fails closed)   auth / auth:optional   tenant (X-Business-Id)   tenant:path ({id})   platform_admin
 */
return static function (Router $r, Container $c): void {
    $health = new Lazy(fn () => new HealthController($c->app()));
    $auth = new Lazy(fn () => new AuthController($c->auth(), $c->sessions()));
    $me = new Lazy(fn () => new MeController($c->me()));
    $biz = new Lazy(fn () => new BusinessController($c->businesses(), $c->tenants()));
    $staff = new Lazy(fn () => new StaffController($c->staff()));
    $loc = new Lazy(fn () => new LocationController($c->locations()));
    $admin = new Lazy(fn () => new AdminController($c->app()));

    $r->get('/health', [$health, 'show'], [], true);

    // --- public auth endpoints (all throttled) ---
    $r->post('/auth/register', [$auth, 'register'], ['throttle:10,3600'], true);
    $r->post('/auth/login', [$auth, 'login'], ['throttle:20,300'], true);
    $r->post('/auth/refresh', [$auth, 'refresh'], ['throttle:60,300'], true);
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
    $r->get('/me/sessions', [$me, 'sessions'], ['auth']);
    $r->delete('/me/sessions/{id}', [$me, 'revokeSession'], ['auth']);
    $r->get('/businesses', [$biz, 'index'], ['auth']);
    $r->post('/businesses', [$biz, 'create'], ['auth', 'throttle:10,3600']);

    // --- business-scoped (membership verified on every request) ---
    $r->get('/businesses/{id}', [$biz, 'show'], ['auth', 'tenant:path']);
    $r->patch('/businesses/{id}', [$biz, 'update'], ['auth', 'tenant:path']);
    $r->get('/staff', [$staff, 'index'], ['auth', 'tenant']);
    $r->post('/staff', [$staff, 'invite'], ['auth', 'tenant', 'throttle:30,3600']);
    $r->patch('/staff/{id}', [$staff, 'update'], ['auth', 'tenant']);
    $r->delete('/staff/{id}', [$staff, 'remove'], ['auth', 'tenant']);
    $r->post('/staff/{id}/resend-invite', [$staff, 'resend'], ['auth', 'tenant', 'throttle:30,3600']);
    $r->get('/locations', [$loc, 'index'], ['auth', 'tenant']);
    $r->post('/locations', [$loc, 'create'], ['auth', 'tenant']);
    $r->get('/locations/{id}', [$loc, 'show'], ['auth', 'tenant']);
    $r->patch('/locations/{id}', [$loc, 'update'], ['auth', 'tenant']);
    $r->delete('/locations/{id}', [$loc, 'remove'], ['auth', 'tenant']);

    // --- platform administration ---
    $r->get('/admin/stats', [$admin, 'stats'], ['auth', 'platform_admin']);
};
