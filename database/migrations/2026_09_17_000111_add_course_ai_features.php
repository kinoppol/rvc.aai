<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddCourseAiFeatures extends Migration
{
    public function description(): string
    {
        return 'เพิ่มสวิตช์เปิด/ปิดฟังก์ชัน AI รายวิชา (ออกข้อสอบ, แผนการจัดการเรียนรู้) ให้ผู้ดูแลระบบควบคุมได้';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `ai_quiz_enabled` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'เปิดให้ครูใช้ AI ออกข้อสอบสำหรับรายวิชานี้',
                ADD COLUMN IF NOT EXISTS `ai_lesson_plan_enabled` TINYINT(1) NOT NULL DEFAULT 1
                    COMMENT 'เปิดให้ครูใช้ AI ทำแผนการจัดการเรียนรู้สำหรับรายวิชานี้'",
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `ai_quiz_enabled`, DROP COLUMN IF EXISTS `ai_lesson_plan_enabled`',
            $this->table('courses')
        ));
    }
}
