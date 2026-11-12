<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ไฟล์ที่ครูแนบไว้กับใบงาน / งานที่มอบหมาย (เช่น ใบงานฉบับ Word แบบฟอร์ม รูปประกอบ)
 * นักเรียนที่ลงทะเบียนเปิดหรือดาวน์โหลดได้จากหน้าใบงาน · ไฟล์จริงอยู่ storage/uploads/assignments/{assignmentId}/
 */
final class CreateAssignmentFiles extends Migration
{
    public function description(): string
    {
        return 'เพิ่มตารางไฟล์แนบของใบงานและงานที่มอบหมาย (ครูแนบให้นักเรียน)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `assignment_id` INT UNSIGNED NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `stored_path` VARCHAR(255) NOT NULL COMMENT \'path ใต้ storage/uploads\',
            `mime_type` VARCHAR(127) NOT NULL,
            `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_assignment_file` (`assignment_id`, `id`),
            CONSTRAINT `fk_assignment_file_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('assignment_files'), $this->table('assignments'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('assignment_files')));
    }
}
