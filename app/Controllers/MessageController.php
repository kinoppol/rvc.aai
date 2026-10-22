<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Roles;
use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\MessageRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * ข้อความส่วนตัวครู–นักเรียน
 *
 *   GET  /messages                          กล่องข้อความ (ครู: บทสนทนาในรายวิชาของตัวเอง ?course= กรองรายวิชา · นักเรียน: หนึ่งแถวต่อรายวิชาที่เรียน)
 *   GET  /messages/{courseId}/{studentId}   บทสนทนา — เปิดแล้วข้อความของอีกฝ่ายเป็น "อ่านแล้ว"
 *   POST /messages/{courseId}/{studentId}   ส่งข้อความ
 *
 * ครูเปิดได้เฉพาะรายวิชาที่ตัวเองสอน · นักเรียนเปิดได้เฉพาะบทสนทนาของตัวเองในรายวิชาที่เรียนอยู่
 */
final class MessageController
{
    public function __construct(
        private readonly View $view,
        private readonly MessageRepository $messages,
        private readonly CourseRepository $courses,
        private readonly EnrollmentRepository $enrollments,
        private readonly Db $db,
    ) {
    }

    public function inbox(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user['id'];

        if (!$this->messages->ready()) {
            return $this->view->render($response, 'messages/index', ['page' => 'messages', 'notReady' => true]);
        }

        if ($this->isStudent($user)) {
            return $this->view->render($response, 'messages/index', [
                'page' => 'messages',
                'asTeacher' => false,
                'threads' => $this->messages->studentInbox($userId),
            ]);
        }

        $courseId = (int) ($request->getQueryParams()['course'] ?? 0);
        $recipients = $this->messages->teacherRecipients($userId);
        $courses = [];
        foreach ($recipients as $r) {
            $courses[(int) $r['course_id']] = $r['course_code'] . ' ' . $r['course_name'];
        }
        $courseId = isset($courses[$courseId]) ? $courseId : 0;

        return $this->view->render($response, 'messages/index', [
            'page' => 'messages',
            'asTeacher' => true,
            'threads' => $this->messages->teacherInbox($userId, $courseId ?: null),
            'recipients' => $recipients,
            'courseFilter' => $courseId,
            'courseOptions' => $courses,
        ]);
    }

    public function thread(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $student, $asTeacher, $canSend] = $this->resolve($request, $user, (int) $args['courseId'], (int) $args['studentId']);

        $this->messages->markRead((int) $course['id'], (int) $student['id'], (int) $user['id']);
        // ป้ายบนเมนูคำนวณไว้ก่อนเปิดหน้านี้ (ViewContext) — นับใหม่หลังอ่านแล้ว
        $this->view->share('unreadMessages', $this->messages->unreadCount((int) $user['id'], !$asTeacher));

        return $this->view->render($response, 'messages/thread', [
            'page' => 'messages',
            'course' => $course,
            'student' => $student,
            'asTeacher' => $asTeacher,
            'canSend' => $canSend,
            'other' => $asTeacher ? $student['full_name'] : ($course['teacher_name'] ?? 'ครูผู้สอน'),
            'messages' => $this->messages->thread((int) $course['id'], (int) $student['id']),
            'draft' => $this->pullDraft((int) $course['id'], (int) $student['id']),
            'maxBody' => MessageRepository::MAX_BODY,
        ]);
    }

    public function send(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $studentId = (int) $args['studentId'];
        $back = $response->withHeader('Location', Url::to(sprintf('/messages/%d/%d', $courseId, $studentId)))->withStatus(302);
        $data = (array) $request->getParsedBody();
        $body = trim(str_replace("\r\n", "\n", (string) ($data['body'] ?? '')));

        if (!Csrf::check($data['_token'] ?? null)) {
            $this->keepDraft($courseId, $studentId, $body);
            Flash::error('เซสชันหมดอายุ กรุณากดส่งอีกครั้ง');

            return $back;
        }

        [$course, $student, , $canSend] = $this->resolve($request, $user, $courseId, $studentId);
        if (!$canSend) {
            Flash::error('นักเรียนคนนี้ไม่ได้อยู่ในรายวิชาแล้ว จึงส่งข้อความเพิ่มไม่ได้');

            return $back;
        }
        if ($body === '') {
            Flash::error('กรุณาพิมพ์ข้อความก่อนกดส่ง');

            return $back;
        }
        if (mb_strlen($body) > MessageRepository::MAX_BODY) {
            $this->keepDraft($courseId, $studentId, $body);
            Flash::error('ข้อความยาวเกิน ' . number_format(MessageRepository::MAX_BODY) . ' ตัวอักษร');

            return $back;
        }

        $this->messages->send((int) $course['id'], (int) $student['id'], (int) $user['id'], $body);

        return $back;
    }

    /**
     * ตรวจสิทธิ์เข้าบทสนทนา
     *
     * @param array<string,mixed> $user
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:bool,3:bool} รายวิชา, นักเรียน, เปิดในฐานะครู, ส่งข้อความได้
     */
    private function resolve(Request $request, array $user, int $courseId, int $studentId): array
    {
        if (!$this->messages->ready()) {
            throw new HttpNotFoundException($request, 'ระบบข้อความยังไม่พร้อม — ผู้ดูแลระบบต้องปรับฐานข้อมูลก่อน');
        }

        $course = $this->courses->find($courseId);
        $student = $this->db->first(
            'SELECT id, username, full_name FROM {users} WHERE id = ? AND role = ?',
            [$studentId, Roles::STUDENT]
        );
        if ($course === null || $student === null) {
            throw new HttpNotFoundException($request, 'ไม่พบบทสนทนานี้');
        }
        $course['teacher_name'] = $this->db->value('SELECT full_name FROM {users} WHERE id = ?', [(int) $course['teacher_id']]);

        $enrolled = $this->enrollments->isEnrolled($courseId, $studentId);
        $userId = (int) $user['id'];

        if ($this->isStudent($user)) {
            if ($studentId !== $userId || !$enrolled) {
                throw new HttpForbiddenException($request, 'คุณเปิดบทสนทนานี้ไม่ได้');
            }

            return [$course, $student, false, $course['status'] === 'active'];
        }

        if (!Roles::canTeach($user) || (int) $course['teacher_id'] !== $userId) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }
        // นักเรียนที่ออกจากรายวิชาไปแล้ว ครูยังเปิดอ่านบทสนทนาเดิมได้ แต่ส่งเพิ่มไม่ได้
        if (!$enrolled && $this->messages->thread($courseId, $studentId, 1) === []) {
            throw new HttpNotFoundException($request, 'นักเรียนคนนี้ไม่ได้อยู่ในรายวิชานี้');
        }

        return [$course, $student, true, $enrolled];
    }

    /** @param array<string,mixed> $user */
    private function isStudent(array $user): bool
    {
        return ($user['role'] ?? '') === Roles::STUDENT;
    }

    /** ข้อความที่ส่งไม่สำเร็จ — เก็บไว้ใส่กลับในช่องพิมพ์ ไม่ให้ผู้ใช้ต้องพิมพ์ใหม่ */
    private function keepDraft(int $courseId, int $studentId, string $body): void
    {
        if ($body !== '') {
            $_SESSION['dm_draft'] = ['key' => $courseId . ':' . $studentId, 'body' => mb_substr($body, 0, 20000)];
        }
    }

    private function pullDraft(int $courseId, int $studentId): string
    {
        $draft = $_SESSION['dm_draft'] ?? null;
        if (!is_array($draft) || ($draft['key'] ?? '') !== $courseId . ':' . $studentId) {
            return '';
        }
        unset($_SESSION['dm_draft']);

        return (string) $draft['body'];
    }
}
