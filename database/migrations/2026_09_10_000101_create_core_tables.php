<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CreateCoreTables extends Migration
{
    public function description(): string
    {
        return 'ตารางแกนระบบ: ผู้ใช้ สิทธิ์เข้าใช้งาน การตั้งค่า และบันทึกการใช้งาน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(64) NOT NULL,
            `email` VARCHAR(191) NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `full_name` VARCHAR(191) NOT NULL,
            `role` ENUM(\'admin\',\'teacher\',\'student\') NOT NULL DEFAULT \'student\',
            `status` ENUM(\'active\',\'suspended\') NOT NULL DEFAULT \'active\',
            `phone` VARCHAR(32) NULL,
            `avatar_path` VARCHAR(255) NULL,
            `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
            `last_login_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_users_username` (`username`),
            UNIQUE KEY `uk_users_email` (`email`),
            KEY `ix_users_role` (`role`, `status`)
        ) %s', $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `user_agent` VARCHAR(255) NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_remember_token` (`token_hash`),
            KEY `ix_remember_user` (`user_id`),
            CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('remember_tokens'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `identifier` VARCHAR(191) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `scope` VARCHAR(32) NOT NULL DEFAULT \'login\',
            `succeeded` TINYINT(1) NOT NULL DEFAULT 0,
            `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_attempts_lookup` (`scope`, `identifier`, `attempted_at`),
            KEY `ix_attempts_ip` (`scope`, `ip_address`, `attempted_at`)
        ) %s', $this->table('login_attempts'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `value` TEXT NULL,
            `type` ENUM(\'string\',\'integer\',\'boolean\',\'json\') NOT NULL DEFAULT \'string\',
            `group_name` VARCHAR(64) NOT NULL DEFAULT \'general\',
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_settings_name` (`name`),
            KEY `ix_settings_group` (`group_name`)
        ) %s', $this->table('settings'), $this->options()));

        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NULL,
            `action` VARCHAR(64) NOT NULL,
            `target` VARCHAR(191) NULL,
            `meta` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_audit_user` (`user_id`, `created_at`),
            KEY `ix_audit_action` (`action`, `created_at`),
            CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('audit_logs'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['audit_logs', 'settings', 'login_attempts', 'remember_tokens', 'users'] as $table) {
            $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table($table)));
        }
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
