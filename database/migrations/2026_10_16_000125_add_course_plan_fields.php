<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ข้อมูลรายวิชาสำหรับแผนการจัดการเรียนรู้ (สอศ. 2567) ที่ครูกรอกเอง
 *
 *   courses.program_type       ประเภทวิชา (เช่น อุตสาหกรรม, บริหารธุรกิจ)
 *   courses.major              สาขาวิชา
 *   courses.plan_date          วันที่จัดทำแผน (แสดงใต้ลายมือชื่อครูผู้สอนในหน้าพิมพ์)
 *   courses.learning_outcomes  ผลลัพธ์การเรียนรู้ระดับรายวิชา (ข้อละบรรทัด · ให้ผู้ช่วย AI ร่างได้)
 */
final class AddCoursePlanFields extends Migration
{
    public function description(): string
    {
        return 'เพิ่มประเภทวิชา สาขาวิชา วันที่จัดทำแผน และผลลัพธ์การเรียนรู้ระดับรายวิชา';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `program_type` VARCHAR(191) NULL DEFAULT NULL COMMENT 'ประเภทวิชา' AFTER `competencies`,
                ADD COLUMN IF NOT EXISTS `major` VARCHAR(191) NULL DEFAULT NULL COMMENT 'สาขาวิชา' AFTER `program_type`,
                ADD COLUMN IF NOT EXISTS `plan_date` DATE NULL DEFAULT NULL COMMENT 'วันที่จัดทำแผน' AFTER `major`,
                ADD COLUMN IF NOT EXISTS `learning_outcomes` TEXT NULL DEFAULT NULL COMMENT 'ผลลัพธ์การเรียนรู้ระดับรายวิชา' AFTER `plan_date`",
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `learning_outcomes`, DROP COLUMN IF EXISTS `plan_date`,
                DROP COLUMN IF EXISTS `major`, DROP COLUMN IF EXISTS `program_type`',
            $this->table('courses')
        ));
    }
}
