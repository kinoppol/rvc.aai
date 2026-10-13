<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddAiModelsUnlimited extends Migration
{
    public function description(): string
    {
        return 'ให้ผู้ดูแลกำหนดโมเดล AI ของส่วนกลางที่ครูใช้ได้ไม่จำกัด (เช่น โมเดลฟรี) โดยไม่นับโควตารายเดือน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s`
                ADD COLUMN IF NOT EXISTS `models_unlimited` TEXT NULL COMMENT 'JSON โมเดลที่ใช้ได้ไม่จำกัด ไม่นับโควตาครู' AFTER `models_enabled`",
            $this->table('ai_endpoints')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` DROP COLUMN IF EXISTS `models_unlimited`',
            $this->table('ai_endpoints')
        ));
    }
}
