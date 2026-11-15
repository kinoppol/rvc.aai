<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ส่วนประกอบของใบงานตามแบบฟอร์มแผนการจัดการเรียนรู้หลักสูตร 2567 (assignments.sheet, JSON)
 * ข้อ 7 ขั้นตอนการปฏิบัติงานคือรายละเอียดใบงานเดิม (assignments.description) · ข้อ 9 การประเมินผลมาจากคะแนนเต็ม
 * ทุกช่องเป็นข้อความหลายบรรทัด (หนึ่งข้อต่อบรรทัด) · ช่องว่าง = หน้าพิมพ์ใช้ข้อมูลของหน่วยแทน หรือเว้นจุด
 */
final class WorksheetSheet
{
    /** key => [เลขข้อในแบบฟอร์ม, ชื่อหัวข้อ, ตัวอย่างในช่อง] */
    public const FIELDS = [
        'outcome' => ['1', 'ผลลัพธ์การเรียนรู้จากการปฏิบัติงาน', 'ผู้เรียนทำอะไรได้เมื่อปฏิบัติงานตามใบงานนี้เสร็จ'],
        'standard' => ['2', 'อ้างอิงมาตรฐาน/เชื่อมโยงกลุ่มอาชีพ', 'หน่วยสมรรถนะ/สมรรถนะย่อยของมาตรฐานอาชีพที่ใบงานนี้ฝึก'],
        'competencies' => ['3', 'สมรรถนะการปฏิบัติงาน', 'หนึ่งข้อต่อบรรทัด · ว่าง = ใช้สมรรถนะประจำหน่วย'],
        'objectives' => ['4', 'จุดประสงค์เชิงพฤติกรรม', 'หนึ่งข้อต่อบรรทัด · ว่าง = ใช้จุดประสงค์ของหน่วย'],
        'tools' => ['5', 'เครื่องมือ วัสดุ และอุปกรณ์', 'หนึ่งรายการต่อบรรทัด'],
        'cautions' => ['6', 'คำแนะนำ/ข้อควรระวัง', 'ความปลอดภัย ข้อควรระวัง ข้อแนะนำในการปฏิบัติงาน'],
        'summary' => ['8', 'สรุปและวิจารณ์ผล', 'คำถามหรือประเด็นให้ผู้เรียนสรุปหลังปฏิบัติงาน หนึ่งข้อต่อบรรทัด'],
        'references' => ['10', 'เอกสารอ้างอิง/เอกสารค้นคว้าเพิ่มเติม', 'หนึ่งรายการต่อบรรทัด'],
    ];

    /** @return array<string,string> ทุกช่องเป็นข้อความ ('' = ยังไม่กรอก) */
    public static function decode(?string $json): array
    {
        $data = $json ? json_decode($json, true) : null;
        $data = is_array($data) ? $data : [];
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = self::text($data[$key] ?? '');
        }

        return $out;
    }

    /**
     * ค่าจากฟอร์มใบงาน (sheet_{key}) → JSON หรือ null เมื่อว่างทุกช่อง
     * @param array<string,mixed> $data
     */
    public static function fromPost(array $data): ?string
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = self::text($data['sheet_' . $key] ?? '');
        }

        return self::encode($out);
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value): ?string
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $text = self::text($value[$key] ?? '');
            if ($text !== '') {
                $out[$key] = mb_substr($text, 0, 5000);
            }
        }

        return $out === [] ? null : json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /**
     * ส่วนที่ผู้ช่วย AI ร่างมา (JSON จากโมเดล) → ข้อความต่อช่อง · ช่องที่เป็น list รวมเป็นบรรทัด
     * @param array<string,mixed> $draft
     * @return array<string,string>
     */
    public static function fromDraft(array $draft): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $out[$key] = self::text($draft[$key] ?? '');
        }

        return $out;
    }

    /**
     * เติมเฉพาะช่องที่ยังว่างจากฉบับที่ผู้ช่วยร่าง — ของที่ครูกรอกไว้แล้วไม่ถูกแทนที่
     * @param array<string,string> $existing
     * @param array<string,string> $draft
     * @return array<string,string>
     */
    public static function fill(array $existing, array $draft): array
    {
        $out = [];
        foreach (array_keys(self::FIELDS) as $key) {
            $mine = trim((string) ($existing[$key] ?? ''));
            $out[$key] = $mine !== '' ? $mine : trim((string) ($draft[$key] ?? ''));
        }

        return $out;
    }

    /** @return list<string> สำหรับหน้าพิมพ์: แยกบรรทัด ตัดเลขข้อนำหน้า */
    public static function lines(string $text): array
    {
        return UnitPlanFields::lines($text);
    }

    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $item = implode(' — ', array_filter(array_map(static fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $item)));
                }
                if (is_scalar($item) && trim((string) $item) !== '') {
                    $items[] = trim((string) $item);
                }
            }

            return implode("\n", $items);
        }

        return is_scalar($value) ? trim(str_replace("\r\n", "\n", (string) $value)) : '';
    }
}
