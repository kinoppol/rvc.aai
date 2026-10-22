<?php

declare(strict_types=1);

namespace App\Support;

use Latte\Runtime\Html;

/**
 * ข้อความที่ผู้ใช้พิมพ์ (ประกาศ ข้อความส่วนตัว) → HTML ที่ปลอดภัย
 * escape ทั้งหมดก่อน แล้วค่อยทำลิงก์ http(s) ให้กดได้ · การขึ้นบรรทัดใช้ CSS white-space: pre-wrap
 */
final class RichText
{
    public static function render(?string $text): Html
    {
        $safe = htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $html = preg_replace_callback(
            // ข้อความถูก escape แล้ว — หยุดที่ &quot; &#039; &lt; &gt; ซึ่งเดิมคือเครื่องหมายคำพูด/วงเล็บแหลม
            '~\bhttps?://(?:(?!&(?:quot|#039|lt|gt);)\S)+~u',
            static function (array $m): string {
                // ไม่เอาเครื่องหมายวรรคตอนท้ายประโยคติดไปกับลิงก์
                $url = rtrim($m[0], '.,;:!?)');
                $rest = substr($m[0], strlen($url));

                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $url . '</a>' . $rest;
            },
            $safe
        );

        return new Html($html ?? $safe);
    }

    /** ตัดข้อความให้สั้นสำหรับแสดงในรายการ (ไม่ตัดกลางสระ/วรรณยุกต์) */
    public static function excerpt(?string $text, int $length = 90): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($flat) > $length ? rtrim(mb_substr($flat, 0, $length)) . '…' : $flat;
    }
}
