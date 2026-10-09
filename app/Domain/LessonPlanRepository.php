<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * แผนการจัดการเรียนรู้ — เก็บเป็นเอกสารใน ai_generations (target_type = lesson_plan)
 * payload มีโครงสร้าง: {title, meta:{unit,competency,hours,week,style}, sections:[{head,body}], status}
 */
final class LessonPlanRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    public function create(int $userId, ?int $jobId, int $courseId, array $payload): int
    {
        $id = $this->db->insert('ai_generations', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'target_type' => 'lesson_plan',
            'target_id' => null,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'review_status' => 'pending',
        ]);

        // target_id ชี้กลับมาที่ตัวเอง เพื่อให้รายการรอตรวจเปิดหน้าตรวจได้
        $this->db->update('ai_generations', ['target_id' => $id], ['id' => $id]);

        return $id;
    }

    /** @return array<string,mixed>|null คืน row พร้อม payload ที่ decode แล้วในคีย์ doc */
    public function find(int $id, int $userId): ?array
    {
        $row = $this->db->first(
            'SELECT g.*, j.course_id
             FROM {ai_generations} g
             LEFT JOIN {ai_jobs} j ON j.id = g.job_id
             WHERE g.id = ? AND g.user_id = ? AND g.target_type = \'lesson_plan\'',
            [$id, $userId]
        );
        if ($row === null) {
            return null;
        }
        $row['doc'] = json_decode((string) $row['payload'], true) ?: [];

        return $row;
    }

    public function updateDoc(int $id, array $doc): void
    {
        $this->db->update('ai_generations', ['payload' => json_encode($doc, JSON_UNESCAPED_UNICODE)], ['id' => $id]);
    }

    public function setStatus(int $id, string $reviewStatus, int $reviewerId): void
    {
        $this->db->run(
            'UPDATE {ai_generations} SET review_status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            [$reviewStatus, $reviewerId, $id]
        );
    }

    /** @return list<array<string,mixed>> แผนของรายวิชานี้ */
    public function forCourse(int $courseId, int $userId): array
    {
        $rows = $this->db->all(
            'SELECT g.id, g.payload, g.review_status, g.created_at
             FROM {ai_generations} g
             JOIN {ai_jobs} j ON j.id = g.job_id
             WHERE j.course_id = ? AND g.user_id = ? AND g.target_type = \'lesson_plan\'
             ORDER BY g.created_at DESC',
            [$courseId, $userId]
        );

        return array_map(static function (array $r): array {
            $doc = json_decode((string) $r['payload'], true) ?: [];

            return [
                'id' => (int) $r['id'],
                'title' => $doc['title'] ?? 'แผนการจัดการเรียนรู้',
                'review_status' => $r['review_status'],
                'created_at' => $r['created_at'],
            ];
        }, $rows);
    }
}
