<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ขนาดเนื้อหาที่ส่งให้โมเดลได้ในหนึ่งรอบ และการแบ่งเนื้อหายาวเป็นหลายรอบ
 *
 * โมเดลเล็กบนเครื่องของวิทยาลัย (เช่น Typhoon 8B) รับคำสั่งยาวไม่ไหว — ส่งเกินแล้วตอบมั่วหรือหลุดรูปแบบ
 * จึงแบ่งฐานความรู้เป็นรอบละไม่เกิน chars() ตัวอักษร แล้วออกข้อสอบทีละรอบ ส่วนโมเดลใหญ่/บริการบนคลาวด์รับได้มากกว่าในรอบเดียว
 * ขนาดเดาจากชื่อโมเดลและชนิดผู้ให้บริการ (ไม่มีบริการไหนบอกขนาดหน้าต่างได้ตรง ๆ ทุกเจ้า)
 */
final class ModelBudget
{
    /** โมเดลเล็ก/ไม่รู้ขนาด — ค่าที่ทดสอบแล้วว่า Typhoon 8B ยังตอบตามรูปแบบได้ */
    public const SMALL = 14000;
    public const MEDIUM = 24000;
    public const LARGE = 40000;
    public const HUGE = 60000;

    /** ตัวอักษรต่อรอบที่โมเดลของเส้นทางนี้รับไหว */
    public static function chars(AiRoute $route): int
    {
        if ($route->provider instanceof GoogleAiProvider) {
            return self::HUGE;   // Gemini หน้าต่างหลักล้าน token
        }

        $model = strtolower($route->model);
        if (preg_match('/gemini|gpt-4|gpt-5|claude|o[134]-|deepseek-(v3|v4|r1|chat)|grok|mistral-large|command-r/', $model) === 1) {
            return self::LARGE;
        }

        // ขนาดพารามิเตอร์ในชื่อ เช่น qwen2.5:14b, llama-3.1-70b, qwen3.8-27b-nvfp4, qwen3-235b-a22b
        if (preg_match_all('/(?<![\d.])(\d{1,4}(?:\.\d)?)b(?![a-z])/', $model, $m) > 0) {
            $size = max(array_map('floatval', $m[1]));
            return match (true) {
                $size >= 60 => self::LARGE,
                $size >= 25 => self::MEDIUM,
                $size >= 13 => 18000,
                default => self::SMALL,
            };
        }

        return self::SMALL;
    }

    /**
     * แบ่งฐานความรู้ Markdown (จาก UnitKnowledge) เป็นก้อนละไม่เกิน $budget ตัวอักษร โดยตัดที่หัวข้อ (# หน่วย / ## ส่วน)
     * ก้อนที่ขึ้นด้วยหัวข้อย่อยจะใส่ชื่อหน่วยกำกับไว้ด้วย ให้โมเดลรู้ว่าเนื้อหาเป็นของหน่วยไหน
     *
     * @return list<string>
     */
    public static function split(string $text, int $budget): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= $budget) {
            return [$text];
        }

        // แยกเป็นบล็อกตามหัวข้อ จำชื่อหน่วยของแต่ละบล็อกไว้
        $blocks = [];
        $unit = '';
        foreach (preg_split('/\n(?=#{1,2} )/u', $text) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            if (str_starts_with($block, '# ')) {
                $unit = strtok($block, "\n");
            }
            foreach (self::hardSplit($block, $budget - mb_strlen($unit) - 2) as $piece) {
                $blocks[] = [$unit, $piece];
            }
        }

        $chunks = [];
        $current = '';
        $currentUnit = null;
        foreach ($blocks as [$blockUnit, $piece]) {
            // หัวหน่วยกำกับเมื่อเริ่มก้อนใหม่หรือเปลี่ยนหน่วย (ถ้าบล็อกยังไม่ได้ขึ้นด้วยหัวหน่วยเอง)
            $prefix = ($blockUnit !== '' && $blockUnit !== $currentUnit && !str_starts_with($piece, '# ')) ? $blockUnit . "\n" : '';
            $add = ($current === '' ? '' : "\n\n") . $prefix . $piece;
            if ($current !== '' && mb_strlen($current) + mb_strlen($add) > $budget) {
                $chunks[] = $current;
                $prefix = ($blockUnit !== '' && !str_starts_with($piece, '# ')) ? $blockUnit . "\n" : '';
                $current = $prefix . $piece;
            } else {
                $current .= $add;
            }
            $currentUnit = $blockUnit;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * จำนวนข้อของแต่ละรอบ ตามความยาวของก้อน (อย่างน้อยรอบละ 1 ข้อ รวมเท่ากับ $count พอดี)
     * รวมก้อนติดกันที่ยังพอดีงบ ($budget) ให้เหลือรอบน้อยที่สุด — ไม่รวมจนเกินงบ เพราะโมเดลเล็กจะรับไม่ไหว
     * ก้อนยังมากกว่าจำนวนข้อ (ข้อสอบน้อย เนื้อหายาว) → เลือกก้อนกระจายทั่วเนื้อหา ก้อนละ 1 ข้อ
     *
     * @param list<string> $chunks
     * @return list<array{0:string,1:int}> [ก้อนเนื้อหา, จำนวนข้อ]
     */
    public static function rounds(array $chunks, int $count, int $budget): array
    {
        $count = max(1, $count);
        for ($merged = true; $merged && count($chunks) > 1;) {
            $merged = false;
            $best = null;
            for ($i = 0; $i < count($chunks) - 1; $i++) {
                $len = mb_strlen($chunks[$i]) + mb_strlen($chunks[$i + 1]) + 2;
                if ($len <= $budget && ($best === null || $len < $best[1])) {
                    $best = [$i, $len];
                }
            }
            if ($best !== null) {
                array_splice($chunks, $best[0], 2, [$chunks[$best[0]] . "\n\n" . $chunks[$best[0] + 1]]);
                $merged = true;
            }
        }

        if (count($chunks) > $count) {
            $step = count($chunks) / $count;
            $chunks = array_map(static fn (int $k): string => $chunks[(int) floor($k * $step + $step / 2)], range(0, $count - 1));
        }

        if ($chunks === []) {
            return [];
        }

        $total = array_sum(array_map('mb_strlen', $chunks));
        $counts = array_map(static fn (string $c): int => max(1, (int) floor($count * mb_strlen($c) / max(1, $total))), $chunks);
        // ปรับให้รวมได้พอดี: เติมให้ก้อนยาวสุดก่อน / ลดจากก้อนที่มีข้อมากสุด
        while (array_sum($counts) < $count) {
            $ratio = array_map(static fn (string $c, int $n): float => mb_strlen($c) / $n, $chunks, $counts);
            $counts[array_search(max($ratio), $ratio, true)]++;
        }
        while (array_sum($counts) > $count) {
            $counts[array_search(max($counts), $counts, true)]--;
        }

        return array_map(null, $chunks, $counts);
    }

    /** @return list<string> บล็อกที่ยาวเกินงบ ตัดที่ย่อหน้า แล้วจึงตัดตามจำนวนตัวอักษร */
    private static function hardSplit(string $block, int $budget): array
    {
        $budget = max(1000, $budget);
        if (mb_strlen($block) <= $budget) {
            return [$block];
        }

        $out = [];
        $current = '';
        foreach (preg_split('/\n{2,}|\n/u', $block) ?: [] as $para) {
            while (mb_strlen($para) > $budget) {
                if ($current !== '') {
                    $out[] = $current;
                    $current = '';
                }
                $out[] = mb_substr($para, 0, $budget);
                $para = mb_substr($para, $budget);
            }
            if ($current !== '' && mb_strlen($current) + mb_strlen($para) + 1 > $budget) {
                $out[] = $current;
                $current = $para;
            } else {
                $current .= ($current === '' ? '' : "\n") . $para;
            }
        }
        if (trim($current) !== '') {
            $out[] = $current;
        }

        return $out;
    }
}
