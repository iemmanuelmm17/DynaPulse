<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller\Admin;

use DynaPulse\Http\Controller\BaseController;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;
use DynaPulse\Repository\AuditRepository;
use DynaPulse\Repository\UserRepository;

final class UserController extends BaseController
{
    public function __construct(
        View $view,
        private UserRepository $users,
        private AuditRepository $audit,
    ) {
        parent::__construct($view);
    }

    public function index(Request $request, array $params = []): Response
    {
        return $this->render('admin/users', [
            'title' => 'Portal users',
            'subtitle' => 'Roles are managed here; passwords stay in NetSapiens',
            'users' => $this->users->all(),
            'roles' => UserRepository::ROLES,
            'currentUserId' => $this->user()->id,
        ]);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $user = $this->users->find($id);

        if ($user === null) {
            throw new HttpException(404, 'That user no longer exists.');
        }

        $role = (string) $request->post('role', (string) $user['role']);
        $active = $request->post('active') === '1' ? 1 : 0;

        if (!in_array($role, UserRepository::ROLES, true)) {
            throw new HttpException(422, 'Unknown role.');
        }

        if ($id === $this->user()->id && ($role !== 'admin' || $active === 0)) {
            throw new HttpException(422, 'You cannot remove your own administrator access.');
        }

        if ($user['role'] === 'admin' && ($role !== 'admin' || $active === 0) && (int) $user['active'] === 1 && $this->users->countAdmins() <= 1) {
            throw new HttpException(422, 'At least one active administrator must remain.');
        }

        $this->users->update($id, ['role' => $role, 'active' => $active]);
        $this->audit->log('user.updated', $this->user()->id, null, [
            'user' => $user['username'],
            'role' => $role,
            'active' => $active,
        ], $request->ip());

        return $this->redirect('/users', sprintf(
            '%s@%s is now %s (%s).',
            $user['username'],
            $user['domain'],
            $role,
            $active === 1 ? 'active' : 'disabled',
        ), 'success');
    }
}
