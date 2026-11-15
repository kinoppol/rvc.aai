<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Cover;
use App\Support\Db;

/**
 * นักเรียนที่ลงทะเบียนในรายวิชา และสถานะการส่งงาน
 */
final class EnrollmentRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    private ?bool $groupsReady = null;

    /** ตาราง course_groups / คอลัมน์ via_group_id มีแล้วหรือยัง (migration 000140) — ก่อนรันทุกอย่างยังใช้ได้ */
    public function groupsReady(): bool
    {
        if ($this->groupsReady === null) {
            try {
                $this->groupsReady = $this->db->first("SHOW COLUMNS FROM {enrollments} LIKE 'via_group_id'") !== null;
            } catch (\PDOException) {
                $this->groupsReady = false;
            }
        }

        return $this->groupsReady;
    }

    /** @return list<array<string,mixed>> กลุ่มเรียนที่ผูกกับรายวิชาทั้งกลุ่ม พร้อมจำนวนนักเรียนที่เข้ามาทางกลุ่ม */
    public function linkedGroups(int $courseId): array
    {
        if (!$this->groupsReady()) {
            return [];
        }

        return $this->db->all(
            'SELECT g.classroom_id AS id, cr.name, g.created_at,
                    (SELECT COUNT(*) FROM {enrollments} e
                     WHERE e.course_id = g.course_id AND e.via_group_id = g.classroom_id AND e.status = \'active\') AS members
             FROM {course_groups} g
             JOIN {classrooms} cr ON cr.id = g.classroom_id
             WHERE g.course_id = ?
             ORDER BY cr.name',
            [$courseId]
        );
    }

    public function isLinked(int $courseId, int $classroomId): bool
    {
        return $this->groupsReady() && $this->db->int(
            'SELECT COUNT(*) FROM {course_groups} WHERE course_id = ? AND classroom_id = ?',
            [$courseId, $classroomId]
        ) > 0;
    }

    /** ผูกกลุ่มเรียนทั้งกลุ่มเข้ารายวิชา แล้วลงทะเบียนสมาชิกทุกคน — คืนจำนวนที่เพิ่งเข้ารายวิชา */
    public function linkGroup(int $courseId, int $classroomId, int $userId): int
    {
        $this->db->run(
            'INSERT IGNORE INTO {course_groups} (course_id, classroom_id, added_by) VALUES (?, ?, ?)',
            [$courseId, $classroomId, $userId]
        );

        return $this->syncGroups($courseId, $classroomId);
    }

    /**
     * ให้สมาชิกปัจจุบันของกลุ่มที่ผูกไว้อยู่ในรายวิชาครบ (นักเรียนที่ย้ายเข้ากลุ่มทีหลังก็เข้ามาเอง)
     * คนที่อยู่ในรายวิชาอยู่แล้วกลายเป็นสมาชิกทางกลุ่ม (ถอนได้ทั้งกลุ่มเท่านั้น) · คืนจำนวนที่เพิ่งเข้ารายวิชา
     */
    public function syncGroups(int $courseId, ?int $onlyClassroomId = null): int
    {
        if (!$this->groupsReady()) {
            return 0;
        }
        $added = 0;
        $groups = $onlyClassroomId !== null
            ? [$onlyClassroomId]
            : array_map('intval', array_column($this->db->all('SELECT classroom_id FROM {course_groups} WHERE course_id = ?', [$courseId]), 'classroom_id'));

        foreach ($groups as $classroomId) {
            $added += $this->db->int(
                'SELECT COUNT(*) FROM {users} u
                 LEFT JOIN {enrollments} e ON e.course_id = ? AND e.student_id = u.id
                 WHERE u.classroom_id = ? AND u.role = \'student\' AND u.status = \'active\'
                   AND (e.id IS NULL OR e.status = \'dropped\')',
                [$courseId, $classroomId]
            );
            $this->db->run(
                'INSERT INTO {enrollments} (course_id, student_id, status, via_group_id)
                 SELECT ?, u.id, \'active\', ? FROM {users} u
                 WHERE u.classroom_id = ? AND u.role = \'student\' AND u.status = \'active\'
                 ON DUPLICATE KEY UPDATE {enrollments}.status = IF({enrollments}.status = \'dropped\', \'active\', {enrollments}.status),
                     {enrollments}.via_group_id = VALUES(via_group_id)',
                [$courseId, $classroomId, $classroomId]
            );
        }

        return $added;
    }

    /** ถอนกลุ่มออกจากรายวิชา: นักเรียนที่เข้ามาทางกลุ่มถูกถอนทั้งหมด (คะแนนยังเก็บไว้) · คืนจำนวนที่ถอน */
    public function unlinkGroup(int $courseId, int $classroomId): int
    {
        if (!$this->groupsReady()) {
            return 0;
        }

        return $this->db->transaction(function () use ($courseId, $classroomId): int {
            $dropped = $this->db->int(
                'SELECT COUNT(*) FROM {enrollments} WHERE course_id = ? AND via_group_id = ? AND status = \'active\'',
                [$courseId, $classroomId]
            );
            $this->db->run(
                'UPDATE {enrollments} SET status = IF(status = \'active\', \'dropped\', status), via_group_id = NULL
                 WHERE course_id = ? AND via_group_id = ?',
                [$courseId, $classroomId]
            );
            $this->db->run('DELETE FROM {course_groups} WHERE course_id = ? AND classroom_id = ?', [$courseId, $classroomId]);

            return $dropped;
        });
    }

    /** @return list<array<string,mixed>> */
    public function studentsInCourse(int $courseId): array
    {
        $via = $this->groupsReady()
            ? 'e.via_group_id, vg.name AS via_group_name'
            : 'NULL AS via_group_id, NULL AS via_group_name';
        $join = $this->groupsReady() ? 'LEFT JOIN {classrooms} vg ON vg.id = e.via_group_id' : '';

        return $this->db->all(
            'SELECT u.id, u.username, u.full_name, u.email, e.status AS enrollment_status, ' . $via . ',
                    COALESCE(cr.name, NULLIF(sp.group_name, \'\'), NULLIF(sp.grade_name, \'\')) AS group_name
             FROM {enrollments} e
             JOIN {users} u ON u.id = e.student_id
             LEFT JOIN {classrooms} cr ON cr.id = u.classroom_id
             LEFT JOIN {student_profiles} sp ON sp.user_id = u.id
             ' . $join . '
             WHERE e.course_id = ? AND e.status = \'active\'
             ORDER BY u.username',
            [$courseId]
        );
    }

    /** @return list<int> */
    public function studentIds(int $courseId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['student_id'],
            $this->db->all('SELECT student_id FROM {enrollments} WHERE course_id = ? AND status = \'active\'', [$courseId])
        );
    }

    public function isEnrolled(int $courseId, int $studentId): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {enrollments} WHERE course_id = ? AND student_id = ? AND status = \'active\'',
            [$courseId, $studentId]
        ) > 0;
    }

    /** ลงทะเบียนนักเรียนเข้ารายวิชา — ถ้าเคยถอนไปแล้วให้กลับมาเรียนต่อได้ */
    public function enroll(int $courseId, int $studentId): void
    {
        $this->db->run(
            'INSERT INTO {enrollments} (course_id, student_id, status) VALUES (?, ?, \'active\')
             ON DUPLICATE KEY UPDATE status = IF(status = \'dropped\', \'active\', status)',
            [$courseId, $studentId]
        );
    }

    /**
     * ถอนนักเรียนออกจากรายวิชา: แค่เปลี่ยนสถานะเป็น dropped — ผลสอบ งานที่ส่ง และข้อความยังอยู่ครบ
     * เพิ่มกลับเข้ามาเมื่อไร (enroll()) คะแนนเดิมก็กลับมาด้วย
     */
    public function drop(int $courseId, int $studentId): bool
    {
        // นักเรียนที่เข้ามาทั้งกลุ่ม ถอนทีละคนไม่ได้ — ต้องถอนทั้งกลุ่ม (unlinkGroup)
        return $this->db->run(
            'UPDATE {enrollments} SET status = \'dropped\' WHERE course_id = ? AND student_id = ? AND status = \'active\''
                . ($this->groupsReady() ? ' AND via_group_id IS NULL' : ''),
            [$courseId, $studentId]
        ) > 0;
    }

    /** @return list<array<string,mixed>> รายวิชาที่นักเรียนคนนี้เรียนอยู่ */
    public function coursesForStudent(int $studentId): array
    {
        return Cover::decorateAll($this->db->all(
            'SELECT c.*, u.full_name AS teacher_name
             FROM {enrollments} e
             JOIN {courses} c ON c.id = e.course_id
             LEFT JOIN {users} u ON u.id = c.teacher_id
             WHERE e.student_id = ? AND e.status = \'active\' AND c.status = \'active\'
             ORDER BY c.code',
            [$studentId]
        ));
    }
}
