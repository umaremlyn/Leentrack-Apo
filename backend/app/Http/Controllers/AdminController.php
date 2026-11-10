<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\AdminService;

/** Platform operator endpoints. Every route using this controller sits behind the `platform_admin` middleware (404 for everyone else). */
final class AdminController
{
    public function __construct(private readonly AdminService $svc, private readonly \LeenTrack\Services\JobService $jobs, private readonly array $config)
    {
    }

    private function id(Request $r): string { return (string) $r->params['id']; }

    public function stats(Request $r): Response { return Response::ok($this->svc->stats()); }
    public function businesses(Request $r): Response { $x = $this->svc->businesses($r->query); return Response::ok($x['rows'], $x['meta']); }
    public function business(Request $r): Response { return Response::ok($this->svc->business($this->id($r))); }
    public function setPlan(Request $r): Response { return Response::ok($this->svc->setPlan($r->auth(), $this->id($r), $r->json(), $r)); }
    public function setStatus(Request $r): Response { return Response::ok($this->svc->setStatus($r->auth(), $this->id($r), $r->json(), $r)); }
    public function deleteBusiness(Request $r): Response { return Response::ok($this->svc->deleteBusiness($r->auth(), $this->id($r), $r->json(), $r)); }
    public function users(Request $r): Response { $x = $this->svc->usersList($r->query); return Response::ok($x['rows'], $x['meta']); }
    public function disableUser(Request $r): Response { return Response::ok($this->svc->setUserStatus($r->auth(), $this->id($r), 'disabled', $r->json(), $r)); }
    public function enableUser(Request $r): Response { return Response::ok($this->svc->setUserStatus($r->auth(), $this->id($r), 'active', $r->json(), $r)); }
    public function deleteUser(Request $r): Response { return Response::ok($this->svc->deleteUser($r->auth(), $this->id($r), $r->json(), $r), [], 202); }
    public function payments(Request $r): Response { $x = $this->svc->payments($r->query); return Response::ok($x['rows'], $x['meta']); }
    public function audit(Request $r): Response { $x = $this->svc->auditLog($r->query); return Response::ok($x['rows'], $x['meta']); }
    public function notices(Request $r): Response { return Response::ok($this->svc->notices()); }
    public function createNotice(Request $r): Response { return Response::ok($this->svc->createNotice($r->auth(), $r->json(), $r), [], 201); }
    public function endNotice(Request $r): Response { return Response::ok($this->svc->endNotice($r->auth(), $this->id($r), $r)); }

    public function jobs(Request $r): Response { return Response::ok($this->jobs->overview()); }
    public function jobHistory(Request $r): Response
    {
        $job = (string) $r->params['job'];
        if (!isset(\LeenTrack\Services\JobService::JOBS[$job])) { throw \LeenTrack\Core\ApiException::notFound(); }
        return Response::ok($this->jobs->history($job));
    }
    public function health(Request $r): Response { $h = $this->jobs->health($this->config, dirname(__DIR__, 3) . '/database/migrations'); return Response::ok($h, [], 200); }

    /** Any signed-in user. */
    public function activeNotices(Request $r): Response { return Response::ok($this->svc->activeNotices()); }
}
