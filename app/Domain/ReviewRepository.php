<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * เนื้อหาที่ผู้ช่วย AI ร่างไว้และรอครูตรวจก่อนเผยแพร่ (ai_generations)
 * และรายการงานที่สร้างล่าสุดสำหรับหน้าแดชบอร์ด
 */
final class ReviewRepository
{
    private const TARGET_LABEL = [
        'quiz' => 'แบบทดสอบ',
        'lesson' => 'ใบความรู้',
        'lesson_plan' => 'แผนการจัดการเรียนรู้',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    public function pendingCount(int $teacherId): int
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {ai_generations} WHERE user_id = ? AND review_status = \'pending\'',
            [$teacherId]
        );
    }

    /**
     * รายการรอตรวจของครู จัดกลุ่มตามรายวิชา
     *
     * @return list<array{course:string,code:string,items:list<array<string,mixed>>}>
     */
    public function pendingGroupedByCourse(int $teacherId): array
    {
        $rows = $this->db->all(
            'SELECT g.id, g.target_type, g.target_id, g.payload, g.created_at,
                    j.kind, j.source, j.course_id,
                    c.name AS course_name, c.code AS course_code
             FROM {ai_generations} g
             LEFT JOIN {ai_jobs} j ON j.id = g.job_id
             LEFT JOIN {courses} c ON c.id = j.course_id
             WHERE g.user_id = ? AND g.review_status = \'pending\'
             ORDER BY c.code, g.created_at DESC',
            [$teacherId]
        );

        $groups = [];
        foreach ($rows as $row) {
            $key = (string) ($row['course_code'] ?? 'อื่น ๆ');
            $groups[$key] ??= [
                'course' => $row['course_name'] ?? 'ไม่ระบุรายวิชา',
                'code' => $row['course_code'] ?? '',
                'items' => [],
            ];

            $payload = json_decode((string) ($row['payload'] ?? '{}'), true) ?: [];
            $groups[$key]['items'][] = [
                'id' => (int) $row['id'],
                'target_type' => $row['target_type'],
                'target_id' => $row['target_id'] !== null ? (int) $row['target_id'] : null,
                'course_id' => $row['course_id'] !== null ? (int) $row['course_id'] : null,
                'title' => $payload['title'] ?? (self::TARGET_LABEL[$row['target_type']] ?? 'เนื้อหา'),
                'summary' => $payload['summary'] ?? '',
                'created_at' => $row['created_at'],
                'source' => $this->sourceLabel($row['source'], $payload['ai_mode'] ?? null),
            ];
        }

        return array_values($groups);
    }

    /** บันทึกว่ามีการร่างเนื้อหาชิ้นใหม่รอตรวจ */
    public function record(int $userId, ?int $jobId, string $targetType, ?int $targetId, array $payload): int
    {
        return $this->db->insert('ai_generations', [
            'job_id' => $jobId,
            'user_id' => $userId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'review_status' => 'pending',
        ]);
    }

    public function approve(int $generationId, int $reviewerId): void
    {
        $this->db->run(
            'UPDATE {ai_generations} SET review_status = \'approved\', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            [$reviewerId, $generationId]
        );
    }

    public function reject(int $generationId, int $reviewerId): void
    {
        $this->db->run(
            'UPDATE {ai_generations} SET review_status = \'rejected\', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
            [$reviewerId, $generationId]
        );
    }

    public function linkTarget(int $generationId, string $targetType, int $targetId): void
    {
        $this->db->update('ai_generations', ['target_type' => $targetType, 'target_id' => $targetId], ['id' => $generationId]);
    }

    /** @return array<string,mixed>|null รายการรอตรวจของเนื้อหาที่ผูกกับ target นี้ */
    public function findByTarget(int $userId, string $targetType, int $targetId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {ai_generations} WHERE user_id = ? AND target_type = ? AND target_id = ? ORDER BY id DESC LIMIT 1',
            [$userId, $targetType, $targetId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $generationId): ?array
    {
        return $this->db->first('SELECT * FROM {ai_generations} WHERE id = ?', [$generationId]);
    }

    /**
     * งานที่ครูสร้างล่าสุด (ทั้งที่รอตรวจและเผยแพร่แล้ว) สำหรับหน้าแดชบอร์ด
     *
     * @return list<array<string,mixed>>
     */
    public function recentForTeacher(int $teacherId, int $limit = 6): array
    {
        $rows = $this->db->all(
            'SELECT g.target_type, g.review_status, g.payload, g.created_at,
                    j.source, c.name AS course_name
             FROM {ai_generations} g
             LEFT JOIN {ai_jobs} j ON j.id = g.job_id
             LEFT JOIN {courses} c ON c.id = j.course_id
             WHERE g.user_id = ?
             ORDER BY g.created_at DESC
             LIMIT ' . max(1, $limit),
            [$teacherId]
        );

        return array_map(function (array $row): array {
            $payload = json_decode((string) ($row['payload'] ?? '{}'), true) ?: [];
            $approved = $row['review_status'] === 'approved';

            return [
                'title' => $payload['title'] ?? (self::TARGET_LABEL[$row['target_type']] ?? 'เนื้อหา'),
                'meta' => trim(($row['course_name'] ?? '') . ($payload['summary'] ?? '' ? ' · ' . $payload['summary'] : ''), ' ·'),
                'status' => $approved ? 'เผยแพร่แล้ว' : 'รอครูตรวจ',
                'status_kind' => $approved ? 'ok' : 'warn',
                'created_at' => $row['created_at'],
            ];
        }, $rows);
    }

    private function sourceLabel(?string $source, ?string $mode): string
    {
        if ($source === 'byok') {
            return 'AI ของฉัน' . ($mode ? ' · ' . $mode : '');
        }

        return 'AI ส่วนกลาง';
    }
}
