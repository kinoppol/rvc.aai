<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * หัวหน้าแผนกวิชาที่ลงนามในแผนการจัดการเรียนรู้ของรายวิชา (ครูเลือกจากรายชื่อบุคลากร)
 * ผู้ลงนามตำแหน่งอื่น (หัวหน้างานหลักสูตร รองฯ วิชาการ ผู้อำนวยการ) เก็บใน settings โดยผู้ดูแลระบบ
 */
final class AddCourseDeptHead extends Migration
{
    public function description(): string
    {
        return 'เพิ่มหัวหน้าแผนกวิชาผู้ลงนามในแผนการสอนของรายวิชา';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `dept_head_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'หัวหน้าแผนกวิชา (ผู้ลงนามในแผนการสอน)' AFTER `plan_date`,
                ADD CONSTRAINT `fk_course_dept_head` FOREIGN KEY (`dept_head_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL",
            $this->table('courses'),
            $this->table('users')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP FOREIGN KEY IF EXISTS `fk_course_dept_head`, DROP COLUMN IF EXISTS `dept_head_id`',
            $this->table('courses')
        ));
    }
}
