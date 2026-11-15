<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * มาตรฐานอาชีพที่รายวิชาอ้างอิง (หน่วยงานรับรอง มาตรฐาน อาชีพ + ตาราง UoC/EoC/PC/วิธีประเมิน)
 * เก็บเป็น JSON — รูปแบบอยู่ใน App\Support\OccupationStandard · NULL = ไม่มี (หน้าพิมพ์ข้ามหน้านี้)
 */
final class AddCourseOccupationStandard extends Migration
{
    public function description(): string
    {
        return 'เพิ่มข้อมูลมาตรฐานอาชีพของรายวิชาสำหรับแผนการสอน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `occupation_standard` MEDIUMTEXT NULL COMMENT \'มาตรฐานอาชีพ (JSON)\'',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `occupation_standard`', $this->table('courses')));
    }
}
