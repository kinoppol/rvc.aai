<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * จำนวนสัปดาห์ของรายวิชาและของแต่ละหน่วย
 *
 *   courses.weeks  สัปดาห์เรียนทั้งภาค "รวมสัปดาห์สอบ" · NULL = ค่าปกติตามระดับชั้นจากรหัสวิชา (App\Support\Semester)
 *   units.weeks    สัปดาห์ที่หน่วยการเรียนนี้ใช้ · NULL = ให้แผนการสอนแบ่งจากสัปดาห์ที่เหลือเท่า ๆ กัน
 */
final class AddSemesterWeeks extends Migration
{
    public function description(): string
    {
        return 'เพิ่มจำนวนสัปดาห์เรียนรวมวัดผลของรายวิชา และจำนวนสัปดาห์ของแต่ละหน่วยการเรียน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `weeks` TINYINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'สัปดาห์เรียนรวมวัดผล · NULL = ตามระดับชั้น' AFTER `practice_hours`",
            $this->table('courses')
        ));
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `weeks` TINYINT UNSIGNED NULL DEFAULT NULL
                COMMENT 'สัปดาห์ของหน่วย · NULL = แบ่งเท่า ๆ กัน' AFTER `kind`",
            $this->table('units')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN IF EXISTS `weeks`', $this->table('units')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN IF EXISTS `weeks`', $this->table('courses')));
    }
}
