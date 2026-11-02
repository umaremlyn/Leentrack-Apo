<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\App;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;

/** Minimal real admin endpoint for Phase 2 (platform statistics). More admin features arrive in Phase 3k. */
final class AdminController
{
    public function __construct(private readonly App $app)
    {
    }

    public function stats(Request $r): Response
    {
        $q = fn (string $sql) => (int) $this->app->pdo()->query($sql)->fetchColumn();
        return Response::ok([
            'users' => $q('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
            'businesses' => $q('SELECT COUNT(*) FROM businesses WHERE deleted_at IS NULL'),
            'paid_businesses' => $q("SELECT COUNT(*) FROM businesses WHERE deleted_at IS NULL AND plan <> 'free'"),
            'active_sessions' => $q('SELECT COUNT(*) FROM auth_sessions WHERE revoked_at IS NULL'),
        ]);
    }
}
