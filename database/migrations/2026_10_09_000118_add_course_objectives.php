<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddCourseObjectives extends Migration
{
    public function description(): string
    {
        return 'เพิ่มจุดประสงค์รายวิชาและสมรรถนะรายวิชาตามหลักสูตร สอศ. ให้รายวิชา';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `objectives` TEXT NULL COMMENT 'จุดประสงค์รายวิชา ข้อละบรรทัด' AFTER `description`,
                ADD COLUMN IF NOT EXISTS `competencies` TEXT NULL COMMENT 'สมรรถนะรายวิชา ข้อละบรรทัด' AFTER `objectives`",
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `objectives`, DROP COLUMN IF EXISTS `competencies`',
            $this->table('courses')
        ));
    }
}
