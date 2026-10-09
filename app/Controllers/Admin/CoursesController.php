<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * รายวิชาทั้งระบบสำหรับผู้ดูแลระบบและผู้ดูแลครู — ดูว่าครูคนไหนเปิดวิชาอะไรไว้บ้าง
 * และเปิดอ่านเนื้อหาหน่วยการเรียนของทุกคนเพื่อตรวจสอบได้ (อ่านอย่างเดียว ไม่แก้ไขแทนครู)
 */
final class CoursesController
{
    private const STATUSES = ['active', 'archived'];

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = trim((string) ($params['q'] ?? ''));
        $teacherId = ((int) ($params['teacher'] ?? 0)) ?: null;
        $status = in_array($params['status'] ?? '', self::STATUSES, true) ? (string) $params['status'] : '';

        $courses = $this->courses->allForAdmin($query, $teacherId, $status);

        return $this->view->render($response, 'admin/courses', [
            'page' => 'admin-courses',
            'courses' => $courses,
            'teachers' => $this->courses->teachersWithCourses(),
            'query' => $query,
            'teacherId' => $teacherId,
            'status' => $status,
            'activeCount' => count(array_filter($courses, static fn (array $c): bool => $c['status'] === 'active')),
        ]);
    }

    /** หน้ารายวิชาเดียว พร้อมหน่วยการเรียนทุกสถานะ เพื่อให้ผู้กำกับดูแลไล่ตรวจได้ */
    public function show(Request $request, Response $response, array $args): Response
    {
        $course = $this->courses->find((int) $args['id']);

        if ($course === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, 'admin/course', [
            'page' => 'admin-courses',
            'course' => $course,
            'units' => $this->units->forCourse((int) $course['id']),
        ]);
    }

    /** เนื้อหาของหน่วยการเรียนแบบอ่านอย่างเดียว */
    public function unit(Request $request, Response $response, array $args): Response
    {
        $course = $this->courses->find((int) $args['id']);
        $unit = $this->units->find((int) $args['unitId']);

        if ($course === null || $unit === null || (int) $unit['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request);
        }

        return $this->view->render($response, 'admin/course-unit', [
            'page' => 'admin-courses',
            'course' => $course,
            'unit' => $unit,
            'sections' => $this->units->sectionsFor((int) $unit['id']),
        ]);
    }
}
