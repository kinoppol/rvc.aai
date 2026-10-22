<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDOException;

/**
 * ประกาศข่าวสารของรายวิชา — ครูผู้สอนประกาศ นักเรียนทุกคนที่ลงทะเบียนอยู่เห็น
 * ป้าย "ใหม่" นับจาก course_post_seen (เวลาที่นักเรียนเปิดหน้ารายวิชาครั้งล่าสุด)
 */
final class CourseFeedRepository
{
    public const MAX_BODY = 5000;

    private ?bool $ready = null;

    public function __construct(private readonly Db $db)
    {
    }

    /** ตารางพร้อมใช้หรือยัง (ผู้ดูแลระบบอาจยังไม่ได้รันการปรับฐานข้อมูล 000130) */
    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = $this->db->first("SHOW TABLES LIKE '" . $this->db->table('course_posts') . "'") !== null;
            } catch (PDOException) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /** @return list<array<string,mixed>> ประกาศที่ปักหมุดก่อน แล้วเรียงใหม่ไปเก่า */
    public function forCourse(int $courseId, int $limit = 200): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->db->all(
            'SELECT p.*, u.full_name AS author_name
             FROM {course_posts} p LEFT JOIN {users} u ON u.id = p.author_id
             WHERE p.course_id = ?
             ORDER BY p.pinned DESC, p.created_at DESC, p.id DESC
             LIMIT ' . max(1, $limit),
            [$courseId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {course_posts} WHERE id = ?', [$id]);
    }

    public function create(int $courseId, int $authorId, string $body, bool $pinned): int
    {
        return $this->db->insert('course_posts', [
            'course_id' => $courseId,
            'author_id' => $authorId,
            'body' => $body,
            'pinned' => $pinned ? 1 : 0,
        ]);
    }

    public function update(int $id, string $body): void
    {
        $this->db->run('UPDATE {course_posts} SET body = ?, updated_at = NOW() WHERE id = ?', [$body, $id]);
    }

    public function setPinned(int $id, bool $pinned): void
    {
        $this->db->run('UPDATE {course_posts} SET pinned = ? WHERE id = ?', [$pinned ? 1 : 0, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM {course_posts} WHERE id = ?', [$id]);
    }

    /** เวลาที่ผู้ใช้คนนี้เปิดดูประกาศของรายวิชาครั้งล่าสุด (ยังไม่เคย = null) */
    public function seenAt(int $courseId, int $userId): ?string
    {
        if (!$this->ready()) {
            return null;
        }
        $at = $this->db->value('SELECT seen_at FROM {course_post_seen} WHERE course_id = ? AND user_id = ?', [$courseId, $userId]);

        return $at === null ? null : (string) $at;
    }

    public function markSeen(int $courseId, int $userId): void
    {
        if (!$this->ready()) {
            return;
        }
        $this->db->run(
            'INSERT INTO {course_post_seen} (course_id, user_id, seen_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE seen_at = NOW()',
            [$courseId, $userId]
        );
    }

    /**
     * จำนวนนักเรียน (ที่ยังเรียนอยู่) ที่เปิดดูหลังประกาศแต่ละชิ้นแล้ว
     *
     * @param list<array<string,mixed>> $posts
     * @return array<int,int> post id => จำนวนคนที่เห็นแล้ว
     */
    public function seenCounts(int $courseId, array $posts): array
    {
        if ($posts === [] || !$this->ready()) {
            return [];
        }
        $seen = array_map(static fn (array $r): string => (string) $r['seen_at'], $this->db->all(
            'SELECT s.seen_at FROM {course_post_seen} s
             JOIN {enrollments} e ON e.course_id = s.course_id AND e.student_id = s.user_id AND e.status = \'active\'
             WHERE s.course_id = ?',
            [$courseId]
        ));

        $out = [];
        foreach ($posts as $p) {
            $out[(int) $p['id']] = count(array_filter($seen, static fn (string $at): bool => $at >= (string) $p['created_at']));
        }

        return $out;
    }

    /** @return array<int,int> course id => จำนวนประกาศที่นักเรียนคนนี้ยังไม่ได้เปิดดู (เฉพาะรายวิชาที่เรียนอยู่) */
    public function unseenByCourse(int $studentId): array
    {
        if (!$this->ready()) {
            return [];
        }
        try {
            $rows = $this->db->all(
                'SELECT p.course_id, COUNT(*) AS n
                 FROM {course_posts} p
                 JOIN {enrollments} e ON e.course_id = p.course_id AND e.student_id = ? AND e.status = \'active\'
                 JOIN {courses} c ON c.id = p.course_id AND c.status = \'active\'
                 LEFT JOIN {course_post_seen} s ON s.course_id = p.course_id AND s.user_id = ?
                 WHERE s.seen_at IS NULL OR p.created_at > s.seen_at
                 GROUP BY p.course_id',
                [$studentId, $studentId]
            );
        } catch (PDOException) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['course_id']] = (int) $r['n'];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> ประกาศล่าสุดจากทุกรายวิชาที่นักเรียนเรียนอยู่ (หน้าแรกของนักเรียน) */
    public function latestForStudent(int $studentId, int $limit = 5): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->db->all(
            'SELECT p.id, p.course_id, p.body, p.pinned, p.created_at, c.code AS course_code, c.name AS course_name,
                    u.full_name AS author_name,
                    (s.seen_at IS NULL OR p.created_at > s.seen_at) AS is_new
             FROM {course_posts} p
             JOIN {enrollments} e ON e.course_id = p.course_id AND e.student_id = ? AND e.status = \'active\'
             JOIN {courses} c ON c.id = p.course_id AND c.status = \'active\'
             LEFT JOIN {users} u ON u.id = p.author_id
             LEFT JOIN {course_post_seen} s ON s.course_id = p.course_id AND s.user_id = ?
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT ' . max(1, $limit),
            [$studentId, $studentId]
        );
    }
}
