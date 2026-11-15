<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * กลุ่มอาชีพของรายวิชา (หลักสูตร ปวช. 2567) — พิมพ์บนปกและหน้าหลักสูตรรายวิชาของแผนการสอน
 * ตัวเลือกมาตรฐานอยู่ใน App\Support\CareerGroups · ชื่อที่ครูพิมพ์เองเห็นเฉพาะในรายวิชาของครูคนนั้น
 */
final class AddCourseCareerGroup extends Migration
{
    public function description(): string
    {
        return 'เพิ่มกลุ่มอาชีพของรายวิชาสำหรับแผนการสอน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `career_group` VARCHAR(191) NULL COMMENT \'กลุ่มอาชีพ\' AFTER `program_type`',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `career_group`', $this->table('courses')));
    }
}
