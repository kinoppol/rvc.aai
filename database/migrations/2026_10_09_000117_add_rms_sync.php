<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddRmsSync extends Migration
{
    public function description(): string
    {
        return 'รองรับการโอนข้อมูลบุคลากร กลุ่มเรียน และนักเรียนจากระบบ RMS ของวิทยาลัย';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `group_code` VARCHAR(50) NULL COMMENT 'รหัสกลุ่มเรียนใน RMS (groupCode)' AFTER `name`,
                ADD COLUMN IF NOT EXISTS `group_abbr` VARCHAR(100) NULL AFTER `group_code`,
                ADD COLUMN IF NOT EXISTS `grade` VARCHAR(50) NULL COMMENT 'ระดับชั้นตาม RMS เช่น ปวช.1' AFTER `group_abbr`,
                ADD COLUMN IF NOT EXISTS `academic_year` SMALLINT UNSIGNED NULL COMMENT 'ปีการศึกษาล่าสุดที่พบใน RMS' AFTER `grade`,
                ADD COLUMN IF NOT EXISTS `semester` TINYINT UNSIGNED NULL AFTER `academic_year`,
                ADD COLUMN IF NOT EXISTS `advisor_name` VARCHAR(150) NULL COMMENT 'ครูที่ปรึกษาตาม RMS' AFTER `semester`,
                ADD COLUMN IF NOT EXISTS `rms_classroom_id` VARCHAR(50) NULL COMMENT 'ClassRoomID ใน RMS' AFTER `advisor_name`,
                ADD COLUMN IF NOT EXISTS `rms_synced_at` DATETIME NULL AFTER `rms_classroom_id`",
            $this->table('classrooms')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY IF NOT EXISTS `uk_classroom_group_code` (`group_code`)',
            $this->table('classrooms')
        ));

        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `people_id` VARCHAR(30) NULL COMMENT 'รหัสบุคลากรใน RMS' AFTER `username`,
                ADD COLUMN IF NOT EXISTS `rms_student_id` VARCHAR(30) NULL COMMENT 'studentID ใน RMS' AFTER `people_id`,
                ADD COLUMN IF NOT EXISTS `classroom_id` INT UNSIGNED NULL COMMENT 'กลุ่มเรียนของนักเรียน' AFTER `institution_id`,
                ADD COLUMN IF NOT EXISTS `rms_deactivated_at` DATETIME NULL
                    COMMENT 'ระงับเพราะหายจาก RMS — ใช้แยกจากการระงับด้วยมือ เพื่อเปิดคืนอัตโนมัติเมื่อกลับมา'",
            $this->table('users')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s`
                ADD KEY IF NOT EXISTS `ix_users_people_id` (`people_id`),
                ADD UNIQUE KEY IF NOT EXISTS `uk_users_rms_student` (`rms_student_id`),
                ADD KEY IF NOT EXISTS `ix_users_classroom` (`classroom_id`)',
            $this->table('users')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD CONSTRAINT `fk_users_classroom` FOREIGN KEY (`classroom_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL',
            $this->table('users'),
            $this->table('classrooms')
        ));

        // ข้อมูลทะเบียนของนักเรียนจาก RMS — ไม่เก็บเลขประจำตัวประชาชน (ใช้ตั้งรหัสผ่านครั้งแรกเท่านั้น)
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `user_id`           INT UNSIGNED NOT NULL,
            `student_id`        VARCHAR(30)  NOT NULL,
            `student_code`      VARCHAR(30)  NULL,
            `gender`            VARCHAR(10)  NULL,
            `group_code`        VARCHAR(50)  NULL,
            `group_name`        VARCHAR(150) NULL,
            `grade_name`        VARCHAR(100) NULL,
            `major_name`        VARCHAR(150) NULL,
            `status_code`       VARCHAR(10)  NULL,
            `status_name`       VARCHAR(100) NULL,
            `entrance_year`     SMALLINT UNSIGNED NULL,
            `entrance_semester` TINYINT UNSIGNED NULL,
            `gpax`              DECIMAL(4,2) NULL,
            `synced_at`         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`user_id`),
            UNIQUE KEY `uk_student_profile_sid` (`student_id`),
            KEY `ix_student_profile_group` (`group_code`),
            CONSTRAINT `fk_student_profile_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('student_profiles'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('student_profiles')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY IF EXISTS `fk_users_classroom`', $this->table('users')));
        $db->exec(sprintf(
            'ALTER TABLE `%s`
                DROP INDEX IF EXISTS `ix_users_people_id`, DROP INDEX IF EXISTS `uk_users_rms_student`, DROP INDEX IF EXISTS `ix_users_classroom`,
                DROP COLUMN IF EXISTS `people_id`, DROP COLUMN IF EXISTS `rms_student_id`,
                DROP COLUMN IF EXISTS `classroom_id`, DROP COLUMN IF EXISTS `rms_deactivated_at`',
            $this->table('users')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s`
                DROP INDEX IF EXISTS `uk_classroom_group_code`,
                DROP COLUMN IF EXISTS `group_code`, DROP COLUMN IF EXISTS `group_abbr`, DROP COLUMN IF EXISTS `grade`,
                DROP COLUMN IF EXISTS `academic_year`, DROP COLUMN IF EXISTS `semester`, DROP COLUMN IF EXISTS `advisor_name`,
                DROP COLUMN IF EXISTS `rms_classroom_id`, DROP COLUMN IF EXISTS `rms_synced_at`',
            $this->table('classrooms')
        ));
    }
}
