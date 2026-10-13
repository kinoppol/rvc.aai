<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddAiModelLists extends Migration
{
    public function description(): string
    {
        return 'ให้ผู้ให้บริการ AI ของส่วนกลางและของครูเปิดใช้ได้หลายโมเดล และปิดผู้ให้บริการส่วนกลางชั่วคราวได้';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `models_enabled` TEXT NULL COMMENT 'JSON รายชื่อโมเดลที่เปิดให้ครูเลือก' AFTER `model`,
                ADD COLUMN IF NOT EXISTS `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = ผู้ดูแลปิดไว้ชั่วคราว' AFTER `is_default`",
            $this->table('ai_endpoints')
        ));

        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `models_enabled` TEXT NULL COMMENT 'JSON รายชื่อโมเดลที่ครูเปิดใช้' AFTER `model`",
            $this->table('user_api_keys')
        ));

        // ข้อมูลเดิมมีโมเดลเดียว — ยกเป็นรายการหนึ่งตัว
        foreach (['ai_endpoints', 'user_api_keys'] as $table) {
            $db->exec(sprintf(
                "UPDATE `%s` SET `models_enabled` = JSON_ARRAY(`model`)
                 WHERE `models_enabled` IS NULL AND `model` IS NOT NULL AND `model` <> ''",
                $this->table($table)
            ));
        }
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `models_enabled`, DROP COLUMN IF EXISTS `is_active`',
            $this->table('ai_endpoints')
        ));
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `models_enabled`',
            $this->table('user_api_keys')
        ));
    }
}
