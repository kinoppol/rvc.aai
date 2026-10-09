<?php

declare(strict_types=1);

namespace App\Support;

/**
 * สร้างชุดสีของธีมจากสีหลักเพียงสีเดียว
 *
 * เก็บค่าสีเป็นเลขฐานสิบหกแบบไม่มี # เพราะ Latte จะ escape # ใน context ของ CSS
 * เทมเพลตจึงเติม # เองเป็น "--brand: #{$ui['brand']}"
 */
final class Palette
{
    /** สีเขียวเดิมของระบบ */
    public const DEFAULT = '0E6B60';

    /** สีสำเร็จรูปให้เลือกโดยไม่ต้องพิมพ์รหัสสีเอง */
    public const PRESETS = [
        '0E6B60' => 'เขียวหัวเป็ด (ค่าเริ่มต้น)',
        '1F5F3F' => 'เขียวใบไม้',
        '2D6E8E' => 'ฟ้าน้ำทะเล',
        '2A4E9B' => 'น้ำเงิน',
        '6B5B95' => 'ม่วง',
        'A23B72' => 'ชมพูบานเย็น',
        'B4432A' => 'ส้มอิฐ',
        '8A5A3B' => 'น้ำตาล',
        '9C6206' => 'เหลืองทอง',
        '3B4252' => 'เทาเข้ม',
    ];

    /** ทำให้รหัสสีอยู่ในรูป RRGGBB ตัวพิมพ์ใหญ่ คืนค่าเริ่มต้นถ้าไม่ถูกต้อง */
    public static function normalise(?string $hex): string
    {
        $hex = strtoupper(ltrim(trim((string) $hex), '#'));

        if (preg_match('~^[0-9A-F]{3}$~', $hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return preg_match('~^[0-9A-F]{6}$~', $hex) ? $hex : self::DEFAULT;
    }

    /**
     * ชุดตัวแปร CSS ที่ได้จากสีหลัก — มีทั้งชุดโหมดสว่างและโหมดมืด
     *
     * @return array{brand:string,brandInk:string,brandSoft:string,brandLine:string,
     *               darkBrand:string,darkInk:string,darkSoft:string,darkLine:string}
     */
    public static function tokens(?string $hex): array
    {
        $base = self::normalise($hex);
        [$r, $g, $b] = self::rgb($base);

        $light = self::luminance($r, $g, $b);
        $onBrand = $light > 0.55 ? '16221F' : 'FFFFFF';

        // โหมดมืดต้องใช้สีที่สว่างขึ้น ไม่งั้นจมไปกับพื้นหลัง
        $darkBrand = self::mix($base, 'FFFFFF', 0.42);
        [$dr, $dg, $db] = self::rgb($darkBrand);

        return [
            'brand' => $base,
            'brandInk' => $onBrand,
            'brandSoft' => self::mix($base, 'FFFFFF', 0.88),
            'brandLine' => self::mix($base, 'FFFFFF', 0.62),
            'darkBrand' => $darkBrand,
            'darkInk' => self::luminance($dr, $dg, $db) > 0.55 ? self::mix($base, '000000', 0.72) : 'FFFFFF',
            'darkSoft' => self::mix($base, '0E1513', 0.76),
            'darkLine' => self::mix($base, '0E1513', 0.58),
        ];
    }

    /** @return array{int,int,int} */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** ผสมสีสองสี — $amount คือสัดส่วนของสีที่สอง (0–1) */
    private static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);

        return sprintf(
            '%02X%02X%02X',
            (int) round($r1 + ($r2 - $r1) * $amount),
            (int) round($g1 + ($g2 - $g1) * $amount),
            (int) round($b1 + ($b2 - $b1) * $amount)
        );
    }

    /** ความสว่างแบบถ่วงน้ำหนักสายตา ใช้ตัดสินว่าตัวอักษรบนสีนี้ควรขาวหรือดำ */
    private static function luminance(int $r, int $g, int $b): float
    {
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }
}
