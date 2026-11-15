<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiUnavailableException;
use App\AI\Drafter;
use App\Auth\Auth;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\BehaviorAnalysis;
use App\Support\Db;
use App\Support\Semester;
use App\Support\Csrf;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ให้ผู้ช่วย AI สรุป "ผลลัพธ์การเรียนรู้ระดับรายวิชา" จากข้อมูลที่ครูกรอกอยู่บนฟอร์มรายวิชา
 * (ส่งค่าจากฟอร์มมาตรง ๆ จึงใช้ได้ทั้งตอนสร้างวิชาใหม่และตอนแก้ไข โดยไม่ต้องบันทึกก่อน)
 * ตอบเป็น JSON ให้สคริปต์บนหน้าเติมลงช่อง — ยังไม่บันทึกอะไร ครูแก้แล้วกดบันทึกเอง
 */
final class CourseOutcomesController
{
    public function __construct(
        private readonly Drafter $drafter,
        private readonly CourseRepository $courses,
        private readonly Auth $auth,
        private readonly LoggerInterface $logger,
        private readonly UnitRepository $units,
        private readonly Db $db,
    ) {
    }

    public function draft(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'], 419);
        }

        // แก้ไขวิชาเดิม: เคารพสวิตช์ปิด AI รายวิชาของผู้ดูแลระบบ
        $courseId = (int) ($data['course_id'] ?? 0);
        if ($courseId > 0) {
            $course = $this->courses->find($courseId);
            if ($course === null || (int) $course['teacher_id'] !== (int) $user['id']) {
                return $this->json($response, ['ok' => false, 'error' => 'ไม่พบรายวิชานี้'], 404);
            }
            if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
                return $this->json($response, ['ok' => false, 'error' => 'ผู้ดูแลระบบปิดฟังก์ชัน AI ของรายวิชานี้ไว้'], 403);
            }
        }

        $field = static fn (string $k, int $max = 8000): string => mb_substr(trim((string) ($data[$k] ?? '')), 0, $max);
        $spec = [
            'task' => 'course_outcomes',
            'course_code' => $field('code', 32),
            'course_name' => $field('name', 191),
            'description' => $field('description'),
            'objectives' => $field('objectives'),
            'competencies' => $field('competencies'),
            'count' => max(2, min(8, (int) ($data['count'] ?? 4))),
        ];
        if ($spec['course_name'] === '' && $spec['description'] === '') {
            return $this->json($response, ['ok' => false, 'error' => 'กรอกชื่อวิชาหรือคำอธิบายรายวิชาก่อน ผู้ช่วยจึงสรุปได้'], 422);
        }

        // ต้องมีข้อความกำกับระบบเสมอ — ผู้ให้บริการบางรายปฏิเสธคำขอที่ system prompt ว่างเปล่า
        $system = 'คุณเป็นผู้เชี่ยวชาญหลักสูตรอาชีวศึกษา สอศ. เขียนผลลัพธ์การเรียนรู้ระดับรายวิชาเป็นภาษาไทย '
            . 'ตอบกลับเป็น NDJSON หนึ่งข้อต่อหนึ่งบรรทัด';

        try {
            $objects = $this->drafter->objects((int) $user['id'], $system, $spec, $spec['count'] + 2);
        } catch (AiUnavailableException $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            $this->logger->error('ร่างผลลัพธ์การเรียนรู้ระดับรายวิชาไม่สำเร็จ: ' . $e->getMessage(), [
                'course_id' => $courseId ?: null,
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยสรุปไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'], 500);
        }

        // โมเดลมักตั้งชื่อคีย์ต่างกันไป — รับข้อความจากคีย์ที่พบบ่อย ตัดเลขข้อนำหน้า และไม่เอาซ้ำ
        $lines = [];
        foreach ($objects as $obj) {
            $text = Drafter::text($obj['outcome'] ?? $obj['clo'] ?? $obj['text'] ?? $obj['outcomes'] ?? '');
            foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
                $line = trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)]|[๑-๙]+[.)]|CLO\s*\d+[.:)]?)\s*/iu', '', $line));
                if ($line !== '' && !in_array($line, $lines, true)) {
                    $lines[] = mb_substr($line, 0, 500);
                }
            }
        }

        if ($lines === []) {
            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยไม่ได้ส่งผลลัพธ์การเรียนรู้กลับมา กรุณาลองใหม่'], 502);
        }

        $this->auth->log('ai.course_outcomes', $courseId > 0 ? 'course#' . $courseId : 'course#new', ['items' => count($lines)]);

        return $this->json($response, ['ok' => true, 'text' => implode("\n", array_slice($lines, 0, 8))]);
    }

    /**
     * ร่างตารางวิเคราะห์พฤติกรรมการเรียนรู้ (K/S/A/Ap + ร้อยละประเมินผล) จากข้อมูลรายวิชาและหน่วยการเรียนที่บันทึกไว้
     * ค่าคำอธิบาย/จุดประสงค์/สมรรถนะ/ผลลัพธ์ที่ยังไม่บันทึกบนฟอร์มส่งมาทับได้ · ตอบ JSON ให้สคริปต์เติมลงตาราง ยังไม่บันทึก
     */
    public function behavior(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'error' => 'เซสชันหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่'], 419);
        }
        $courseId = (int) $args['id'];
        $course = $this->courses->find($courseId);
        if ($course === null || (int) $course['teacher_id'] !== (int) $user['id']) {
            return $this->json($response, ['ok' => false, 'error' => 'ไม่พบรายวิชานี้'], 404);
        }
        if ((int) ($course['ai_lesson_plan_enabled'] ?? 1) !== 1) {
            return $this->json($response, ['ok' => false, 'error' => 'ผู้ดูแลระบบปิดฟังก์ชัน AI ของรายวิชานี้ไว้'], 403);
        }

        $lessons = array_values(array_filter(
            $this->units->forCourse($courseId),
            static fn (array $u): bool => !UnitRepository::isExam($u)
        ));
        if ($lessons === []) {
            return $this->json($response, ['ok' => false, 'error' => 'รายวิชานี้ยังไม่มีหน่วยการเรียนรู้ สร้างหน่วยก่อนจึงวิเคราะห์ได้'], 422);
        }

        // หัวข้อเนื้อหาและใบงานของแต่ละหน่วย ช่วยให้ AI ประเมินระดับและน้ำหนักได้ตรงขึ้น
        $ids = array_map(static fn (array $u): int => (int) $u['id'], $lessons);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $topics = [];
        foreach ($this->db->all("SELECT unit_id, title FROM {unit_sections} WHERE unit_id IN ($marks) ORDER BY sort_order, id", $ids) as $r) {
            $topics[(int) $r['unit_id']][] = $r['title'];
        }
        $sheets = [];
        foreach ($this->db->all("SELECT unit_id, title FROM {assignments} WHERE unit_id IN ($marks) AND kind = 'worksheet' ORDER BY id", $ids) as $r) {
            $sheets[(int) $r['unit_id']][] = $r['title'];
        }

        $weekly = (int) $course['theory_hours'] + (int) $course['practice_hours'];
        $short = static fn (?string $v, int $max = 600): string => mb_substr(trim((string) $v), 0, $max);
        $units = [];
        foreach ($lessons as $i => $u) {
            $units[] = [
                'no' => $i + 1,
                'title' => $u['title'],
                'hours' => !empty($u['weeks']) ? (int) $u['weeks'] * $weekly : 0,
                'key_content' => $short($u['key_content'] ?? ''),
                'objectives' => $short($u['objectives'] ?? ''),
                'competencies' => $short($u['competencies'] ?? ''),
                'topics' => mb_substr(implode(' · ', $topics[(int) $u['id']] ?? []), 0, 400),
                'worksheets' => mb_substr(implode(' · ', $sheets[(int) $u['id']] ?? []), 0, 300),
            ];
        }
        $posted = static fn (string $k, ?string $saved): string => mb_substr(trim((string) ($data[$k] ?? $saved ?? '')), 0, 4000);
        $spec = [
            'task' => 'behavior_analysis',
            'course_code' => $course['code'],
            'course_name' => $course['name'],
            'level' => Semester::level((string) $course['code'])['label'],
            'theory' => (int) $course['theory_hours'],
            'practice' => (int) $course['practice_hours'],
            'description' => $posted('description', $course['description'] ?? null),
            'objectives' => $posted('objectives', $course['objectives'] ?? null),
            'competencies' => $posted('competencies', $course['competencies'] ?? null),
            'outcomes' => $posted('learning_outcomes', $course['learning_outcomes'] ?? null),
            'units' => $units,
        ];
        $system = 'คุณเป็นผู้เชี่ยวชาญหลักสูตรและการวัดผลอาชีวศึกษา สอศ. วิเคราะห์ระดับพฤติกรรมการเรียนรู้ตามหลักการวัดผล '
            . 'ตอบกลับเป็น NDJSON หนึ่งหน่วยต่อหนึ่งบรรทัด';

        try {
            $objects = $this->drafter->objects((int) $user['id'], $system, $spec, count($lessons) + 4);
        } catch (AiUnavailableException $e) {
            return $this->json($response, ['ok' => false, 'error' => $e->getMessage()], 503);
        } catch (Throwable $e) {
            $this->logger->error('ร่างตารางวิเคราะห์พฤติกรรมการเรียนรู้ไม่สำเร็จ: ' . $e->getMessage(), [
                'course_id' => $courseId,
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);

            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยวิเคราะห์ไม่สำเร็จ กรุณาลองใหม่อีกครั้ง'], 500);
        }

        // จับคู่เลขหน่วยในคำตอบกับหน่วยจริงตามลำดับ · ไม่มีเลขหน่วย = ใช้ลำดับที่ตอบมา
        $rows = [];
        $outcome = null;
        $next = 0;
        foreach ($objects as $obj) {
            if (isset($obj['outcome_weight']) && !isset($obj['k'])) {
                $outcome = is_numeric($obj['outcome_weight']) ? (int) round((float) $obj['outcome_weight']) : null;
                continue;
            }
            $row = BehaviorAnalysis::cleanRow($obj);
            if ($row === null) {
                continue;
            }
            $index = is_numeric($obj['unit'] ?? null) ? (int) $obj['unit'] - 1 : $next;
            if (isset($lessons[$index]) && !isset($rows[(int) $lessons[$index]['id']])) {
                $rows[(int) $lessons[$index]['id']] = $row;
            }
            $next = $index + 1;
        }
        if ($rows === []) {
            return $this->json($response, ['ok' => false, 'error' => 'ผู้ช่วยไม่ได้ส่งผลวิเคราะห์กลับมา กรุณาลองใหม่'], 502);
        }

        // ให้ร้อยละรวมเป็น 100 พอดี (โมเดลมักบวกไม่ลงตัว) · ไม่บอกร้อยละผลลัพธ์ระดับรายวิชา = 20
        $weights = array_map(static fn (array $r): int => (int) ($r['weight'] ?? 0), $rows);
        $weights['outcome'] = $outcome ?? 20;
        $weights = BehaviorAnalysis::scaleTo100($weights);
        foreach (array_keys($rows) as $id) {
            $rows[$id]['weight'] = $weights[$id];
        }

        $this->auth->log('ai.behavior_analysis', 'course#' . $courseId, ['units' => count($rows)]);

        return $this->json($response, [
            'ok' => true,
            'units' => (object) $rows,
            'outcome_weight' => $weights['outcome'],
            'missing' => count($lessons) - count($rows),
        ]);
    }

    /** @param array<string,mixed> $body */
    private function json(Response $response, array $body, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($body, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
