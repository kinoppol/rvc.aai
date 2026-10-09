<?php

declare(strict_types=1);

use App\Migration\Migration;
use App\Migration\Runner;

final class CourseCodeUniquePerTeacher extends Migration
{
    public function description(): string
    {
        return 'ให้รหัสวิชาซ้ำกันได้ข้ามครู — ครูแต่ละคนถือรายวิชารหัสเดียวกันเป็นของตัวเองแยกกัน';
    }

    public function up(Runner $db): void
    {
        // เดิมรหัสวิชาห้ามซ้ำทั้งระบบภายในภาคเรียน/กลุ่มเรียนเดียวกัน ครูคนที่สองที่สอนวิชาเดียวกัน
        // จึงสร้างรายวิชาของตัวเองไม่ได้ · ย้ายมาคุมความซ้ำเป็นรายครูแทน
        $db->exec(sprintf('ALTER TABLE `%s` DROP INDEX `uk_course_term`', $this->table('courses')));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY `uk_course_teacher_term` (`teacher_id`, `code`, `term_id`, `classroom_id`)',
            $this->table('courses')
        ));
    }

    public function down(Runner $db): void
    {
        $db->exec(sprintf('ALTER TABLE `%s` DROP INDEX `uk_course_teacher_term`', $this->table('courses')));
        $db->exec(sprintf(
            'ALTER TABLE `%s` ADD UNIQUE KEY `uk_course_term` (`code`, `term_id`, `classroom_id`)',
            $this->table('courses')
        ));
    }
}
