<?php

declare(strict_types=1);

namespace App\Support;

/**
 * มาตรฐานอาชีพที่รายวิชาอ้างอิง (หน้า "มาตรฐานอาชีพ" ของแผนการสอนหลักสูตร 2567)
 * เก็บเป็น JSON ใน courses.occupation_standard:
 *   {agency, standard, occupation, rows: [{uoc_code, uoc, eoc_code, eoc, pc, method}]}
 * ไม่มีข้อมูลเลย = NULL → หน้าพิมพ์ข้ามหน้านี้ไป
 */
final class OccupationStandard
{
    public const MAX_ROWS = 60;
    public const HEAD = ['agency', 'standard', 'occupation'];
    public const COLS = ['uoc_code', 'uoc', 'eoc_code', 'eoc', 'pc', 'method'];

    /**
     * แปลงค่าจากฟอร์ม (os_agency, os_standard, os_occupation, os_{col}[]) เป็น JSON — ว่างทั้งหมด = null
     * @param array<string,mixed> $data
     */
    public static function fromPost(array $data): ?string
    {
        $out = [];
        foreach (self::HEAD as $key) {
            $out[$key] = self::clean($data['os_' . $key] ?? '', 255);
        }
        $rows = [];
        $count = 0;
        foreach (self::COLS as $col) {
            $count = max($count, is_array($data['os_' . $col] ?? null) ? count($data['os_' . $col]) : 0);
        }
        for ($i = 0; $i < min($count, self::MAX_ROWS); $i++) {
            $row = [];
            foreach (self::COLS as $col) {
                $value = is_array($data['os_' . $col] ?? null) ? ($data['os_' . $col][$i] ?? '') : '';
                $row[$col] = self::clean($value, str_ends_with($col, 'code') ? 50 : 2000, multiline: !str_ends_with($col, 'code'));
            }
            if (implode('', $row) !== '') {
                $rows[] = $row;
            }
        }
        $out['rows'] = $rows;

        return self::isEmpty($out) ? null : json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    /** @return array{agency:string,standard:string,occupation:string,rows:list<array<string,string>>}|null */
    public static function decode(?string $json): ?array
    {
        $data = $json ? json_decode($json, true) : null;
        if (!is_array($data)) {
            return null;
        }
        $out = [];
        foreach (self::HEAD as $key) {
            $out[$key] = is_string($data[$key] ?? null) ? $data[$key] : '';
        }
        $out['rows'] = [];
        foreach (is_array($data['rows'] ?? null) ? $data['rows'] : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach (self::COLS as $col) {
                $clean[$col] = is_string($row[$col] ?? null) ? $row[$col] : '';
            }
            $out['rows'][] = $clean;
        }

        return self::isEmpty($out) ? null : $out;
    }

    /**
     * แถวสำหรับหน้าพิมพ์: รหัส/คำอธิบาย UoC ที่ซ้ำกับแถวก่อนหน้ารวมเป็นช่องเดียว (rowspan)
     * @param list<array<string,string>> $rows
     * @return list<array<string,mixed>>
     */
    public static function printRows(array $rows): array
    {
        $out = [];
        $head = null;
        foreach ($rows as $row) {
            $same = $head !== null
                && ($row['uoc_code'] === '' && $row['uoc'] === ''
                    || ($row['uoc_code'] === $out[$head]['uoc_code'] && $row['uoc'] === $out[$head]['uoc']));
            if ($same) {
                $out[$head]['span']++;
                $out[] = $row + ['span' => 0];
            } else {
                $head = count($out);
                $out[] = $row + ['span' => 1];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function isEmpty(array $data): bool
    {
        return implode('', array_map(static fn (string $k): string => (string) ($data[$k] ?? ''), self::HEAD)) === ''
            && ($data['rows'] ?? []) === [];
    }

    private static function clean(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_string($value) ? str_replace("\r\n", "\n", $value) : '';
        $value = $multiline
            ? trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '')
            : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($value, 0, $max);
    }
}
