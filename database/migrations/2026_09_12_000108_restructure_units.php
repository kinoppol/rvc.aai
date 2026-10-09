<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * เปลี่ยนโครงสร้างรายวิชาจาก "บทเรียน + แบบทดสอบ" แยกกัน
 * มาเป็น "หน่วยการเรียน" ที่รวมทุกส่วนไว้ด้วยกัน
 *
 * หน่วยการเรียน 1 หน่วย ประกอบด้วย:
 *   1. บทนำ (fields ใน units: key_content, objectives, competencies)
 *   2. แบบทดสอบก่อนเรียน (quizzes.kind = 'pretest', ไม่คิดคะแนน)
 *   3. เนื้อหา (unit_sections: text / video / pdf)
 *   4. ใบงาน (assignments → unit_id)
 *   5. แบบทดสอบหลังเรียน (quizzes.kind = 'posttest', คิดคะแนน)
 */
final class RestructureUnits extends Migration
{
    public function description(): string
    {
        return 'รวมบทเรียนและแบบทดสอบเป็นหน่วยการเรียน พร้อมเนื้อหาหลายส่วนและติดตามความคืบหน้า';
    }

    public function up(Runner $db): void
    {
        // ─── 1. หน่วยการเรียน (แทน lessons) ───────────────────────────────────
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id`      INT UNSIGNED NOT NULL,
            `title`          VARCHAR(191) NOT NULL,
            `key_content`    TEXT NULL COMMENT \'สาระสำคัญ\',
            `objectives`     TEXT NULL COMMENT \'วัตถุประสงค์การเรียนรู้\',
            `competencies`   TEXT NULL COMMENT \'สมรรถนะประจำหน่วย\',
            `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `source`         ENUM(\'manual\',\'ai\') NOT NULL DEFAULT \'manual\',
            `review_status`  ENUM(\'draft\',\'pending\',\'published\') NOT NULL DEFAULT \'draft\',
            `created_by`     INT UNSIGNED NULL,
            `published_at`   DATETIME NULL,
            `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_unit_course` (`course_id`, `sort_order`),
            KEY `ix_unit_review` (`review_status`),
            CONSTRAINT `fk_unit_course`  FOREIGN KEY (`course_id`)  REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_unit_author` FOREIGN KEY (`created_by`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('units'), $this->table('courses'), $this->table('users'), $this->options()));

        // ─── 2. ส่วนเนื้อหา (text / video / pdf) ──────────────────────────────
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `unit_id`    INT UNSIGNED NOT NULL,
            `type`       ENUM(\'text\',\'video\',\'pdf\') NOT NULL DEFAULT \'text\',
            `title`      VARCHAR(191) NOT NULL DEFAULT \'\',
            `content`    LONGTEXT NULL COMMENT \'สำหรับ type=text\',
            `video_url`  VARCHAR(512) NULL COMMENT \'YouTube / URL สำหรับ type=video\',
            `file_path`  VARCHAR(255) NULL COMMENT \'path ใน storage/ สำหรับ type=pdf\',
            `file_name`  VARCHAR(255) NULL COMMENT \'ชื่อไฟล์ต้นฉบับ\',
            `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_section_unit` (`unit_id`, `sort_order`),
            CONSTRAINT `fk_section_unit` FOREIGN KEY (`unit_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('unit_sections'), $this->table('units'), $this->options()));

        // ─── 3. ย้ายข้อมูล lessons → units (ถ้ายังมีตาราง lessons อยู่) ────────
        try {
            $db->exec(sprintf(
                'INSERT IGNORE INTO `%s`
                    (id, course_id, title, key_content, sort_order, source, review_status, created_by, published_at, created_at, updated_at)
                 SELECT id, course_id, title, summary, sort_order, source, review_status, created_by, published_at, created_at, updated_at
                 FROM `%s`',
                $this->table('units'),
                $this->table('lessons')
            ));

            // เนื้อหา text ของบทเรียนเดิม → unit_section type=text
            $db->exec(sprintf(
                'INSERT IGNORE INTO `%s` (unit_id, type, title, content, sort_order, created_at, updated_at)
                 SELECT id, \'text\', \'เนื้อหา\', content, 0, created_at, updated_at
                 FROM `%s`
                 WHERE content IS NOT NULL AND content != \'\'',
                $this->table('unit_sections'),
                $this->table('lessons')
            ));
        } catch (\Throwable) {
            // ตาราง lessons ไม่มีแล้ว (เคย migrate แล้วหรือ fresh install) — ข้ามได้
        }

        // ─── 4. เพิ่ม unit_id + kind ใน quizzes ───────────────────────────────
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `unit_id` INT UNSIGNED NULL AFTER `lesson_id`,
                ADD COLUMN IF NOT EXISTS `kind` ENUM('pretest','posttest') NOT NULL DEFAULT 'posttest' AFTER `unit_id`",
            $this->table('quizzes')
        ));
        // เพิ่ม FK แยกต่างหาก (ถ้ามีอยู่แล้วจะ ignore)
        try {
            $db->exec(sprintf(
                "ALTER TABLE `%s` ADD CONSTRAINT `fk_quiz_unit` FOREIGN KEY (`unit_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL",
                $this->table('quizzes'),
                $this->table('units')
            ));
        } catch (\Throwable) {
            // FK มีอยู่แล้ว — ข้ามได้
        }

        // map lesson_id → unit_id สำหรับ quiz เดิม (id เหมือนกันเพราะ copy ตรงๆ)
        try {
            $db->exec(sprintf(
                'UPDATE `%s` SET unit_id = lesson_id WHERE lesson_id IS NOT NULL AND unit_id IS NULL',
                $this->table('quizzes')
            ));
        } catch (\Throwable) {
            // lesson_id column อาจไม่มีใน schema ใหม่ — ข้ามได้
        }

        // ─── 5. เพิ่ม unit_id ใน assignments ──────────────────────────────────
        $db->exec(sprintf(
            "ALTER TABLE `%s` ADD COLUMN IF NOT EXISTS `unit_id` INT UNSIGNED NULL AFTER `lesson_id`",
            $this->table('assignments')
        ));
        try {
            $db->exec(sprintf(
                "ALTER TABLE `%s` ADD CONSTRAINT `fk_assignment_unit` FOREIGN KEY (`unit_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL",
                $this->table('assignments'),
                $this->table('units')
            ));
        } catch (\Throwable) {
            // FK มีอยู่แล้ว — ข้ามได้
        }

        try {
            $db->exec(sprintf(
                'UPDATE `%s` SET unit_id = lesson_id WHERE lesson_id IS NOT NULL AND unit_id IS NULL',
                $this->table('assignments')
            ));
        } catch (\Throwable) {
            // lesson_id column อาจไม่มี — ข้ามได้
        }

        // ─── 6. เพิ่ม pass_threshold ใน courses ───────────────────────────────
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `pass_threshold` DECIMAL(5,2) NOT NULL DEFAULT 70.00
                    COMMENT 'เกณฑ์ผ่านรายวิชา (%%) รวมคะแนนทุกหน่วย' AFTER `status`",
            $this->table('courses')
        ));

        // ─── 7. ติดตามความคืบหน้าระดับหน่วย ──────────────────────────────────
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `unit_id`          INT UNSIGNED NOT NULL,
            `student_id`       INT UNSIGNED NOT NULL,
            `intro_read_at`    DATETIME NULL COMMENT \'นักเรียนอ่านบทนำแล้ว\',
            `pretest_done_at`  DATETIME NULL COMMENT \'ทำแบบทดสอบก่อนเรียนแล้ว\',
            `posttest_done_at` DATETIME NULL COMMENT \'ทำแบบทดสอบหลังเรียนแล้ว\',
            `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_unit_progress` (`unit_id`, `student_id`),
            KEY `ix_unit_progress_student` (`student_id`),
            CONSTRAINT `fk_uprog_unit`    FOREIGN KEY (`unit_id`)    REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_uprog_student` FOREIGN KEY (`student_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('unit_progress'), $this->table('units'), $this->table('users'), $this->options()));

        // ─── 8. ติดตามความคืบหน้าระดับส่วนเนื้อหา ────────────────────────────
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `section_id`   INT UNSIGNED NOT NULL,
            `student_id`   INT UNSIGNED NOT NULL,
            `completed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_section_progress` (`section_id`, `student_id`),
            KEY `ix_sec_progress_student` (`student_id`),
            CONSTRAINT `fk_sprog_section` FOREIGN KEY (`section_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_sprog_student` FOREIGN KEY (`student_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('section_progress'), $this->table('unit_sections'), $this->table('users'), $this->options()));

        // ─── 9. ไฟล์แนบต่อการส่งใบงาน (รองรับหลายไฟล์) ──────────────────────
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `submission_id` INT UNSIGNED NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `stored_path`   VARCHAR(255) NOT NULL,
            `mime_type`     VARCHAR(128) NULL,
            `size_bytes`    INT UNSIGNED NOT NULL DEFAULT 0,
            `uploaded_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `ix_subfile_submission` (`submission_id`),
            CONSTRAINT `fk_subfile_submission` FOREIGN KEY (`submission_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('submission_files'), $this->table('submissions'), $this->options()));

        // ─── 10. ลบตารางเดิมที่ไม่ใช้แล้ว ────────────────────────────────────
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('lesson_attachments')));
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('lessons')));
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function down(Runner $db): void
    {
        $db->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['section_progress', 'unit_progress', 'submission_files', 'unit_sections', 'units'] as $t) {
            $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table($t)));
        }
        // ลบคอลัมน์ที่เพิ่มในตารางเดิม
        $db->exec(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY IF EXISTS fk_quiz_unit, DROP COLUMN IF EXISTS unit_id, DROP COLUMN IF EXISTS kind', $this->table('quizzes')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP FOREIGN KEY IF EXISTS fk_assignment_unit, DROP COLUMN IF EXISTS unit_id', $this->table('assignments')));
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN IF EXISTS pass_threshold', $this->table('courses')));
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
