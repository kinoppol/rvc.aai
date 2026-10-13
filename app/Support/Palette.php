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
    /** สีม่วงคราม Indigo ของธีมกระจกพาสเทล */
    public const DEFAULT = '6366F1';

    /** สีสำเร็จรูปให้เลือกโดยไม่ต้องพิมพ์รหัสสีเอง */
    public const PRESETS = [
        '6366F1' => 'ม่วงคราม · ม่วง (ค่าเริ่มต้น)',
        '1A73E8' => 'น้ำเงิน · ฟ้าคราม',
        '0E6B60' => 'เขียวหัวเป็ด · เขียวมิ้นต์',
        '1F5F3F' => 'เขียวใบไม้ · เขียวมะนาว',
        '2D6E8E' => 'ฟ้าน้ำทะเล · ฟ้าใส',
        '2A4E9B' => 'น้ำเงิน · ม่วงคราม',
        '6B5B95' => 'ม่วง · ม่วงลาเวนเดอร์',
        'A23B72' => 'บานเย็น · ชมพูกุหลาบ',
        'B4432A' => 'ส้มอิฐ · ส้มอำพัน',
        '8A5A3B' => 'น้ำตาล · คาราเมล',
        '9C6206' => 'เหลืองทอง · ทองอ่อน',
        '3B4252' => 'เทาเข้ม · เทาฟ้า',
    ];

    /**
     * สีคู่ (accent) ที่คัดให้เข้ากับสีหลักแต่ละสี — ใช้เป็นปลายทางของแถบไล่สี ปุ่ม และพื้นหลังพาสเทล
     * สีที่ผู้ใช้เลือกเองนอกรายการนี้ ระบบหาสีคู่ให้ด้วยการหมุนเฉดสีเล็กน้อย (accentFor)
     */
    private const ACCENTS = [
        '6366F1' => '8B5CF6',
        '1A73E8' => '0EA5E9',
        '0E6B60' => '2BB39A',
        '1F5F3F' => '6BA539',
        '2D6E8E' => '38B2D8',
        '2A4E9B' => '6366F1',
        '6B5B95' => 'A78BDA',
        'A23B72' => 'E8608C',
        'B4432A' => 'E8902E',
        '8A5A3B' => 'C9925E',
        '9C6206' => 'D9A21B',
        '3B4252' => '6B7F99',
    ];

    /** พื้นหลังพาสเทล 4 สีของสีเริ่มต้น (ตามแบบที่ออกแบบไว้) — สีอื่นคำนวณจากคู่สี */
    private const DEFAULT_PASTELS = ['E9D5FF', 'FBCFE8', 'DBEAFE', 'DCFCE7'];

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
     * @return array{brand:string,brandInk:string,brandSoft:string,brandLine:string,accent:string,bg:list<string>,
     *               darkBrand:string,darkInk:string,darkSoft:string,darkLine:string,darkAccent:string,darkBg:list<string>}
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

        $accent = self::accentFor($base);
        $night = '0B0B16';

        return [
            'brand' => $base,
            'brandInk' => $onBrand,
            'brandSoft' => self::mix($base, 'FFFFFF', 0.88),
            'brandLine' => self::mix($base, 'FFFFFF', 0.62),
            'accent' => $accent,
            // พื้นหลังพาสเทลไล่ 4 สี: สลับโทนสีหลักกับสีคู่ ให้ทั้งหน้าไปทางเดียวกับธีม
            'bg' => $base === self::DEFAULT ? self::DEFAULT_PASTELS : [
                self::mix($accent, 'FFFFFF', 0.78),
                self::mix($base, 'FFFFFF', 0.86),
                self::mix($accent, 'FFFFFF', 0.9),
                self::mix($base, 'FFFFFF', 0.8),
            ],
            'darkBrand' => $darkBrand,
            'darkInk' => self::luminance($dr, $dg, $db) > 0.55 ? self::mix($base, '000000', 0.72) : 'FFFFFF',
            'darkSoft' => self::mix($base, '0E1513', 0.76),
            'darkLine' => self::mix($base, '0E1513', 0.58),
            'darkAccent' => self::mix($accent, 'FFFFFF', 0.25),
            'darkBg' => [
                self::mix($base, $night, 0.72),
                self::mix($accent, $night, 0.8),
                self::mix($base, $night, 0.88),
                self::mix($accent, $night, 0.74),
            ],
        ];
    }

    /**
     * สีคู่ของสีหลัก — สีสำเร็จรูปใช้คู่ที่คัดไว้ สีที่เลือกเองหมุนเฉดสีไป 30° และสว่างขึ้นเล็กน้อย
     * สีที่แทบไม่มีเฉด (เทา) ใช้โทนเดียวกันที่อ่อนลง จะได้ไม่กลายเป็นสีที่ไม่เกี่ยวกัน
     */
    public static function accentFor(string $hex): string
    {
        $hex = self::normalise($hex);
        if (isset(self::ACCENTS[$hex])) {
            return self::ACCENTS[$hex];
        }

        [$h, $s, $l] = self::hsl(...self::rgb($hex));
        if ($s < 0.15) {
            return self::mix($hex, 'FFFFFF', 0.3);
        }

        return self::fromHsl(fmod($h + 30, 360), min(1.0, $s * 1.05), min(0.62, $l + 0.1));
    }

    /** @return array{float,float,float} เฉด (0–360) ความอิ่ม ความสว่าง (0–1) */
    private static function hsl(int $r, int $g, int $b): array
    {
        $r /= 255; $g /= 255; $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            return [0.0, 0.0, $l];
        }

        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => fmod(($g - $b) / $d + ($g < $b ? 6 : 0), 6),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h * 60, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;

        [$r, $g, $b] = match (true) {
            $h < 60 => [$c, $x, 0.0],
            $h < 120 => [$x, $c, 0.0],
            $h < 180 => [0.0, $c, $x],
            $h < 240 => [0.0, $x, $c],
            $h < 300 => [$x, 0.0, $c],
            default => [$c, 0.0, $x],
        };

        return sprintf('%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
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
