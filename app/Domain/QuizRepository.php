<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * แบบทดสอบ ข้อสอบ และตัวเลือก — รวมถึงการบันทึกร่างจากผู้ช่วย AI
 */
final class QuizRepository
{
    /** แบบทดสอบอยู่ในถังขยะได้กี่วันก่อนถูกลบถาวร */
    public const TRASH_DAYS = 30;

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> แบบทดสอบทุกฉบับของรายวิชา รวมฉบับร่างที่ยังไม่ได้เผยแพร่ */
    public function forCourse(int $courseId): array
    {
        return $this->db->all(
            'SELECT q.*, u.title AS unit_title, u.kind AS unit_kind, u.sort_order AS unit_order,
                    (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count,
                    (SELECT COUNT(DISTINCT a.student_id) FROM {quiz_attempts} a WHERE a.quiz_id = q.id AND a.status <> \'in_progress\') AS submitted_count
             FROM {quizzes} q
             LEFT JOIN {units} u ON u.id = q.unit_id
             WHERE q.course_id = ? AND q.deleted_at IS NULL
             ORDER BY u.id IS NULL, u.sort_order, u.id, q.kind = \'posttest\', q.created_at DESC',
            [$courseId]
        );
    }

    /** @return list<array<string,mixed>> แบบทดสอบของหน่วยนี้ ก่อนเรียนขึ้นก่อน */
    public function forUnit(int $unitId): array
    {
        return $this->db->all(
            'SELECT q.*,
                    (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count,
                    (SELECT COUNT(DISTINCT a.student_id) FROM {quiz_attempts} a WHERE a.quiz_id = q.id AND a.status <> \'in_progress\') AS submitted_count
             FROM {quizzes} q
             WHERE q.unit_id = ? AND q.deleted_at IS NULL
             ORDER BY q.kind = \'posttest\', q.created_at',
            [$unitId]
        );
    }

    /**
     * แบบทดสอบที่นักเรียนทำได้ — เผยแพร่แล้วและมีข้อสอบอย่างน้อยหนึ่งข้อ พร้อมสถานะการทำของนักเรียนคนนี้
     *
     * @return list<array<string,mixed>>
     */
    public function publishedForStudent(int $courseId, int $studentId): array
    {
        return $this->db->all(
            'SELECT q.id, q.title, q.kind, q.unit_id, q.time_limit_minutes,
                    u.title AS unit_title,
                    (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count,
                    (SELECT COALESCE(SUM(qq.score), 0) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS total_score,
                    (SELECT a.id FROM {quiz_attempts} a
                      WHERE a.quiz_id = q.id AND a.student_id = ? AND a.status = \'in_progress\'
                      ORDER BY a.id DESC LIMIT 1) AS open_attempt_id,
                    (SELECT COUNT(*) FROM {quiz_attempts} a
                      WHERE a.quiz_id = q.id AND a.student_id = ? AND a.status <> \'in_progress\') AS done_count,
                    (SELECT MAX(a.score) FROM {quiz_attempts} a
                      WHERE a.quiz_id = q.id AND a.student_id = ? AND a.status = \'graded\') AS best_score,
                    (SELECT a.max_score FROM {quiz_attempts} a
                      WHERE a.quiz_id = q.id AND a.student_id = ? AND a.status = \'graded\'
                      ORDER BY a.score DESC LIMIT 1) AS best_max_score
             FROM {quizzes} q
             LEFT JOIN {units} u ON u.id = q.unit_id
             WHERE q.course_id = ? AND q.review_status = \'published\' AND q.deleted_at IS NULL
             HAVING question_count > 0
             ORDER BY u.sort_order IS NULL, u.sort_order, q.kind = \'posttest\', q.created_at',
            [$studentId, $studentId, $studentId, $studentId, $courseId]
        );
    }

    /** @return array<string,mixed>|null แบบทดสอบที่ใช้งานอยู่ (ชุดในถังขยะถือว่าไม่พบ) */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {quizzes} WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** @return array<string,mixed>|null แบบทดสอบในถังขยะของรายวิชานี้ */
    public function findTrashed(int $id, int $courseId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {quizzes} WHERE id = ? AND course_id = ? AND deleted_at IS NOT NULL',
            [$id, $courseId]
        );
    }

    /** @return list<array<string,mixed>> ถังขยะของทุกรายวิชาที่ครูคนนี้สอน ลบล่าสุดขึ้นก่อน */
    public function trashedForTeacher(int $teacherId): array
    {
        return $this->db->all(
            'SELECT q.id, q.course_id, q.title, q.kind, q.deleted_at, u.title AS unit_title,
                    c.code AS course_code, c.name AS course_name,
                    DATE_ADD(q.deleted_at, INTERVAL ' . self::TRASH_DAYS . ' DAY) AS purge_at,
                    (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count,
                    (SELECT COUNT(DISTINCT a.student_id) FROM {quiz_attempts} a WHERE a.quiz_id = q.id AND a.status <> \'in_progress\') AS submitted_count
             FROM {quizzes} q
             JOIN {courses} c ON c.id = q.course_id
             LEFT JOIN {units} u ON u.id = q.unit_id
             WHERE c.teacher_id = ? AND q.deleted_at IS NOT NULL
             ORDER BY q.deleted_at DESC',
            [$teacherId]
        );
    }

    /** @return list<array<string,mixed>> ข้อสอบพร้อมตัวเลือกของแบบทดสอบ */
    public function questions(int $quizId): array
    {
        $questions = $this->db->all(
            'SELECT * FROM {quiz_questions} WHERE quiz_id = ? ORDER BY sort_order, id',
            [$quizId]
        );

        foreach ($questions as $i => $q) {
            $questions[$i]['choices'] = $this->db->all(
                'SELECT * FROM {quiz_choices} WHERE question_id = ? ORDER BY sort_order, id',
                [(int) $q['id']]
            );
        }

        return $questions;
    }

    /**
     * สร้างแบบทดสอบใหม่พร้อมข้อสอบทั้งหมดในครั้งเดียว
     *
     * @param array<string,mixed> $quiz ข้อมูลหลักของแบบทดสอบ (course_id, title, ...)
     * @param list<array{type:string,question:string,explanation:?string,score:float,source:string,choices:list<array{label:string,content:string,is_correct:bool,match_key?:?string}>}> $questions
     */
    public function createWithQuestions(array $quiz, array $questions): int
    {
        return (int) $this->db->transaction(function (Db $db) use ($quiz, $questions): int {
            $quizId = $db->insert('quizzes', $quiz);

            foreach ($questions as $order => $q) {
                $questionId = $db->insert('quiz_questions', [
                    'quiz_id' => $quizId,
                    'type' => $q['type'],
                    'question' => $q['question'],
                    'explanation' => $q['explanation'] ?? null,
                    'score' => $q['score'] ?? 1.0,
                    'sort_order' => $order,
                    'source' => $q['source'] ?? 'ai',
                ]);

                foreach ($q['choices'] as $ci => $choice) {
                    $db->insert('quiz_choices', [
                        'question_id' => $questionId,
                        'label' => $choice['label'] ?? '',
                        'content' => $choice['content'],
                        'match_key' => $choice['match_key'] ?? null,
                        'is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                        'sort_order' => $ci,
                    ]);
                }
            }

            return $quizId;
        });
    }

    public function setReviewStatus(int $quizId, string $status): void
    {
        $this->db->update('quizzes', ['review_status' => $status], ['id' => $quizId]);
    }

    /** @param array<string,mixed> $fields */
    public function updateQuiz(int $quizId, array $fields): void
    {
        $this->db->update('quizzes', $fields, ['id' => $quizId]);
    }

    /** @return array<string,mixed>|null */
    public function getQuestion(int $questionId): ?array
    {
        $q = $this->db->first('SELECT * FROM {quiz_questions} WHERE id = ?', [$questionId]);
        if ($q === null) {
            return null;
        }
        $q['choices'] = $this->db->all(
            'SELECT * FROM {quiz_choices} WHERE question_id = ? ORDER BY sort_order, id',
            [$questionId]
        );

        return $q;
    }

    /**
     * เพิ่มข้อสอบหนึ่งข้อพร้อมตัวเลือกเข้าแบบทดสอบที่มีอยู่
     *
     * @param array{type:string,question:string,explanation:?string,score:float,choices:list<array{label:string,content:string,is_correct:bool}>} $q
     */
    public function addQuestion(int $quizId, array $q, int $sortOrder, string $source = 'ai'): int
    {
        return (int) $this->db->transaction(function (Db $db) use ($quizId, $q, $sortOrder, $source): int {
            $questionId = $db->insert('quiz_questions', [
                'quiz_id' => $quizId,
                'type' => $q['type'],
                'question' => $q['question'],
                'explanation' => $q['explanation'] ?? null,
                'score' => $q['score'] ?? 1.0,
                'sort_order' => $sortOrder,
                'source' => $source,
            ]);

            foreach ($q['choices'] as $ci => $choice) {
                $db->insert('quiz_choices', [
                    'question_id' => $questionId,
                    'label' => $choice['label'] ?? '',
                    'content' => $choice['content'],
                    'is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                    'sort_order' => $ci,
                ]);
            }

            return $questionId;
        });
    }

    public function replaceQuestion(int $questionId, array $q): void
    {
        $this->db->transaction(function (Db $db) use ($questionId, $q): void {
            $db->update('quiz_questions', [
                'type' => $q['type'],
                'question' => $q['question'],
                'explanation' => $q['explanation'] ?? null,
            ], ['id' => $questionId]);

            $db->run('DELETE FROM {quiz_choices} WHERE question_id = ?', [$questionId]);
            foreach ($q['choices'] as $ci => $choice) {
                $db->insert('quiz_choices', [
                    'question_id' => $questionId,
                    'label' => $choice['label'] ?? '',
                    'content' => $choice['content'],
                    'is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                    'sort_order' => $ci,
                ]);
            }
        });
    }

    /** อัปเดตเฉพาะข้อความ (จากการแก้ในหน้าตรวจ) — ไม่แตะจำนวน/ลำดับตัวเลือก */
    public function saveEdits(int $quizId, array $questions): void
    {
        $this->db->transaction(function (Db $db) use ($quizId, $questions): void {
            foreach ($questions as $q) {
                $qid = (int) ($q['id'] ?? 0);
                if ($qid <= 0) {
                    continue;
                }
                $db->update('quiz_questions', [
                    'question' => (string) ($q['question'] ?? ''),
                    'explanation' => (string) ($q['explanation'] ?? '') ?: null,
                ], ['id' => $qid, 'quiz_id' => $quizId]);

                foreach ($q['choices'] ?? [] as $c) {
                    $cid = (int) ($c['id'] ?? 0);
                    if ($cid > 0) {
                        $db->update('quiz_choices', ['content' => (string) ($c['content'] ?? '')], ['id' => $cid, 'question_id' => $qid]);
                    }
                }
            }
        });
    }

    public function deleteQuestion(int $quizId, int $questionId): void
    {
        $this->db->run('DELETE FROM {quiz_questions} WHERE id = ? AND quiz_id = ?', [$questionId, $quizId]);
    }

    /**
     * ย้ายแบบทดสอบลงถังขยะ · ข้อสอบและผลสอบยังอยู่ครบ กู้คืนได้ภายใน TRASH_DAYS วัน
     * งาน AI ที่ยังรอตรวจของชุดนี้ถูกปิดเป็น rejected เพื่อไม่ให้ค้างในคิวตรวจ
     */
    public function trash(int $quizId): void
    {
        $this->db->transaction(function () use ($quizId): void {
            $this->db->run(
                "UPDATE {ai_generations} SET review_status = 'rejected'
                 WHERE target_type = 'quiz' AND target_id = ? AND review_status = 'pending'",
                [$quizId]
            );
            $this->db->run('UPDATE {quizzes} SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL', [$quizId]);
        });
    }

    public function restore(int $quizId): void
    {
        $this->db->run('UPDATE {quizzes} SET deleted_at = NULL WHERE id = ?', [$quizId]);
    }

    /** ลบถาวรเฉพาะชุดที่อยู่ในถังขยะ · ข้อสอบ ตัวเลือก และผลสอบของนักเรียนถูกลบตาม (FK ON DELETE CASCADE) */
    public function destroy(int $quizId): void
    {
        $this->db->run('DELETE FROM {quizzes} WHERE id = ? AND deleted_at IS NOT NULL', [$quizId]);
    }

    /** ลบถาวรทุกชุดที่อยู่ในถังขยะครบ TRASH_DAYS วันแล้ว (ไม่มี cron · เรียกตอนเปิดแท็บแบบทดสอบ) */
    public function purgeExpired(): int
    {
        return $this->db->run(
            'DELETE FROM {quizzes} WHERE deleted_at IS NOT NULL AND deleted_at < NOW() - INTERVAL ' . self::TRASH_DAYS . ' DAY'
        );
    }

    public function deleteAllQuestions(int $quizId): void
    {
        $this->db->run('DELETE FROM {quiz_questions} WHERE quiz_id = ?', [$quizId]);
    }

    public function questionCount(int $quizId): int
    {
        return $this->db->int('SELECT COUNT(*) FROM {quiz_questions} WHERE quiz_id = ?', [$quizId]);
    }
}
