<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AttemptRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\UnitRepository;
use App\Domain\QuizRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

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

        $course = $this->db->first('SELECT c.*, u.full_name AS teacher_name FROM {courses} c LEFT JOIN {users} u ON u.id = c.teacher_id WHERE c.id = ?', [$courseId]);

        $units = array_values(array_filter(
            $this->units->forCourse($courseId),
            static fn (array $u): bool => $u['review_status'] === 'published'
        ));
        $publishedUnitIds = array_map(static fn (array $u): int => (int) $u['id'], $units);

        // แบบทดสอบของหน่วยที่เผยแพร่แล้วแสดงใต้หน่วยนั้น ส่วนที่ไม่ผูกหน่วย (หรือหน่วยยังไม่เผยแพร่) รวมไว้ท้ายหน้า
        // เพื่อไม่ให้แบบทดสอบที่ครูเผยแพร่แล้วหายไปจากสายตานักเรียน
        $quizzesByUnit = [];
        $otherQuizzes = [];
        foreach ($this->decorateQuizzes($this->quizzes->publishedForStudent($courseId, (int) $user['id'])) as $q) {
            if ($q['unit_id'] !== null && in_array((int) $q['unit_id'], $publishedUnitIds, true)) {
                $quizzesByUnit[(int) $q['unit_id']][] = $q;
            } else {
                $otherQuizzes[] = $q;
            }
        }

        return $this->view->render($response, 'learn/course', [
            'page' => 'learn',
            'course' => $course,
            'units' => $units,
            'quizzesByUnit' => $quizzesByUnit,
            'otherQuizzes' => $otherQuizzes,
        ]);
    }

    public function unit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $unit = $this->units->find((int) $args['id']);
        if ($unit === null || $unit['review_status'] !== 'published') {
            throw new HttpNotFoundException($request, 'ไม่พบหน่วยการเรียนนี้');
        }
        $this->requireEnrolled($request, (int) $unit['course_id'], (int) $user['id']);
        $course = $this->db->first('SELECT * FROM {courses} WHERE id = ?', [(int) $unit['course_id']]);
        $sections = $this->units->sectionsFor((int) $unit['id']);

        $quizzes = array_values(array_filter(
            $this->decorateQuizzes($this->quizzes->publishedForStudent((int) $unit['course_id'], (int) $user['id'])),
            static fn (array $q): bool => (int) $q['unit_id'] === (int) $unit['id']
        ));

        return $this->view->render($response, 'learn/unit', [
            'page' => 'learn',
            'course' => $course,
            'unit' => $unit,
            'sections' => $sections,
            'pretests' => array_values(array_filter($quizzes, static fn (array $q): bool => $q['kind'] === 'pretest')),
            'posttests' => array_values(array_filter($quizzes, static fn (array $q): bool => $q['kind'] !== 'pretest')),
        ]);
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
