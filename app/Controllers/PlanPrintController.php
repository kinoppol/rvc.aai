<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AssignmentRepository;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\Db;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * พิมพ์แผนการจัดการเรียนรู้มุ่งเน้นสมรรถนะ ตามแบบฟอร์มของ สอศ. หลักสูตร พ.ศ. 2567
 * ดึงข้อมูลจากรายวิชาและหน่วยการเรียนที่ครูวางไว้แล้ว ส่วนที่ระบบยังไม่มีข้อมูล
 * (เช่น ผลลัพธ์การเรียนรู้ระดับรายวิชา) ครูพิมพ์เติมบนหน้าได้ก่อนสั่งพิมพ์
 */
final class PlanPrintController
{
    /** จำนวนสัปดาห์ต่อภาคเรียนตามหลักสูตร สอศ. */
    private const WEEKS = 18;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly AssignmentRepository $assignments,
        private readonly Db $db,
    ) {
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->courses->find((int) $args['id']);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== (int) $user['id']) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        $term = $course['term_id'] !== null
            ? $this->db->first('SELECT academic_year, semester FROM {academic_terms} WHERE id = ?', [$course['term_id']])
            : null;
        $teacher = $this->db->first('SELECT full_name, subject_area FROM {users} WHERE id = ?', [$course['teacher_id']]);

        $weekly = (int) $course['theory_hours'] + (int) $course['practice_hours'];
        $totalHours = $weekly * self::WEEKS;

        $units = $this->units->forCourse((int) $course['id']);

        // หน่วยสอบกลางภาค/ปลายภาคใช้เวลา 1 สัปดาห์ · เวลาที่เหลือแบ่งเท่า ๆ กันให้หน่วยการเรียน
        $examCount = count(array_filter($units, [UnitRepository::class, 'isExam']));
        $lessonHours = $this->spreadHours(max(0, $totalHours - $examCount * $weekly), count($units) - $examCount);

        $plans = [];
        $sessionNo = 1;
        $lessonNo = 0;
        foreach ($units as $u) {
            $exam = UnitRepository::isExam($u);
            $unitId = (int) $u['id'];
            $sections = $this->units->sectionsFor($unitId);
            $quizzes = $this->db->all(
                'SELECT q.title, q.kind, (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count
                 FROM {quizzes} q WHERE q.unit_id = ? ORDER BY q.kind = \'posttest\', q.id',
                [$unitId]
            );
            $h = $exam ? $weekly : $lessonHours[$lessonNo];
            $sessions = $weekly > 0 ? max(1, (int) ceil($h / $weekly)) : 1;

            $plans[] = [
                // หน่วยสอบไม่นับเลขหน่วย และไม่มีแผนรายหน่วย (มีแต่ในตารางเวลา)
                'no'           => $exam ? UnitRepository::KINDS[$u['kind']] : ++$lessonNo,
                'exam'         => $exam,
                'unit'         => $u,
                'hours'        => $h,
                'session_from' => $sessionNo,
                'session_to'   => $sessionNo + $sessions - 1,
                'key_content'  => $this->lines($u['key_content']),
                'objectives'   => $this->lines($u['objectives']),
                'competencies' => $this->lines($u['competencies']),
                'sections'     => array_map(fn (array $s) => [
                    'type'    => $s['type'],
                    'title'   => $s['title'] !== '' ? $s['title'] : ($s['file_name'] ?? 'สื่อประกอบ'),
                    'excerpt' => $this->excerpt((string) ($s['content'] ?? '')),
                    'url'     => $s['video_url'],
                ], $sections),
                'assignments'  => $this->assignments->forUnit($unitId),
                'pretests'     => array_values(array_filter($quizzes, fn ($q) => $q['kind'] === 'pretest')),
                'posttests'    => array_values(array_filter($quizzes, fn ($q) => $q['kind'] === 'posttest')),
            ];
            $sessionNo += $sessions;
        }

        return $this->view->render($response, 'plan/print', [
            'course'      => $course,
            'term'        => $term,
            'teacher'     => $teacher,
            'weekly'      => $weekly,
            'totalHours'  => $totalHours,
            'plans'       => $plans,
            'description' => $this->lines($course['description']),
            'courseObjectives'   => $this->lines($course['objectives'] ?? null),
            'courseCompetencies' => $this->lines($course['competencies'] ?? null),
        ]);
    }

    /**
     * แบ่งชั่วโมงรวมทั้งภาคให้หน่วยการเรียนเท่า ๆ กัน (เศษไปอยู่หน่วยแรก ๆ)
     *
     * @return list<int>
     */
    private function spreadHours(int $total, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        $base = intdiv($total, $count);
        $extra = $total % $count;

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $base + ($i < $extra ? 1 : 0);
        }

        return $out;
    }

    /** @return list<string> แยกข้อความหลายบรรทัดเป็นข้อ ๆ และตัดเลขข้อ/สัญลักษณ์นำหน้าที่ครูพิมพ์มาเอง */
    private function lines(?string $text): array
    {
        $out = [];
        foreach (preg_split('/\R+/u', trim((string) $text)) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)]|[๑-๙]+[.)])\s*/u', '', $line));
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    private function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > 220 ? mb_substr($text, 0, 220) . '…' : $text;
    }
}
