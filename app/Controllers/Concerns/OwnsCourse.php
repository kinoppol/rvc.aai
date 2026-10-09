<?php

declare(strict_types=1);

namespace App\Controllers\Concerns;

use App\Domain\CourseRepository;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * ตรวจว่าครูที่ล็อกอินเป็นเจ้าของรายวิชาที่ร้องขอ ใช้ร่วมกันในคอนโทรลเลอร์ฝั่งครู
 */
trait OwnsCourse
{
    abstract protected function courseRepo(): CourseRepository;

    /** @return array<string,mixed> */
    protected function ownedCourse(Request $request, int $courseId): array
    {
        $user = $request->getAttribute('user');
        $course = $this->courseRepo()->find($courseId);

        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== (int) $user['id']) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }
        if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
            throw new HttpForbiddenException($request, 'ผู้ดูแลระบบยังไม่เปิดใช้งานฟังก์ชันแผนการจัดการเรียนรู้ด้วย AI สำหรับรายวิชานี้');
        }

        return $course;
    }
}
