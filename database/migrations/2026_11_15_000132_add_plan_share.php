<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ลิงก์แชร์แผนการสอน (อ่านอย่างเดียว) ให้ครูเผยแพร่แผนของตนบนอินเทอร์เน็ต
 *   plan_share_token = โทเคนสุ่ม 32 ตัว (NULL = ไม่ได้แชร์) · เปิดดูที่ /plan/{token} โดยไม่ต้องเข้าสู่ระบบ
 *   plan_shared_at   = เวลาที่สร้างลิงก์ล่าสุด
 */
final class AddPlanShare extends Migration
{
    public function description(): string
    {
        return 'เพิ่มลิงก์แชร์แผนการสอนแบบอ่านอย่างเดียว';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `plan_share_token` CHAR(32) NULL COMMENT \'โทเคนลิงก์แชร์แผนการสอน\', ADD COLUMN `plan_shared_at` DATETIME NULL COMMENT \'เวลาที่สร้างลิงก์แชร์\', ADD UNIQUE KEY `uk_plan_share_token` (`plan_share_token`)',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP KEY `uk_plan_share_token`, DROP COLUMN `plan_shared_at`, DROP COLUMN `plan_share_token`',
            $this->table('courses')
        ));
    }
}
