<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Cover;
use App\Support\Db;

/**
 * รายวิชา: การดึงรายวิชาของครู จำนวนนักเรียน และข้อมูลสรุปที่หน้าแดชบอร์ด/หน้ารายวิชาต้องใช้
 */
final class CourseRepository
{
    /** ตัดตัวที่อ่านสับสนกันออก (O/0, I/1) เพราะครูมักเขียนรหัสบนกระดาน */
    private const JOIN_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const JOIN_LENGTH = 6;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> รายวิชาที่ครูคนนี้สอนในภาคเรียนปัจจุบัน */
    public function forTeacher(int $teacherId): array
    {
        $rows = $this->db->all(
            'SELECT c.*, cr.name AS classroom_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {units} u WHERE u.course_id = c.id) AS unit_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.teacher_id = ? AND c.status = \'active\'
             ORDER BY c.code',
            [$teacherId]
        );

        // สีและรูปหน้าปกของแต่ละวิชา (คงที่ต่อวิชา ไม่ขึ้นกับลำดับในรายการ)
        return Cover::decorateAll($rows);
    }

    /**
     * รายวิชาของครูในภาคเรียนที่เลือก (หน้า "รายวิชาของฉัน")
     * - $termId = null → ทุกภาคเรียน · ภาคเรียนปัจจุบันรวมวิชาที่ยังไม่ระบุภาคเรียนด้วย
     * - ภาคเรียนที่ผ่านมา/ทุกภาค แสดงวิชาที่เก็บเข้าคลังแล้วด้วย (ครูย้อนดูของเก่าได้) ส่วนภาคปัจจุบันแสดงเฉพาะที่เปิดสอน
     *
     * @return list<array<string,mixed>>
     */
    public function forTeacherInTerm(int $teacherId, ?int $termId, ?int $currentTermId): array
    {
        $isCurrent = $termId !== null && $termId === $currentTermId;
        $where = 'c.teacher_id = ?';
        $params = [$teacherId];

        if ($termId !== null) {
            $where .= $isCurrent ? ' AND (c.term_id = ? OR c.term_id IS NULL)' : ' AND c.term_id = ?';
            $params[] = $termId;
        }
        $where .= $isCurrent ? " AND c.status = 'active'" : " AND c.status IN ('active','archived')";

        return Cover::decorateAll($this->db->all(
            'SELECT c.*, cr.name AS classroom_name, t.name AS term_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {units} u WHERE u.course_id = c.id) AS unit_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {academic_terms} t ON t.id = c.term_id
             WHERE ' . $where . '
             ORDER BY t.academic_year DESC, t.semester DESC, c.status, c.code',
            $params
        ));
    }

    /**
     * ภาคเรียนที่ครูคนนี้มีรายวิชา (รวมภาคปัจจุบันเสมอ) พร้อมจำนวนวิชา — ใช้ทำตัวเลือกภาคเรียน
     *
     * @return list<array{id:int,name:string,count:int}>
     */
    public function teacherTerms(int $teacherId, ?int $currentTermId): array
    {
        $rows = $this->db->all(
            'SELECT t.id, t.name, t.academic_year, t.semester,
                    (SELECT COUNT(*) FROM {courses} c WHERE c.term_id = t.id AND c.teacher_id = ?
                        AND c.status IN (\'active\',\'archived\')) AS n
             FROM {academic_terms} t ORDER BY t.academic_year DESC, t.semester DESC',
            [$teacherId]
        );

        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['n'] > 0 || (int) $r['id'] === $currentTermId) {
                $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'count' => (int) $r['n']];
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first(
            'SELECT c.*, cr.name AS classroom_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {units} u WHERE u.course_id = c.id) AS unit_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.id = ?',
            [$id]
        );

        return $row === null ? null : Cover::decorate($row);
    }

    /**
     * เพิ่มรายวิชาใหม่ให้ครูคนนี้
     *
     * @param array<string,mixed> $data
     */
    public function create(int $teacherId, array $data): int
    {
        return $this->db->insert('courses', $data + [
            'teacher_id' => $teacherId,
            'status' => 'active',
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('courses', $data, ['id' => $id]);
    }

    /** เก็บรายวิชาเข้าคลัง (ไม่ลบทิ้ง เพราะบทเรียนและคะแนนยังผูกอยู่) */
    public function archive(int $id): void
    {
        $this->db->update('courses', ['status' => 'archived'], ['id' => $id]);
    }

    /**
     * ครูคนนี้มีรายวิชารหัสนี้ในภาคเรียน/กลุ่มเรียนเดียวกันอยู่แล้วหรือไม่
     * ครูคนอื่นถือรหัสเดียวกันได้ เพราะรายวิชาเดียวกันมีหลายคนสอน และต่างคนต่างใช้ของตัวเอง
     */
    public function codeTaken(int $teacherId, string $code, ?int $termId, ?int $classroomId, ?int $exceptId = null): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {courses}
             WHERE teacher_id = ? AND code = ? AND term_id <=> ? AND classroom_id <=> ? AND id <> ?',
            [$teacherId, $code, $termId, $classroomId, $exceptId ?? 0]
        ) > 0;
    }

    /** @return list<array<string,mixed>> ภาคเรียนทั้งหมด ใหม่สุดขึ้นก่อน */
    public function terms(): array
    {
        return $this->db->all(
            'SELECT id, academic_year, semester, name, is_current
             FROM {academic_terms} ORDER BY academic_year DESC, semester DESC'
        );
    }

    /** @return list<array<string,mixed>> กลุ่มเรียนพร้อมชื่อสาขา */
    public function classrooms(): array
    {
        return $this->db->all(
            'SELECT cr.id, cr.name, d.name AS department_name
             FROM {classrooms} cr LEFT JOIN {departments} d ON d.id = cr.department_id
             ORDER BY cr.level, cr.year_level, cr.name'
        );
    }

    public function currentTermId(): ?int
    {
        $id = $this->db->int('SELECT id FROM {academic_terms} WHERE is_current = 1 ORDER BY id DESC LIMIT 1');

        return $id > 0 ? $id : null;
    }

    /** ครูเป็นเจ้าของรายวิชานี้หรือไม่ */
    public function ownedByTeacher(int $courseId, int $teacherId): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {courses} WHERE id = ? AND teacher_id = ?',
            [$courseId, $teacherId]
        ) > 0;
    }

    /** รหัสเข้าร่วมของรายวิชา — สร้างให้ครั้งแรกที่ครูเปิดดู */
    public function joinCode(int $courseId): string
    {
        $code = (string) $this->db->value('SELECT join_code FROM {courses} WHERE id = ?', [$courseId]);

        return $code !== '' ? $code : $this->regenerateJoinCode($courseId);
    }

    /** สุ่มรหัสใหม่ — รหัสเดิมและลิงก์เดิมที่แจกไปจะใช้ไม่ได้อีก (นักเรียนที่เข้าร่วมแล้วยังอยู่ครบ) */
    public function regenerateJoinCode(int $courseId): string
    {
        do {
            $code = '';
            for ($i = 0; $i < self::JOIN_LENGTH; $i++) {
                $code .= self::JOIN_ALPHABET[random_int(0, strlen(self::JOIN_ALPHABET) - 1)];
            }
        } while ($this->db->int('SELECT COUNT(*) FROM {courses} WHERE join_code = ?', [$code]) > 0);

        $this->db->update('courses', ['join_code' => $code], ['id' => $courseId]);

        return $code;
    }

    public function setJoinEnabled(int $courseId, bool $enabled): void
    {
        $this->db->update('courses', ['join_enabled' => $enabled ? 1 : 0], ['id' => $courseId]);
    }

    /** @return array<string,mixed>|null รายวิชาจากรหัสเข้าร่วม พร้อมชื่อและสถานศึกษาของครูผู้สอน */
    public function findByJoinCode(string $code): ?array
    {
        if ($code === '') {
            return null;
        }

        return $this->db->first(
            'SELECT c.*, cr.name AS classroom_name, u.full_name AS teacher_name, u.institution_id AS teacher_institution_id
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {users} u ON u.id = c.teacher_id
             WHERE c.join_code = ?',
            [$code]
        );
    }

    /** แปลงสิ่งที่นักเรียนกรอก (รหัส หรือวางทั้งลิงก์) ให้เป็นรหัสมาตรฐาน */
    public static function normalizeJoinCode(string $input): string
    {
        $input = trim($input);
        if (preg_match('~/join/([^/?#\s]+)~i', $input, $m)) {
            $input = $m[1];
        }

        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    /** @return list<array<string,mixed>> รายวิชาที่ยังเปิดใช้งานทั้งหมด สำหรับผู้ดูแลตั้งค่าฟังก์ชัน AI */
    public function allActiveForAdmin(): array
    {
        return $this->db->all(
            'SELECT c.id, c.code, c.name, c.ai_quiz_enabled, c.ai_lesson_plan_enabled,
                    u.full_name AS teacher_name, cr.name AS classroom_name
             FROM {courses} c
             JOIN {users} u ON u.id = c.teacher_id
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.status = \'active\'
             ORDER BY u.full_name, c.code'
        );
    }

    /**
     * รายวิชาทั้งระบบสำหรับผู้ดูแล — รวมวิชาที่เก็บเข้าคลังแล้ว และบอกว่าใครเป็นเจ้าของ
     * ครูหลายคนถือรหัสวิชาเดียวกันได้ จึงเรียงตามรหัสเพื่อให้เห็นวิชาเดียวกันของแต่ละคนติดกัน
     *
     * @return list<array<string,mixed>>
     */
    public function allForAdmin(string $query = '', ?int $teacherId = null, string $status = ''): array
    {
        $where = [];
        $params = [];

        if ($query !== '') {
            $where[] = '(c.code LIKE ? OR c.name LIKE ? OR u.full_name LIKE ?)';
            $like = '%' . $query . '%';
            array_push($params, $like, $like, $like);
        }
        if ($teacherId !== null) {
            $where[] = 'c.teacher_id = ?';
            $params[] = $teacherId;
        }
        if ($status !== '') {
            $where[] = 'c.status = ?';
            $params[] = $status;
        }

        return $this->db->all(
            'SELECT c.id, c.code, c.name, c.credits, c.status, c.show_on_landing,
                    u.id AS teacher_id, u.full_name AS teacher_name,
                    cr.name AS classroom_name, t.name AS term_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {units} un WHERE un.course_id = c.id) AS unit_count
             FROM {courses} c
             LEFT JOIN {users} u ON u.id = c.teacher_id
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {academic_terms} t ON t.id = c.term_id
             ' . ($where === [] ? '' : 'WHERE ' . implode(' AND ', $where)) . '
             ORDER BY c.code, u.full_name
             LIMIT 500',
            $params
        );
    }

    /** @return list<array<string,mixed>> ครูที่มีรายวิชาในระบบ สำหรับตัวกรองของผู้ดูแล */
    public function teachersWithCourses(): array
    {
        return $this->db->all(
            'SELECT u.id, u.full_name, COUNT(c.id) AS course_count
             FROM {users} u
             JOIN {courses} c ON c.teacher_id = u.id
             GROUP BY u.id, u.full_name
             ORDER BY u.full_name'
        );
    }

    /** เปิด/ปิดฟังก์ชัน AI ของรายวิชานี้ */
    public function setAiFeatures(int $id, bool $quizEnabled, bool $lessonPlanEnabled): void
    {
        $this->db->update('courses', [
            'ai_quiz_enabled' => $quizEnabled ? 1 : 0,
            'ai_lesson_plan_enabled' => $lessonPlanEnabled ? 1 : 0,
        ], ['id' => $id]);
    }

    /** เผยแพร่/ยกเลิกการเผยแพร่รายวิชานี้ในหน้าแรกสาธารณะ (ตั้งค่าแยกเป็นรายวิชา) */
    public function setLandingForCourse(int $courseId, bool $visible): void
    {
        $this->db->update('courses', ['show_on_landing' => $visible ? 1 : 0], ['id' => $courseId]);
    }

    /**
     * รายวิชาที่เผยแพร่สู่สาธารณะแล้ว สำหรับหน้าที่ผู้เยี่ยมชมเปิดดูได้โดยไม่ต้องเข้าสู่ระบบ
     * คืน null ถ้าไม่มีวิชานี้ หรือครูยังไม่ได้เผยแพร่ (กันเดา id เพื่อดูวิชาที่ยังไม่เปิด)
     *
     * @return array<string,mixed>|null
     */
    public function publicFind(int $id): ?array
    {
        return $this->db->first(
            'SELECT c.id, c.code, c.name, c.credits, c.theory_hours, c.practice_hours, c.description, c.cover_color, c.cover_image,
                    cr.name AS classroom_name, d.name AS department_name,
                    u.full_name AS teacher_name, t.name AS term_name
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {departments} d ON d.id = cr.department_id
             LEFT JOIN {users} u ON u.id = c.teacher_id
             LEFT JOIN {academic_terms} t ON t.id = c.term_id
             WHERE c.id = ? AND c.show_on_landing = 1 AND c.status = \'active\'',
            [$id]
        );
    }

    /**
     * รายวิชาที่ครูเลือกให้แสดงในหน้าแรกสาธารณะ จัดกลุ่มตามสาขาวิชา
     *
     * @return list<array{department:string,courses:list<array<string,mixed>>}>
     */
    public function publicLanding(): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.code, c.name, c.credits, c.theory_hours, c.practice_hours, c.description, c.cover_color, c.cover_image,
                    cr.name AS classroom_name,
                    d.name AS department_name,
                    u.full_name AS teacher_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {units} u2 WHERE u2.course_id = c.id AND u2.review_status = \'published\') AS unit_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {departments} d ON d.id = cr.department_id
             LEFT JOIN {users} u ON u.id = c.teacher_id
             WHERE c.show_on_landing = 1 AND c.status = \'active\'
             ORDER BY d.name, c.code'
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row['department_name'] ?? 'รายวิชาอื่น ๆ');
            $groups[$key] ??= ['department' => $key, 'courses' => []];
            $groups[$key]['courses'][] = Cover::decorate($row);
        }

        return array_values($groups);
    }
}
