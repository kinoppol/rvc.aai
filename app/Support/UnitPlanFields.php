<?php

declare(strict_types=1);

namespace App\Support;

/**
 * อ้างอิงมาตรฐาน/เชื่อมโยงกลุ่มอาชีพของหน่วยการเรียน (ข้อ 2 ของแผนการจัดการเรียนรู้หลักสูตร 2567)
 * เก็บเป็น JSON ใน units.standard_ref: {uoc, eoc: [สมรรถนะย่อย…], career}
 *   uoc    = 2.2 หน่วยสมรรถนะ (เช่น "10213 ติดตั้งระบบฐานข้อมูล")
 *   eoc    = สมรรถนะย่อยของหน่วยสมรรถนะนั้น หนึ่งข้อต่อรายการ
 *   career = 2.3 บูรณาการกลุ่มอาชีพ (ว่าง = ใช้กลุ่มอาชีพของรายวิชา)
 * 2.1 มาตรฐานอาชีพมาจากรายวิชา (courses.occupation_standard) · ว่างทั้งหมด = NULL
 */
final class UnitPlanFields
{
    public const MAX_EOC = 12;

    /** @return array{uoc:string,eoc:list<string>,career:string} */
    public static function decode(?string $json): array
    {
        $data = $json ? json_decode($json, true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'uoc' => is_string($data['uoc'] ?? null) ? $data['uoc'] : '',
            'eoc' => self::list($data['eoc'] ?? []),
            'career' => is_string($data['career'] ?? null) ? $data['career'] : '',
        ];
    }

    /**
     * ค่าจากฟอร์มหน้าแก้ไขหน่วย (std_uoc, std_eoc หลายบรรทัด, std_career) → JSON หรือ null
     * @param array<string,mixed> $data
     */
    public static function fromPost(array $data): ?string
    {
        return self::encode([
            'uoc' => (string) ($data['std_uoc'] ?? ''),
            'eoc' => self::lines((string) ($data['std_eoc'] ?? '')),
            'career' => (string) ($data['std_career'] ?? ''),
        ]);
    }

    /** @param array<string,mixed> $value */
    public static function encode(array $value): ?string
    {
        $out = [
            'uoc' => mb_substr(trim((string) ($value['uoc'] ?? '')), 0, 500),
            'eoc' => array_slice(self::list($value['eoc'] ?? []), 0, self::MAX_EOC),
            'career' => mb_substr(trim((string) ($value['career'] ?? '')), 0, 1000),
        ];

        return $out['uoc'] === '' && $out['eoc'] === [] && $out['career'] === ''
            ? null
            : json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /** @return list<string> แยกบรรทัด ตัดเลขข้อ/สัญลักษณ์นำหน้าที่พิมพ์มาเอง */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R+/u', trim($text)) ?: [] as $line) {
            $line = trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)]|[๑-๙]+[.)])\s*/u', '', $line));
            if ($line !== '') {
                $out[] = mb_substr($line, 0, 1000);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            return self::lines($value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_scalar($item) && ($item = trim((string) $item)) !== '') {
                $out[] = mb_substr($item, 0, 1000);
            }
        }

        return $out;
    }
}
