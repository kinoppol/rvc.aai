<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;
use PDOException;

/**
 * โหมดคุมสอบ — มาตรการป้องปรามการใช้ AI/ตัวช่วยระหว่างทำแบบทดสอบ (migration 000142)
 *
 * ฝั่งนักเรียน (public/assets/js/quiz-proctor.js) รายงานเหตุการณ์มาที่ record():
 *   switch = สลับแท็บ/แอป หรือออกจากโหมดเต็มจอ · copy = คัดลอก จับภาพหน้าจอ หรือสั่งพิมพ์
 *   paste / blocked = บันทึกไว้เฉย ๆ ไม่นับ
 * แต่ละกลุ่มยอมได้ LIMIT ครั้ง เกินจากนั้นระงับการสอบ (proctor_locked_at) จนครูอนุมัติให้สอบต่อ
 * ตัวนับเริ่มใหม่ทุกครั้งที่ครูอนุมัติ ส่วนบันทึกเหตุการณ์เก็บไว้ทั้งหมดให้ครูดูประกอบ
 *
 * เป็นเพียงการป้องปราม — ตรวจผิดพลาดได้ (เช่น มีการแจ้งเตือนเด้งขึ้นมา) ครูต้องใช้วิจารณญาณเสมอ
 * ทุกเมธอดทำงานได้ก่อนรัน migration (ถือว่าปิดโหมดคุมสอบ)
 */
final class ProctorRepository
{
    public const LIMIT = 3;

    /** ช่วงซ้อมสอบที่ครูเปิดให้ต่อครั้ง (นาที) */
    public const PRACTICE_MINUTES = 10;

    /** ป้ายภาษาไทยของเหตุการณ์ */
    public const LABELS = [
        'switch:hidden'     => 'สลับแท็บ/แอป',
        'switch:blur'       => 'ออกจากหน้าต่างข้อสอบ',
        'switch:fullscreen' => 'ออกจากโหมดเต็มจอ',
        'copy:copy'         => 'คัดลอกข้อความ',
        'copy:cut'          => 'ตัดข้อความ',
        'copy:printscreen'  => 'กดปุ่มจับภาพหน้าจอ',
        'copy:snip'         => 'ใช้คีย์ลัดจับภาพหน้าจอ',
        'copy:print'        => 'สั่งพิมพ์/บันทึกหน้า',
        'paste:paste'       => 'วางข้อความ (ถูกกันไว้)',
        'blocked:devtools'  => 'เปิดเครื่องมือนักพัฒนา (ถูกกันไว้)',
        'unlock'            => 'ครูอนุมัติให้สอบต่อ',
        'force_submit'      => 'ครูส่งคำตอบแทนและจบการสอบ',
    ];

    private const DETAILS = [
        'switch' => ['hidden', 'blur', 'fullscreen'],
        'copy'   => ['copy', 'cut', 'printscreen', 'snip', 'print'],
        'paste'  => ['paste'],
        'blocked' => ['devtools'],
    ];

    private ?bool $ready = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function ready(): bool
    {
        if ($this->ready === null) {
            try {
                $this->ready = $this->db->first("SHOW COLUMNS FROM {quiz_attempts} LIKE 'proctor_locked_at'") !== null;
            } catch (PDOException) {
                $this->ready = false;
            }
        }

        return $this->ready;
    }

    /** แบบทดสอบนี้เปิดโหมดคุมสอบหรือไม่ */
    public function enabled(array $quiz): bool
    {
        return $this->ready() && (int) ($quiz['proctor'] ?? 0) === 1;
    }

    public static function label(string $type, ?string $detail): string
    {
        return self::LABELS[$detail !== null && $detail !== '' ? "$type:$detail" : $type] ?? $type;
    }

    /**
     * สถานะสำหรับหน้าทำข้อสอบ
     *
     * @return array{switches:int,copies:int,locked:bool,extra:int,limit:int}
     */
    public function state(array $attempt): array
    {
        return [
            'switches' => (int) ($attempt['proctor_switches'] ?? 0),
            'copies'   => (int) ($attempt['proctor_copies'] ?? 0),
            'locked'   => !empty($attempt['proctor_locked_at']),
            'extra'    => (int) ($attempt['proctor_extra_seconds'] ?? 0),
            'limit'    => self::LIMIT,
        ];
    }

    public function isLocked(array $attempt): bool
    {
        return !empty($attempt['proctor_locked_at']);
    }

    /**
     * บันทึกเหตุการณ์จากเบราว์เซอร์ของนักเรียน แล้วคืนสถานะล่าสุด
     * เหตุการณ์ประเภทเดียวกันที่มาซ้ำภายใน 2 วินาที (เบราว์เซอร์ยิงหลายอีเวนต์ต่อการสลับครั้งเดียว) นับเป็นครั้งเดียว
     *
     * @return array{switches:int,copies:int,locked:bool,extra:int,limit:int,counted:bool}|null null = ประเภทไม่ถูกต้อง
     */
    public function record(int $attemptId, string $type, string $detail): ?array
    {
        if (!in_array($detail, self::DETAILS[$type] ?? [], true)) {
            return null;
        }

        $counted = false;
        $this->db->transaction(function () use ($attemptId, $type, $detail, &$counted): void {
            $attempt = $this->db->first('SELECT * FROM {quiz_attempts} WHERE id = ? FOR UPDATE', [$attemptId]);
            if ($attempt === null || $attempt['status'] !== 'in_progress' || $this->isLocked($attempt)) {
                return;
            }

            $recent = $this->db->int(
                'SELECT COUNT(*) FROM {quiz_attempt_events}
                 WHERE attempt_id = ? AND type = ? AND created_at >= NOW() - INTERVAL 2 SECOND',
                [$attemptId, $type]
            );
            if ($recent > 0) {
                return;
            }

            $this->db->insert('quiz_attempt_events', ['attempt_id' => $attemptId, 'type' => $type, 'detail' => $detail]);
            if ($type !== 'switch' && $type !== 'copy') {
                return;
            }

            $counted = true;
            $col = $type === 'switch' ? 'proctor_switches' : 'proctor_copies';
            $n = (int) $attempt[$col] + 1;
            $fields = [$col => $n];
            if ($n > self::LIMIT) {
                $fields['proctor_locked_at'] = date('Y-m-d H:i:s');
                $fields['proctor_lock_reason'] = $type;
            }
            $this->db->update('quiz_attempts', $fields, ['id' => $attemptId]);
        });

        $attempt = $this->db->first('SELECT * FROM {quiz_attempts} WHERE id = ?', [$attemptId]) ?? [];

        return $this->state($attempt) + ['counted' => $counted];
    }

    /** ครูอนุมัติให้สอบต่อ: ปลดระงับ เริ่มนับใหม่ และชดเชยเวลาที่เสียไประหว่างถูกระงับ */
    public function unlock(array $attempt, int $teacherId): void
    {
        if (!$this->isLocked($attempt)) {
            return;
        }
        $lost = max(0, time() - (int) strtotime((string) $attempt['proctor_locked_at']));
        $this->db->update('quiz_attempts', [
            'proctor_locked_at'     => null,
            'proctor_lock_reason'   => null,
            'proctor_switches'      => 0,
            'proctor_copies'        => 0,
            'proctor_extra_seconds' => (int) $attempt['proctor_extra_seconds'] + $lost,
            'proctor_unlocks'       => (int) $attempt['proctor_unlocks'] + 1,
        ], ['id' => (int) $attempt['id']]);
        $this->db->insert('quiz_attempt_events', ['attempt_id' => (int) $attempt['id'], 'type' => 'unlock', 'user_id' => $teacherId]);
    }

    /** ครูจบการสอบแทน (หลังจากนั้นเรียก AttemptRepository::grade()) */
    public function closeByTeacher(array $attempt, int $teacherId): void
    {
        $this->db->update('quiz_attempts', ['proctor_locked_at' => null], ['id' => (int) $attempt['id']]);
        $this->db->insert('quiz_attempt_events', ['attempt_id' => (int) $attempt['id'], 'type' => 'force_submit', 'user_id' => $teacherId]);
    }

    /**
     * ครั้งที่กำลังทำของแบบทดสอบนี้ (ไว้แสดงคนที่ถูกระงับ/กำลังสอบ)
     *
     * @return list<array<string,mixed>>
     */
    public function liveForQuiz(int $quizId): array
    {
        if (!$this->ready()) {
            return [];
        }

        return $this->db->all(
            'SELECT a.*, u.username, u.full_name
             FROM {quiz_attempts} a JOIN {users} u ON u.id = a.student_id
             WHERE a.quiz_id = ? AND a.status = \'in_progress\'
             ORDER BY a.proctor_locked_at IS NULL, a.proctor_locked_at, u.username',
            [$quizId]
        );
    }

    /**
     * เหตุการณ์ทั้งหมดของแบบทดสอบนี้ จัดกลุ่มตามนักเรียน
     *
     * @return array<int,array{switch:int,copy:int,other:int,unlock:int,events:list<array<string,mixed>>}>
     */
    public function eventsByStudent(int $quizId): array
    {
        if (!$this->ready()) {
            return [];
        }
        $rows = $this->db->all(
            'SELECT e.type, e.detail, e.created_at, a.student_id, a.attempt_no
             FROM {quiz_attempt_events} e JOIN {quiz_attempts} a ON a.id = e.attempt_id
             WHERE a.quiz_id = ?
             ORDER BY e.id',
            [$quizId]
        );
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['student_id'];
            $out[$sid] ??= ['switch' => 0, 'copy' => 0, 'other' => 0, 'unlock' => 0, 'events' => []];
            $key = match ($r['type']) {
                'switch', 'copy', 'unlock' => $r['type'],
                default => 'other',
            };
            $out[$sid][$key]++;
            $out[$sid]['events'][] = $r + ['label' => self::label((string) $r['type'], $r['detail'])];
        }

        return $out;
    }

    /**
     * ซ้อมสอบ (หน่วยสอบกลางภาค/ปลายภาค): นักเรียนลองทำข้อสอบตัวอย่างในโหมดคุมสอบได้ไม่จำกัดครั้ง
     * ลองละเมิดมาตรการได้ (ไม่บันทึกอะไร) แต่เปิดเฉพาะช่วงที่ครูเปิดไว้ PRACTICE_MINUTES นาที
     * กันการใช้หน้าซ้อมหาช่องโหว่ของระบบนอกเวลาเรียน
     *
     * @return int|null เวลาปิด (unix) ถ้ากำลังเปิดอยู่
     */
    public function practiceUntil(array $unit): ?int
    {
        $until = $unit['practice_until'] ?? null;
        if (!$until) {
            return null;
        }
        $ts = (int) strtotime((string) $until);

        return $ts > time() ? $ts : null;
    }

    public function openPractice(int $unitId): void
    {
        $this->db->update('units', ['practice_until' => date('Y-m-d H:i:s', time() + self::PRACTICE_MINUTES * 60)], ['id' => $unitId]);
    }

    public function closePractice(int $unitId): void
    {
        $this->db->update('units', ['practice_until' => null], ['id' => $unitId]);
    }

    /**
     * แบบทดสอบของครูที่มีนักเรียนถูกระงับรอครูอนุมัติ (แถบแจ้งเตือนบนทุกหน้า)
     *
     * @return list<array{course_id:int,quiz_id:int,title:string,n:int}>
     */
    public function pendingForTeacher(int $teacherId): array
    {
        if (!$this->ready()) {
            return [];
        }
        try {
            return array_map(static fn (array $r): array => [
                'course_id' => (int) $r['course_id'], 'quiz_id' => (int) $r['quiz_id'],
                'title' => (string) $r['title'], 'n' => (int) $r['n'],
            ], $this->db->all(
                'SELECT q.course_id, q.id AS quiz_id, q.title, COUNT(*) AS n
                 FROM {quiz_attempts} a
                 JOIN {quizzes} q ON q.id = a.quiz_id
                 JOIN {courses} c ON c.id = q.course_id
                 WHERE c.teacher_id = ? AND a.status = \'in_progress\' AND a.proctor_locked_at IS NOT NULL
                   AND q.deleted_at IS NULL
                 GROUP BY q.id, q.course_id, q.title
                 ORDER BY MIN(a.proctor_locked_at)',
                [$teacherId]
            ));
        } catch (PDOException) {
            return [];
        }
    }
}
