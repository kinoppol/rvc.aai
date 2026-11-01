<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * บันทึกว่าการเรียก AI แต่ละครั้งใช้ทำงานอะไร (ai_usage_logs.task) ให้หน้าสถิติการใช้งานแยกตามงานได้
 *   chat = แชทผู้ช่วย · quiz = ออกข้อสอบ · plan = แผนการสอน · ocr = อ่านไฟล์สแกน
 *   งานร่างสั้น ๆ ใช้ชื่อ task ของ Drafter ตรง ๆ (unit_outline, unit_content, assignment, course_outcomes, model_roles)
 * แถวก่อนหน้านี้เป็น NULL (หน้าสถิติแสดงเป็น "ไม่ระบุ")
 */
final class AddAiUsageTask extends Migration
{
    public function description(): string
    {
        return 'เพิ่มคอลัมน์ประเภทงานในบันทึกการใช้ AI สำหรับหน้าสถิติ';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `task` VARCHAR(32) NULL COMMENT \'ประเภทงาน\' AFTER `source`, ADD KEY `ix_usage_task` (`task`, `created_at`), ADD KEY `ix_usage_created` (`created_at`)',
            $this->table('ai_usage_logs')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP KEY `ix_usage_created`, DROP KEY `ix_usage_task`, DROP COLUMN `task`',
            $this->table('ai_usage_logs')
        ));
    }
}
