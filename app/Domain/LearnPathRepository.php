<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * เส้นทางการเรียนของนักเรียน: รายการในแต่ละหน่วยเรียงตามลำดับเรียน
 * แบบทดสอบก่อนเรียน → ส่วนเนื้อหา → ใบงาน → แบบทดสอบหลังเรียน พร้อมสถานะว่าทำครบแล้วหรือยัง
 *
 * ความคืบหน้านับเฉพาะส่วนเนื้อหา (เปิดอ่านแล้ว = section_progress) และแบบทดสอบ (ส่งแล้วอย่างน้อยหนึ่งครั้ง)
 * ใบงานแสดงในรายการแต่ไม่นับ เพราะนักเรียนยังส่งใบงานผ่านระบบไม่ได้
 */
final class LearnPathRepository
{
    /** ความเร็วอ่านภาษาไทยโดยประมาณ (ตัวอักษร/นาที) ใช้ประเมินเวลาอ่านของส่วนเนื้อหา */
    private const CHARS_PER_MINUTE = 600;

    /** ป้ายและไอคอนของแต่ละประเภท: [ป้าย, ไอคอน Material Symbols, โทนสี] */
    public const TYPES = [
        'pretest'   => ['แบบทดสอบก่อนเรียน', 'flag', 'pre'],
        'text'      => ['บทความ', 'article', 'text'],
        'video'     => ['วิดีโอ', 'smart_display', 'video'],
        'pdf'       => ['เอกสาร', 'description', 'pdf'],
        'worksheet' => ['ใบงาน', 'assignment', 'work'],
        'posttest'  => ['แบบทดสอบหลังเรียน', 'task_alt', 'post'],
        'exam'      => ['ข้อสอบ', 'edit_note', 'exam'],
    ];

    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @param list<array<string,mixed>> $units   หน่วยที่นักเรียนเห็น (เรียงแล้ว)
     * @param list<array<string,mixed>> $quizzes แบบทดสอบที่นักเรียนเห็น (decorate แล้ว)
     * @return array{units: array<int, array{items: list<array<string,mixed>>, done: int, total: int, minutes: int}>, loose: list<array<string,mixed>>}
     */
    public function build(int $courseId, int $studentId, array $units, array $quizzes): array
    {
        $unitIds = array_map(static fn (array $u): int => (int) $u['id'], $units);
        $exams = [];
        foreach ($units as $u) {
            $exams[(int) $u['id']] = UnitRepository::isExam($u);
        }

        $sections = $this->db->all(
            'SELECT s.id, s.unit_id, s.type, s.title, CHAR_LENGTH(COALESCE(s.content, \'\')) AS chars,
                    (sp.id IS NOT NULL) AS done
             FROM {unit_sections} s
             JOIN {units} u ON u.id = s.unit_id
             LEFT JOIN {section_progress} sp ON sp.section_id = s.id AND sp.student_id = ?
             WHERE u.course_id = ?
             ORDER BY s.sort_order, s.id',
            [$studentId, $courseId]
        );
        $worksheets = $this->db->all(
            'SELECT a.id, a.unit_id, a.title, a.max_score, a.due_at,
                    EXISTS(SELECT 1 FROM {submissions} sb WHERE sb.assignment_id = a.id AND sb.student_id = ?) AS done
             FROM {assignments} a
             WHERE a.course_id = ? AND a.kind = \'worksheet\' AND a.unit_id IS NOT NULL
             ORDER BY a.created_at, a.id',
            [$studentId, $courseId]
        );

        $buckets = array_fill_keys($unitIds, ['pre' => [], 'section' => [], 'work' => [], 'post' => []]);
        $loose = [];

        foreach ($quizzes as $q) {
            $uid = $q['unit_id'] !== null ? (int) $q['unit_id'] : 0;
            $type = ($exams[$uid] ?? false) ? 'exam' : ($q['kind'] === 'pretest' ? 'pretest' : 'posttest');
            $item = $this->quizItem($q, $type);
            if (isset($buckets[$uid])) {
                $buckets[$uid][$q['kind'] === 'pretest' ? 'pre' : 'post'][] = $item;
            } else {
                $loose[] = $item;
            }
        }
        foreach ($sections as $s) {
            $uid = (int) $s['unit_id'];
            if (!isset($buckets[$uid]) || $exams[$uid]) {
                continue;
            }
            $minutes = $s['type'] === 'text' ? max(1, (int) ceil((int) $s['chars'] / self::CHARS_PER_MINUTE)) : 0;
            $buckets[$uid]['section'][] = $this->item('section', (int) $s['id'], $s['type'], (string) $s['title'], (bool) $s['done'], true)
                + ['minutes' => $minutes, 'score' => null, 'meta' => $minutes ? $minutes . ' นาที' : ''];
        }
        foreach ($worksheets as $a) {
            $uid = (int) $a['unit_id'];
            if (!isset($buckets[$uid]) || $exams[$uid]) {
                continue;
            }
            $buckets[$uid]['work'][] = $this->item('worksheet', (int) $a['id'], 'worksheet', (string) $a['title'], (bool) $a['done'], false)
                + [
                    'minutes' => 0,
                    'score' => (float) $a['max_score'] > 0 ? self::num($a['max_score']) . ' คะแนน' : null,
                    'meta' => $a['due_at'] ? 'ส่ง ' . \App\Support\Thai::date((string) $a['due_at']) : 'ส่งกับครูผู้สอน',
                ];
        }

        $result = [];
        foreach ($buckets as $uid => $b) {
            $items = array_merge($b['pre'], $b['section'], $b['work'], $b['post']);
            $result[$uid] = ['items' => $items] + self::summary($items);
        }

        return ['units' => $result, 'loose' => $loose];
    }

    /** @param list<array<string,mixed>> $items @return array{done:int,total:int,minutes:int} */
    public static function summary(array $items): array
    {
        $counted = array_filter($items, static fn (array $i): bool => $i['counts']);

        return [
            'done' => count(array_filter($counted, static fn (array $i): bool => $i['done'])),
            'total' => count($counted),
            'minutes' => array_sum(array_column($items, 'minutes')),
        ];
    }

    /** ส่วนเนื้อหาของนักเรียนคนนี้เปิดอ่านแล้ว (บันทึกครั้งแรกครั้งเดียว) */
    public function markSection(int $sectionId, int $studentId): void
    {
        $this->db->run(
            'INSERT IGNORE INTO {section_progress} (section_id, student_id) VALUES (?, ?)',
            [$sectionId, $studentId]
        );
    }

    /** @param array<string,mixed> $q */
    private function quizItem(array $q, string $type): array
    {
        $minutes = $q['time_limit_minutes'] ? (int) $q['time_limit_minutes'] : max(1, (int) $q['question_count']);

        return $this->item('quiz', (int) $q['id'], $type, (string) $q['title'], (int) $q['done_count'] > 0, true) + [
            'minutes' => $minutes,
            'score' => (float) $q['total_score'] > 0 ? self::num($q['total_score']) . ' คะแนน' : null,
            'meta' => (int) $q['question_count'] . ' ข้อ · ' . $minutes . ' นาที',
            'quiz' => $q,
        ];
    }

    private function item(string $kind, int $id, string $type, string $title, bool $done, bool $counts): array
    {
        [$label, $icon, $tone] = self::TYPES[$type] ?? self::TYPES['text'];

        return [
            'kind' => $kind,
            'id' => $id,
            'type' => $type,
            'label' => $label,
            'icon' => $icon,
            'tone' => $tone,
            'title' => $title !== '' ? $title : $label,
            'done' => $done,
            'counts' => $counts,
        ];
    }

    private static function num(mixed $v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }
}
