<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * แบบฟอร์มแผนการจัดการเรียนรู้ที่ครูเลือกพิมพ์ต่อรายวิชา (App\Support\PlanFormat)
 *   NULL = ค่าเริ่มต้น (แผนการจัดการเรียนรู้หลักสูตร 2567) · competency = แบบมุ่งเน้นสมรรถนะเดิม
 */
final class AddCoursePlanFormat extends Migration
{
    public function description(): string
    {
        return 'เพิ่มการเลือกแบบฟอร์มแผนการจัดการเรียนรู้ของรายวิชา';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `plan_format` VARCHAR(20) NULL COMMENT \'แบบฟอร์มแผนการสอน (NULL = ค่าเริ่มต้น)\'',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `plan_format`', $this->table('courses')));
    }
}
