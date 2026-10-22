<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * ประกาศข่าวสารของรายวิชา (ครูประกาศให้นักเรียนทุกคนในรายวิชาทราบ)
 * และข้อความส่วนตัวระหว่างครูผู้สอนกับนักเรียนแต่ละคน
 *
 *   course_posts      ประกาศหนึ่งแถวต่อหนึ่งประกาศ · pinned = ปักหมุดไว้บนสุด
 *   course_post_seen  เวลาที่นักเรียนเปิดดูประกาศของรายวิชาล่าสุด (ใช้ทำป้าย "ใหม่" และนับว่ามีคนเห็นแล้วกี่คน)
 *   course_messages   ข้อความส่วนตัว · หนึ่งบทสนทนา = รายวิชา + นักเรียน (อีกฝ่ายคือครูผู้สอนรายวิชานั้น)
 *                     sender_id = ผู้ส่ง (ครูหรือนักเรียนคนนั้น) · read_at = อีกฝ่ายเปิดอ่านแล้ว
 */
final class CreateCourseFeedAndMessages extends Migration
{
    public function description(): string
    {
        return 'เพิ่มตารางประกาศข่าวสารของรายวิชาและข้อความส่วนตัวครู–นักเรียน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `author_id` INT UNSIGNED NULL,
            `body` TEXT NOT NULL,
            `pinned` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'ปักหมุดไว้บนสุด\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL COMMENT \'แก้ไขล่าสุด (NULL = ไม่เคยแก้)\',
            PRIMARY KEY (`id`),
            KEY `idx_course_post_course` (`course_id`, `created_at`),
            CONSTRAINT `fk_course_post_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_course_post_author` FOREIGN KEY (`author_id`) REFERENCES `%s` (`id`) ON DELETE SET NULL
        ) %s', $this->table('course_posts'), $this->table('courses'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `course_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `seen_at` DATETIME NOT NULL,
            PRIMARY KEY (`course_id`, `user_id`),
            CONSTRAINT `fk_post_seen_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_post_seen_user` FOREIGN KEY (`user_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('course_post_seen'), $this->table('courses'), $this->table('users'), $this->options()));

        $db->exec(sprintf('CREATE TABLE IF NOT EXISTS `%s` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `course_id` INT UNSIGNED NOT NULL,
            `student_id` INT UNSIGNED NOT NULL COMMENT \'นักเรียนเจ้าของบทสนทนา\',
            `sender_id` INT UNSIGNED NOT NULL,
            `body` TEXT NOT NULL,
            `read_at` DATETIME NULL COMMENT \'อีกฝ่ายเปิดอ่านแล้ว\',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_message_thread` (`course_id`, `student_id`, `id`),
            KEY `idx_message_unread` (`student_id`, `read_at`),
            CONSTRAINT `fk_message_course` FOREIGN KEY (`course_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_message_student` FOREIGN KEY (`student_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_message_sender` FOREIGN KEY (`sender_id`) REFERENCES `%s` (`id`) ON DELETE CASCADE
        ) %s', $this->table('course_messages'), $this->table('courses'), $this->table('users'), $this->table('users'), $this->options()));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('course_messages')));
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('course_post_seen')));
        $db->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table('course_posts')));
    }
}
