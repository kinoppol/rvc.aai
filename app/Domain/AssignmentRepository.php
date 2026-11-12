<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use App\Support\Paths;
use PDOException;

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

    /** ลบได้เฉพาะใบงานที่ยังไม่มีนักเรียนส่งงาน (ลบไฟล์แนบของครูทิ้งด้วย) */
    public function delete(int $id): bool
    {
        if ($this->db->int('SELECT COUNT(*) FROM {submissions} WHERE assignment_id = ?', [$id]) > 0) {
            return false;
        }

        $this->removeFiles($id, array_map(static fn (array $f): int => (int) $f['id'], $this->files($id)));
        $this->db->run('DELETE FROM {assignments} WHERE id = ?', [$id]);

        return true;
    }

    // ── ไฟล์ที่ครูแนบให้นักเรียน (migration 000132) ─────────────────────────

    public const FILE_MAX = 10;
    public const FILE_MAX_BYTES = 20 * 1024 * 1024;

    /**
     * ชนิดไฟล์ที่ครูแนบได้ (ตรวจจากเนื้อไฟล์จริงด้วย finfo) → นามสกุลที่เก็บ
     * รูปและ PDF เปิดดูในเบราว์เซอร์ ที่เหลือดาวน์โหลด · ไม่รับ SVG/HTML เพราะฝังสคริปต์ได้
     */
    public const FILE_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/zip' => 'zip',
        'text/plain' => 'txt',
    ];

    /** ชนิดที่เปิดในเบราว์เซอร์ได้ (inline) */
    public const FILE_INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** มีตารางไฟล์แนบแล้วหรือยัง — ก่อนรัน migration ทุกเมธอดไฟล์คืนค่าว่าง */
    public function filesReady(): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $this->db->value('SELECT 1 FROM {assignment_files} LIMIT 1');
                $ready = true;
            } catch (PDOException) {
                $ready = false;
            }
        }

        return $ready;
    }

    /**
     * ชนิดไฟล์จริงจาก finfo + นามสกุลเดิม → mime ที่รับ หรือ null
     * ไฟล์ Office รุ่นใหม่เป็น zip และรุ่นเก่าเป็น CDF — finfo บางเครื่องบอกแค่นั้น จึงดูนามสกุลประกอบ
     */
    public static function fileType(string $detected, string $clientName): ?string
    {
        $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $byExt = array_search($ext, self::FILE_TYPES, true);

        if (isset(self::FILE_TYPES[$detected])) {
            // zip ที่จริงคือ docx/xlsx/pptx ให้เก็บตามนามสกุล Office
            return $detected === 'application/zip' && in_array($ext, ['docx', 'xlsx', 'pptx'], true) ? (string) $byExt : $detected;
        }
        if (in_array($detected, ['application/octet-stream', 'application/x-zip-compressed'], true) && in_array($ext, ['docx', 'xlsx', 'pptx', 'zip'], true)) {
            return (string) $byExt;
        }
        if (in_array($detected, ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office', 'application/octet-stream'], true)
            && in_array($ext, ['doc', 'xls', 'ppt'], true)) {
            return (string) $byExt;
        }
        if (str_starts_with($detected, 'text/') && $ext === 'txt') {
            return 'text/plain';
        }

        return null;
    }

    /** @return list<array<string,mixed>> ไฟล์แนบของงานนี้ (`is_image`, `inline`) */
    public function files(int $assignmentId): array
    {
        if (!$this->filesReady()) {
            return [];
        }

        return array_map(static fn (array $f): array => $f + [
            'is_image' => str_starts_with((string) $f['mime_type'], 'image/'),
            'inline' => in_array($f['mime_type'], self::FILE_INLINE, true),
            'ext' => strtoupper(self::FILE_TYPES[$f['mime_type']] ?? ''),
        ], $this->db->all('SELECT * FROM {assignment_files} WHERE assignment_id = ? ORDER BY id', [$assignmentId]));
    }

    /** @return array<string,mixed>|null ไฟล์ที่อยู่ในงานนี้จริง */
    public function file(int $fileId, int $assignmentId): ?array
    {
        if (!$this->filesReady()) {
            return null;
        }

        return $this->db->first('SELECT * FROM {assignment_files} WHERE id = ? AND assignment_id = ?', [$fileId, $assignmentId]);
    }

    /** @param array{original_name:string,stored_path:string,mime_type:string,size_bytes:int} $file */
    public function addFile(int $assignmentId, array $file): void
    {
        $this->db->insert('assignment_files', $file + ['assignment_id' => $assignmentId]);
    }

    /** ลบไฟล์แนบ (ทั้งแถวและไฟล์จริง) — id ที่ไม่ใช่ของงานนี้ถูกข้าม */
    public function removeFiles(int $assignmentId, array $ids): void
    {
        foreach ($ids as $id) {
            $f = $this->file((int) $id, $assignmentId);
            if ($f === null) {
                continue;
            }
            $this->db->run('DELETE FROM {assignment_files} WHERE id = ?', [(int) $f['id']]);
            $path = SubmissionRepository::path((string) $f['stored_path']);
            if ($path !== null) {
                @unlink($path);
            }
        }
    }

    /** โฟลเดอร์เก็บไฟล์ของงานนี้ (สัมพัทธ์กับ storage/uploads) */
    public static function fileDir(int $assignmentId): string
    {
        $dir = 'assignments/' . $assignmentId;
        if (!is_dir(Paths::storage('uploads/' . $dir))) {
            @mkdir(Paths::storage('uploads/' . $dir), 0775, true);
        }

        return $dir;
    }
}
