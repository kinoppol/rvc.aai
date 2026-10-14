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

        // ผู้ดูแลระบบที่สอนด้วยเข้าหน้าฝั่งครูได้ทุกหน้าที่ครูเข้าได้
        $allowed = is_array($user) && (
            in_array($user['role'], $this->roles, true)
            || (in_array(Roles::TEACHER, $this->roles, true) && Roles::canTeach($user))
        );
        if (!$allowed) {
            throw new HttpForbiddenException($request, 'บัญชีของคุณไม่มีสิทธิ์เข้าหน้านี้');
        }

        return $handler->handle($request);
    }
}
