<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AttemptRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\LearnPathRepository;
use App\Domain\UnitRepository;
use App\Domain\QuizRepository;
use App\Support\Cover;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Paths;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

/**
 * มุมมองนักเรียน: ดูเนื้อหาบทเรียนที่เผยแพร่แล้ว และทำแบบทดสอบ
 */
final class StudentController
{
    public function __construct(
        private readonly View $view,
        private readonly EnrollmentRepository $enrollments,
        private readonly UnitRepository $units,
        private readonly QuizRepository $quizzes,
        private readonly AttemptRepository $attempts,
        private readonly LearnPathRepository $path,
        private readonly Db $db,
    ) {
    }

    public function courses(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        return $this->view->render($response, 'learn/courses', [
            'page' => 'learn',
            'firstName' => explode(' ', trim($user['full_name']))[0],
            'courses' => $this->enrollments->coursesForStudent((int) $user['id']),
        ]);
    }

    public function course(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $this->requireEnrolled($request, $courseId, (int) $user['id']);

        $course = $this->courseRow($courseId);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }

        return $this->view->render($response, 'learn/course',
            ['page' => 'learn', 'studentName' => (string) $user['full_name']]
            + $this->courseContext($course, (int) $user['id'], false));
    }

    /** ครูดูทั้งรายวิชาในมุมมองของนักเรียน · เห็นเฉพาะหน่วยที่เผยแพร่แล้ว เหมือนนักเรียน แต่ไม่มีการบันทึกความคืบหน้า */
    public function previewCourse(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->courseRow((int) $args['courseId']);
        if ($course === null || (int) $course['teacher_id'] !== (int) $user['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }

        $hidden = count(array_filter(
            $this->units->forCourse((int) $course['id']),
            static fn (array $u): bool => $u['review_status'] !== 'published'
        ));

        return $this->view->render($response, 'learn/course',
            ['page' => 'courses', 'studentName' => '', 'hiddenUnits' => $hidden]
            + $this->courseContext($course, 0, true));
    }

    public function unit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->studentUnit($request, (int) $args['id'], (int) $user['id']);

        return $this->view->render($response, 'learn/unit',
            ['page' => 'learn', 'studentName' => (string) $user['full_name']]
            + $this->unitContext($course, $unit, (int) $user['id'], false));
    }

    /**
     * ครูดูหน่วยในมุมมองของนักเรียน (อ่านอย่างเดียว) · เปิดได้ทุกสถานะ แม้ยังไม่เผยแพร่
     * แบบทดสอบแสดงเฉพาะชุดที่นักเรียนมองเห็นจริง (เผยแพร่แล้วและมีข้อสอบ) แต่เริ่มทำไม่ได้
     */
    public function preview(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->teacherUnit($request, (int) $args['courseId'], (int) $args['id'], (int) $user['id']);

        return $this->view->render($response, 'learn/unit',
            ['page' => 'courses', 'studentName' => '']
            + $this->unitContext($course, $unit, 0, true));
    }

    /** หน้าอ่านส่วนเนื้อหา / ใบงานทีละรายการ · เปิดส่วนเนื้อหา = อ่านแล้ว (นับความคืบหน้า) */
    public function item(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->studentUnit($request, (int) $args['id'], (int) $user['id']);

        if ($args['kind'] === 'section') {
            $section = $this->units->findSection((int) $args['itemId']);
            if ($section !== null && (int) $section['unit_id'] === (int) $unit['id']) {
                $this->path->markSection((int) $section['id'], (int) $user['id']);
            }
        }

        return $this->renderItem($request, $response, $args, $course, $unit, (int) $user['id'], false);
    }

    public function previewItem(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$course, $unit] = $this->teacherUnit($request, (int) $args['courseId'], (int) $args['id'], (int) $user['id']);

        return $this->renderItem($request, $response, $args, $course, $unit, 0, true);
    }

    /** ไฟล์ PDF ของส่วนเนื้อหา — นักเรียนที่ลงทะเบียน (หน่วยที่เผยแพร่แล้ว) */
    public function file(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [, $unit] = $this->studentUnit($request, (int) $args['id'], (int) $user['id']);

        return $this->sendPdf($request, $response, (int) $unit['id'], (int) $args['itemId']);
    }

    /** ไฟล์ PDF ของส่วนเนื้อหา — ครูเจ้าของรายวิชา (ทุกสถานะ ใช้ในหน้าแก้ไขหน่วยและมุมมองนักเรียน) */
    public function previewFile(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [, $unit] = $this->teacherUnit($request, (int) $args['courseId'], (int) $args['id'], (int) $user['id']);

        return $this->sendPdf($request, $response, (int) $unit['id'], (int) $args['itemId']);
    }

    /** ส่งไฟล์ให้เบราว์เซอร์เปิดอ่านในหน้า (inline) — ไฟล์อยู่ใน storage/uploads นอก public/ จึงต้องผ่านการตรวจสิทธิ์ที่นี่เสมอ */
    private function sendPdf(Request $request, Response $response, int $unitId, int $sectionId): Response
    {
        $section = $this->units->findSection($sectionId);
        $root = realpath(Paths::storage('uploads'));
        $path = $section !== null && $section['type'] === 'pdf' && (int) $section['unit_id'] === $unitId
            ? realpath(Paths::storage('uploads/' . (string) $section['file_path']))
            : false;
        // กัน file_path ที่ชี้ออกนอกโฟลเดอร์ uploads
        if ($root === false || $path === false || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new HttpNotFoundException($request, 'ไม่พบไฟล์นี้');
        }

        $name = (string) ($section['file_name'] ?: basename($path));
        if (!str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }
        $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: 'document.pdf';
        $download = ($request->getQueryParams()['download'] ?? '') === '1';

        return $response
            ->withBody(new Stream(fopen($path, 'rb')))
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Content-Disposition', sprintf(
                '%s; filename="%s"; filename*=UTF-8\'\'%s',
                $download ? 'attachment' : 'inline',
                $ascii,
                rawurlencode($name)
            ))
            ->withHeader('Cache-Control', 'private, max-age=3600')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function startQuiz(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $quiz = $this->quizzes->find((int) $args['quizId']);
        if ($quiz === null || $quiz['review_status'] !== 'published') {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }
        $this->requireEnrolled($request, (int) $quiz['course_id'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $this->redirect($response, "/learn/{$quiz['course_id']}");
        }

        $active = $this->attempts->inProgress((int) $quiz['id'], (int) $user['id']);
        $attemptId = $active['id'] ?? $this->attempts->start((int) $quiz['id'], (int) $user['id']);

        return $this->redirect($response, "/learn/attempts/$attemptId");
    }

    public function take(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        if ($attempt['status'] !== 'in_progress') {
            return $this->redirect($response, "/learn/attempts/{$attempt['id']}/result");
        }

        $questions = $this->quizzes->questions((int) $quiz['id']);
        $saved = $this->attempts->answers((int) $attempt['id']);

        // ซ่อนเฉลยจากฝั่งนักเรียน
        $view = [];
        foreach ($questions as $i => $q) {
            $view[] = [
                'id' => (int) $q['id'],
                'no' => $i + 1,
                'type' => $q['type'],
                'question' => $q['question'],
                'choices' => array_map(static fn (array $c): array => [
                    'id' => (int) $c['id'], 'label' => $c['label'], 'content' => $c['content'],
                ], $q['choices']),
                'answered_choice' => isset($saved[(int) $q['id']]) ? ($saved[(int) $q['id']]['choice_id'] !== null ? (int) $saved[(int) $q['id']]['choice_id'] : null) : null,
                'answered_text' => $saved[(int) $q['id']]['answer_text'] ?? '',
            ];
        }

        return $this->view->render($response, 'learn/take', [
            'page' => 'learn',
            'bare' => true,
            'course' => $this->db->first('SELECT * FROM {courses} WHERE id = ?', [(int) $quiz['course_id']]),
            'quiz' => $quiz,
            'attempt' => $attempt,
            'questions' => $view,
            'timeLimit' => $quiz['time_limit_minutes'] ? (int) $quiz['time_limit_minutes'] : null,
            'startedAt' => strtotime((string) $attempt['started_at']),
        ]);
    }

    public function answer(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null) || $attempt['status'] !== 'in_progress') {
            return $this->json($response, ['ok' => false], 419);
        }

        $questionId = (int) ($data['question'] ?? 0);
        $valid = $this->db->int('SELECT COUNT(*) FROM {quiz_questions} WHERE id = ? AND quiz_id = ?', [$questionId, (int) $quiz['id']]);
        if ($valid === 0) {
            return $this->json($response, ['ok' => false], 422);
        }

        $choiceId = isset($data['choice']) && $data['choice'] !== '' ? (int) $data['choice'] : null;
        if ($choiceId !== null) {
            $ok = $this->db->int('SELECT COUNT(*) FROM {quiz_choices} WHERE id = ? AND question_id = ?', [$choiceId, $questionId]);
            if ($ok === 0) {
                $choiceId = null;
            }
        }
        $text = isset($data['text']) ? mb_substr(trim((string) $data['text']), 0, 4000) : null;

        $this->attempts->saveAnswer((int) $attempt['id'], $questionId, $choiceId, $text ?: null);

        return $this->json($response, ['ok' => true]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, "/learn/attempts/{$attempt['id']}");
        }

        if ($attempt['status'] === 'in_progress') {
            $this->attempts->grade((int) $attempt['id'], (int) $quiz['id']);
        }

        return $this->redirect($response, "/learn/attempts/{$attempt['id']}/result");
    }

    public function result(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        if ($attempt['status'] === 'in_progress') {
            return $this->redirect($response, "/learn/attempts/{$attempt['id']}");
        }

        $duration = $attempt['submitted_at']
            ? strtotime((string) $attempt['submitted_at']) - strtotime((string) $attempt['started_at'])
            : 0;

        return $this->view->render($response, 'learn/result', [
            'page' => 'learn',
            'course' => $this->db->first('SELECT * FROM {courses} WHERE id = ?', [(int) $quiz['course_id']]),
            'quiz' => $quiz,
            'attempt' => $attempt,
            'graded' => $attempt['status'] === 'graded',
            'durationText' => $this->humanDuration($duration),
        ]);
    }

    // ---- ภายใน ----

    /** @return array<string,mixed>|null รายวิชาพร้อมชื่อครูและสีปก */
    private function courseRow(int $courseId): ?array
    {
        $course = $this->db->first(
            'SELECT c.*, u.full_name AS teacher_name FROM {courses} c LEFT JOIN {users} u ON u.id = c.teacher_id WHERE c.id = ?',
            [$courseId]
        );

        return $course === null ? null : Cover::decorate($course);
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} รายวิชา + หน่วยที่นักเรียนคนนี้เปิดได้ */
    private function studentUnit(Request $request, int $unitId, int $studentId): array
    {
        $unit = $this->units->find($unitId);
        if ($unit === null || $unit['review_status'] !== 'published') {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }
        $this->requireEnrolled($request, (int) $unit['course_id'], $studentId);
        $course = $this->courseRow((int) $unit['course_id']);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }

        return [$course, $unit];
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} รายวิชา + หน่วยของครูเจ้าของรายวิชา (ทุกสถานะ) */
    private function teacherUnit(Request $request, int $courseId, int $unitId, int $teacherId): array
    {
        $course = $this->courseRow($courseId);
        if ($course === null || (int) $course['teacher_id'] !== $teacherId) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        $unit = $this->units->find($unitId);
        if ($unit === null || (int) $unit['course_id'] !== $courseId) {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }

        return [$course, $unit];
    }

    /**
     * ข้อมูลหน้ารายวิชา: หน่วยที่เผยแพร่แล้ว ความคืบหน้ารวม และหน่วยถัดไปที่ควรเรียน
     * แบบทดสอบที่ไม่ผูกหน่วย (หรือหน่วยยังไม่เผยแพร่) รวมไว้ท้ายหน้า เพื่อไม่ให้ชุดที่เผยแพร่แล้วหายไปจากสายตานักเรียน
     *
     * @param array<string,mixed> $course
     * @return array<string,mixed>
     */
    private function courseContext(array $course, int $studentId, bool $preview): array
    {
        $courseId = (int) $course['id'];
        $units = $this->numberUnits(array_values(array_filter(
            $this->units->forCourse($courseId),
            static fn (array $u): bool => $u['review_status'] === 'published'
        )));
        $path = $this->path->build($courseId, $studentId, $units,
            $this->decorateQuizzes($this->quizzes->publishedForStudent($courseId, $studentId)));

        $allItems = array_merge(array_merge([], ...array_column($path['units'], 'items')), $path['loose']);
        $next = null;
        foreach ($units as $u) {
            $p = $path['units'][(int) $u['id']];
            if ($p['done'] < $p['total']) {
                $next = $u;
                break;
            }
        }

        return [
            'preview' => $preview,
            'course' => $course,
            'units' => $units,
            'path' => $path['units'],
            'looseQuizzes' => $path['loose'],
            'totals' => LearnPathRepository::summary($allItems),
            'typeCounts' => $this->typeCounts($allItems),
            'nextUnit' => $next,
            // ลิงก์ไปหน้าหน่วย: นักเรียน /learn/units/{id} · ครู /courses/{c}/units/{id}/preview
            'unitBase' => $preview ? '/courses/' . $courseId . '/units/' : '/learn/units/',
            'unitSuffix' => $preview ? '/preview' : '',
        ];
    }

    /**
     * ข้อมูลหน้าหน่วย: เลขหน่วย รายการเรียงตามลำดับ ความคืบหน้า และรายการถัดไปที่ควรเรียน
     *
     * @param array<string,mixed> $course
     * @param array<string,mixed> $unit
     * @return array<string,mixed>
     */
    private function unitContext(array $course, array $unit, int $studentId, bool $preview): array
    {
        $courseId = (int) $course['id'];
        $units = $this->numberUnits(array_values(array_filter(
            $this->units->forCourse($courseId),
            static fn (array $u): bool => $u['review_status'] === 'published' || (int) $u['id'] === (int) $unit['id']
        )));
        $current = $unit;
        foreach ($units as $u) {
            if ((int) $u['id'] === (int) $unit['id']) {
                $current = $u;
            }
        }

        $path = $this->path->build($courseId, $studentId, $units,
            $this->decorateQuizzes($this->quizzes->publishedForStudent($courseId, $studentId)));
        $p = $path['units'][(int) $unit['id']] ?? ['items' => [], 'done' => 0, 'total' => 0, 'minutes' => 0];

        $next = null;
        foreach ($p['items'] as $it) {
            if ($it['counts'] && !$it['done']) {
                $next = $it;
                break;
            }
        }

        return [
            'preview' => $preview,
            'course' => $course,
            'unit' => $current,
            'items' => $p['items'],
            'progress' => $p,
            'typeCounts' => $this->typeCounts($p['items']),
            'nextItem' => $next,
            'itemBase' => $preview
                ? '/courses/' . $courseId . '/units/' . $unit['id'] . '/preview'
                : '/learn/units/' . $unit['id'],
        ];
    }

    /**
     * @param array<string,mixed> $course
     * @param array<string,mixed> $unit
     */
    private function renderItem(Request $request, Response $response, array $args, array $course, array $unit, int $studentId, bool $preview): Response
    {
        $ctx = $this->unitContext($course, $unit, $studentId, $preview);
        $kind = (string) $args['kind'];
        $itemId = (int) $args['itemId'];

        $pos = null;
        foreach ($ctx['items'] as $i => $it) {
            if ($it['kind'] === $kind && $it['id'] === $itemId) {
                $pos = $i;
            }
        }
        if ($pos === null) {
            throw new HttpNotFoundException($request, 'ไม่พบเนื้อหานี้');
        }

        $record = $kind === 'section'
            ? $this->units->findSection($itemId)
            : $this->db->first('SELECT * FROM {assignments} WHERE id = ?', [$itemId]);

        return $this->view->render($response, 'learn/item', [
            'page' => $preview ? 'courses' : 'learn',
            'item' => $ctx['items'][$pos],
            'record' => $record,
            'position' => $pos + 1,
            'prevItem' => $ctx['items'][$pos - 1] ?? null,
            'nextInPath' => $ctx['items'][$pos + 1] ?? null,
        ] + $ctx);
    }

    /**
     * เติมเลขหน่วย (หน่วยสอบไม่นับเลข) ให้ตรงกับหน้าครู
     *
     * @param list<array<string,mixed>> $units
     * @return list<array<string,mixed>>
     */
    private function numberUnits(array $units): array
    {
        $no = 0;
        foreach ($units as $i => $u) {
            $exam = UnitRepository::isExam($u);
            $units[$i]['is_exam'] = $exam;
            $units[$i]['no'] = $exam ? null : ++$no;
            $units[$i]['kind_label'] = $exam ? UnitRepository::KINDS[$u['kind']] : 'หน่วยที่ ' . $no;
        }

        return $units;
    }

    /**
     * จำนวนรายการแยกตามประเภท เช่น "2 บทความ, 1 วิดีโอ และ 2 แบบทดสอบ"
     *
     * @param list<array<string,mixed>> $items
     */
    private function typeCounts(array $items): string
    {
        $counts = [];
        foreach ($items as $it) {
            $label = match ($it['kind']) {
                'section' => $it['label'],
                'quiz' => 'แบบทดสอบ',
                default => 'ใบงาน',
            };
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = $n . ' ' . $label;
        }
        $last = array_pop($parts);

        return $parts ? implode(', ', $parts) . ' และ ' . $last : (string) $last;
    }


    /**
     * เติมข้อความที่หน้ารายการแบบทดสอบใช้: ชนิด ปุ่ม และสถานะการทำของนักเรียน
     *
     * @param list<array<string,mixed>> $quizzes
     * @return list<array<string,mixed>>
     */
    private function decorateQuizzes(array $quizzes): array
    {
        $num = static fn (mixed $v): string => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');

        foreach ($quizzes as $i => $q) {
            $done = (int) $q['done_count'];
            $meta = [(int) $q['question_count'] . ' ข้อ'];
            if ($q['time_limit_minutes']) {
                $meta[] = 'เวลา ' . (int) $q['time_limit_minutes'] . ' นาที';
            }

            $quizzes[$i]['kind_label'] = $q['kind'] === 'pretest' ? 'ก่อนเรียน' : 'หลังเรียน';
            $quizzes[$i]['meta'] = implode(' · ', $meta);
            $quizzes[$i]['action'] = $q['open_attempt_id'] ? 'ทำต่อ' : ($done > 0 ? 'ทำอีกครั้ง' : 'เริ่มทำ');
            $quizzes[$i]['state'] = match (true) {
                $q['best_score'] !== null => 'คะแนนสูงสุด ' . $num($q['best_score']) . ' / ' . $num($q['best_max_score']),
                $done > 0 => 'ส่งแล้ว · รอครูตรวจ',
                $q['open_attempt_id'] !== null => 'กำลังทำอยู่',
                default => 'ยังไม่ได้ทำ',
            };
            $quizzes[$i]['state_kind'] = $q['best_score'] !== null ? 'ok' : ($done > 0 || $q['open_attempt_id'] ? 'warn' : 'muted');
        }

        return $quizzes;
    }

    private function requireEnrolled(Request $request, int $courseId, int $studentId): void
    {
        if (!$this->enrollments->isEnrolled($courseId, $studentId)) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้ลงทะเบียนในรายวิชานี้');
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function requireOwnAttempt(Request $request, int $attemptId, int $studentId): array
    {
        $attempt = $this->attempts->find($attemptId);
        if ($attempt === null || (int) $attempt['student_id'] !== $studentId) {
            throw new HttpNotFoundException($request, 'ไม่พบการทำแบบทดสอบนี้');
        }
        $quiz = $this->quizzes->find((int) $attempt['quiz_id']);
        if ($quiz === null) {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }

        return [$attempt, $quiz];
    }

    private function humanDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $m > 0 ? sprintf('%d นาที %d วินาที', $m, $s) : sprintf('%d วินาที', $s);
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
