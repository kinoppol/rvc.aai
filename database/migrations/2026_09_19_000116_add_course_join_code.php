<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddCourseJoinCode extends Migration
{
    public function description(): string
    {
        return 'เพิ่มรหัสเข้าร่วมรายวิชา — นักเรียนกรอกรหัสหรือเปิดลิงก์ที่ครูแจก เพื่อลงทะเบียนเข้ารายวิชาเอง';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `join_code` VARCHAR(12) NULL
                    COMMENT 'รหัสเข้าร่วมรายวิชา สร้างให้ครั้งแรกที่ครูเปิดแท็บนักเรียน',
                ADD COLUMN IF NOT EXISTS `join_enabled` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'เปิดให้นักเรียนเข้าร่วมด้วยรหัส/ลิงก์ได้หรือไม่'",
            $this->table('courses')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY IF NOT EXISTS `uk_course_join_code` (`join_code`)',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP INDEX IF EXISTS `uk_course_join_code`,
                DROP COLUMN IF EXISTS `join_code`, DROP COLUMN IF EXISTS `join_enabled`',
            $this->table('courses')
        ));
    }
}
