<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;

/**
 * ตัวช่วยจัดรูปแบบข้อความภาษาไทย: วันที่ พ.ศ. ชื่อย่อ และเวลาที่ผ่านมา
 */
final class Thai
{
    private const MONTHS = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.', 5 => 'พ.ค.', 6 => 'มิ.ย.',
        7 => 'ก.ค.', 8 => 'ส.ค.', 9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.',
    ];

    /** 2026-09-10 15:04:00 → "10 ก.ย. 2569" */
    public static function date(DateTimeInterface|string|null $when): string
    {
        $ts = self::timestamp($when);
        if ($ts === null) {
            return '—';
        }

        return sprintf('%d %s %d', (int) date('j', $ts), self::MONTHS[(int) date('n', $ts)], (int) date('Y', $ts) + 543);
    }

    /** เพิ่มเวลาต่อท้ายวันที่ เช่น "10 ก.ย. 2569 15:04" */
    public static function dateTime(DateTimeInterface|string|null $when): string
    {
        $ts = self::timestamp($when);

        return $ts === null ? '—' : self::date($when) . ' ' . date('H:i', $ts);
    }

    /** ระยะเวลาที่ผ่านมาแบบอ่านง่าย เช่น "เมื่อสักครู่", "3 ชม.ที่แล้ว", "เมื่อวาน 16:02" */
    public static function ago(DateTimeInterface|string|null $when): string
    {
        $ts = self::timestamp($when);
        if ($ts === null) {
            return '—';
        }

        $diff = time() - $ts;

        return match (true) {
            $diff < 60 => 'เมื่อสักครู่',
            $diff < 3600 => sprintf('%d นาทีที่แล้ว', intdiv($diff, 60)),
            $diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d') => 'วันนี้ ' . date('H:i', $ts),
            date('Y-m-d', $ts) === date('Y-m-d', time() - 86400) => 'เมื่อวาน ' . date('H:i', $ts),
            $diff < 7 * 86400 => sprintf('%d วันที่แล้ว', intdiv($diff, 86400)),
            default => self::date($when),
        };
    }

    /** ตัวอักษรย่อจากชื่อ-สกุลไทย ใช้ทำ avatar เช่น "ธนพล ศรีบุญเรือง" → "ธศ" */
    public static function initials(string $name): string
    {
        $name = trim(preg_replace('/^(อ\.|นาย|นาง|นางสาว|น\.ส\.|ด\.ช\.|ด\.ญ\.)\s*/u', '', $name) ?? $name);
        $parts = preg_split('/\s+/u', $name) ?: [];
        $take = static fn (string $s): string => mb_substr($s, 0, 1);

        if (count($parts) >= 2) {
            return $take($parts[0]) . $take($parts[1]);
        }

        return mb_substr($name, 0, 2) ?: '??';
    }

    private static function timestamp(DateTimeInterface|string|null $when): ?int
    {
        if ($when === null || $when === '') {
            return null;
        }
        if ($when instanceof DateTimeInterface) {
            return $when->getTimestamp();
        }

        $ts = strtotime($when);

        return $ts === false ? null : $ts;
    }
}
