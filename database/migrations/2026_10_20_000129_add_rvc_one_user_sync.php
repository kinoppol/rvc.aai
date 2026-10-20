<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddRvcOneUserSync extends Migration
{
    public function description(): string
    {
        return 'รองรับการซิงค์รายชื่อผู้ใช้จาก RVC-ONE (รหัสผู้ใช้ RVC-ONE และการระงับโดยการซิงค์)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `oa_user_id` INT UNSIGNED NULL
                    COMMENT 'id ของผู้ใช้ใน RVC-ONE (User-sync API / SSO)' AFTER `rms_student_id`,
                ADD COLUMN IF NOT EXISTS `oa_deactivated_at` DATETIME NULL
                    COMMENT 'ระงับเพราะ RVC-ONE ส่ง status=suspended — ใช้แยกจากการระงับด้วยมือ เพื่อเปิดคืนอัตโนมัติ'",
            $this->table('users')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY IF NOT EXISTS `uk_users_oa_user` (`oa_user_id`)',
            $this->table('users')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s`
                DROP INDEX IF EXISTS `uk_users_oa_user`,
                DROP COLUMN IF EXISTS `oa_user_id`, DROP COLUMN IF EXISTS `oa_deactivated_at`',
            $this->table('users')
        ));
    }
}
