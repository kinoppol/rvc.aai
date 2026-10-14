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

    /** ค่าตั้งรายผู้ใช้ `admin_teaches:{id}` = ผู้ดูแลระบบคนนี้สอนด้วย (ใช้เมนูฝั่งครูได้) */
    public const ADMIN_TEACHES = 'admin_teaches';

    /** ตัวเลือกบทบาทในหน้าผู้ใช้งาน = ผู้ดูแลระบบ + สอนด้วย (บันทึกเป็น role admin + ค่าตั้งข้างบน) */
    public const ADMIN_TEACHING = 'admin_teaching';

    /**
     * ผู้ใช้คนนี้ใช้งานฝั่งครูได้ไหม — ครู ผู้ดูแลครู และผู้ดูแลระบบที่ได้รับแต่งตั้งให้สอนด้วย
     * $user มาจาก Auth::user() ซึ่งเติมคีย์ `teaches` ไว้แล้ว
     *
     * @param array<string,mixed>|null $user
     */
    public static function canTeach(?array $user): bool
    {
        return $user !== null && (self::teaches($user['role'] ?? null) || !empty($user['teaches']));
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
