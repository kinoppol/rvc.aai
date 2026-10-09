<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * ใบงานที่ครูมอบหมายในแต่ละหน่วยการเรียน และจำนวนงานที่นักเรียนส่งเข้ามาแล้ว
 */
final class AssignmentRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forUnit(int $unitId): array
    {
        return $this->db->all(
            'SELECT a.*,
                    (SELECT COUNT(*) FROM {submissions} s WHERE s.assignment_id = a.id) AS submission_count
             FROM {assignments} a
             WHERE a.unit_id = ?
             ORDER BY a.created_at',
            [$unitId]
        );
    }

    /** @return array<string,mixed>|null ใบงานที่อยู่ในรายวิชานี้จริง (กันเดา id ข้ามรายวิชา) */
    public function find(int $id, int $courseId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {assignments} WHERE id = ? AND course_id = ?',
            [$id, $courseId]
        );
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('assignments', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('assignments', $data, ['id' => $id]);
    }

    /** ลบได้เฉพาะใบงานที่ยังไม่มีนักเรียนส่งงาน */
    public function delete(int $id): bool
    {
        if ($this->db->int('SELECT COUNT(*) FROM {submissions} WHERE assignment_id = ?', [$id]) > 0) {
            return false;
        }

        $this->db->run('DELETE FROM {assignments} WHERE id = ?', [$id]);

        return true;
    }
}
