<?php

declare(strict_types=1);

namespace App\Support;

/**
 * จำนวนสัปดาห์ของภาคเรียนและการวางสัปดาห์สอบ
 *
 * ระดับชั้นดูจากรหัสวิชา:
 *   ปวช.   รหัสขึ้นต้นด้วย 2     เรียน 18 สัปดาห์ "รวม" วัดผล  → สอบปลายภาคสัปดาห์ที่ 18
 *   ปวส.   รหัสขึ้นต้นด้วย 3     เรียน 15 สัปดาห์ "ไม่รวม" วัดผล → สัปดาห์สอบต่อท้าย (สอบปลายภาค = 16)
 *   ทล.บ.  รหัสขึ้นต้นด้วย 26-4  เรียน 18 สัปดาห์ "รวม" วัดผล  → สอบปลายภาคสัปดาห์ที่ 18
 *
 * จำนวนสัปดาห์ที่เก็บใน courses.weeks คือ "รวมสัปดาห์สอบแล้ว" เสมอ
 * ชั่วโมงต่อสัปดาห์ = ท + ป (น คือหน่วยกิต ไม่ใช่ชั่วโมง)
 */
final class Semester
{
    public const MAX_WEEKS = 30;

    /** @return array{label:string, teaching:int, examsIncluded:bool} */
    public static function level(string $code): array
    {
        $code = trim($code);

        if (preg_match('/^26-?4/', $code)) {
            return ['label' => 'ปริญญาตรี (ทล.บ.)', 'teaching' => 18, 'examsIncluded' => true];
        }
        if (str_starts_with($code, '3')) {
            return ['label' => 'ปวส.', 'teaching' => 15, 'examsIncluded' => false];
        }
        if (str_starts_with($code, '2')) {
            return ['label' => 'ปวช.', 'teaching' => 18, 'examsIncluded' => true];
        }

        return ['label' => '', 'teaching' => 18, 'examsIncluded' => true];
    }

    /** จำนวนสัปดาห์รวมวัดผลตามค่าปกติของระดับชั้น */
    public static function defaultWeeks(string $code, bool $midterm, bool $final): int
    {
        $level = self::level($code);

        return $level['examsIncluded']
            ? $level['teaching']
            : $level['teaching'] + (int) $midterm + (int) $final;
    }

    /**
     * แบ่งสัปดาห์เรียน (ไม่นับสัปดาห์สอบ) ให้หน่วยการเรียนเป็นจำนวนเต็มสัปดาห์
     * เศษไปอยู่หน่วยแรก ๆ · ถ้าหน่วยมากกว่าสัปดาห์ บางหน่วยได้ 0 (ผู้เรียกต้องจัดการเอง)
     *
     * @return list<int>
     */
    public static function spread(int $weeks, int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $weeks = max(0, $weeks);
        $base = intdiv($weeks, $count);
        $extra = $weeks % $count;

        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $base + ($i < $extra ? 1 : 0);
        }

        return $out;
    }

    /**
     * สอบกลางภาคควรอยู่หลังหน่วยที่เท่าไร — เลือกจุดแบ่งหน่วยที่ทำให้สัปดาห์สอบใกล้กลางภาคเรียนที่สุด
     * (สัปดาห์กลางภาค = ครึ่งหนึ่งของจำนวนสัปดาห์รวม ปัดขึ้น)
     *
     * @param list<int> $unitWeeks สัปดาห์ของหน่วยการเรียนแต่ละหน่วยตามลำดับ
     * @return int จำนวนหน่วยก่อนสอบกลางภาค (อย่างน้อย 1)
     */
    public static function midtermAfter(array $unitWeeks, int $totalWeeks): int
    {
        $n = count($unitWeeks);
        if ($n <= 1) {
            return $n;
        }

        $target = intdiv($totalWeeks + 1, 2);
        $best = 1;
        $bestGap = PHP_INT_MAX;
        $cum = 0;
        for ($k = 1; $k < $n; $k++) {
            $cum += $unitWeeks[$k - 1];
            $gap = abs($cum + 1 - $target);
            if ($gap < $bestGap) {
                $best = $k;
                $bestGap = $gap;
            }
        }

        return $best;
    }

    /**
     * วางตารางทั้งภาค: หน่วยการเรียน n หน่วย + สอบกลางภาค/ปลายภาคอย่างละ 1 สัปดาห์
     * มีสอบกลางภาคต้องมีสอบปลายภาคด้วยเสมอ · สอบปลายภาคอยู่สัปดาห์สุดท้าย
     *
     * @return array{
     *   weeks:int, lessonWeeks:int, midterm:bool, final:bool,
     *   units:list<array{weeks:int, from:int, to:int}>,
     *   midtermAfter:?int, midtermWeek:?int, finalWeek:?int
     * }
     */
    public static function plan(int $weeks, int $count, bool $midterm, bool $final): array
    {
        $final = $final || $midterm;
        $weeks = max(1, min(self::MAX_WEEKS, $weeks));
        $lessonWeeks = max(0, $weeks - (int) $midterm - (int) $final);
        $spread = self::spread($lessonWeeks, $count);
        $after = $midterm ? self::midtermAfter($spread, $weeks) : null;

        $units = [];
        $week = 1;
        foreach ($spread as $i => $w) {
            if ($after !== null && $i === $after) {
                $week++; // สัปดาห์สอบกลางภาค
            }
            $units[] = ['weeks' => $w, 'from' => $week, 'to' => max($week, $week + $w - 1)];
            $week += $w;
        }

        $midWeek = null;
        if ($after !== null) {
            $midWeek = 1 + array_sum(array_slice($spread, 0, $after));
        }

        return [
            'weeks' => $weeks,
            'lessonWeeks' => $lessonWeeks,
            'midterm' => $midterm,
            'final' => $final,
            'units' => $units,
            'midtermAfter' => $after,
            'midtermWeek' => $midWeek,
            'finalWeek' => $final ? $weeks : null,
        ];
    }
}
