<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * การทำแบบทดสอบของนักเรียน: การเริ่มทำ บันทึกคำตอบระหว่างทาง การส่ง และการตรวจอัตโนมัติ
 */
final class AttemptRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function find(int $attemptId): ?array
    {
        return $this->db->first('SELECT * FROM {quiz_attempts} WHERE id = ?', [$attemptId]);
    }

    /** ครั้งที่นักเรียนกำลังทำอยู่ หรือ null */
    public function inProgress(int $quizId, int $studentId): ?array
    {
        return $this->db->first(
            'SELECT * FROM {quiz_attempts} WHERE quiz_id = ? AND student_id = ? AND status = \'in_progress\' ORDER BY id DESC LIMIT 1',
            [$quizId, $studentId]
        );
    }

    /** @return list<array<string,mixed>> ประวัติการทำของนักเรียนในแบบทดสอบนี้ */
    public function history(int $quizId, int $studentId): array
    {
        return $this->db->all(
            'SELECT * FROM {quiz_attempts} WHERE quiz_id = ? AND student_id = ? ORDER BY attempt_no',
            [$quizId, $studentId]
        );
    }

    public function start(int $quizId, int $studentId): int
    {
        $next = $this->db->int(
            'SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM {quiz_attempts} WHERE quiz_id = ? AND student_id = ?',
            [$quizId, $studentId]
        );

        return $this->db->insert('quiz_attempts', [
            'quiz_id' => $quizId,
            'student_id' => $studentId,
            'attempt_no' => $next,
            'status' => 'in_progress',
        ]);
    }

    /** @return array<int,array<string,mixed>> คำตอบปัจจุบัน key ตาม question_id */
    public function answers(int $attemptId): array
    {
        $rows = $this->db->all('SELECT * FROM {quiz_answers} WHERE attempt_id = ?', [$attemptId]);

        return array_column($rows, null, 'question_id');
    }

    public function saveAnswer(int $attemptId, int $questionId, ?int $choiceId, ?string $text): void
    {
        $existing = $this->db->int(
            'SELECT id FROM {quiz_answers} WHERE attempt_id = ? AND question_id = ?',
            [$attemptId, $questionId]
        );

        if ($existing > 0) {
            $this->db->update('quiz_answers', ['choice_id' => $choiceId, 'answer_text' => $text], ['id' => $existing]);

            return;
        }

        $this->db->insert('quiz_answers', [
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'choice_id' => $choiceId,
            'answer_text' => $text,
        ]);
    }

    /**
     * ตรวจอัตโนมัติเฉพาะข้อปรนัย แล้วปิดการทำ
     *
     * @return array{score:float,max:float,graded:bool}
     */
    public function grade(int $attemptId, int $quizId): array
    {
        $questions = $this->db->all('SELECT id, type, score FROM {quiz_questions} WHERE quiz_id = ?', [$quizId]);
        $answers = $this->answers($attemptId);

        $score = 0.0;
        $max = 0.0;
        $needsManual = false;

        foreach ($questions as $q) {
            $qid = (int) $q['id'];
            $max += (float) $q['score'];

            if ($q['type'] !== 'choice' && $q['type'] !== 'multi_choice') {
                $needsManual = true;
                continue;
            }

            $answer = $answers[$qid] ?? null;
            $isCorrect = false;
            if ($answer && $answer['choice_id'] !== null) {
                $isCorrect = $this->db->int(
                    'SELECT is_correct FROM {quiz_choices} WHERE id = ?',
                    [(int) $answer['choice_id']]
                ) === 1;
            }

            $earned = $isCorrect ? (float) $q['score'] : 0.0;
            $score += $earned;

            if ($answer) {
                $this->db->update('quiz_answers', [
                    'is_correct' => $isCorrect ? 1 : 0,
                    'score' => $earned,
                ], ['id' => (int) $answer['id']]);
            }
        }

        $status = $needsManual ? 'submitted' : 'graded';
        $this->db->update('quiz_attempts', [
            'status' => $status,
            'score' => $score,
            'max_score' => $max,
            'submitted_at' => date('Y-m-d H:i:s'),
        ], ['id' => $attemptId]);

        return ['score' => $score, 'max' => $max, 'graded' => !$needsManual];
    }

    /**
     * ครูเปลี่ยนเฉลย/คะแนนหลังมีนักเรียนทำแล้ว: คิดคะแนนข้อปรนัยของทุกครั้งที่ส่งแล้วใหม่
     * ข้ออัตนัยใช้คะแนนที่ครูให้ไว้เดิม · ไม่แตะสถานะและเวลาส่ง
     *
     * @return int จำนวนครั้งที่คิดใหม่
     */
    public function regradeQuiz(int $quizId): int
    {
        $questions = $this->db->all('SELECT id, type, score FROM {quiz_questions} WHERE quiz_id = ?', [$quizId]);
        $attempts = $this->db->all(
            'SELECT id FROM {quiz_attempts} WHERE quiz_id = ? AND status IN (\'submitted\',\'graded\')',
            [$quizId]
        );

        foreach ($attempts as $a) {
            $answers = $this->answers((int) $a['id']);
            $score = 0.0;
            $max = 0.0;

            foreach ($questions as $q) {
                $max += (float) $q['score'];
                $answer = $answers[(int) $q['id']] ?? null;

                if ($q['type'] !== 'choice' && $q['type'] !== 'multi_choice') {
                    // อัตนัย: คะแนนที่ครูให้ไว้ ไม่เกินคะแนนเต็มใหม่ของข้อ
                    $score += min((float) ($answer['score'] ?? 0), (float) $q['score']);
                    continue;
                }

                $isCorrect = $answer !== null && $answer['choice_id'] !== null && $this->db->int(
                    'SELECT is_correct FROM {quiz_choices} WHERE id = ?',
                    [(int) $answer['choice_id']]
                ) === 1;
                $earned = $isCorrect ? (float) $q['score'] : 0.0;
                $score += $earned;

                if ($answer !== null) {
                    $this->db->update('quiz_answers', ['is_correct' => $isCorrect ? 1 : 0, 'score' => $earned], ['id' => (int) $answer['id']]);
                }
            }

            $this->db->update('quiz_attempts', ['score' => $score, 'max_score' => $max], ['id' => (int) $a['id']]);
        }

        return count($attempts);
    }

    public function bestScore(int $quizId, int $studentId): ?float
    {
        $value = $this->db->value(
            'SELECT MAX(score) FROM {quiz_attempts} WHERE quiz_id = ? AND student_id = ? AND status IN (\'graded\',\'submitted\')',
            [$quizId, $studentId]
        );

        return $value === null ? null : (float) $value;
    }
}
