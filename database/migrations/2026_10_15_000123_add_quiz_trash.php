<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ถังขยะของแบบทดสอบ
 *
 *   quizzes.deleted_at  เวลาที่ครูกดลบ · NULL = ใช้งานอยู่
 *                       แบบทดสอบในถังขยะถูกซ่อนจากทุกหน้า กู้คืนได้ภายใน 30 วัน แล้วจึงลบถาวร
 */
final class AddQuizTrash extends Migration
{
    public function description(): string
    {
        return 'เพิ่มถังขยะของแบบทดสอบ (กู้คืนได้ภายใน 30 วันก่อนลบถาวร)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `deleted_at` DATETIME NULL DEFAULT NULL
                COMMENT 'ย้ายลงถังขยะเมื่อ · NULL = ใช้งานอยู่' AFTER `updated_at`,
             ADD KEY IF NOT EXISTS `ix_quiz_deleted` (`deleted_at`)",
            $this->table('quizzes')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP KEY IF EXISTS `ix_quiz_deleted`, DROP COLUMN IF EXISTS `deleted_at`',
            $this->table('quizzes')
        ));
    }
}
