<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * มาตรการป้องปรามการทุจริตระหว่างทำแบบทดสอบ/ข้อสอบ (App\Domain\ProctorRepository)
 * - quizzes.proctor = เปิดโหมดคุมสอบ (เต็มจอ ตรวจการสลับหน้าจอ/คัดลอก/จับภาพ) ค่าเริ่มต้นเปิด
 * - quiz_attempts.proctor_* = ตัวนับตั้งแต่ครูอนุมัติครั้งล่าสุด สถานะระงับ และเวลาที่ชดเชยให้ช่วงถูกระงับ
 * - quiz_attempt_events = บันทึกเหตุการณ์ทุกครั้ง ให้ครูดูประกอบการตัดสินใจ
 * - units.practice_until = ช่วงซ้อมสอบของหน่วยสอบกลางภาค/ปลายภาค (ครูเปิดครั้งละ 10 นาที)
 */
final class AddQuizProctoring extends Migration
{
    public function description(): string
    {
        return 'เพิ่มโหมดคุมสอบ: ตรวจการสลับหน้าจอ คัดลอก จับภาพหน้าจอ ระงับการสอบเมื่อเกินกำหนดและให้ครูอนุมัติสอบต่อ';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `proctor` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'โหมดคุมสอบ\'',
            $this->table('quizzes')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s`
                ADD COLUMN `proctor_switches` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'สลับหน้าจอ (นับใหม่หลังครูอนุมัติ)\',
                ADD COLUMN `proctor_copies` SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'คัดลอก/จับภาพ (นับใหม่หลังครูอนุมัติ)\',
                ADD COLUMN `proctor_locked_at` DATETIME NULL COMMENT \'ถูกระงับรอครูอนุมัติ\',
                ADD COLUMN `proctor_lock_reason` VARCHAR(20) NULL,
                ADD COLUMN `proctor_extra_seconds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'เวลาชดเชยช่วงถูกระงับ\',
                ADD COLUMN `proctor_unlocks` SMALLINT UNSIGNED NOT NULL DEFAULT 0',
            $this->table('quiz_attempts')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `practice_until` DATETIME NULL COMMENT \'เปิดซ้อมสอบถึงเวลานี้\'',
            $this->table('units')
        ));
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `attempt_id` INT UNSIGNED NOT NULL,
            `type` VARCHAR(20) NOT NULL,
            `detail` VARCHAR(40) NULL,
            `user_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_proctor_attempt` (`attempt_id`, `id`),
            CONSTRAINT `fk_proctor_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('quiz_attempt_events'), $this->table('quiz_attempts'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('quiz_attempt_events')));
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN `proctor_switches`, DROP COLUMN `proctor_copies`, DROP COLUMN `proctor_locked_at`,
                DROP COLUMN `proctor_lock_reason`, DROP COLUMN `proctor_extra_seconds`, DROP COLUMN `proctor_unlocks`',
            $this->table('quiz_attempts')
        ));
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `proctor`', $this->table('quizzes')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `practice_until`', $this->table('units')));
    }
}
