<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Services\AccountService;
use LeenTrack\Services\MeService;

final class MeController
{
    public function __construct(private readonly MeService $me, private readonly AccountService $account, private readonly \LeenTrack\Services\StockAlertService $alerts)
    {
    }

    public function show(Request $r): Response { return Response::ok($this->me->show($r->auth())); }
    public function update(Request $r): Response { return Response::ok($this->me->update($r->auth(), $r->json(), $r)); }
    public function sessions(Request $r): Response { return Response::ok($this->me->sessions($r->auth())); }
    public function revokeSession(Request $r): Response { $this->me->revokeSession($r->auth(), (string) $r->params['id'], $r); return Response::ok(['revoked' => true]); }

    public function export(Request $r): Response { return Response::ok($this->account->exportMe($r->auth(), $r)); }
    public function requestDeletion(Request $r): Response { return Response::ok($this->account->requestDeletion($r->auth(), $r->json(), $r), [], 202); }
    public function cancelDeletion(Request $r): Response { return Response::ok($this->account->cancelDeletion($r->json(), $r)); }
    public function avatarSet(Request $r): Response { return Response::ok($this->me->setAvatar($r->auth(), $r->rawBody(), $r)); }
    public function avatarDelete(Request $r): Response { $this->me->deleteAvatar($r->auth(), $r); return Response::ok(['has_avatar' => false]); }
    public function avatarShow(Request $r): Response { [$bytes, $mime] = $this->me->avatar($r->auth()); return Response::binary($bytes, $mime); }
    public function alertShow(Request $r): Response { return Response::ok($this->alerts->settings($r->tenant())); }
    public function alertSave(Request $r): Response { return Response::ok($this->alerts->save($r->tenant(), $r->json(), $r)); }
    public function alertTest(Request $r): Response { return Response::ok($this->alerts->test($r->tenant(), $r)); }
    public function preferences(Request $r): Response { return Response::ok($this->me->preferences($r->auth(), $r->json(), $r)); }
    public function businessExport(Request $r): Response { $x = $this->account->exportBusiness($r->tenant(), $r->query, $r); return Response::ok($x['data'], $x['meta']); }
}
