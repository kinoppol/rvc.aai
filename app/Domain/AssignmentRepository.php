<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * งานที่ครูมอบหมาย และจำนวนงานที่นักเรียนส่งเข้ามาแล้ว
 *
 * kind = worksheet → ใบงานของหน่วยการเรียน (unit_id บังคับ)
 * kind = task      → งานที่มอบหมายเพิ่มเติมนอกเหนือจากใบงาน (ผูกหน่วยหรือไม่ก็ได้)
 */
final class AssignmentRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public const KINDS = ['worksheet', 'task'];

    /** @return list<array<string,mixed>> ใบงานของหน่วยนี้ (ไม่รวมงานที่มอบหมายเพิ่มเติม) */
    public function forUnit(int $unitId): array
    {
        return $this->db->all(
            'SELECT a.*,
                    (SELECT COUNT(*) FROM {submissions} s WHERE s.assignment_id = a.id) AS submission_count
             FROM {assignments} a
             WHERE a.unit_id = ? AND a.kind = \'worksheet\'
             ORDER BY a.created_at',
            [$unitId]
        );
    }

    /**
     * งานทั้งรายวิชาตามประเภท เรียงตามลำดับหน่วย (ใบงาน) หรือกำหนดส่ง (งานที่มอบหมาย)
     *
     * @return list<array<string,mixed>>
     */
    public function forCourse(int $courseId, string $kind): array
    {
        $order = $kind === 'task'
            ? 'a.due_at IS NULL, a.due_at, a.created_at'
            : 'u.sort_order, u.id, a.created_at';

        return $this->db->all(
            'SELECT a.*, u.title AS unit_title, u.sort_order AS unit_order,
                    (SELECT COUNT(*) FROM {submissions} s WHERE s.assignment_id = a.id) AS submission_count
             FROM {assignments} a
             LEFT JOIN {units} u ON u.id = a.unit_id
             WHERE a.course_id = ? AND a.kind = ?
             ORDER BY ' . $order,
            [$courseId, $kind === 'task' ? 'task' : 'worksheet']
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
