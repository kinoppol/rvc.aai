<?php

declare(strict_types=1);

namespace App\Support;

/**
 * หน้าปกรายวิชา — สีและรูปพื้นหลังที่ใช้ตรงกันทุกที่ (การ์ดรายวิชา แบนเนอร์หน้ารายวิชา หน้าแรกสาธารณะ)
 *
 * ครูเลือกสีเอง (courses.cover_color) หรืออัปโหลดรูป (courses.cover_image) ได้
 * ถ้าไม่ได้เลือก ระบบสุ่มสีจาก id รายวิชา — วิชาเดียวกันได้สีเดิมเสมอ ไม่เปลี่ยนตามลำดับในรายการ
 * สีทุกสีในชุดนี้เข้มพอให้ตัวอักษรสีขาวอ่านชัด · เก็บ/ส่งแบบไม่มี # (Latte escape # ใน style)
 */
final class Cover
{
    /**
     * เป็นรายการ [รหัสสี, ชื่อ] ไม่ใช้รหัสสีเป็น key — รหัสที่เป็นตัวเลขล้วน (475569) PHP จะแปลง key เป็น int
     *
     * @var list<array{0:string,1:string}>
     */
    public const PALETTE = [
        ['4F46E5', 'คราม'],
        ['7C3AED', 'ม่วง'],
        ['C026D3', 'บานเย็น'],
        ['DB2777', 'ชมพู'],
        ['E11D48', 'กุหลาบ'],
        ['EA580C', 'ส้ม'],
        ['B45309', 'อำพัน'],
        ['059669', 'มรกต'],
        ['0F766E', 'เขียวหัวเป็ด'],
        ['0284C7', 'ฟ้า'],
        ['2563EB', 'น้ำเงิน'],
        ['475569', 'เทาฟ้า'],
    ];

    /** @return list<string> */
    public static function hexes(): array
    {
        return array_column(self::PALETTE, 0);
    }

    /** สีหน้าปกของรายวิชา */
    public static function color(array $course): string
    {
        $own = strtoupper(trim((string) ($course['cover_color'] ?? '')));
        if (preg_match('/^[0-9A-F]{6}$/', $own) === 1) {
            return $own;
        }

        $hexes = self::hexes();

        return $hexes[crc32('course-' . (int) ($course['id'] ?? 0)) % count($hexes)];
    }

    /** ที่อยู่รูปหน้าปก (มีเลขเวอร์ชันในชื่อไฟล์ เปลี่ยนรูปแล้วเบราว์เซอร์โหลดใหม่เอง) */
    public static function imageUrl(array $course): ?string
    {
        $file = (string) ($course['cover_image'] ?? '');

        return $file === '' ? null : Url::to('/course-cover/' . (int) $course['id'] . '?v=' . substr(md5($file), 0, 8));
    }

    /**
     * เติมข้อมูลหน้าปกให้แถวรายวิชา: color (สีหลัก), accent (สีคู่สำหรับไล่สี), cover_url
     *
     * @param array<string,mixed> $course
     * @return array<string,mixed>
     */
    public static function decorate(array $course): array
    {
        $color = self::color($course);

        return [
            'color' => $color,
            'accent' => self::accent($color),
            'cover_url' => self::imageUrl($course),
        ] + $course;
    }

    /**
     * สีคู่สำหรับไล่สีหน้าปก — ถ้าสว่างเกิน (เช่น เหลือง) ดึงกลับเข้าหาสีหลัก ตัวอักษรสีขาวจะได้อ่านชัดทั้งแผ่น
     * (cover-picker.js คำนวณแบบเดียวกันสำหรับตัวอย่างหน้าปก)
     */
    public static function accent(string $color): string
    {
        $accent = Palette::accentFor($color);
        $lum = static function (string $hex): float {
            return (0.299 * hexdec(substr($hex, 0, 2)) + 0.587 * hexdec(substr($hex, 2, 2)) + 0.114 * hexdec(substr($hex, 4, 2))) / 255;
        };

        if ($lum($accent) <= 0.6) {
            return $accent;
        }

        $mixed = '';
        foreach ([0, 2, 4] as $i) {
            $a = hexdec(substr($accent, $i, 2));
            $mixed .= sprintf('%02X', (int) round($a + (hexdec(substr($color, $i, 2)) - $a) * 0.45));
        }

        return $mixed;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    public static function decorateAll(array $rows): array
    {
        return array_map([self::class, 'decorate'], $rows);
    }

    /** ทำให้รหัสสีที่ครูส่งมาอยู่ในรูป RRGGBB — คืน null ถ้าว่างหรือไม่ถูกต้อง (= กลับไปสุ่มตาม id) */
    public static function normalise(?string $hex): ?string
    {
        $hex = strtoupper(ltrim(trim((string) $hex), '#'));

        return preg_match('/^[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    public static function dir(): string
    {
        return Paths::storage('uploads/covers');
    }
}
