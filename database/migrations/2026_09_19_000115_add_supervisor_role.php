<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class AddSupervisorRole extends Migration
{
    public function description(): string
    {
        return 'เพิ่มบทบาท "ผู้ดูแลครู" — ครูที่ผู้ดูแลระบบแต่งตั้งให้กำกับดูแลและตรวจเนื้อหาของครูทุกคน';
    }

    public function up(Runner $db): void
    {
        $db->exec(sprintf(
            "ALTER TABLE `%s` MODIFY COLUMN `role` ENUM('admin','supervisor','teacher','student')
             NOT NULL DEFAULT 'student'",
            $this->table('users')
        ));
    }

    public function down(Runner $db): void
    {
        // คืนผู้ดูแลครูกลับเป็นครูทั่วไปก่อน ไม่งั้นแถวเดิมจะค้างกับ enum ที่ไม่มีค่านี้แล้ว
        $db->exec(sprintf("UPDATE `%s` SET role = 'teacher' WHERE role = 'supervisor'", $this->table('users')));
        $db->exec(sprintf(
            "ALTER TABLE `%s` MODIFY COLUMN `role` ENUM('admin','teacher','student') NOT NULL DEFAULT 'student'",
            $this->table('users')
        ));
    }
}
