<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDOException;

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
                    (SELECT COUNT(*) FROM {quizzes} q WHERE q.unit_id = u.id AND q.kind = \'pretest\' AND q.deleted_at IS NULL) AS has_pretest,
                    (SELECT COUNT(*) FROM {quizzes} q WHERE q.unit_id = u.id AND q.kind = \'posttest\' AND q.deleted_at IS NULL) AS has_posttest,
                    (SELECT COUNT(*) FROM {assignments} a WHERE a.unit_id = u.id AND a.kind = \'worksheet\') AS has_assignment
             FROM {units} u
             WHERE u.course_id = ?
             ORDER BY u.sort_order, u.id',
            [$courseId]
        );
    }

    /** ประเภทของหน่วย: หน่วยการเรียนปกติ หรือหน่วยสอบ (ไม่มีเนื้อหา มีแต่แบบทดสอบ) */
    public const KINDS = [
        'lesson'  => 'หน่วยการเรียน',
        'midterm' => 'สอบกลางภาค',
        'final'   => 'สอบปลายภาค',
    ];

    public static function isExam(array $unit): bool
    {
        return in_array($unit['kind'] ?? 'lesson', ['midterm', 'final'], true);
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
            'SELECT u.id, u.title, u.kind, u.key_content, u.sort_order,
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

    /**
     * บันทึกหลังการจัดการเรียนรู้ของหน่วย (ข้อ 9 ในแผนการสอน) · ยังไม่ได้บันทึก = null
     * ถ้ายังไม่ได้รันไมเกรชัน 000127 ก็คืน null ให้หน้าแก้ไขหน่วยและหน้าพิมพ์ยังเปิดได้
     *
     * @return array<string,mixed>|null
     */
    public function report(int $unitId): ?array
    {
        try {
            return $this->db->first('SELECT * FROM {unit_reports} WHERE unit_id = ?', [$unitId]);
        } catch (PDOException) {
            return null;
        }
    }

    /** @return array<int,array<string,mixed>> บันทึกหลังสอนของทุกหน่วยในรายวิชา คีย์ด้วย unit_id */
    public function reportsForCourse(int $courseId): array
    {
        try {
            $rows = $this->db->all(
                'SELECT r.* FROM {unit_reports} r JOIN {units} u ON u.id = r.unit_id WHERE u.course_id = ?',
                [$courseId]
            );
        } catch (PDOException) {
            return [];
        }

        return array_column($rows, null, 'unit_id');
    }

    /** @param array{plan_result:?string, learner_result:?string, problems:?string, recorded_on:?string} $data */
    public function saveReport(int $unitId, int $userId, array $data): void
    {
        $this->db->run(
            'INSERT INTO {unit_reports} (unit_id, plan_result, learner_result, problems, recorded_on, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE plan_result = VALUES(plan_result), learner_result = VALUES(learner_result),
                 problems = VALUES(problems), recorded_on = VALUES(recorded_on), recorded_by = VALUES(recorded_by)',
            [$unitId, $data['plan_result'], $data['learner_result'], $data['problems'], $data['recorded_on'], $userId]
        );
    }

    /**
     * สรุปผลแบบทดสอบของหน่วยไว้ช่วยครูเขียนข้อ 9.2 — ใช้คะแนนดีที่สุดของนักเรียนแต่ละคนเป็นร้อยละ
     *
     * @return array{enrolled:int, pre:?array{count:int, avg:float}, post:?array{count:int, avg:float, passed:int}}
     */
    public function quizSummary(int $unitId, int $courseId, float $passPercent): array
    {
        $best = fn (string $kind): array => array_map('floatval', array_column($this->db->all(
            'SELECT MAX(a.score / a.max_score * 100) AS pct
             FROM {quiz_attempts} a JOIN {quizzes} q ON q.id = a.quiz_id
             WHERE q.unit_id = ? AND q.kind = ? AND q.deleted_at IS NULL
               AND a.status <> \'in_progress\' AND a.score IS NOT NULL AND a.max_score > 0
             GROUP BY a.student_id',
            [$unitId, $kind]
        ), 'pct'));

        $pre = $best('pretest');
        $post = $best('posttest');

        return [
            'enrolled' => $this->db->int('SELECT COUNT(*) FROM {enrollments} WHERE course_id = ? AND status <> \'dropped\'', [$courseId]),
            'pre' => $pre === [] ? null : ['count' => count($pre), 'avg' => array_sum($pre) / count($pre)],
            'post' => $post === [] ? null : [
                'count' => count($post),
                'avg' => array_sum($post) / count($post),
                'passed' => count(array_filter($post, static fn (float $p): bool => $p >= $passPercent)),
            ],
        ];
    }
}
