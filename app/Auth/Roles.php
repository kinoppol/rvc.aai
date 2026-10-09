<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * บทบาทผู้ใช้และกลุ่มสิทธิ์ที่ใช้ร่วมกันทั้งระบบ
 *
 * "ผู้ดูแลครู" (supervisor) คือครูที่ได้รับแต่งตั้งให้กำกับดูแลเพิ่ม — ยังมีรายวิชาของตัวเองเหมือนครูทั่วไป
 * และดูภาพรวมผู้ใช้/รายวิชา รวมถึงตรวจเนื้อหาหน่วยการเรียนของครูทุกคนได้
 * แต่ไม่แตะเรื่อง AI โควตา และการตั้งค่าทางเทคนิค ซึ่งสงวนไว้ให้ผู้ดูแลระบบเท่านั้น
 */
final class Roles
{
    public const ADMIN = 'admin';
    public const SUPERVISOR = 'supervisor';
    public const TEACHER = 'teacher';
    public const STUDENT = 'student';

    /** บทบาทที่มีรายวิชาของตัวเอง ใช้งานเมนูฝั่งครูได้ทั้งหมด */
    public const TEACHING = [self::TEACHER, self::SUPERVISOR];

    /** บทบาทที่ดูภาพรวมและตรวจเนื้อหาของครูคนอื่นได้ */
    public const OVERSIGHT = [self::ADMIN, self::SUPERVISOR];

    /** บทบาทของบุคลากร (ใช้ตอนค้นบัญชีเข้าสู่ระบบฝั่งครู/ผู้ดูแล) */
    public const STAFF = [self::ADMIN, self::SUPERVISOR, self::TEACHER];

    /** บทบาทที่ผู้ดูแลระบบแต่งตั้งให้กันได้ */
    public const ASSIGNABLE = [self::ADMIN, self::SUPERVISOR, self::TEACHER];

    public static function label(string $role): string
    {
        return match ($role) {
            self::ADMIN => 'ผู้ดูแลระบบ',
            self::SUPERVISOR => 'ผู้ดูแลครู',
            self::TEACHER => 'ครูผู้สอน',
            default => 'นักเรียน',
        };
    }

    public static function teaches(?string $role): bool
    {
        return in_array((string) $role, self::TEACHING, true);
    }

    public static function oversees(?string $role): bool
    {
        return in_array((string) $role, self::OVERSIGHT, true);
    }
}
