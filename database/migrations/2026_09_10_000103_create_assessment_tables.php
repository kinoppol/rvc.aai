<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CreateAssessmentTables extends Migration
{
    public function description(): string
    {
        return 'ตารางการวัดผล: แบบทดสอบ ข้อสอบ ตัวเลือก การทำข้อสอบ งานที่มอบหมาย และการส่งงาน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `lesson_id` INT UNSIGNED NULL,
            `title` VARCHAR(191) NOT NULL,
            `instructions` TEXT NULL,
            `time_limit_minutes` SMALLINT UNSIGNED NULL,
            `attempts_allowed` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `shuffle_questions` TINYINT(1) NOT NULL DEFAULT 0,
            `open_at` DATETIME NULL,
            `close_at` DATETIME NULL,
            `source` ENUM(\'manual\',\'ai\') NOT NULL DEFAULT \'manual\',
            `review_status` ENUM(\'draft\',\'pending\',\'published\') NOT NULL DEFAULT \'draft\',
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_quiz_course` (`course_id`, `review_status`),
            CONSTRAINT `fk_quiz_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_quiz_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_quiz_author` FOREIGN KEY (`created_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('quizzes'), $this->table('courses'), $this->table('lessons'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `quiz_id` INT UNSIGNED NOT NULL,
            `type` ENUM(\'choice\',\'multi_choice\',\'short_answer\',\'matching\') NOT NULL DEFAULT \'choice\',
            `question` TEXT NOT NULL,
            `explanation` TEXT NULL COMMENT \'คำอธิบายเฉลย\',
            `score` DECIMAL(6,2) NOT NULL DEFAULT 1.00,
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `source` ENUM(\'manual\',\'ai\') NOT NULL DEFAULT \'manual\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_question_quiz` (`quiz_id`, `sort_order`),
            CONSTRAINT `fk_question_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('quiz_questions'), $this->table('quizzes'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `question_id` INT UNSIGNED NOT NULL,
            `label` VARCHAR(8) NOT NULL DEFAULT \'\' COMMENT \'ก ข ค ง\',
            `content` TEXT NOT NULL,
            `match_key` VARCHAR(191) NULL COMMENT \'ใช้กับข้อสอบแบบจับคู่\',
            `is_correct` TINYINT(1) NOT NULL DEFAULT 0,
            `sort_order` TINYINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            KEY `ix_choice_question` (`question_id`, `sort_order`),
            CONSTRAINT `fk_choice_question` FOREIGN KEY (`question_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('quiz_choices'), $this->table('quiz_questions'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `quiz_id` INT UNSIGNED NOT NULL,
            `student_id` INT UNSIGNED NOT NULL,
            `attempt_no` TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `status` ENUM(\'in_progress\',\'submitted\',\'graded\') NOT NULL DEFAULT \'in_progress\',
            `score` DECIMAL(6,2) NULL,
            `max_score` DECIMAL(6,2) NULL,
            `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `submitted_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_attempt` (`quiz_id`, `student_id`, `attempt_no`),
            KEY `ix_attempt_student` (`student_id`, `status`),
            CONSTRAINT `fk_attempt_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_attempt_student` FOREIGN KEY (`student_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('quiz_attempts'), $this->table('quizzes'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `attempt_id` INT UNSIGNED NOT NULL,
            `question_id` INT UNSIGNED NOT NULL,
            `choice_id` INT UNSIGNED NULL,
            `answer_text` TEXT NULL,
            `is_correct` TINYINT(1) NULL,
            `score` DECIMAL(6,2) NULL,
            `graded_by` INT UNSIGNED NULL,
            `graded_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `ix_answer_attempt` (`attempt_id`),
            CONSTRAINT `fk_answer_attempt` FOREIGN KEY (`attempt_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_answer_question` FOREIGN KEY (`question_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_answer_choice` FOREIGN KEY (`choice_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_answer_grader` FOREIGN KEY (`graded_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('quiz_answers'), $this->table('quiz_attempts'), $this->table('quiz_questions'), $this->table('quiz_choices'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `lesson_id` INT UNSIGNED NULL,
            `title` VARCHAR(191) NOT NULL,
            `description` TEXT NULL,
            `due_at` DATETIME NULL,
            `max_score` DECIMAL(6,2) NOT NULL DEFAULT 10.00,
            `allow_late` TINYINT(1) NOT NULL DEFAULT 1,
            `created_by` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_assignment_course` (`course_id`, `due_at`),
            CONSTRAINT `fk_assignment_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_assignment_lesson` FOREIGN KEY (`lesson_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_assignment_author` FOREIGN KEY (`created_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('assignments'), $this->table('courses'), $this->table('lessons'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `assignment_id` INT UNSIGNED NOT NULL,
            `student_id` INT UNSIGNED NOT NULL,
            `content` TEXT NULL,
            `file_path` VARCHAR(255) NULL,
            `status` ENUM(\'submitted\',\'graded\',\'returned\') NOT NULL DEFAULT \'submitted\',
            `score` DECIMAL(6,2) NULL,
            `feedback` TEXT NULL,
            `graded_by` INT UNSIGNED NULL,
            `graded_at` DATETIME NULL,
            `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_submission` (`assignment_id`, `student_id`),
            KEY `ix_submission_student` (`student_id`, `status`),
            CONSTRAINT `fk_submission_assignment` FOREIGN KEY (`assignment_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_submission_student` FOREIGN KEY (`student_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_submission_grader` FOREIGN KEY (`graded_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('submissions'), $this->table('assignments'), $this->table('users'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['submissions', 'assignments', 'quiz_answers', 'quiz_attempts', 'quiz_choices', 'quiz_questions', 'quizzes'] as $table) {
            $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table($table)));
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
