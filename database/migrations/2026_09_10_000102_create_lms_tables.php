<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CreateLmsTables extends Migration
{
    public function description(): string
    {
        return 'ตารางงานวิชาการ: ภาคเรียน สาขาวิชา กลุ่มเรียน รายวิชา การลงทะเบียน และบทเรียน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `academic_year` SMALLINT UNSIGNED NOT NULL COMMENT \'ปีการศึกษา พ.ศ.\',
            `semester` TINYINT UNSIGNED NOT NULL COMMENT \'ภาคเรียนที่\',
            `name` VARCHAR(64) NOT NULL,
            `starts_on` DATE NULL,
            `ends_on` DATE NULL,
            `is_current` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_term` (`academic_year`, `semester`),
            KEY `ix_term_current` (`is_current`)
        ) %s', $this->table('academic_terms'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(32) NOT NULL,
            `name` VARCHAR(191) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_department_code` (`code`)
        ) %s', $this->table('departments'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `department_id` INT UNSIGNED NULL,
            `advisor_id` INT UNSIGNED NULL,
            `level` ENUM(\'pvch\',\'pvs\') NOT NULL DEFAULT \'pvch\' COMMENT \'pvch = ปวช., pvs = ปวส.\',
            `year_level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `name` VARCHAR(191) NOT NULL COMMENT \'เช่น ปวช.2 ช่างกลโรงงาน\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_classroom_department` (`department_id`),
            CONSTRAINT `fk_classroom_department` FOREIGN KEY (`department_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_classroom_advisor` FOREIGN KEY (`advisor_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('classrooms'), $this->table('departments'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(32) NOT NULL COMMENT \'รหัสวิชา เช่น 20127-2002\',
            `name` VARCHAR(191) NOT NULL,
            `credits` DECIMAL(3,1) NOT NULL DEFAULT 3.0,
            `theory_hours` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `practice_hours` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            `description` TEXT NULL,
            `teacher_id` INT UNSIGNED NULL,
            `term_id` INT UNSIGNED NULL,
            `classroom_id` INT UNSIGNED NULL,
            `status` ENUM(\'active\',\'archived\') NOT NULL DEFAULT \'active\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_course_term` (`code`, `term_id`, `classroom_id`),
            KEY `ix_course_teacher` (`teacher_id`, `status`),
            CONSTRAINT `fk_course_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_course_term` FOREIGN KEY (`term_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_course_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('courses'), $this->table('users'), $this->table('academic_terms'), $this->table('classrooms'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `student_id` INT UNSIGNED NOT NULL,
            `status` ENUM(\'active\',\'dropped\',\'completed\') NOT NULL DEFAULT \'active\',
            `enrolled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_enrollment` (`course_id`, `student_id`),
            KEY `ix_enrollment_student` (`student_id`, `status`),
            CONSTRAINT `fk_enrollment_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_enrollment_student` FOREIGN KEY (`student_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('enrollments'), $this->table('courses'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(191) NOT NULL,
            `summary` TEXT NULL,
            `content` LONGTEXT NULL,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `source` ENUM(\'manual\',\'ai\') NOT NULL DEFAULT \'manual\',
            `review_status` ENUM(\'draft\',\'pending\',\'published\') NOT NULL DEFAULT \'draft\'
                COMMENT \'pending = รอครูตรวจก่อนเผยแพร่\',
            `created_by` INT UNSIGNED NULL,
            `published_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_lesson_course` (`course_id`, `sort_order`),
            KEY `ix_lesson_review` (`review_status`),
            CONSTRAINT `fk_lesson_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_lesson_author` FOREIGN KEY (`created_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('lessons'), $this->table('courses'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `lesson_id` INT UNSIGNED NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `stored_path` VARCHAR(255) NOT NULL,
            `mime_type` VARCHAR(128) NULL,
            `size_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
            `uploaded_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_attachment_lesson` (`lesson_id`),
            CONSTRAINT `fk_attachment_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_attachment_user` FOREIGN KEY (`uploaded_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('lesson_attachments'), $this->table('lessons'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['lesson_attachments', 'lessons', 'enrollments', 'courses', 'classrooms', 'departments', 'academic_terms'] as $table) {
            $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table($table)));
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
