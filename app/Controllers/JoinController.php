<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Auth\Roles;
use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * นักเรียนเข้าร่วมรายวิชาเองด้วยรหัสหรือลิงก์ที่ครูแจก
 */
final class JoinController
{
    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
        private readonly Auth $auth,
        private readonly Db $db,
    ) {
    }

    /** เปิดลิงก์เข้าร่วม — แสดงรายวิชาให้นักเรียนยืนยันก่อน ไม่ลงทะเบียนเพียงเพราะเปิดลิงก์ */
    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');

        if ($user['role'] !== Roles::STUDENT) {
            Flash::warning('ลิงก์นี้ใช้เข้าร่วมรายวิชาสำหรับบัญชีนักเรียน · ถ้าจะทดลอง ให้เข้าสู่ระบบด้วยบัญชีนักเรียน');

            return $this->redirect($response, '/dashboard');
        }

        $code = CourseRepository::normalizeJoinCode((string) $args['code']);
        $course = $this->courses->findByJoinCode($code);
        $problem = $this->problem($course, (int) $user['id']);
        if ($problem !== null) {
            Flash::error($problem);

            return $this->redirect($response, '/learn');
        }

        if ($this->enrollments->isEnrolled((int) $course['id'], (int) $user['id'])) {
            Flash::success('คุณอยู่ในรายวิชา ' . $course['name'] . ' แล้ว');

            return $this->redirect($response, '/learn/' . $course['id']);
        }

        return $this->view->render($response, 'learn/join', [
            'page' => 'learn',
            'course' => $course,
            'code' => $code,
        ]);
    }

    /** ลงทะเบียนจากรหัสที่กรอก หรือจากปุ่มยืนยันบนหน้าลิงก์เข้าร่วม */
    public function join(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/learn');
        }

        $code = CourseRepository::normalizeJoinCode((string) ($data['code'] ?? ''));
        $course = $this->courses->findByJoinCode($code);
        $studentId = (int) $user['id'];
        $problem = $this->problem($course, $studentId);
        if ($problem !== null) {
            Flash::error($problem);

            return $this->redirect($response, '/learn');
        }

        $courseId = (int) $course['id'];
        if ($this->enrollments->isEnrolled($courseId, $studentId)) {
            Flash::success('คุณอยู่ในรายวิชา ' . $course['name'] . ' แล้ว');

            return $this->redirect($response, '/learn/' . $courseId);
        }

        $this->enrollments->enroll($courseId, $studentId);

        // แถวเดิมที่สถานะ "เรียนจบแล้ว" จะไม่ถูกเปลี่ยนกลับ
        if (!$this->enrollments->isEnrolled($courseId, $studentId)) {
            Flash::error('คุณเรียนรายวิชา ' . $course['name'] . ' จบไปแล้ว · ถ้าต้องเรียนซ้ำให้ติดต่อครูประจำวิชา');

            return $this->redirect($response, '/learn');
        }

        $this->auth->log('course.join', 'course#' . $courseId, ['code' => $code]);
        Flash::success('เข้าร่วมรายวิชา ' . $course['name'] . ' แล้ว');

        return $this->redirect($response, '/learn/' . $courseId);
    }

    /**
     * เหตุผลที่เข้าร่วมไม่ได้เป็นข้อความไทย หรือ null ถ้าเข้าร่วมได้
     *
     * @param array<string,mixed>|null $course
     */
    private function problem(?array $course, int $studentId): ?string
    {
        if ($course === null) {
            return 'ไม่พบรายวิชาจากรหัสนี้ · ตรวจรหัสอีกครั้ง หรือขอรหัสใหม่จากครูประจำวิชา';
        }
        if ($course['status'] !== 'active') {
            return 'รายวิชา ' . $course['name'] . ' ปิดไปแล้ว';
        }
        if (!(int) $course['join_enabled']) {
            return 'ครูปิดการเข้าร่วมรายวิชา ' . $course['name'] . ' ด้วยรหัสอยู่ · ติดต่อครูประจำวิชา';
        }

        // นักเรียนและครูต้องอยู่สถานศึกษาเดียวกัน (ข้ามการตรวจเมื่อบัญชีฝั่งใดยังไม่ระบุสถานศึกษา)
        if ($course['teacher_institution_id'] !== null) {
            $mine = $this->db->value('SELECT institution_id FROM {users} WHERE id = ?', [$studentId]);
            if ($mine !== null && (int) $mine !== (int) $course['teacher_institution_id']) {
                return 'รายวิชานี้เป็นของสถานศึกษาอื่น · ตรวจรหัสอีกครั้ง หรือติดต่อครูประจำวิชา';
            }
        }

        return null;
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
