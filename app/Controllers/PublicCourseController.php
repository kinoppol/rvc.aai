<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Domain\UnitRepository;
use App\Support\Config;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * หน้ารายวิชาสำหรับผู้เยี่ยมชมทั่วไป — อ่านเนื้อหาของรายวิชาที่ครูเผยแพร่ได้โดยไม่ต้องสมัครหรือเข้าสู่ระบบ
 *
 * เปิดให้เห็นเฉพาะรายวิชาที่ show_on_landing = 1 และหน่วยการเรียนที่ผ่านการตรวจแล้ว (review_status = published)
 * ส่วนแบบทดสอบและงานที่ต้องส่งยังต้องเข้าสู่ระบบเสมอ
 */
final class PublicCourseController
{
    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly SettingsRepository $settings,
        private readonly Config $config,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $course = $this->publicCourse($request, (int) $args['id']);

        return $this->view->render($response, 'public/course', $this->shared() + [
            'course' => $course,
            'units' => $this->units->publishedForCourse((int) $course['id']),
        ]);
    }

    public function unit(Request $request, Response $response, array $args): Response
    {
        $course = $this->publicCourse($request, (int) $args['id']);
        $unit = $this->units->findPublished((int) $args['unitId'], (int) $course['id']);

        if ($unit === null) {
            throw new HttpNotFoundException($request);
        }

        $all = $this->units->publishedForCourse((int) $course['id']);
        $index = array_search((int) $unit['id'], array_map(static fn (array $u): int => (int) $u['id'], $all), true);

        return $this->view->render($response, 'public/unit', $this->shared() + [
            'course' => $course,
            'unit' => $unit,
            'sections' => $this->units->sectionsFor((int) $unit['id']),
            'prev' => $index > 0 ? $all[$index - 1] : null,
            'next' => $index !== false && isset($all[$index + 1]) ? $all[$index + 1] : null,
        ]);
    }

    /** @return array<string,mixed> */
    private function publicCourse(Request $request, int $id): array
    {
        $course = $this->courses->publicFind($id);

        if ($course === null) {
            throw new HttpNotFoundException($request);
        }

        return $course;
    }

    /** ค่าที่ layout ของหน้าสาธารณะต้องใช้ (ViewContext ไม่ทำงานกับผู้ที่ยังไม่ได้เข้าสู่ระบบ) */
    private function shared(): array
    {
        return [
            'appName' => (string) $this->settings->get('site_name') ?: $this->config->get('app.name'),
            'college' => (string) $this->settings->get('college_name') ?: $this->config->get('app.college'),
        ];
    }
}
