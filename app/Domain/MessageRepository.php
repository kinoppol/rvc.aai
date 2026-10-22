<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDOException;

/**
 * ข้อความส่วนตัวระหว่างครูผู้สอนกับนักเรียน
 * หนึ่งบทสนทนา = รายวิชา + นักเรียน · อีกฝ่ายคือครูผู้สอนรายวิชานั้นเสมอ (courses.teacher_id)
 */
final class MessageRepository
{
    public const MAX_BODY = 2000;

    private ?bool $ready = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** ตารางพร้อมใช้หรือยัง (ผู้ดูแลระบบอาจยังไม่ได้รันการปรับฐานข้อมูล 000130) */
    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = $this->db->first("SHOW TABLES LIKE '" . $this->db->table('course_messages') . "'") !== null;
            } catch (PDOException) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /** @return list<array<string,mixed>> ข้อความทั้งบทสนทนา เก่าไปใหม่ */
    public function thread(int $courseId, int $studentId, int $limit = 300): array
    {
        $rows = $this->db->all(
            'SELECT m.*, u.full_name AS sender_name
             FROM {course_messages} m LEFT JOIN {users} u ON u.id = m.sender_id
             WHERE m.course_id = ? AND m.student_id = ?
             ORDER BY m.id DESC LIMIT ' . max(1, $limit),
            [$courseId, $studentId]
        );

        return array_reverse($rows);
    }

    public function send(int $courseId, int $studentId, int $senderId, string $body): int
    {
        return $this->db->insert('course_messages', [
            'course_id' => $courseId,
            'student_id' => $studentId,
            'sender_id' => $senderId,
            'body' => $body,
        ]);
    }

    /** ผู้อ่านเปิดบทสนทนาแล้ว — ข้อความของอีกฝ่ายที่ยังไม่อ่านกลายเป็นอ่านแล้ว */
    public function markRead(int $courseId, int $studentId, int $readerId): void
    {
        $this->db->run(
            'UPDATE {course_messages} SET read_at = NOW()
             WHERE course_id = ? AND student_id = ? AND sender_id <> ? AND read_at IS NULL',
            [$courseId, $studentId, $readerId]
        );
    }

    /** จำนวนข้อความที่ยังไม่ได้อ่าน (ป้ายบนเมนู) · ฐานข้อมูลยังไม่พร้อม = 0 */
    public function unreadCount(int $userId, bool $isStudent): int
    {
        if (!$this->ready()) {
            return 0;
        }
        try {
            return $isStudent
                ? $this->db->int(
                    'SELECT COUNT(*) FROM {course_messages} m
                     JOIN {enrollments} e ON e.course_id = m.course_id AND e.student_id = m.student_id AND e.status = \'active\'
                     WHERE m.student_id = ? AND m.sender_id <> ? AND m.read_at IS NULL',
                    [$userId, $userId]
                )
                : $this->db->int(
                    'SELECT COUNT(*) FROM {course_messages} m
                     JOIN {courses} c ON c.id = m.course_id AND c.teacher_id = ?
                     WHERE m.sender_id = m.student_id AND m.read_at IS NULL',
                    [$userId]
                );
        } catch (PDOException) {
            return 0;
        }
    }

    /**
     * กล่องข้อความของครู: บทสนทนาที่มีข้อความแล้วในรายวิชาของครูคนนี้ ล่าสุดก่อน
     *
     * @return list<array<string,mixed>>
     */
    public function teacherInbox(int $teacherId, ?int $courseId = null): array
    {
        $params = [$teacherId];
        $courseCond = '';
        if ($courseId !== null) {
            $courseCond = ' AND course_id = ?';
            $params[] = $courseId;
        }

        return $this->db->all(
            'SELECT t.course_id, t.student_id, t.unread, m.body, m.sender_id, m.created_at,
                    u.full_name AS student_name, u.username, c.code AS course_code, c.name AS course_name
             FROM (
                SELECT course_id, student_id, MAX(id) AS last_id,
                       SUM(sender_id = student_id AND read_at IS NULL) AS unread
                FROM {course_messages}
                WHERE course_id IN (SELECT id FROM {courses} WHERE teacher_id = ?)' . $courseCond . '
                GROUP BY course_id, student_id
             ) t
             JOIN {course_messages} m ON m.id = t.last_id
             JOIN {courses} c ON c.id = t.course_id
             JOIN {users} u ON u.id = t.student_id
             ORDER BY t.unread > 0 DESC, m.id DESC',
            $params
        );
    }

    /**
     * นักเรียนที่ครูส่งข้อความหาได้ — ทุกคนที่เรียนอยู่ในรายวิชาที่เปิดสอนของครูคนนี้
     *
     * @return list<array<string,mixed>>
     */
    public function teacherRecipients(int $teacherId): array
    {
        return $this->db->all(
            'SELECT c.id AS course_id, c.code AS course_code, c.name AS course_name, u.id AS student_id, u.username, u.full_name
             FROM {courses} c
             JOIN {enrollments} e ON e.course_id = c.id AND e.status = \'active\'
             JOIN {users} u ON u.id = e.student_id
             WHERE c.teacher_id = ? AND c.status = \'active\'
             ORDER BY c.code, c.name, u.username',
            [$teacherId]
        );
    }

    /**
     * กล่องข้อความของนักเรียน: หนึ่งแถวต่อรายวิชาที่เรียนอยู่ (คุยกับครูผู้สอน) พร้อมข้อความล่าสุดถ้ามี
     *
     * @return list<array<string,mixed>>
     */
    public function studentInbox(int $studentId): array
    {
        return $this->db->all(
            'SELECT c.id AS course_id, c.code AS course_code, c.name AS course_name, tu.full_name AS teacher_name,
                    COALESCE(t.unread, 0) AS unread, m.body, m.sender_id, m.created_at
             FROM {enrollments} e
             JOIN {courses} c ON c.id = e.course_id AND c.status = \'active\'
             LEFT JOIN {users} tu ON tu.id = c.teacher_id
             LEFT JOIN (
                SELECT course_id, MAX(id) AS last_id, SUM(sender_id <> student_id AND read_at IS NULL) AS unread
                FROM {course_messages} WHERE student_id = ?
                GROUP BY course_id
             ) t ON t.course_id = c.id
             LEFT JOIN {course_messages} m ON m.id = t.last_id
             WHERE e.student_id = ? AND e.status = \'active\'
             ORDER BY COALESCE(t.unread, 0) > 0 DESC, m.id IS NULL, m.id DESC, c.code',
            [$studentId, $studentId]
        );
    }
}
