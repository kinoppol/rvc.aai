<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddCourseCover extends Migration
{
    public function description(): string
    {
        return 'ให้ครูตั้งสีหน้าปกรายวิชา หรืออัปโหลดรูปหน้าปกได้';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `cover_color` CHAR(6) NULL COMMENT 'สีหน้าปก RRGGBB ไม่มี # · ว่าง = สุ่มจาก id วิชา' AFTER `competencies`,
                ADD COLUMN IF NOT EXISTS `cover_image` VARCHAR(64) NULL COMMENT 'ชื่อไฟล์รูปหน้าปกใน storage/uploads/covers' AFTER `cover_color`",
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `cover_color`, DROP COLUMN IF EXISTS `cover_image`',
            $this->table('courses')
        ));
    }
}
