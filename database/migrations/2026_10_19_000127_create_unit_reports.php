<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * บันทึกหลังการจัดการเรียนรู้ของแต่ละหน่วย (ข้อ 9 ในแผนการสอนแบบ สอศ. 2567)
 * ครูกรอกหลังสอนจบหน่วย — หน่วยละหนึ่งแถว และพิมพ์ลงแผนพร้อมวันที่บันทึก
 *
 *   plan_result     9.1 ผลการใช้แผนการจัดการเรียนรู้
 *   learner_result  9.2 ผลการเรียนรู้ของผู้เรียน
 *   problems        9.3 ปัญหาที่พบและแนวทางแก้ไข
 *   recorded_on     วันที่บันทึก (แสดงใต้ลายมือชื่อครูผู้สอนท้ายแผนรายหน่วย)
 */
final class CreateUnitReports extends Migration
{
    public function description(): string
    {
        return 'เพิ่มตารางบันทึกหลังการจัดการเรียนรู้รายหน่วย';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `unit_id` INT UNSIGNED NOT NULL,
            `plan_result` TEXT NULL COMMENT \'ผลการใช้แผนการจัดการเรียนรู้\',
            `learner_result` TEXT NULL COMMENT \'ผลการเรียนรู้ของผู้เรียน\',
            `problems` TEXT NULL COMMENT \'ปัญหาที่พบและแนวทางแก้ไข\',
            `recorded_on` DATE NULL COMMENT \'วันที่บันทึกหลังสอน\',
            `recorded_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`unit_id`),
            CONSTRAINT `fk_unit_report_unit` FOREIGN KEY (`unit_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('unit_reports'), $this->table('units'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('unit_reports')));
    }
}
