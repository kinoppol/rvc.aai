<?php

declare(strict_types=1);

namespace App\Auth;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpForbiddenException;

final class RoleMiddleware implements MiddlewareInterface
{
    /** @param list<string> $roles */
    public function __construct(private readonly array $roles)
    {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $user = $request->getAttribute('user');

        if (!is_array($user) || !in_array($user['role'], $this->roles, true)) {
            throw new HttpForbiddenException($request, 'บัญชีของคุณไม่มีสิทธิ์เข้าหน้านี้');
        }

        return $handler->handle($request);
    }
}
