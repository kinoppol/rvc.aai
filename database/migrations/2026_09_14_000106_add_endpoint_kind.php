<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddEndpointKind extends Migration
{
    public function description(): string
    {
        return 'เพิ่มชนิดการเชื่อมต่อของเครื่อง AI ส่วนกลาง (Ollama หรือ API แบบ OpenAI)';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `kind` VARCHAR(32) NOT NULL DEFAULT \'ollama\'
                COMMENT \'ollama | openai_compatible\' AFTER `base_url`',
            $this->table('ai_endpoints')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `kind`', $this->table('ai_endpoints')));
    }
}
