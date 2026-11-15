<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ตารางวิเคราะห์พฤติกรรมการเรียนรู้ (แบบที่ 2) ของแผนการสอนหลักสูตร 2567
 * เก็บเป็น JSON ใน courses.behavior_analysis:
 *   {units: {"<unitId>": {k: ["K1","K2"], s: "S3", a: "A2", ap: "Ap2", weight: 10}}, outcome_weight: 20}
 * k ใส่ได้หลายระดับ · s / a / ap ระดับเดียว · weight = ร้อยละประเมินผลของหน่วย
 * outcome_weight = ร้อยละของแถว "ประเมินผลลัพธ์การเรียนรู้ระดับรายวิชา" · รวมทั้งหมดควรเป็น 100
 */
final class BehaviorAnalysis
{
    public const K = ['K1' => 'ความรู้ ความจำ', 'K2' => 'ความเข้าใจ', 'K3' => 'การนำไปใช้', 'K4' => 'การวิเคราะห์', 'K5' => 'การประเมินค่า', 'K6' => 'การสร้างสรรค์'];
    public const S = ['S1' => 'เลียนแบบ', 'S2' => 'ทำได้ตามแบบ', 'S3' => 'ทำได้ถูกต้อง', 'S4' => 'ทำได้อย่างต่อเนื่อง', 'S5' => 'ทำได้อย่างเป็นธรรมชาติ'];
    public const A = ['A1' => 'รับรู้', 'A2' => 'ตอบสนอง', 'A3' => 'การสร้างคุณค่า', 'A4' => 'จัดระบบคุณค่านิยม', 'A5' => 'การสร้างลักษณะนิสัย'];
    public const AP = [
        'Ap1' => 'ปฏิบัติงานตามแบบแผนที่กำหนด',
        'Ap2' => 'ปฏิบัติงานตามแบบแผน และปรับตัวภายใต้ความเปลี่ยนแปลงที่ไม่ซับซ้อน',
        'Ap3' => 'วางแผนงานที่ได้รับมอบหมายและแก้ไขปัญหาที่ไม่อยู่ภายใต้การควบคุมในบางเรื่อง',
        'Ap4' => 'วางแผนงานที่รับผิดชอบ ปรับตัวและแก้ปัญหาที่ไม่คุ้นเคยหรือซับซ้อน',
        'Ap5' => 'ประยุกต์ใช้ความรู้และทักษะวิชาชีพวางแผนแก้ปัญหาและพัฒนานวัตกรรมตามสายอาชีพ',
    ];

    /** @return array{units: array<int,array{k:list<string>,s:string,a:string,ap:string,weight:int|null}>, outcome_weight: int|null} */
    public static function empty(): array
    {
        return ['units' => [], 'outcome_weight' => null];
    }

    /**
     * อ่านค่าจากฟอร์ม: bh[{unitId}][k][] / [s] / [a] / [ap] / [weight] และ bh_outcome_weight
     * เก็บเฉพาะหน่วยที่อยู่ในรายวิชานี้ ($unitIds) · ว่างทั้งหมด = null
     * @param array<string,mixed> $data
     * @param list<int> $unitIds
     */
    public static function fromPost(array $data, array $unitIds): ?string
    {
        $rows = is_array($data['bh'] ?? null) ? $data['bh'] : [];
        $out = self::empty();
        foreach ($unitIds as $id) {
            $row = self::cleanRow(is_array($rows[$id] ?? null) ? $rows[$id] : []);
            if ($row !== null) {
                $out['units'][$id] = $row;
            }
        }
        $out['outcome_weight'] = self::weight($data['bh_outcome_weight'] ?? null);

        return self::isEmpty($out) ? null : json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /** @return array{units: array<int,array{k:list<string>,s:string,a:string,ap:string,weight:int|null}>, outcome_weight: int|null} */
    public static function decode(?string $json): array
    {
        $data = $json ? json_decode($json, true) : null;
        $out = self::empty();
        if (!is_array($data)) {
            return $out;
        }
        foreach (is_array($data['units'] ?? null) ? $data['units'] : [] as $id => $row) {
            $clean = is_array($row) ? self::cleanRow($row) : null;
            if ($clean !== null && (int) $id > 0) {
                $out['units'][(int) $id] = $clean;
            }
        }
        $out['outcome_weight'] = self::weight($data['outcome_weight'] ?? null);

        return $out;
    }

    /**
     * ทำความสะอาดหนึ่งแถว — รับทั้งค่าจากฟอร์มและจาก AI (k เป็นรายการหรือข้อความ "K1, K2")
     * @param array<string,mixed> $row
     * @return array{k:list<string>,s:string,a:string,ap:string,weight:int|null}|null
     */
    public static function cleanRow(array $row): ?array
    {
        $k = $row['k'] ?? [];
        if (is_string($k)) {
            $k = preg_split('/[\s,،、]+/u', $k) ?: [];
        }
        $levels = [];
        foreach ((array) $k as $v) {
            $v = self::level((string) $v, self::K);
            if ($v !== '' && !in_array($v, $levels, true)) {
                $levels[] = $v;
            }
        }
        sort($levels);
        $clean = [
            'k' => $levels,
            's' => self::level((string) ($row['s'] ?? ''), self::S),
            'a' => self::level((string) ($row['a'] ?? ''), self::A),
            'ap' => self::level((string) ($row['ap'] ?? ''), self::AP),
            'weight' => self::weight($row['weight'] ?? null),
        ];

        return $clean['k'] === [] && $clean['s'] === '' && $clean['a'] === '' && $clean['ap'] === '' && $clean['weight'] === null
            ? null : $clean;
    }

    /** ผลรวมร้อยละ (หน่วย + ผลลัพธ์ระดับรายวิชา) */
    public static function total(array $analysis): int
    {
        return array_sum(array_map(static fn (array $r): int => (int) ($r['weight'] ?? 0), $analysis['units']))
            + (int) ($analysis['outcome_weight'] ?? 0);
    }

    /** แถวนี้ครบทุกช่องหรือยัง */
    public static function rowComplete(?array $row): bool
    {
        return $row !== null && $row['k'] !== [] && $row['s'] !== '' && $row['a'] !== '' && $row['ap'] !== '' && $row['weight'] !== null;
    }

    /**
     * ปรับร้อยละให้รวมเป็น 100 พอดีตามสัดส่วนเดิม (ใช้กับคำตอบจาก AI ที่บวกกันไม่ลงตัว)
     * @param array<int|string,int> $weights
     * @return array<int|string,int>
     */
    public static function scaleTo100(array $weights): array
    {
        if ($weights === []) {
            return [];
        }
        $weights = array_map(static fn ($w): int => max(0, (int) $w), $weights);
        if (array_sum($weights) <= 0) {
            $weights = array_map(static fn (): int => 1, $weights);   // ไม่มีน้ำหนักเลย = แบ่งเท่ากัน
        }
        $sum = array_sum($weights);
        $out = [];
        $rest = [];
        foreach ($weights as $key => $w) {
            $exact = $w * 100 / $sum;
            $out[$key] = (int) floor($exact);
            $rest[$key] = $exact - $out[$key];
        }
        arsort($rest);
        $missing = 100 - array_sum($out);
        foreach (array_keys($rest) as $key) {
            if ($missing-- <= 0) {
                break;
            }
            $out[$key]++;
        }

        return $out;
    }

    /** @param array<string,string> $allowed */
    private static function level(string $value, array $allowed): string
    {
        $value = strtoupper(trim($value));
        foreach (array_keys($allowed) as $key) {
            if ($value === strtoupper($key)) {
                return $key;
            }
        }

        return '';
    }

    private static function weight(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int) round((float) $value)));
    }

    private static function isEmpty(array $data): bool
    {
        return $data['units'] === [] && $data['outcome_weight'] === null;
    }
}
