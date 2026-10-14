<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ประเภทของหน่วยการเรียนและงาน
 *
 *   units.kind        lesson = หน่วยการเรียนปกติ · midterm / final = หน่วยสอบกลางภาค / ปลายภาค
 *                     (หน่วยสอบไม่มีเนื้อหา มีแต่แบบทดสอบ)
 *   assignments.kind  worksheet = ใบงานของหน่วย · task = งานที่มอบหมายเพิ่มเติม (ผูกหน่วยหรือไม่ก็ได้)
 */
final class AddUnitAndAssignmentKinds extends Migration
{
    public function description(): string
    {
        return 'เพิ่มหน่วยสอบกลางภาค/ปลายภาค และงานที่มอบหมายเพิ่มเติมนอกเหนือจากใบงาน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `kind` ENUM('lesson','midterm','final') NOT NULL DEFAULT 'lesson'
                COMMENT 'lesson = หน่วยการเรียน · midterm/final = หน่วยสอบ' AFTER `title`",
            $this->table('units')
        ));
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `kind` ENUM('worksheet','task') NOT NULL DEFAULT 'worksheet'
                COMMENT 'worksheet = ใบงานของหน่วย · task = งานที่มอบหมายเพิ่มเติม' AFTER `unit_id`",
            $this->table('assignments')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN IF EXISTS `kind`', $this->table('assignments')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN IF EXISTS `kind`', $this->table('units')));
    }
}
