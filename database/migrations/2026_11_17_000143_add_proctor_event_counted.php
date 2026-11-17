<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * บันทึกความพยายามทุจริตทุกครั้งที่ตรวจพบ แม้ไม่ถูกนับเข้าเกณฑ์ระงับการสอบ
 * quiz_attempt_events.counted = 1 เมื่อเหตุการณ์นั้นถูกนับเข้าเกณฑ์ (สลับหน้าจอ / คัดลอก-จับภาพ)
 * 0 = บันทึกไว้ให้ครูดูอย่างเดียว เช่น คลิกขวา วางข้อความ เกิดซ้ำระหว่างแสดงคำเตือน หรือระหว่างถูกระงับ
 */
final class AddProctorEventCounted extends Migration
{
    public function description(): string
    {
        return 'บันทึกความพยายามทุจริตทุกครั้ง (แยกว่านับเข้าเกณฑ์ระงับการสอบหรือไม่)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `counted` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'นับเข้าเกณฑ์ระงับการสอบ\' AFTER `detail`',
            $this->table('quiz_attempt_events')
        ));
        // ของเดิมบันทึกเฉพาะครั้งที่นับแล้ว
        $db->exec(sprintf('UPDATE `%s` SET `counted` = 1 WHERE `type` IN (\'switch\', \'copy\')', $this->table('quiz_attempt_events')));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `counted`', $this->table('quiz_attempt_events')));
    }
}
