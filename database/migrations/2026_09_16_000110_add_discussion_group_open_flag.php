<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddDiscussionGroupOpenFlag extends Migration
{
    public function description(): string
    {
        return 'เพิ่มสถานะ "กลุ่มเปิด" ให้กระดานสนทนา — ผู้ที่ไม่ได้เป็นสมาชิกอ่านและตอบกระทู้ได้โดยไม่ต้องขออนุมัติ';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `is_open` TINYINT(1) NOT NULL DEFAULT 0 AFTER `category`",
            $this->table('discussion_groups')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `is_open`',
            $this->table('discussion_groups')
        ));
    }
}
