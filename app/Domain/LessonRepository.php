<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * บทเรียนในรายวิชา และไฟล์ใบความรู้ที่แนบ
 */
final class LessonRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forCourse(int $courseId): array
    {
        return $this->db->all(
            'SELECT l.*,
                    (SELECT COUNT(*) FROM {lesson_attachments} a WHERE a.lesson_id = l.id) AS attachment_count
             FROM {lessons} l
             WHERE l.course_id = ?
             ORDER BY l.sort_order, l.id',
            [$courseId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {lessons} WHERE id = ?', [$id]);
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    public function byIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->all(
            "SELECT * FROM {lessons} WHERE id IN ($in) ORDER BY sort_order, id",
            array_values($ids)
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('lessons', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('lessons', $data, ['id' => $id]);
    }

    public function nextSortOrder(int $courseId): int
    {
        return $this->db->int('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {lessons} WHERE course_id = ?', [$courseId]);
    }
}
