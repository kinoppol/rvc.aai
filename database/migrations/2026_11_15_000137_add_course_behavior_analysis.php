<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ตารางวิเคราะห์พฤติกรรมการเรียนรู้ของรายวิชา (ระดับ K/S/A/Ap + ร้อยละประเมินผลต่อหน่วย)
 * เก็บเป็น JSON — รูปแบบอยู่ใน App\Support\BehaviorAnalysis · NULL = ยังไม่วิเคราะห์ (หน้าพิมพ์เว้นช่องว่าง)
 */
final class AddCourseBehaviorAnalysis extends Migration
{
    public function description(): string
    {
        return 'เพิ่มตารางวิเคราะห์พฤติกรรมการเรียนรู้ของรายวิชาสำหรับแผนการสอน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `behavior_analysis` MEDIUMTEXT NULL COMMENT \'ตารางวิเคราะห์พฤติกรรมการเรียนรู้ (JSON)\'',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `behavior_analysis`', $this->table('courses')));
    }
}
