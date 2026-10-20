<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use App\Support\Paths;

/**
 * งานที่นักเรียนส่ง (ใบงาน / งานที่มอบหมาย) — ข้อความ + ไฟล์แนบ (รูปภาพ / PDF) หลายไฟล์ต่อการส่งหนึ่งครั้ง
 *
 * หนึ่งคนส่งได้หนึ่งแถวต่องาน (uk_submission) — แก้ไข/ส่งใหม่ = อัปเดตแถวเดิม
 * status: submitted = รอตรวจ · graded = ตรวจแล้ว (ล็อก) · returned = ครูส่งกลับให้แก้ (นักเรียนส่งใหม่ได้)
 * ไฟล์อยู่ใน storage/uploads/submissions/{assignmentId}/ นอก public/ — เปิดได้ผ่าน controller ที่ตรวจสิทธิ์เท่านั้น
 */
final class SubmissionRepository
{
    public const MAX_FILES = 5;
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_TEXT = 20000;
    /** ชนิดไฟล์ที่รับ (ตรวจจากเนื้อไฟล์จริงด้วย finfo) → นามสกุลที่เก็บ — ไม่รับ SVG เพราะฝังสคริปต์ได้ */
    public const TYPES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'application/pdf' => 'pdf',
    ];
    public const STATUS = [
        'submitted' => 'ส่งแล้ว · รอตรวจ',
        'graded'    => 'ตรวจแล้ว',
        'returned'  => 'ครูส่งกลับให้แก้ไข',
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null งานของนักเรียนคนนี้ + ไฟล์แนบ (`files`) */
    public function forStudent(int $assignmentId, int $studentId): ?array
    {
        $row = $this->db->first(
            'SELECT * FROM {submissions} WHERE assignment_id = ? AND student_id = ?',
            [$assignmentId, $studentId]
        );
        if ($row === null) {
            return null;
        }
        $row['files'] = $this->files((int) $row['id']);

        return $row;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->first('SELECT * FROM {submissions} WHERE id = ?', [$id]);
        if ($row !== null) {
            $row['files'] = $this->files($id);
        }

        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function files(int $submissionId): array
    {
        return array_map(static fn (array $f): array => $f + ['is_image' => str_starts_with((string) $f['mime_type'], 'image/')],
            $this->db->all('SELECT * FROM {submission_files} WHERE submission_id = ? ORDER BY id', [$submissionId]));
    }

    /** @return array<string,mixed>|null ไฟล์ + เจ้าของงาน + รายวิชา (ใช้ตรวจสิทธิ์ก่อนเปิดไฟล์) */
    public function file(int $fileId): ?array
    {
        return $this->db->first(
            'SELECT f.*, s.student_id, s.assignment_id, a.course_id
             FROM {submission_files} f
             JOIN {submissions} s ON s.id = f.submission_id
             JOIN {assignments} a ON a.id = s.assignment_id
             WHERE f.id = ?',
            [$fileId]
        );
    }

    /** สร้างหรืออัปเดตงานของนักเรียน (ส่งใหม่ = กลับเป็นรอตรวจ) แล้วคืน id */
    public function save(int $assignmentId, int $studentId, string $content): int
    {
        $existing = $this->db->first(
            'SELECT id FROM {submissions} WHERE assignment_id = ? AND student_id = ?',
            [$assignmentId, $studentId]
        );
        $fields = [
            'content'      => $content !== '' ? $content : null,
            'status'       => 'submitted',
            'submitted_at' => date('Y-m-d H:i:s'),
        ];
        if ($existing !== null) {
            $this->db->update('submissions', $fields, ['id' => (int) $existing['id']]);

            return (int) $existing['id'];
        }

        return $this->db->insert('submissions', $fields + ['assignment_id' => $assignmentId, 'student_id' => $studentId]);
    }

    /** @param array{original_name:string,stored_path:string,mime_type:string,size_bytes:int} $file */
    public function addFile(int $submissionId, array $file): void
    {
        $this->db->insert('submission_files', $file + ['submission_id' => $submissionId]);
    }

    /** ลบไฟล์ของงานนี้ (ทั้งแถวและไฟล์จริง) — id ที่ไม่ใช่ของงานนี้ถูกข้าม */
    public function removeFiles(int $submissionId, array $fileIds): void
    {
        foreach ($fileIds as $id) {
            $f = $this->db->first('SELECT * FROM {submission_files} WHERE id = ? AND submission_id = ?', [(int) $id, $submissionId]);
            if ($f === null) {
                continue;
            }
            $this->db->run('DELETE FROM {submission_files} WHERE id = ?', [(int) $f['id']]);
            $path = self::path((string) $f['stored_path']);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    public function grade(int $id, ?float $score, string $feedback, string $status, int $graderId): void
    {
        $this->db->update('submissions', [
            'score'     => $score,
            'feedback'  => $feedback !== '' ? $feedback : null,
            'status'    => $status,
            'graded_by' => $graderId,
            'graded_at' => date('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * นักเรียนทุกคนในรายวิชา + งานที่ส่ง (ถ้ามี) — ใช้ในหน้าตรวจงานของครู
     *
     * @return list<array<string,mixed>>
     */
    public function roster(int $assignmentId, int $courseId): array
    {
        $rows = $this->db->all(
            "SELECT u.id AS student_id, u.username, u.full_name,
                    s.id, s.content, s.status, s.score, s.feedback, s.submitted_at, s.graded_at
             FROM {enrollments} e
             JOIN {users} u ON u.id = e.student_id
             LEFT JOIN {submissions} s ON s.assignment_id = ? AND s.student_id = u.id
             WHERE e.course_id = ? AND e.status = 'active'
             ORDER BY s.id IS NULL, s.status = 'graded', u.username",
            [$assignmentId, $courseId]
        );
        foreach ($rows as $i => $r) {
            $rows[$i]['files'] = $r['id'] !== null ? $this->files((int) $r['id']) : [];
        }

        return $rows;
    }

    /** ที่อยู่จริงของไฟล์ — null ถ้าไม่มีหรือชี้ออกนอก storage/uploads */
    public static function path(string $stored): ?string
    {
        $root = realpath(Paths::storage('uploads'));
        $path = realpath(Paths::storage('uploads/' . $stored));

        return $root !== false && $path !== false && is_file($path) && str_starts_with($path, $root . DIRECTORY_SEPARATOR)
            ? $path
            : null;
    }

    /** ปิดรับงานแล้วหรือยัง (เลยกำหนดส่งและไม่รับงานส่งช้า) */
    public static function closed(array $assignment): bool
    {
        return $assignment['due_at'] !== null
            && strtotime((string) $assignment['due_at']) < time()
            && !(int) $assignment['allow_late'];
    }

    /** นักเรียนแก้ไข/ส่งใหม่ได้หรือไม่ */
    public static function editable(array $assignment, ?array $submission): bool
    {
        return !self::closed($assignment) && ($submission === null || $submission['status'] !== 'graded');
    }

    public static function late(array $assignment, ?array $submission): bool
    {
        return $submission !== null && $assignment['due_at'] !== null
            && strtotime((string) $submission['submitted_at']) > strtotime((string) $assignment['due_at']);
    }
}
