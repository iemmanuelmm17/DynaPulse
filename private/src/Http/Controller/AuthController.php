<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Service\AuthException;
use DynaPulse\Service\AuthService;
use DynaPulse\Support\Logger;

final class AuthController extends BaseController
{
    public function __construct(
        \DynaPulse\Http\View $view,
        private AuthService $auth,
    ) {
        parent::__construct($view);
    }

    public function showLogin(Request $request, array $params = []): Response
    {
        return $this->render('auth/login', [
            'title' => 'Sign in',
            'next' => $this->safeRedirect((string) $request->query('next', '/')),
            'login' => (string) $request->query('login', ''),
            'mailReady' => (bool) Context::config('mail.host', ''),
        ], 'minimal');
    }

    public function login(Request $request, array $params = []): Response
    {
        $login = trim((string) $request->post('login', ''));
        $password = (string) $request->post('password', '');
        $next = $this->safeRedirect((string) $request->post('next', '/'));

        try {
            $user = $this->auth->attempt($login, $password, $request->ip());
        } catch (AuthException $e) {
            Logger::warning('auth.rejected', ['reason' => $e->reason, 'login' => $login, 'ip' => $request->ip()]);
            Context::$session?->flash('error', $e->getMessage(), 'Sign-in failed');

            return Response::redirect('/login?next=' . rawurlencode($next) . '&login=' . rawurlencode($login));
        }

        Context::$session?->login($user->id);
        Context::$session?->put('view_domain_id', $user->isAdmin() ? 'all' : (string) $user->domainId);

        return $this->redirect($next, 'Signed in as ' . $user->displayName . '.', 'success');
    }

    public function logout(Request $request, array $params = []): Response
    {
        Context::$session?->destroy();

        return Response::redirect('/login');
    }
}
