<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

/**
 * รหัสวิชาเดียวกันใน ศธ.02 มี ท-ป-น ต่างกันได้ตามสาขาที่นำไปใช้ (เช่น 30000-1301 มีทั้ง 2-2-3 และ 0-2-1)
 * คลังเก็บแบบที่พบบ่อยที่สุดเป็นค่าหลัก และเก็บแบบอื่นไว้ในคอลัมน์นี้ (เช่น "0-2-1, 3-0-3") ให้ครูเลือกได้
 */
final class AddSubjectTpnVariants extends Migration
{
    public function description(): string
    {
        return 'คลังรายวิชา: เก็บ ท-ป-น แบบอื่นของรหัสวิชาเดียวกัน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD COLUMN `tpn_variants` VARCHAR(191) NULL COMMENT \'ท-ป-น แบบอื่นของรหัสเดียวกัน\' AFTER `self_study_hours`',
            $this->table('subject_catalog')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP COLUMN `tpn_variants`', $this->table('subject_catalog')));
    }
}
