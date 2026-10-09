<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CreatePasswordResets extends Migration
{
    public function description(): string
    {
        return 'ตารางลิงก์รีเซ็ตรหัสผ่าน — ให้ผู้ดูแลระบบสร้างให้ครูที่ลืมรหัสผ่าน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `token_hash` CHAR(64) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_password_reset_token` (`token_hash`),
            KEY `ix_password_reset_user` (`user_id`),
            CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('password_resets'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('password_resets')));
    }
}
