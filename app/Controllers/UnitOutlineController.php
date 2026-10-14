<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiUnavailableException;
use App\AI\Drafter;
use App\Auth\Auth;
use App\Controllers\Concerns\OwnsCourse;
use App\Domain\CourseRepository;
use App\Domain\UnitRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Semester;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ให้ผู้ช่วย AI ออกแบบ "รายชื่อหน่วยการเรียน" ของทั้งรายวิชาให้ครอบคลุมคำอธิบายรายวิชา
 *
 * ทำงานสามจังหวะ: เปิดฟอร์ม → ร่างรายการมาให้ตรวจ (เก็บไว้ในเซสชัน ยังไม่เขียนฐานข้อมูล)
 * → ครูติ๊กเลือกหน่วยที่ต้องการแล้วกดบันทึก จึงสร้างเป็นหน่วยฉบับร่างให้แก้ต่อ
 *
 * ครูกำหนดจำนวนสัปดาห์เรียนรวมวัดผลและเลือกว่ามีสอบกลาง/ปลายภาคหรือไม่ ระบบวางตารางสัปดาห์เอง
 * (App\Support\Semester) แล้วบอกผู้ช่วยว่าแต่ละหน่วยได้กี่สัปดาห์ · ตอนบันทึกจะวางหน่วยสอบให้ด้วย
 */
final class UnitOutlineController
{
    use OwnsCourse;

    private const SESSION_KEY = 'unit_outline';
    private const MAX_UNITS = 20;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly Drafter $drafter,
        private readonly Auth $auth,
        private readonly LoggerInterface $logger,
    ) {
    }

    protected function courseRepo(): CourseRepository
    {
        return $this->courses;
    }

    public function form(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $existing = $this->units->forCourse((int) $course['id']);
        $draft = $_SESSION[self::SESSION_KEY] ?? null;
        $draft = is_array($draft) && (int) ($draft['course_id'] ?? 0) === (int) $course['id'] ? $draft : null;
        $proposed = $draft !== null ? (array) $draft['units'] : [];

        // ค่าเริ่มต้นของการสอบ: ตามหน่วยสอบที่มีอยู่แล้ว · วิชาที่ยังไม่มีหน่วยเลยตั้งให้มีทั้งกลางภาคและปลายภาค
        $kinds = array_column($existing, 'kind');
        $fresh = $existing === [];
        $midterm = (bool) ($draft['midterm'] ?? ($fresh || in_array('midterm', $kinds, true)));
        $final = $midterm || (bool) ($draft['final'] ?? ($fresh || in_array('final', $kinds, true)));
        $weeks = (int) ($draft['weeks'] ?? 0)
            ?: (int) ($course['weeks'] ?? 0)
            ?: Semester::defaultWeeks((string) $course['code'], $midterm, $final);
        $existingLessons = count($existing) - count(array_filter($existing, [UnitRepository::class, 'isExam']));
        $room = $weeks - (int) $midterm - (int) $final - $existingLessons;

        return $this->view->render($response, 'units/outline', [
            'page' => 'courses',
            'course' => $course,
            'existing' => $existing,
            'existingLessons' => $existingLessons,
            'proposed' => $proposed,
            'plan' => $proposed === []
                ? null
                : Semester::plan($weeks, $existingLessons + count($proposed), $midterm, $final),
            'count' => (int) ($draft['count'] ?? 0) ?: max(1, min(8, $room)),
            'weeks' => $weeks,
            'weeksSaved' => (int) ($course['weeks'] ?? 0) > 0 || (int) ($draft['weeks'] ?? 0) > 0,
            'midterm' => $midterm,
            'final' => $final,
            'level' => Semester::level((string) $course['code']),
            'weekly' => (int) $course['theory_hours'] + (int) $course['practice_hours'],
            'note' => (string) ($draft['note'] ?? ''),
            'ai' => $this->drafter->picker((int) $request->getAttribute('user')['id']),
        ]);
    }

    /** เรียกผู้ช่วยให้ร่างรายชื่อหน่วย แล้วพักผลไว้ในเซสชันเพื่อให้ครูตรวจก่อน */
    public function generate(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response, $course);
        }

        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 2000);
        $current = $this->units->forCourse((int) $course['id']);
        $existing = array_map(static fn (array $u): string => (string) $u['title'], $current);
        $existingLessons = count($current) - count(array_filter($current, [UnitRepository::class, 'isExam']));

        // สัปดาห์เรียนรวมวัดผล + สอบกลาง/ปลายภาค (มีสอบกลางภาคต้องมีสอบปลายภาคด้วยเสมอ)
        $midterm = ($data['midterm'] ?? '') === '1';
        $final = $midterm || ($data['final'] ?? '') === '1';
        $weeks = (int) ($data['weeks'] ?? 0) ?: Semester::defaultWeeks((string) $course['code'], $midterm, $final);
        $weeks = max(1, min(Semester::MAX_WEEKS, $weeks));
        $room = $weeks - (int) $midterm - (int) $final - $existingLessons;
        if ($room < 1) {
            Flash::error('สัปดาห์เรียนไม่พอสำหรับหน่วยใหม่ · ' . $weeks . ' สัปดาห์ หักสัปดาห์สอบและหน่วยเดิม '
                . $existingLessons . ' หน่วยแล้วไม่เหลือสัปดาห์ว่าง');

            return $this->back($response, $course);
        }

        // หน่วยละอย่างน้อย 1 สัปดาห์ — ขอหน่วยมากกว่าสัปดาห์ที่เหลือไม่ได้
        $count = max(1, min(self::MAX_UNITS, $room, (int) ($data['count'] ?? 8)));
        $plan = Semester::plan($weeks, $existingLessons + $count, $midterm, $final);
        $weekly = (int) $course['theory_hours'] + (int) $course['practice_hours'];
        $spec = fn (array $titles, int $offset, int $n): array => [
            'task' => 'unit_outline',
            'course_code' => $course['code'],
            'course_name' => $course['name'],
            'description' => $course['description'],
            'existing' => $titles,
            'count' => $n,
            'note' => $note,
            'schedule' => $this->scheduleSpec($plan, $existingLessons + $offset, $n, $weekly),
        ];

        // ต้องมีข้อความกำกับระบบเสมอ — ผู้ให้บริการบางรายปฏิเสธคำขอที่ system prompt ว่างเปล่า
        $system = 'คุณเป็นผู้ช่วยครูอาชีวศึกษา ออกแบบโครงสร้างหน่วยการเรียนเป็นภาษาไทย '
            . 'ตอบกลับเป็น NDJSON หนึ่งหน่วยต่อหนึ่งบรรทัด';

        try {
            $units = $this->clean($this->drafter->objects(
                (int) $user['id'], $system, $spec($existing, 0, $count), $count, (string) ($data['ai'] ?? '')
            ));

            // โมเดลเล็กมักหยุดก่อนครบจำนวน — ขอส่วนที่ขาดอีกหนึ่งรอบ (ไม่ให้ซ้ำชื่อหน่วยที่มีแล้ว)
            if ($units !== [] && count($units) < $count) {
                $missing = $count - count($units);
                $more = $this->clean($this->drafter->objects(
                    (int) $user['id'],
                    $system,
                    $spec(array_merge($existing, array_column($units, 'title')), count($units), $missing),
                    $missing,
                    (string) ($data['ai'] ?? '')
                ));

                $seen = array_map('mb_strtolower', array_column($units, 'title'));
                foreach ($more as $u) {
                    if (count($units) < $count && !in_array(mb_strtolower($u['title']), $seen, true)) {
                        $units[] = $u;
                        $seen[] = mb_strtolower($u['title']);
                    }
                }
            }
        } catch (AiUnavailableException $e) {
            Flash::error($e->getMessage());

            return $this->back($response, $course);
        } catch (Throwable $e) {
            // บันทึกสาเหตุจริงไว้ให้ผู้ดูแลตามดูที่ /admin/logs ส่วนครูเห็นข้อความที่อ่านรู้เรื่อง
            $this->logger->error('ร่างรายชื่อหน่วยการเรียนไม่สำเร็จ: ' . $e->getMessage(), [
                'course_id' => (int) $course['id'],
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            Flash::error('ผู้ช่วยร่างรายการหน่วยไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response, $course);
        }

        if ($units === []) {
            Flash::error('ผู้ช่วยไม่ได้ร่างหน่วยการเรียนออกมา กรุณาลองใหม่หรือปรับคำสั่งเพิ่มเติม');

            return $this->back($response, $course);
        }

        // จำนวนสัปดาห์/ชั่วโมงมาจากตารางที่ระบบวาง ไม่เชื่อตัวเลขที่โมเดลตอบมา
        foreach ($units as $i => $u) {
            $units[$i]['weeks'] = $plan['units'][$existingLessons + $i]['weeks'];
            $units[$i]['hours'] = $units[$i]['weeks'] * $weekly;
        }

        $_SESSION[self::SESSION_KEY] = [
            'course_id' => (int) $course['id'],
            'units' => $units,
            'count' => $count,
            'note' => $note,
            'weeks' => $weeks,
            'midterm' => $midterm,
            'final' => $final,
        ];
        $this->auth->log('ai.unit_outline', 'course#' . $course['id'], ['units' => count($units)]);

        return $this->back($response, $course);
    }

    /** บันทึกหน่วยที่ครูติ๊กเลือกเป็นฉบับร่าง แล้ววางหน่วยสอบตามที่เลือกไว้ */
    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->back($response, $course);
        }

        $draft = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($draft) || (int) ($draft['course_id'] ?? 0) !== (int) $course['id']) {
            Flash::error('ไม่พบรายการที่ร่างไว้ กรุณาให้ผู้ช่วยร่างใหม่อีกครั้ง');

            return $this->back($response, $course);
        }

        $picked = array_map('intval', (array) ($data['pick'] ?? []));
        $created = 0;
        $sortOrder = $this->units->nextSortOrder((int) $course['id']);

        foreach ((array) $draft['units'] as $i => $unit) {
            if (!in_array($i, $picked, true)) {
                continue;
            }

            $this->units->create([
                'course_id' => (int) $course['id'],
                'title' => $unit['title'],
                'key_content' => $unit['key_content'] ?: null,
                'objectives' => $unit['objectives'] ?: null,
                'competencies' => $unit['competencies'] ?: null,
                'weeks' => ((int) ($unit['weeks'] ?? 0)) ?: null,
                'sort_order' => $sortOrder++,
                'source' => 'ai',
                'review_status' => 'draft',
                'created_by' => (int) $user['id'],
            ]);
            $created++;
        }

        if ($created === 0) {
            Flash::warning('ยังไม่ได้เลือกหน่วยการเรียนที่จะบันทึก');

            return $this->back($response, $course);
        }

        unset($_SESSION[self::SESSION_KEY]);
        $this->arrangeExams($course, $draft, (int) $user['id']);

        $this->auth->log('ai.unit_outline.save', 'course#' . $course['id'], ['created' => $created]);
        Flash::success('บันทึกหน่วยการเรียนเป็นฉบับร่าง ' . $created . ' หน่วยแล้ว · เปิดแก้ไขเพื่อใส่เนื้อหาแล้วเผยแพร่');

        return $response
            ->withHeader('Location', Url::to('/courses/' . $course['id'] . '?tab=units'))
            ->withStatus(302);
    }

    /**
     * ตารางสัปดาห์ของหน่วยใหม่ที่ส่งให้ผู้ช่วย เพื่อให้ร่างเนื้อหาแต่ละหน่วยพอดีกับเวลาที่ได้
     * และให้หน่วยก่อนสอบกลางภาครวมกันเป็นเนื้อหาครึ่งแรกของวิชา
     *
     * @param array<string,mixed> $plan ผลจาก Semester::plan()
     * @return array<string,mixed>
     */
    private function scheduleSpec(array $plan, int $offset, int $count, int $weekly): array
    {
        $units = [];
        foreach (array_slice($plan['units'], $offset, $count) as $i => $u) {
            $units[] = ['no' => $offset + $i + 1, 'hours' => $u['weeks'] * $weekly] + $u;
        }

        return [
            'weeks' => $plan['weeks'],
            'weekly' => $weekly,
            'units' => $units,
            'midterm_after' => $plan['midtermAfter'],
            'midterm_week' => $plan['midtermWeek'],
            'final_week' => $plan['finalWeek'],
        ];
    }

    /**
     * บันทึกจำนวนสัปดาห์ของรายวิชา และวางหน่วยสอบตามที่ครูเลือกในฟอร์ม
     * สอบกลางภาคแทรกหลังหน่วยที่ทำให้สัปดาห์สอบใกล้กลางภาคที่สุด สอบปลายภาคอยู่ท้ายสุด
     * ใช้หน่วยสอบเดิมถ้ามี (ย้ายตำแหน่ง) ไม่สร้างซ้ำ · หน่วยสอบที่ครูไม่ได้เลือกคงตำแหน่งเดิมไว้
     *
     * @param array<string,mixed> $course
     * @param array<string,mixed> $draft
     */
    private function arrangeExams(array $course, array $draft, int $userId): void
    {
        $courseId = (int) $course['id'];
        $weeks = (int) ($draft['weeks'] ?? 0);
        if ($weeks > 0) {
            $this->courses->update($courseId, ['weeks' => $weeks]);
        }

        $want = [
            'midterm' => !empty($draft['midterm']),
            'final' => !empty($draft['final']) || !empty($draft['midterm']),
        ];
        if (!$want['final']) {
            return;
        }

        $all = $this->units->forCourse($courseId);
        $lessons = array_values(array_filter($all, static fn (array $u): bool => !UnitRepository::isExam($u)));

        // ใช้หน่วยสอบเดิมหน่วยแรกของแต่ละชนิด ไม่มีก็สร้างใหม่เป็นฉบับร่าง
        $exam = [];
        foreach ($want as $kind => $on) {
            if (!$on) {
                continue;
            }
            foreach ($all as $u) {
                if ($u['kind'] === $kind) {
                    $exam[$kind] = (int) $u['id'];
                    break;
                }
            }
            $exam[$kind] ??= $this->units->create([
                'course_id' => $courseId,
                'title' => UnitRepository::KINDS[$kind],
                'kind' => $kind,
                'sort_order' => $this->units->nextSortOrder($courseId),
                'source' => 'manual',
                'review_status' => 'draft',
                'created_by' => $userId,
            ]);
        }

        // สัปดาห์ของหน่วยการเรียนตามลำดับ (หน่วยที่ไม่ได้กำหนดได้ส่วนแบ่งของสัปดาห์ที่เหลือ) เพื่อหาจุดแทรกสอบกลางภาค
        $after = null;
        if (isset($exam['midterm'])) {
            $fixed = array_sum(array_map(static fn (array $u): int => (int) ($u['weeks'] ?? 0), $lessons));
            $open = count(array_filter($lessons, static fn (array $u): bool => (int) ($u['weeks'] ?? 0) <= 0));
            $share = Semester::spread(max(0, $weeks - count($exam) - $fixed), $open);
            $list = [];
            foreach ($lessons as $u) {
                $list[] = (int) ($u['weeks'] ?? 0) > 0 ? (int) $u['weeks'] : (int) array_shift($share);
            }
            $after = Semester::midtermAfter($list, $weeks);
        }

        // เรียงใหม่: หน่วยการเรียนตามลำดับเดิม · หน่วยสอบที่ไม่ได้จัดวางคงอยู่หลังหน่วยการเรียนตัวเดิม
        $order = $after === 0 ? [$exam['midterm']] : [];
        $lessonNo = 0;
        foreach ($all as $u) {
            $id = (int) $u['id'];
            if (in_array($id, $exam, true)) {
                continue;
            }
            $order[] = $id;
            if (!UnitRepository::isExam($u) && ++$lessonNo === $after) {
                $order[] = $exam['midterm'];
            }
        }
        if ($after !== null && $after > $lessonNo) {
            $order[] = $exam['midterm'];
        }
        $order[] = $exam['final'];

        $current = array_column($all, 'sort_order', 'id');
        foreach ($order as $i => $id) {
            if ((int) ($current[$id] ?? -1) !== $i + 1) {
                $this->units->update($id, ['sort_order' => $i + 1]);
            }
        }
    }

    /**
     * คัดเฉพาะหน่วยที่ใช้ได้ และตัดความยาวให้พอดีกับคอลัมน์
     *
     * @param list<array<string,mixed>> $objects
     * @return list<array{title:string,key_content:string,objectives:string,competencies:string,hours:int}>
     */
    private function clean(array $objects): array
    {
        $units = [];

        foreach ($objects as $obj) {
            $title = trim((string) ($obj['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $units[] = [
                'title' => mb_substr($title, 0, 191),
                'key_content' => mb_substr(Drafter::text($obj['key_content'] ?? ''), 0, 2000),
                'objectives' => mb_substr(Drafter::text($obj['objectives'] ?? ''), 0, 2000),
                'competencies' => mb_substr(Drafter::text($obj['competencies'] ?? ''), 0, 2000),
                'hours' => max(0, min(255, (int) ($obj['hours'] ?? 0))),
            ];
        }

        return $units;
    }

    /** @param array<string,mixed> $course */
    private function back(Response $response, array $course): Response
    {
        return $response
            ->withHeader('Location', Url::to('/courses/' . $course['id'] . '/units/design'))
            ->withStatus(302);
    }
}
