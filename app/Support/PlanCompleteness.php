<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\UnitRepository;

/**
 * ความสมบูรณ์ของแผนการจัดการเรียนรู้ของรายวิชา (ร้อยละ) + คำแนะนำสิ่งที่ยังขาด
 * นับเฉพาะสิ่งที่ครูเติมเองได้ — ไม่นับผู้ลงนามที่ผู้ดูแลระบบตั้ง มาตรฐานอาชีพ (ไม่ใช่ทุกวิชามี)
 * และบันทึกหลังสอน (เกิดขึ้นหลังสอนจริง) · น้ำหนักรวม 100
 */
final class PlanCompleteness
{
    /**
     * @param array<string,mixed> $course แถว courses
     * @param list<array<string,mixed>> $units UnitRepository::forCourse() (มี section_count, has_pretest, …)
     * @param array<string,mixed>|null $behavior BehaviorAnalysis::decode() · null = ยังไม่รัน migration (ไม่นับ)
     * @return array{percent:int, items:list<array{label:string,weight:int,score:float,done:bool,tip:string,link:string}>}
     */
    public static function check(array $course, array $units, ?array $behavior, string $base): array
    {
        $id = (int) $course['id'];
        $edit = $base . '/courses/' . $id . '/edit';
        $filled = static fn (string $k): bool => trim((string) ($course[$k] ?? '')) !== '';
        $lessons = array_values(array_filter($units, static fn (array $u): bool => !UnitRepository::isExam($u)));
        $unitLink = static fn (array $u): string => $base . '/courses/' . $id . '/units/' . $u['id'] . '/edit';

        $items = [];
        $add = static function (string $label, int $weight, float $score, string $tip, string $link) use (&$items): void {
            $score = max(0.0, min(1.0, $score));
            $items[] = ['label' => $label, 'weight' => $weight, 'score' => $score, 'done' => $score >= 0.999, 'tip' => $tip, 'link' => $link];
        };

        $add('คำอธิบายรายวิชา', 6, $filled('description') ? 1 : 0, 'กรอกคำอธิบายรายวิชาตามหลักสูตร', $edit . '#about');
        $add('จุดประสงค์รายวิชา', 6, $filled('objectives') ? 1 : 0, 'กรอกจุดประสงค์รายวิชา บรรทัดละข้อ', $edit . '#about');
        $add('สมรรถนะรายวิชา', 6, $filled('competencies') ? 1 : 0, 'กรอกสมรรถนะรายวิชา บรรทัดละข้อ', $edit . '#about');
        $add('ผลลัพธ์การเรียนรู้ระดับรายวิชา', 6, $filled('learning_outcomes') ? 1 : 0,
            'กรอกเอง หรือกด “ให้ AI สรุป” ในแท็บแผนการสอน', $edit . '#plan');

        $cover = ['program_type' => 'ประเภทวิชา', 'major' => 'สาขาวิชา'];
        if (array_key_exists('career_group', $course)) {
            $cover['career_group'] = 'กลุ่มอาชีพ';
        }
        $missing = array_values(array_filter($cover, static fn (string $label, string $k): bool => !$filled($k), ARRAY_FILTER_USE_BOTH));
        $add('ข้อมูลหน้าปก', 6, 1 - count($missing) / count($cover), 'ยังไม่ได้กรอก ' . implode(' ', $missing), $edit . '#plan');

        $sign = ['plan_date' => 'วันที่จัดทำแผน'];
        if (array_key_exists('dept_head_id', $course)) {
            $sign['dept_head_id'] = 'หัวหน้าแผนกวิชา';
        }
        $missing = array_values(array_filter($sign, static fn (string $label, string $k): bool => empty($course[$k]), ARRAY_FILTER_USE_BOTH));
        $add('วันที่จัดทำแผนและผู้ลงนาม', 4, 1 - count($missing) / count($sign), 'ยังไม่ได้ระบุ ' . implode(' ', $missing), $edit . '#plan');

        $add('หน่วยการเรียนรู้', 6, $lessons !== [] ? 1 : 0,
            'ยังไม่มีหน่วยการเรียนรู้ — ให้ AI ออกแบบหน่วยจากคำอธิบายรายวิชาได้', $base . '/courses/' . $id . '/units/design');

        // สัดส่วนหน่วยที่ผ่านเงื่อนไข + ชื่อหน่วยแรก ๆ ที่ยังขาด
        $perUnit = static function (string $label, int $weight, callable $ok, string $what) use ($lessons, $add, $unitLink, $base, $id): void {
            if ($lessons === []) {
                $add($label, $weight, 0, 'สร้างหน่วยการเรียนรู้ก่อน', $base . '/courses/' . $id);
                return;
            }
            $lacking = array_values(array_filter($lessons, static fn (array $u): bool => !$ok($u)));
            $names = array_map(static fn (array $u): string => '“' . $u['title'] . '”', array_slice($lacking, 0, 3));
            $more = count($lacking) > 3 ? ' และอีก ' . (count($lacking) - 3) . ' หน่วย' : '';
            $add(
                $label,
                $weight,
                1 - count($lacking) / count($lessons),
                $lacking === [] ? '' : $what . ' ใน ' . implode(' ', $names) . $more,
                $lacking === [] ? '' : $unitLink($lacking[0])
            );
        };
        $has = static fn (array $u, string $k): bool => trim((string) ($u[$k] ?? '')) !== '';
        $perUnit('สาระสำคัญ จุดประสงค์ และสมรรถนะของหน่วย', 14,
            static fn (array $u): bool => $has($u, 'key_content') && $has($u, 'objectives') && $has($u, 'competencies'),
            'เติมสาระสำคัญ/จุดประสงค์/สมรรถนะ');
        $perUnit('เนื้อหาของหน่วย (ใบความรู้)', 12, static fn (array $u): bool => (int) $u['section_count'] > 0, 'เพิ่มเนื้อหา');
        $perUnit('ใบงาน', 8, static fn (array $u): bool => (int) $u['has_assignment'] > 0, 'สร้างใบงาน');
        $perUnit('แบบทดสอบก่อนเรียน', 5, static fn (array $u): bool => (int) $u['has_pretest'] > 0, 'สร้างแบบทดสอบก่อนเรียน');
        $perUnit('แบบทดสอบหลังเรียน', 8, static fn (array $u): bool => (int) $u['has_posttest'] > 0, 'สร้างแบบทดสอบหลังเรียน');

        if ($behavior !== null) {
            if ($lessons === []) {
                $add('ตารางวิเคราะห์พฤติกรรมการเรียนรู้', 13, 0, 'สร้างหน่วยการเรียนรู้ก่อน', $base . '/courses/' . $id);
            } else {
                $done = count(array_filter($lessons, static fn (array $u): bool => BehaviorAnalysis::rowComplete($behavior['units'][(int) $u['id']] ?? null)));
                $total = BehaviorAnalysis::total($behavior);
                $score = $done / count($lessons) * ($total === 100 ? 1 : 0.8);
                $tip = $done < count($lessons)
                    ? 'ยังกำหนดระดับ K/S/A/Ap หรือร้อยละไม่ครบ ' . (count($lessons) - $done) . ' หน่วย — กด “ให้ AI วิเคราะห์” ได้'
                    : ($total !== 100 ? 'ร้อยละประเมินผลรวมได้ ' . $total . ' ต้องเท่ากับ 100' : '');
                $add('ตารางวิเคราะห์พฤติกรรมการเรียนรู้', 13, $score, $tip, $edit . '#plan');
            }
        }

        $weights = array_sum(array_column($items, 'weight'));
        $got = array_sum(array_map(static fn (array $i): float => $i['weight'] * $i['score'], $items));

        // สิ่งที่ยังขาด เรียงจากที่เพิ่มคะแนนได้มากที่สุด · gain = ร้อยละที่จะเพิ่มขึ้นเมื่อเติมครบ
        $todo = [];
        foreach ($items as $item) {
            if (!$item['done']) {
                $todo[] = $item + ['gain' => max(1, (int) round($item['weight'] * (1 - $item['score']) * 100 / max(1, $weights)))];
            }
        }
        usort($todo, static fn (array $a, array $b): int => $b['gain'] <=> $a['gain']);

        return [
            'percent' => $weights > 0 ? (int) floor($got * 100 / $weights) : 0,
            'items' => $items,
            'todo' => $todo,
            'done' => count($items) - count($todo),
        ];
    }
}
