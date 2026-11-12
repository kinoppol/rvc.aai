<?php

declare(strict_types=1);

namespace App\AI;

/**
 * สร้างร่างข้อสอบจากบทเรียนที่ครูเลือก โดยส่ง prompt ให้ผู้ให้บริการ AI
 * แล้วแปลงผลลัพธ์ NDJSON เป็นโครงสร้างข้อสอบทีละข้อ
 *
 * โมเดลไม่ได้ทำตามคำสั่งเสมอ จึงตรวจซ้ำฝั่งระบบ:
 * - ข้อที่ชนิดไม่ตรงกับที่ครูเลือก (เช่น ขอปรนัยอย่างเดียวแต่ได้อัตนัย) ถูกแปลงหรือตัดทิ้ง แล้วขอเพิ่มให้ครบ
 * - ข้อปรนัยต้องมีตัวเลือก ≥ 2 และมีคำตอบถูกข้อเดียว
 * - ตำแหน่งคำตอบที่ถูกต้องห้ามซ้ำกันติดต่อกันเกิน MAX_RUN ข้อ (สลับตัวเลือกให้เอง)
 */
final class QuizGenerator
{
    /** ตำแหน่งคำตอบที่ถูกซ้ำกันติดต่อกันได้ไม่เกินกี่ข้อ */
    public const MAX_RUN = 2;

    private const TYPE_MAP = [
        'ปรนัย' => 'choice',
        'อัตนัย' => 'short_answer',
        'จับคู่' => 'matching',
    ];

    private const LABELS = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ'];

    /** ตัวเลือกที่อ้างถึงตัวเลือกอื่น/ตำแหน่ง — ย้ายที่ไม่ได้ */
    private const POSITIONAL = '/ทุกข้อ|(ถูก|ผิด)ทั้งหมด|ทั้งหมดที่กล่าว|ไม่มีข้อใด|ข้างต้น'
        . '|ข้อ\s*[กขคงจ](?!\p{Thai})|(?<!\p{Thai})[กขคงจ]\.?\s*(และ|หรือ|,)\s*[กขคงจ](?!\p{Thai})|ทั้ง\s*[กขคงจ](?!\p{Thai})/u';

    /**
     * @param array{course_code:string,course_name:string,lesson_titles:list<string>,count:int,types:list<string>,level:string,avoid_correct?:list<int>} $params
     * @return iterable<array{event:string,data?:array,total?:int,message?:string}>
     */
    public function stream(AiRoute $route, array $params, string $quality = 'fast'): iterable
    {
        $types = array_values(array_unique(array_map(
            static fn (string $t): string => self::TYPE_MAP[$t] ?? $t,
            $params['types'] ?: ['ปรนัย']
        )));
        $target = max(1, (int) $params['count']);

        $system = 'คุณเป็นผู้ช่วยครูอาชีวศึกษาที่เชี่ยวชาญการวัดและประเมินผล ออกข้อสอบภาษาไทยที่ถูกต้องตามหลักวิชาชีพ '
            . 'และตามหลักการเขียนข้อสอบที่ดี ทำตามชนิดข้อสอบและจำนวนข้อที่กำหนดอย่างเคร่งครัด '
            . (($params['source'] ?? '') !== '' ? 'โดยใช้เฉพาะเนื้อหาที่ครูสอนซึ่งแนบมาให้ ห้ามออกเกินเนื้อหานั้น ' : '')
            . ($types === ['choice'] ? 'ข้อสอบทุกข้อต้องเป็นปรนัย 4 ตัวเลือกเท่านั้น ห้ามออกอัตนัย ' : '')
            . 'ตอบกลับเป็น NDJSON หนึ่งข้อต่อหนึ่งบรรทัด';

        $history = array_values(array_map('intval', $params['avoid_correct'] ?? []));
        $used = [];
        foreach ($history as $h) {
            $used[$h] = ($used[$h] ?? 0) + 1;
        }
        $existing = [];
        $count = 0;

        // รอบแรกขอทั้งหมด ถ้าโมเดลส่งข้อที่ใช้ไม่ได้มา ขอเพิ่มอีกได้หนึ่งรอบ
        for ($round = 0; $round < 2 && $count < $target; $round++) {
            $spec = json_encode([
                'task' => 'quiz',
                'course_code' => $params['course_code'],
                'course_name' => $params['course_name'],
                'lesson_titles' => $params['lesson_titles'],
                'count' => $target - $count,
                'types' => $types,
                'level' => $params['level'],
                // เนื้อหาที่สอนจริงของหน่วย (UnitKnowledge) — ออกข้อสอบจากตรงนี้เท่านั้น
                'source' => (string) ($params['source'] ?? ''),
                'avoid' => (string) ($params['avoid'] ?? ''),
                'existing' => $existing,
            ], JSON_UNESCAPED_UNICODE);

            $rejected = 0;
            foreach (JsonStream::objects($route->provider->stream($system, $spec, $quality)) as $obj) {
                if (isset($obj['error'])) {
                    if ($count > 0) {
                        break 2;
                    }
                    yield ['event' => 'error', 'message' => (string) $obj['error']];

                    return;
                }
                if (!empty($obj['done'])) {
                    break;
                }
                if (!isset($obj['question'])) {
                    continue;
                }

                $q = $this->normalise($obj, $count + 1, $types);
                if ($q === null || in_array($q['question'], $existing, true)) {
                    $rejected++;
                    continue;
                }

                if ($q['type'] === 'choice') {
                    $q = self::spreadCorrect($q, $history, $used);
                    $idx = self::correctIndex($q['choices']);
                    $history[] = $idx;
                    $used[$idx] = ($used[$idx] ?? 0) + 1;
                }

                $count++;
                $q['no'] = $count;
                $existing[] = $q['question'];
                yield ['event' => 'question', 'data' => $q];

                if ($count >= $target) {
                    break;
                }
            }

            if ($rejected === 0) {
                break; // โมเดลหยุดเองโดยไม่ได้ส่งข้อเสีย — ไม่ขอซ้ำ
            }
        }

        yield ['event' => 'done', 'total' => $count];
    }

    /**
     * ตำแหน่ง (0-based) ของตัวเลือกที่ถูก, -1 ถ้าไม่มี
     *
     * @param list<array{is_correct?:mixed}> $choices
     */
    public static function correctIndex(array $choices): int
    {
        foreach (array_values($choices) as $i => $c) {
            if (!empty($c['is_correct'])) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * ย้ายคำตอบที่ถูกไปตำแหน่งอื่น ถ้าตำแหน่งเดิมจะทำให้ซ้ำกันติดต่อกันเกิน MAX_RUN ข้อ
     * หรือตำแหน่งเดิมถูกใช้มากเกินไปจนคำตอบเอียงไปข้อเดียว
     * (นับข้อก่อนหน้าใน $before และข้อถัดไปใน $after — ใช้ตอนร่างข้อเดียวใหม่กลางชุด)
     * เลือกตำแหน่งที่ถูกใช้น้อยที่สุด เพื่อให้คำตอบกระจายทั่ว ก ข ค ง
     *
     * @param array{choices:list<array{label:string,content:string,is_correct:bool}>} $q
     * @param list<int> $before ตำแหน่งคำตอบถูกของข้อก่อนหน้า เรียงจากข้อแรก
     * @param array<int,int> $used จำนวนครั้งที่แต่ละตำแหน่งเป็นคำตอบถูก
     * @param list<int> $after ตำแหน่งคำตอบถูกของข้อถัดไป เรียงจากข้อที่ติดกัน
     */
    public static function spreadCorrect(array $q, array $before, array $used = [], array $after = []): array
    {
        $choices = array_values($q['choices']);
        $n = count($choices);
        $c = self::correctIndex($choices);
        if ($c < 0 || $n < 2 || preg_match(self::POSITIONAL, $choices[$c]['content'])) {
            return $q; // คำตอบถูกคือ "ถูกทุกข้อ" ฯลฯ — ย้ายแล้วความหมายเปลี่ยน
        }

        $candidates = [];
        foreach ($choices as $i => $choice) {
            if ($i !== $c && !preg_match(self::POSITIONAL, $choice['content']) && !self::breaksRun($i, $before, $after)) {
                $candidates[] = $i;
            }
        }
        if ($candidates === []) {
            return $q;
        }

        // ย้ายเมื่อซ้ำติดกันเกินกำหนด หรือตำแหน่งนี้ถูกใช้มากกว่าตำแหน่งที่ใช้น้อยสุดตั้งแต่ 2 ครั้งขึ้นไป
        $minUsed = min(array_map(static fn (int $i): int => $used[$i] ?? 0, $candidates));
        if (!self::breaksRun($c, $before, $after) && ($used[$c] ?? 0) - $minUsed < 2) {
            return $q;
        }

        $least = min(array_map(static fn (int $i): int => $used[$i] ?? 0, $candidates));
        $pool = array_values(array_filter($candidates, static fn (int $i): bool => ($used[$i] ?? 0) === $least));
        $to = $pool[random_int(0, count($pool) - 1)];

        [$choices[$c], $choices[$to]] = [$choices[$to], $choices[$c]];
        foreach ($choices as $i => &$choice) {
            $choice['label'] = self::LABELS[$i] ?? $choice['label'];
        }
        unset($choice);
        $q['choices'] = $choices;

        return $q;
    }

    /** ใส่คำตอบที่ตำแหน่ง $idx แล้วจะมีตำแหน่งเดียวกันติดกันเกิน MAX_RUN ข้อหรือไม่ */
    private static function breaksRun(int $idx, array $before, array $after): bool
    {
        $run = 1;
        for ($i = count($before) - 1; $i >= 0 && $before[$i] === $idx; $i--) {
            $run++;
        }
        foreach ($after as $a) {
            if ($a !== $idx) {
                break;
            }
            $run++;
        }

        return $run > self::MAX_RUN;
    }

    /**
     * @param list<string> $types ชนิดที่ครูเลือก
     * @return array<string,mixed>|null null = ข้อนี้ใช้ไม่ได้
     */
    private function normalise(array $obj, int $fallbackNo, array $types): ?array
    {
        $choices = [];
        foreach ($obj['choices'] ?? [] as $c) {
            if (is_string($c)) {
                $c = ['content' => $c];
            }
            if (!is_array($c)) {
                continue;
            }
            $content = trim((string) ($c['content'] ?? $c['text'] ?? ''));
            if ($content === '') {
                continue;
            }
            $choices[] = [
                'label' => self::LABELS[count($choices)] ?? (string) ($c['label'] ?? ''),
                'content' => $content,
                'is_correct' => !empty($c['is_correct']),
                'match_key' => isset($c['match_key']) ? (string) $c['match_key'] : null,
            ];
        }

        $question = trim((string) ($obj['question'] ?? ''));
        if ($question === '') {
            return null;
        }

        $type = (string) ($obj['type'] ?? 'choice');
        if (!in_array($type, ['choice', 'short_answer', 'matching'], true)) {
            $type = $choices !== [] ? 'choice' : 'short_answer';
        }
        // โมเดลบอกชนิดผิดแต่มีตัวเลือกครบ — ถือเป็นปรนัยเมื่อครูขอปรนัย
        if (!in_array($type, $types, true) && in_array('choice', $types, true) && count($choices) >= 2) {
            $type = 'choice';
        }
        if (!in_array($type, $types, true)) {
            return null; // ครูไม่ได้ขอชนิดนี้ เช่น ขอปรนัยแต่ได้อัตนัย
        }

        if ($type === 'choice') {
            $correct = count(array_filter($choices, static fn (array $c): bool => $c['is_correct']));
            if (count($choices) < 2 || $correct !== 1) {
                return null;
            }
            foreach ($choices as &$c) {
                unset($c['match_key']);
            }
            unset($c);
        } elseif ($type === 'short_answer') {
            $choices = [];
        }

        return [
            'no' => (int) ($obj['no'] ?? $fallbackNo),
            'type' => $type,
            'question' => $question,
            'explanation' => trim((string) ($obj['explanation'] ?? '')),
            'score' => (float) ($obj['score'] ?? ($type === 'short_answer' ? 2.0 : 1.0)),
            'choices' => $choices,
        ];
    }
}
