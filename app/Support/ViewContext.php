<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;
use App\Auth\Roles;
use App\Domain\ReviewRepository;
use App\Domain\SettingsRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * เติมตัวแปรที่ทุกหน้าใน layout ต้องใช้ (ผู้ใช้ปัจจุบัน จำนวนรอตรวจ ชื่อภาคเรียน)
 * ทำงานหลัง AuthMiddleware จึงมี attribute "user" เสมอ
 */
final class ViewContext implements MiddlewareInterface
{
    public function __construct(
        private readonly View $view,
        private readonly SettingsRepository $settings,
        private readonly ReviewRepository $reviews,
        private readonly Auth $auth,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        /** @var array<string,mixed>|null $user */
        $user = $request->getAttribute('user');

        $this->view->share('user', $user);
        $this->view->share('term', $this->termLabel());
        $this->view->share('ui', Palette::tokens(
            $this->settings->uiPrimary(is_array($user) ? (int) $user['id'] : null)['hex']
        ));
        $this->view->share('impersonatedBy', $this->auth->isImpersonating() ? $this->auth->impersonatorName() : null);

        if (is_array($user)) {
            $this->view->share('roleLabel', Roles::label((string) ($user['role'] ?? '')));

            if (Roles::teaches($user['role'] ?? null)) {
                $this->view->share('reviewCount', $this->reviews->pendingCount((int) $user['id']));
            }
        }

        return $handler->handle($request);
    }

    private function termLabel(): string
    {
        $year = $this->settings->int('academic_year');
        $semester = $this->settings->int('semester');

        return $year > 0 ? sprintf('ภาคเรียนที่ %d / %d', $semester, $year) : '';
    }
}
