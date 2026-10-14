<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddRmsPassword extends Migration
{
    public function description(): string
    {
        return 'ให้บุคลากรล็อกอินครั้งแรกด้วยรหัสผ่านของ RMS (people_pass แบบ md5) แล้วเปลี่ยนไปเก็บแบบปลอดภัย';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `rms_pass_md5` CHAR(32) NULL
                    COMMENT 'people_pass จาก RMS ที่ยังใช้ล็อกอินได้ — ล้างทิ้งเมื่อล็อกอินสำเร็จครั้งแรก' AFTER `password_hash`,
                ADD COLUMN IF NOT EXISTS `rms_pass_seen` CHAR(32) NULL
                    COMMENT 'people_pass ล่าสุดที่โอนมา — รับรหัสใหม่เฉพาะเมื่อค่านี้เปลี่ยน' AFTER `rms_pass_md5`",
            $this->table('users')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `rms_pass_md5`, DROP COLUMN IF EXISTS `rms_pass_seen`',
            $this->table('users')
        ));
    }
}
