<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddInstitutions extends Migration
{
    public function description(): string
    {
        return 'เพิ่มตารางสถานศึกษา ผูกกับผู้ใช้ทุกคน และให้รหัสผู้ใช้ของนักเรียนซ้ำกันได้ข้ามสถานศึกษา';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_institutions_name` (`name`)
        ) %s', $this->table('institutions'), $this->options()));

        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN `institution_id` INT UNSIGNED NULL COMMENT 'สถานศึกษาที่สังกัด' AFTER `institution`,
                ADD CONSTRAINT `fk_users_institution` FOREIGN KEY (`institution_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL",
            $this->table('users'),
            $this->table('institutions')
        ));

        // ย้ายข้อความ "สถานศึกษา" อิสระของครูที่มีอยู่เดิม เข้าตาราง institutions แล้วผูก institution_id ให้อัตโนมัติ
        $db->exec(sprintf(
            "INSERT INTO `%s` (name, created_at, updated_at)
             SELECT DISTINCT TRIM(institution), NOW(), NOW()
             FROM `%s`
             WHERE institution IS NOT NULL AND TRIM(institution) <> ''
               AND TRIM(institution) NOT IN (SELECT name FROM `%s` x)",
            $this->table('institutions'),
            $this->table('users'),
            $this->table('institutions')
        ));

        $db->exec(sprintf(
            "UPDATE `%s` u
             JOIN `%s` i ON i.name = TRIM(u.institution)
             SET u.institution_id = i.id
             WHERE u.institution IS NOT NULL AND TRIM(u.institution) <> ''",
            $this->table('users'),
            $this->table('institutions')
        ));

        // username ของนักเรียน (รหัสนักศึกษา) ซ้ำกันได้ข้ามสถานศึกษา จึงเปลี่ยนจาก unique ทั้งระบบ
        // เป็น unique ต่อสถานศึกษาแทน — บัญชีครู/ผู้ดูแลยังคงต้องคุมความซ้ำกันเองที่ชั้นแอปพลิเคชัน
        $db->exec(sprintf('ALTER TABLE `%s` DROP INDEX `uk_users_username`', $this->table('users')));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY `uk_users_institution_username` (`institution_id`, `username`)',
            $this->table('users')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP INDEX `uk_users_institution_username`', $this->table('users')));
        $db->exec(sprintf('ALTER TABLE `%s` ADD UNIQUE KEY `uk_users_username` (`username`)', $this->table('users')));
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP FOREIGN KEY `fk_users_institution`, DROP COLUMN `institution_id`',
            $this->table('users')
        ));
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('institutions')));
    }
}
