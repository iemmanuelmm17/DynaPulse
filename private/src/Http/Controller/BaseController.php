<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\AuthUser;
use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Response;
use DynaPulse\Http\View;

abstract class BaseController
{
    public function __construct(protected View $view)
    {
    }

    /** @param array<string, mixed> $data */
    protected function render(string $template, array $data = [], string $layout = 'app'): Response
    {
        $data['title'] = $data['title'] ?? 'DynaPulse';
        $data['subtitle'] = $data['subtitle'] ?? '';

        return Response::html($this->view->render($template, $data, $layout));
    }

    protected function redirect(string $to, ?string $message = null, string $type = 'success', ?string $title = null): Response
    {
        if ($message !== null) {
            Context::$session?->flash($type, $message, $title);
        }

        return Response::redirect($to);
    }

    protected function back(string $fallback = '/'): Response
    {
        $previous = Context::$session?->previousUrl($fallback);

        return Response::redirect($this->safeRedirect($previous ?? $fallback));
    }

    protected function safeRedirect(?string $target, string $fallback = '/'): string
    {
        if ($target === null || $target === '' || !str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return $fallback;
        }

        return $target;
    }

    protected function user(): AuthUser
    {
        $user = Context::$user;

        if ($user === null) {
            throw new HttpException(401);
        }

        return $user;
    }

    protected function domainId(): ?int
    {
        return Context::domainId();
    }

    protected function requireManage(): void
    {
        if (!Context::can('manage')) {
            throw new HttpException(403, 'You have read-only access to this domain.');
        }
    }

    /** Domain-scoped writes need a concrete domain (managers always have one). */
    protected function requireDomainId(): int
    {
        $domainId = $this->domainId();

        if ($domainId === null) {
            throw new HttpException(422, 'Choose a domain from the switcher before making changes.');
        }

        return $domainId;
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    protected function strings(array $input, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $value = $input[$key] ?? '';
            $values[$key] = is_scalar($value) ? trim((string) $value) : '';
        }

        return $values;
    }

    protected function pagination(int $total, int $perPage, int $page): array
    {
        $perPage = max(1, $perPage);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($pages, $page));

        return [
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'total' => $total,
        ];
    }
}
