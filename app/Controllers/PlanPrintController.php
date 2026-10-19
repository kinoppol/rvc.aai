<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AssignmentRepository;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\Db;
use App\Support\Semester;
use App\Support\Thai;
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

        // ชั่วโมงต่อสัปดาห์ = ท + ป (น คือหน่วยกิต)
        $weekly = (int) $course['theory_hours'] + (int) $course['practice_hours'];
        $units = $this->units->forCourse((int) $course['id']);
        $kinds = array_column($units, 'kind');
        $weeks = (int) ($course['weeks'] ?? 0) ?: Semester::defaultWeeks(
            (string) $course['code'],
            in_array('midterm', $kinds, true),
            in_array('final', $kinds, true)
        );
        $schedule = $this->schedule($units, $weeks, $weekly);
        $totalHours = array_sum(array_column($schedule, 'hours'));

        $reports = $this->units->reportsForCourse((int) $course['id']);
        $plans = [];
        $lessonNo = 0;
        foreach ($units as $i => $u) {
            $exam = UnitRepository::isExam($u);
            $unitId = (int) $u['id'];
            $sections = $this->units->sectionsFor($unitId);
            $quizzes = $this->db->all(
                'SELECT q.title, q.kind, (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count
                 FROM {quizzes} q WHERE q.unit_id = ? AND q.deleted_at IS NULL ORDER BY q.kind = \'posttest\', q.id',
                [$unitId]
            );
            $plans[] = [
                // หน่วยสอบไม่นับเลขหน่วย และไม่มีแผนรายหน่วย (มีแต่ในตารางเวลา)
                'no'           => $exam ? UnitRepository::KINDS[$u['kind']] : ++$lessonNo,
                'exam'         => $exam,
                'unit'         => $u,
                'hours'        => $schedule[$i]['hours'],
                'week_from'    => $schedule[$i]['from'],
                'week_to'      => $schedule[$i]['to'],
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
                // ข้อ 9 บันทึกหลังสอน: ข้อความที่ครูบันทึกไว้ และวันที่บันทึก (ไม่ใช่วันที่จัดทำแผน)
                'report'       => [
                    'plan'     => $this->lines($reports[$unitId]['plan_result'] ?? null),
                    'learner'  => $this->lines($reports[$unitId]['learner_result'] ?? null),
                    'problems' => $this->lines($reports[$unitId]['problems'] ?? null),
                    'date'     => Thai::formalDate($reports[$unitId]['recorded_on'] ?? null),
                ],
            ];
        }

        return $this->view->render($response, 'plan/print', [
            'course'      => $course,
            'term'        => $term,
            'teacher'     => $teacher,
            'weekly'      => $weekly,
            'weeks'       => $weeks,
            'lastWeek'    => max([0, ...array_column($schedule, 'to')]),
            'totalHours'  => $totalHours,
            'plans'       => $plans,
            'description' => $this->lines($course['description']),
            'courseObjectives'   => $this->lines($course['objectives'] ?? null),
            'courseCompetencies' => $this->lines($course['competencies'] ?? null),
            'courseOutcomes'     => $this->lines($course['learning_outcomes'] ?? null),
            'planDate'           => Thai::formalDate($course['plan_date'] ?? null),
            'level'              => Semester::level((string) $course['code'])['label'],
        ]);
    }

    /**
     * วางสัปดาห์และชั่วโมงของทุกหน่วยตามลำดับ
     *
     * หน่วยสอบใช้ 1 สัปดาห์เต็มเสมอ · หน่วยการเรียนที่ครูกำหนดสัปดาห์ไว้ (units.weeks) ใช้ตามนั้น
     * ที่เหลือแบ่งสัปดาห์ที่ยังว่างให้เท่า ๆ กันเป็นจำนวนเต็มสัปดาห์ — ถ้าหน่วยมากกว่าสัปดาห์ที่ว่าง
     * จึงแบ่งเป็นชั่วโมงแทน (หลายหน่วยอยู่ในสัปดาห์เดียวกันได้) ตารางจึงไม่ล้นจำนวนสัปดาห์ของภาค
     *
     * @param list<array<string,mixed>> $units
     * @return list<array{hours:int, from:int, to:int}>
     */
    private function schedule(array $units, int $weeks, int $weekly): array
    {
        // คำนวณตำแหน่งเป็นชั่วโมง · วิชาที่ยังไม่กรอก ท/ป ถือว่าสัปดาห์ละ 1 หน่วยไว้วางตาราง แต่แสดง 0 ชั่วโมง
        $per = max(1, $weekly);
        $exams = count(array_filter($units, [UnitRepository::class, 'isExam']));
        $fixed = 0;
        $open = [];
        foreach ($units as $i => $u) {
            if (UnitRepository::isExam($u)) {
                continue;
            }
            if ((int) ($u['weeks'] ?? 0) > 0) {
                $fixed += (int) $u['weeks'];
            } else {
                $open[] = $i;
            }
        }

        $free = max(0, $weeks - $exams - $fixed);
        $shares = count($open) <= $free
            ? array_map(static fn (int $w): int => $w * $per, Semester::spread($free, count($open)))
            : Semester::spread($free * $per, count($open));
        $openHours = $open === [] ? [] : array_combine($open, $shares);

        $out = [];
        $cursor = 0;
        foreach ($units as $i => $u) {
            if (UnitRepository::isExam($u)) {
                $cursor = (int) ceil($cursor / $per) * $per; // สอบเริ่มต้นสัปดาห์ใหม่เสมอ
                $h = $per;
            } else {
                $h = isset($openHours[$i]) ? $openHours[$i] : (int) $u['weeks'] * $per;
            }

            $from = intdiv($cursor, $per) + 1;
            $cursor += $h;
            $out[$i] = [
                'hours' => $weekly > 0 ? $h : 0,
                'from' => $from,
                'to' => max($from, (int) ceil($cursor / $per)),
            ];
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
