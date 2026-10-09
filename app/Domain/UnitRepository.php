<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * หน่วยการเรียนในรายวิชา และส่วนเนื้อหา (text / video / pdf) ภายในแต่ละหน่วย
 */
final class UnitRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forCourse(int $courseId): array
    {
        return $this->db->all(
            'SELECT u.*,
                    (SELECT COUNT(*) FROM {unit_sections} s WHERE s.unit_id = u.id) AS section_count,
                    (SELECT COUNT(*) FROM {quizzes} q WHERE q.unit_id = u.id AND q.kind = \'pretest\') AS has_pretest,
                    (SELECT COUNT(*) FROM {quizzes} q WHERE q.unit_id = u.id AND q.kind = \'posttest\') AS has_posttest,
                    (SELECT COUNT(*) FROM {assignments} a WHERE a.unit_id = u.id) AS has_assignment
             FROM {units} u
             WHERE u.course_id = ?
             ORDER BY u.sort_order, u.id',
            [$courseId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {units} WHERE id = ?', [$id]);
    }

    /**
     * หน่วยการเรียนที่ครูตรวจและเผยแพร่แล้วเท่านั้น สำหรับหน้าสาธารณะ
     * (หน่วยที่ยังเป็นร่างหรือรอตรวจต้องไม่หลุดออกไปให้คนนอกเห็น)
     *
     * @return list<array<string,mixed>>
     */
    public function publishedForCourse(int $courseId): array
    {
        return $this->db->all(
            'SELECT u.id, u.title, u.key_content, u.sort_order,
                    (SELECT COUNT(*) FROM {unit_sections} s WHERE s.unit_id = u.id) AS section_count
             FROM {units} u
             WHERE u.course_id = ? AND u.review_status = \'published\'
             ORDER BY u.sort_order, u.id',
            [$courseId]
        );
    }

    /** @return array<string,mixed>|null หน่วยที่เผยแพร่แล้วและอยู่ในรายวิชานี้จริง */
    public function findPublished(int $unitId, int $courseId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {units} WHERE id = ? AND course_id = ? AND review_status = \'published\'',
            [$unitId, $courseId]
        );
    }

    /** @return list<array<string,mixed>> */
    public function sectionsFor(int $unitId): array
    {
        return $this->db->all(
            'SELECT * FROM {unit_sections} WHERE unit_id = ? ORDER BY sort_order, id',
            [$unitId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findSection(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {unit_sections} WHERE id = ?', [$id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        return $this->db->insert('units', $data);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('units', $data, ['id' => $id]);
    }

    /** @param array<string,mixed> $data */
    public function createSection(array $data): int
    {
        return $this->db->insert('unit_sections', $data);
    }

    /** @param array<string,mixed> $data */
    public function updateSection(int $id, array $data): void
    {
        $this->db->update('unit_sections', $data, ['id' => $id]);
    }

    public function deleteSection(int $id): void
    {
        $this->db->run('DELETE FROM {unit_sections} WHERE id = ?', [$id]);
    }

    public function nextSortOrder(int $courseId): int
    {
        return $this->db->int('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {units} WHERE course_id = ?', [$courseId]);
    }

    public function nextSectionSortOrder(int $unitId): int
    {
        return $this->db->int('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {unit_sections} WHERE unit_id = ?', [$unitId]);
    }
}
