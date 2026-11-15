<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ข้อมูลตามแบบฟอร์มแผนการจัดการเรียนรู้หลักสูตร 2567 ระดับหน่วยและใบงาน
 * - units.learning_outcome = ผลลัพธ์การเรียนรู้ระดับหน่วยการเรียน (หนึ่งข้อต่อบรรทัด)
 * - units.standard_ref     = อ้างอิงมาตรฐาน/เชื่อมโยงกลุ่มอาชีพ (JSON: App\Support\UnitPlanFields)
 * - assignments.sheet      = ส่วนประกอบของใบงานตามแบบฟอร์ม (JSON: App\Support\WorksheetSheet)
 */
final class AddUnitPlanFields extends Migration
{
    public function description(): string
    {
        return 'เพิ่มผลลัพธ์การเรียนรู้/อ้างอิงมาตรฐานระดับหน่วย และส่วนประกอบใบงานตามแบบฟอร์มแผนการสอน 2567';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `learning_outcome` TEXT NULL COMMENT \'ผลลัพธ์การเรียนรู้ระดับหน่วย\',
                 ADD COLUMN `standard_ref` TEXT NULL COMMENT \'อ้างอิงมาตรฐาน/กลุ่มอาชีพ (JSON)\'',
            $this->table('units')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `sheet` MEDIUMTEXT NULL COMMENT \'ส่วนประกอบใบงานตามแบบฟอร์ม (JSON)\'',
            $this->table('assignments')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `learning_outcome`, DROP COLUMN `standard_ref`', $this->table('units')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `sheet`', $this->table('assignments')));
    }
}
