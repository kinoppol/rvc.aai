<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CreateDiscussionTables extends Migration
{
    public function description(): string
    {
        return 'สร้างตาราง กระดานสนทนาสำหรับครู (กลุ่ม, สมาชิก, กระทู้, ความคิดเห็น)';
    }

    public function up(Runner $run): void
    {
        $run->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(120) NOT NULL,
                description TEXT         NULL,
                category    ENUM(\'lms\',\'teaching\',\'general\',\'other\') NOT NULL DEFAULT \'general\',
                created_by  INT UNSIGNED NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_category (category),
                KEY idx_created_by (created_by)
            ) %s',
            $this->table('discussion_groups'),
            $this->options()
        ));

        $run->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                group_id    INT UNSIGNED NOT NULL,
                user_id     INT UNSIGNED NOT NULL,
                status      ENUM(\'pending\',\'approved\') NOT NULL DEFAULT \'pending\',
                joined_at   DATETIME NULL,
                requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (group_id, user_id),
                KEY idx_user (user_id),
                KEY idx_status (status)
            ) %s',
            $this->table('discussion_members'),
            $this->options()
        ));

        $run->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id    INT UNSIGNED NOT NULL,
                author_id   INT UNSIGNED NOT NULL,
                title       VARCHAR(200) NOT NULL,
                body        TEXT         NOT NULL,
                reply_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_group (group_id, created_at),
                KEY idx_author (author_id)
            ) %s',
            $this->table('discussion_topics'),
            $this->options()
        ));

        $run->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                topic_id    INT UNSIGNED NOT NULL,
                author_id   INT UNSIGNED NOT NULL,
                body        TEXT         NOT NULL,
                created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_topic (topic_id, created_at),
                KEY idx_author (author_id)
            ) %s',
            $this->table('discussion_replies'),
            $this->options()
        ));
    }

    public function down(Runner $run): void
    {
        $run->exec('DROP TABLE IF EXISTS ' . $this->table('discussion_replies'));
        $run->exec('DROP TABLE IF EXISTS ' . $this->table('discussion_topics'));
        $run->exec('DROP TABLE IF EXISTS ' . $this->table('discussion_members'));
        $run->exec('DROP TABLE IF EXISTS ' . $this->table('discussion_groups'));
    }
}
