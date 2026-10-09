<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddTeacherRegistration extends Migration
{
    public function description(): string
    {
        return 'เพิ่มสถานะ "รออนุมัติ" และข้อมูลสาขาวิชา/สถานศึกษาสำหรับครูที่สมัครสมาชิกเอง';
    }

    public function up(Runner $db): void
    {
        // เพิ่ม pending เข้าไปใน enum เดิม (active, suspended) — บัญชีที่สมัครเองเริ่มที่สถานะนี้
        // จนกว่าผู้ดูแลจะอนุมัติ
        $db->exec(sprintf(
            "ALTER TABLE `%s` MODIFY COLUMN `status` ENUM('active','pending','suspended') NOT NULL DEFAULT 'active'",
            $this->table('users')
        ));

        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN `subject_area` VARCHAR(191) NULL COMMENT 'สาขาวิชาที่สอน (กรอกเองตอนสมัคร)' AFTER `phone`,
                ADD COLUMN `institution` VARCHAR(191) NULL COMMENT 'สถานศึกษาที่สังกัด (กรอกเองตอนสมัคร)' AFTER `subject_area`",
            $this->table('users')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s` DROP COLUMN `institution`, DROP COLUMN `subject_area`",
            $this->table('users')
        ));
        $db->exec(sprintf(
            "ALTER TABLE `%s` MODIFY COLUMN `status` ENUM('active','suspended') NOT NULL DEFAULT 'active'",
            $this->table('users')
        ));
    }
}
