<?php
declare(strict_types=1);

namespace LeenTrack\Http\Controllers;

use LeenTrack\Core\ApiException;
use LeenTrack\Core\Request;
use LeenTrack\Core\Response;
use LeenTrack\Core\Validator;
use LeenTrack\Services\AuthService;
use LeenTrack\Services\SessionService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth, private readonly SessionService $sessions)
    {
    }

    public function register(Request $r): Response { return Response::ok($this->auth->register($r->json(), $r), [], 202); }
    public function login(Request $r): Response { return Response::ok($this->auth->login($r->json(), $r)); }
    public function forgot(Request $r): Response { return Response::ok($this->auth->forgotPassword($r->json(), $r), [], 202); }
    public function reset(Request $r): Response { return Response::ok($this->auth->resetPassword($r->json(), $r)); }
    public function verifyEmail(Request $r): Response { return Response::ok($this->auth->verifyEmail($r->json(), $r)); }
    public function resendVerification(Request $r): Response { return Response::ok($this->auth->resendVerification($r->json(), $r), [], 202); }

    public function refresh(Request $r): Response
    {
        $v = new Validator($r->json());
        $v->only(['refresh_token']);
        $t = $v->string('refresh_token', 20, 200);
        $v->done();
        return Response::ok($this->sessions->refresh((string) $t, $r));
    }

    public function logout(Request $r): Response
    {
        $a = $r->auth();
        if (($r->query['all'] ?? null) === '1') {
            $this->sessions->logoutAll($a->userId);
        } else {
            $this->sessions->logout($a->sessionId);
        }
        return Response::ok(['message' => 'Signed out.']);
    }

    public function changePassword(Request $r): Response { return Response::ok($this->auth->changePassword($r->auth(), $r->json(), $r)); }
}
