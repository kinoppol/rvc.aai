<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddCourseLandingFlag extends Migration
{
    public function description(): string
    {
        return 'เพิ่มตัวเลือกให้ครูแสดงรายวิชาในหน้าแรกสาธารณะ (ค่าเริ่มต้นไม่แสดง)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s`
                ADD COLUMN `show_on_landing` TINYINT(1) NOT NULL DEFAULT 0
                    COMMENT \'ครูเลือกให้แสดงรายวิชานี้ในหน้าแรกสาธารณะ\' AFTER `status`,
                ADD KEY `ix_course_landing` (`show_on_landing`, `status`)',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP KEY `ix_course_landing`, DROP COLUMN `show_on_landing`',
            $this->table('courses')
        ));
    }
}
