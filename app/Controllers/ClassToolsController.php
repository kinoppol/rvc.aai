<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ข้อมูลให้แท็บ "เครื่องมือ" ในหน้าต่างผู้ช่วย (นาฬิกาจับเวลา / วงล้อสุ่มชื่อ)
 * ตัวเครื่องมือทำงานในเบราว์เซอร์ทั้งหมด (public/assets/js/class-tools.js) — ที่นี่ส่งแค่รายชื่อผู้เรียน
 */
final class ClassToolsController
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
    ) {
    }

    /**
     * รายวิชาที่เปิดสอนของครู + รายชื่อนักเรียนของวิชาที่เลือก (?course=)
     * ไม่ระบุหรือไม่ใช่วิชาของครูคนนี้ → ส่งรายชื่อว่าง ให้หน้าจอเลือกวิชาเอง
     */
    public function roster(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $courses = array_map(static fn (array $c): array => [
            'id' => (int) $c['id'],
            'label' => $c['code'] . ' ' . $c['name'] . ($c['classroom_name'] ? ' · ' . $c['classroom_name'] : ''),
            'count' => (int) $c['student_count'],
        ], $this->courses->forTeacher((int) $user['id']));

        $courseId = (int) ($request->getQueryParams()['course'] ?? 0);
        $owned = in_array($courseId, array_column($courses, 'id'), true);
        $students = $owned
            ? array_map(static fn (array $s): array => [
                'id' => (int) $s['id'],
                'name' => trim((string) $s['full_name']) !== '' ? (string) $s['full_name'] : (string) $s['username'],
            ], $this->enrollments->studentsInCourse($courseId))
            : [];

        $response->getBody()->write((string) json_encode([
            'courses' => $courses,
            'course' => $owned ? $courseId : 0,
            'students' => $students,
        ], JSON_UNESCAPED_UNICODE));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }
}
