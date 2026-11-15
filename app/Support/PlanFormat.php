<?php

declare(strict_types=1);

namespace App\Support;

/**
 * แบบฟอร์มแผนการจัดการเรียนรู้ที่ครูเลือกพิมพ์ได้ (courses.plan_format, NULL = ค่าเริ่มต้น)
 *   cur2567    = แผนการจัดการเรียนรู้หลักสูตร 2567 (แบบฟอร์มของ สอศ. มีหลักสูตรรายวิชา ตารางวิเคราะห์ ใบความรู้ ใบงาน ใบมอบหมายงาน) — ค่าเริ่มต้น
 *   competency = แผนการจัดการเรียนรู้มุ่งเน้นสมรรถนะ (แบบเดิม: ปก ลักษณะรายวิชา ตารางหน่วย แผนรายหน่วย)
 */
final class PlanFormat
{
    public const DEFAULT = 'cur2567';

    /** @var array<string, array{label:string, template:string}> */
    public const FORMATS = [
        'cur2567'    => ['label' => 'แผนการจัดการเรียนรู้หลักสูตร 2567', 'template' => 'plan/print-2567'],
        'competency' => ['label' => 'แผนการจัดการเรียนรู้มุ่งเน้นสมรรถนะ', 'template' => 'plan/print'],
    ];

    /** แบบที่ขอมา (?format=) ก่อน แล้วจึงแบบที่ครูตั้งไว้ในรายวิชา ไม่รู้จัก = ค่าเริ่มต้น */
    public static function resolve(?string $requested, ?string $saved = null): string
    {
        foreach ([$requested, $saved] as $key) {
            if ($key !== null && isset(self::FORMATS[$key])) {
                return $key;
            }
        }

        return self::DEFAULT;
    }

    /** ค่าที่จะบันทึกลง courses.plan_format (ไม่รู้จัก = ค่าเริ่มต้น) */
    public static function clean(?string $key): string
    {
        return self::resolve($key);
    }

    public static function template(string $key): string
    {
        return self::FORMATS[self::resolve($key)]['template'];
    }

    /** @return array<string,string> key => ชื่อแบบฟอร์ม */
    public static function labels(): array
    {
        return array_map(static fn (array $f): string => $f['label'], self::FORMATS);
    }
}
