<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AttemptRepository;
use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\QuizRepository;
use App\Domain\UnitRepository;
use App\Support\Db;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * ผลคะแนนของแบบทดสอบหนึ่งชุด (ครูเจ้าของรายวิชา) — ใช้ได้กับทุกประเภท รวมถึงแบบทดสอบก่อนเรียนที่ไม่นำไปคิดคะแนน
 *
 *   GET /courses/{c}/quizzes/{quizId}/results
 *
 * คะแนนหลักของแต่ละคน: ก่อนเรียน = ครั้งแรก (วัดพื้นฐานก่อนเรียนจริง ๆ) · หลังเรียน/สอบ = ครั้งที่ดีที่สุด
 * ถ้าหน่วยเดียวกันมีแบบทดสอบอีกฝั่ง (ก่อน ↔ หลัง) จะแสดงเทียบกันเป็นร้อยละ เพื่อดูพัฒนาการ
 */
final class QuizResultsController
{
    public function __construct(
        private readonly View $view,
        private readonly Db $db,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly QuizRepository $quizzes,
        private readonly EnrollmentRepository $enrollments,
        private readonly AttemptRepository $attempts,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $course = $this->courses->find((int) $args['courseId']);
        if ($course === null || (int) $course['teacher_id'] !== (int) $request->getAttribute('user')['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        $quiz = $this->quizzes->find((int) $args['quizId']);
        if ($quiz === null || (int) $quiz['course_id'] !== (int) $course['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }

        $unit = $quiz['unit_id'] ? $this->units->find((int) $quiz['unit_id']) : null;
        $isExam = $unit !== null && UnitRepository::isExam($unit);
        $isPre = $quiz['kind'] === 'pretest' && !$isExam;
        $questions = $this->quizzes->questions((int) $quiz['id']);
        $fullScore = array_sum(array_map(static fn (array $q): float => (float) $q['score'], $questions));
        $passPct = (float) $course['pass_threshold'];

        // แบบทดสอบอีกฝั่งของหน่วยเดียวกัน (ก่อน ↔ หลัง) ไว้เทียบพัฒนาการ
        $pair = null;
        if ($unit !== null && !$isExam) {
            $pair = $this->db->first(
                'SELECT id, title, kind FROM {quizzes}
                 WHERE unit_id = ? AND kind = ? AND deleted_at IS NULL
                 ORDER BY review_status = \'published\' DESC, created_at LIMIT 1',
                [(int) $unit['id'], $isPre ? 'posttest' : 'pretest']
            );
        }
        $pairPct = [];
        if ($pair !== null) {
            foreach ($this->byStudent($this->attempts->submittedForQuiz((int) $pair['id'])) as $sid => $list) {
                $main = $this->mainAttempt($list, $pair['kind'] === 'pretest');
                if ($main !== null && (float) $main['max_score'] > 0) {
                    $pairPct[$sid] = (float) $main['score'] / (float) $main['max_score'] * 100;
                }
            }
        }

        // รายชื่อ: นักเรียนในรายวิชาทุกคน + คนที่เคยทำแต่ออกจากรายวิชาไปแล้ว
        $byStudent = $this->byStudent($this->attempts->submittedForQuiz((int) $quiz['id']));
        $roster = [];
        foreach ($this->enrollments->studentsInCourse((int) $course['id']) as $s) {
            $roster[(int) $s['id']] = ['id' => (int) $s['id'], 'username' => $s['username'], 'full_name' => $s['full_name'], 'left' => false];
        }
        foreach ($byStudent as $sid => $list) {
            $roster[$sid] ??= ['id' => $sid, 'username' => $list[0]['username'], 'full_name' => $list[0]['full_name'], 'left' => true];
        }

        $rows = [];
        $mainIds = [];
        $pcts = [];
        $gains = [];
        $waiting = 0;
        foreach ($roster as $sid => $s) {
            $list = $byStudent[$sid] ?? [];
            $main = $this->mainAttempt($list, $isPre);
            $pct = $main !== null && (float) $main['max_score'] > 0 ? (float) $main['score'] / (float) $main['max_score'] * 100 : null;
            $other = $pairPct[$sid] ?? null;
            $gain = $pct !== null && $other !== null ? ($isPre ? $other - $pct : $pct - $other) : null;
            if ($main !== null) {
                $mainIds[] = (int) $main['id'];
                if ($pct !== null) {
                    $pcts[] = $pct;
                }
                if ($gain !== null) {
                    $gains[] = $gain;
                }
            }
            $pendingManual = array_filter($list, static fn (array $a): bool => $a['status'] === 'submitted') !== [];
            $waiting += $pendingManual ? 1 : 0;
            $rows[] = $s + [
                'tries'   => count($list),
                'first'   => $list[0] ?? null,
                'best'    => $this->mainAttempt($list, false),
                'last_at' => $list === [] ? null : max(array_column($list, 'submitted_at')),
                'pct'     => $pct,
                'pair'    => $other,
                'gain'    => $gain,
                'pending' => $pendingManual,
            ];
        }
        // ทำแล้วขึ้นก่อน เรียงตามรหัส · ยังไม่ทำอยู่ท้าย
        usort($rows, static fn (array $a, array $b): int => [$a['tries'] === 0, $a['left'], $a['username']] <=> [$b['tries'] === 0, $b['left'], $b['username']]);

        // ร้อยละที่ตอบถูกต่อข้อ (นับจากคะแนนหลักของแต่ละคน) — ข้ออัตนัยครูให้คะแนนเอง จึงไม่นับ
        $correct = $this->attempts->correctByQuestion($mainIds);
        $items = [];
        foreach ($questions as $i => $q) {
            $auto = $q['type'] === 'choice' || $q['type'] === 'multi_choice';
            $items[] = [
                'no'       => $i + 1,
                'question' => $q['question'],
                'auto'     => $auto,
                'pct'      => $auto && $mainIds !== [] ? ($correct[(int) $q['id']] ?? 0) / count($mainIds) * 100 : null,
            ];
        }

        $taken = count($mainIds);

        return $this->view->render($response, 'quiz/results', [
            'page'      => 'courses',
            'course'    => $course,
            'quiz'      => $quiz,
            'unit'      => $unit,
            'isExam'    => $isExam,
            'isPre'     => $isPre,
            'pair'      => $pair,
            'rows'      => $rows,
            'items'     => $items,
            'fullScore' => $fullScore,
            'passPct'   => $passPct,
            'stats'     => [
                'students' => count(array_filter($roster, static fn (array $s): bool => !$s['left'])),
                'taken'    => $taken,
                'avg'      => $pcts === [] ? null : array_sum($pcts) / count($pcts),
                'max'      => $pcts === [] ? null : max($pcts),
                'min'      => $pcts === [] ? null : min($pcts),
                'passed'   => count(array_filter($pcts, static fn (float $p): bool => $p >= $passPct)),
                'gain'     => $gains === [] ? null : array_sum($gains) / count($gains),
                'gainN'    => count($gains),
                'waiting'  => $waiting,
            ],
        ]);
    }

    /**
     * @param list<array<string,mixed>> $attempts
     * @return array<int,list<array<string,mixed>>>
     */
    private function byStudent(array $attempts): array
    {
        $out = [];
        foreach ($attempts as $a) {
            $out[(int) $a['student_id']][] = $a;
        }

        return $out;
    }

    /**
     * ครั้งที่ใช้เป็นคะแนนหลัก: ครั้งแรก (ก่อนเรียน) หรือครั้งที่ได้คะแนนสูงสุด
     *
     * @param list<array<string,mixed>> $list เรียงตาม attempt_no แล้ว
     * @return array<string,mixed>|null
     */
    private function mainAttempt(array $list, bool $first): ?array
    {
        if ($list === []) {
            return null;
        }
        if ($first) {
            return $list[0];
        }
        $best = $list[0];
        foreach ($list as $a) {
            if ((float) $a['score'] > (float) $best['score']) {
                $best = $a;
            }
        }

        return $best;
    }
}
