<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * กลุ่มผู้เรียนของรายวิชา: ครูผูกกลุ่มเรียนทั้งกลุ่มเข้ารายวิชา (course_groups)
 * นักเรียนที่เข้ามาทางกลุ่มจำกลุ่มไว้ที่ enrollments.via_group_id — ถอนได้ทั้งกลุ่มเท่านั้น ไม่ถอนทีละคน
 */
final class AddCourseGroups extends Migration
{
    public function description(): string
    {
        return 'รายวิชา: ผูกกลุ่มเรียนทั้งกลุ่ม (เพิ่ม/ถอนนักเรียนทั้งกลุ่ม)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `classroom_id` INT UNSIGNED NOT NULL,
            `added_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_course_group` (`course_id`, `classroom_id`),
            KEY `ix_course_group_classroom` (`classroom_id`),
            CONSTRAINT `fk_course_group_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_course_group_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('course_groups'), $this->table('courses'), $this->table('classrooms'), $this->options()));

        $db->exec(sprintf(
            'ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `via_group_id` INT UNSIGNED NULL COMMENT \'เข้ามาพร้อมกลุ่มเรียนนี้ (classrooms.id)\' AFTER `status`,
                ADD KEY IF NOT EXISTS `ix_enrollment_via_group` (`course_id`, `via_group_id`)',
            $this->table('enrollments')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP KEY IF EXISTS `ix_enrollment_via_group`, DROP COLUMN IF EXISTS `via_group_id`',
            $this->table('enrollments')
        ));
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('course_groups')));
    }
}
