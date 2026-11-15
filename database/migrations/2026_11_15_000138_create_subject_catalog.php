<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * คลังรายวิชามาตรฐานของ สอศ. (ข้อมูลจากระบบ ศธ.02 ออนไลน์) — ให้ครูกรอกแค่รหัสวิชาแล้วระบบเติมชื่อวิชา
 * ท-ป-น คำอธิบาย จุดประสงค์ และสมรรถนะรายวิชาให้ · ข้อมูลนำเข้าจาก database/data/vec-subjects-*.json.gz
 * (App\Domain\SubjectCatalogRepository) — รหัสเดียวกันมีได้หลายปีหลักสูตร
 */
final class CreateSubjectCatalog extends Migration
{
    public function description(): string
    {
        return 'สร้างคลังรายวิชามาตรฐาน สอศ. สำหรับเติมข้อมูลรายวิชาจากรหัสวิชา';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(20) NOT NULL COMMENT \'รหัสวิชา\',
                `curriculum_year` SMALLINT UNSIGNED NOT NULL COMMENT \'ปีหลักสูตร (พ.ศ.)\',
                `name_th` VARCHAR(255) NOT NULL,
                `name_en` VARCHAR(255) NULL,
                `theory_hours` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `practice_hours` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `credits` DECIMAL(3,1) NOT NULL DEFAULT 0,
                `self_study_hours` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `subject_group` VARCHAR(191) NULL COMMENT \'ประเภทรายวิชาตาม ศธ.02\',
                `description` TEXT NULL,
                `objectives` TEXT NULL,
                `competencies` TEXT NULL,
                `source_id` VARCHAR(20) NULL COMMENT \'subjectId ในระบบ ศธ.02\',
                `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_subject_code_year` (`code`, `curriculum_year`),
                KEY `idx_subject_name` (`name_th`(60))
            ) %s',
            $this->table('subject_catalog'),
            $this->options()
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('subject_catalog')));
    }
}
