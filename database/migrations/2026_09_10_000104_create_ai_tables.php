<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CreateAiTables extends Migration
{
    public function description(): string
    {
        return 'ตารางผู้ช่วย AI: เครื่อง AI ของส่วนกลาง คีย์ของครู โควตา คิวงาน และเนื้อหาที่รอตรวจ';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL COMMENT \'ชื่อที่ครูเห็น เช่น AI ของส่วนกลาง\',
            `base_url` VARCHAR(255) NOT NULL,
            `model` VARCHAR(191) NOT NULL DEFAULT \'\',
            `api_key_encrypted` TEXT NULL,
            `is_default` TINYINT(1) NOT NULL DEFAULT 0,
            `status` ENUM(\'online\',\'offline\',\'unknown\') NOT NULL DEFAULT \'unknown\',
            `queue_length` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `notes` VARCHAR(255) NULL,
            `last_checked_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_endpoint_default` (`is_default`, `status`)
        ) %s', $this->table('ai_endpoints'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `provider` VARCHAR(64) NOT NULL COMMENT \'google, openrouter, openai_compatible\',
            `label` VARCHAR(191) NOT NULL DEFAULT \'\',
            `base_url` VARCHAR(255) NULL,
            `model` VARCHAR(191) NULL,
            `key_encrypted` TEXT NOT NULL,
            `key_last4` CHAR(4) NOT NULL DEFAULT \'\',
            `status` ENUM(\'active\',\'invalid\',\'disabled\') NOT NULL DEFAULT \'active\',
            `verified_at` DATETIME NULL,
            `last_used_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_key_user` (`user_id`, `status`),
            CONSTRAINT `fk_key_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('user_api_keys'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NULL COMMENT \'ว่าง = ค่าเริ่มต้นของทั้งระบบ\',
            `period` CHAR(7) NOT NULL COMMENT \'ปี-เดือน เช่น 2569-01\',
            `monthly_limit` SMALLINT UNSIGNED NOT NULL DEFAULT 60,
            `used_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_quota` (`user_id`, `period`),
            CONSTRAINT `fk_quota_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('ai_quotas'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NULL,
            `course_id` INT UNSIGNED NULL,
            `kind` ENUM(\'quiz\',\'lesson_plan\',\'summary\',\'other\') NOT NULL DEFAULT \'other\',
            `source` ENUM(\'college\',\'byok\') NOT NULL DEFAULT \'college\',
            `status` ENUM(\'queued\',\'running\',\'done\',\'failed\',\'cancelled\') NOT NULL DEFAULT \'queued\',
            `prompt` LONGTEXT NULL,
            `result` LONGTEXT NULL,
            `error` TEXT NULL,
            `duration_ms` INT UNSIGNED NULL,
            `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `started_at` DATETIME NULL,
            `finished_at` DATETIME NULL,
            PRIMARY KEY (`id`),
            KEY `ix_job_queue` (`status`, `queued_at`),
            KEY `ix_job_user` (`user_id`, `queued_at`),
            CONSTRAINT `fk_job_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_job_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('ai_jobs'), $this->table('users'), $this->table('courses'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NULL,
            `job_id` BIGINT UNSIGNED NULL,
            `source` ENUM(\'college\',\'byok\') NOT NULL DEFAULT \'college\',
            `model` VARCHAR(191) NOT NULL DEFAULT \'\',
            `prompt_units` INT UNSIGNED NOT NULL DEFAULT 0,
            `completion_units` INT UNSIGNED NOT NULL DEFAULT 0,
            `succeeded` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_usage_user` (`user_id`, `created_at`),
            KEY `ix_usage_source` (`source`, `created_at`),
            CONSTRAINT `fk_usage_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_usage_job` FOREIGN KEY (`job_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('ai_usage_logs'), $this->table('users'), $this->table('ai_jobs'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `job_id` BIGINT UNSIGNED NULL,
            `user_id` INT UNSIGNED NULL,
            `target_type` ENUM(\'lesson\',\'quiz\',\'lesson_plan\') NOT NULL,
            `target_id` INT UNSIGNED NULL,
            `payload` LONGTEXT NULL,
            `review_status` ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\',
            `reviewed_by` INT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_generation_review` (`review_status`, `created_at`),
            CONSTRAINT `fk_generation_job` FOREIGN KEY (`job_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_generation_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL,
            CONSTRAINT `fk_generation_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('ai_generations'), $this->table('ai_jobs'), $this->table('users'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['ai_generations', 'ai_usage_logs', 'ai_jobs', 'ai_quotas', 'user_api_keys', 'ai_endpoints'] as $table) {
            $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table($table)));
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
