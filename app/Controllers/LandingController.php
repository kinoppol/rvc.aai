<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Support\Config;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * หน้าแรกสาธารณะ — แสดงภาพรวมของสถานศึกษาและรายวิชาที่ครูเลือกเปิดเผย
 * ให้ผู้สนใจภายนอกดูได้โดยไม่ต้องเข้าสู่ระบบ ไม่ชูสาขาวิชาใดเป็นพิเศษ
 */
final class LandingController
{
    public function __construct(
        private readonly View $view,
        private readonly Auth $auth,
        private readonly CourseRepository $courses,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', Url::to('/dashboard'))->withStatus(302);
        }

        $groups = $this->courses->publicLanding();
        $courseCount = array_sum(array_map(static fn (array $g): int => count($g['courses']), $groups));

        return $this->view->render($response, 'landing', [
            'groups' => $groups,
            'courseCount' => $courseCount,
            'departmentCount' => count($groups),
            'college' => (string) $this->settings->get('college_name') ?: $this->config->get('app.college'),
            'appName' => (string) $this->settings->get('site_name') ?: $this->config->get('app.name'),
            'term' => $this->termLabel(),
        ]);
    }

    private function termLabel(): string
    {
        $year = $this->settings->int('academic_year');
        $semester = $this->settings->int('semester');

        return $year > 0 ? sprintf('ภาคเรียนที่ %d / %d', $semester, $year) : '';
    }
}
