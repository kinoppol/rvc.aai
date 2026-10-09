<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ผู้ช่วย AI จำลอง — ใช้แทนเครื่องจริงระหว่างที่ยังไม่มีเครื่อง GPU ของส่วนกลาง
 *
 * รับ prompt เป็น JSON ที่ QuizGenerator/LessonPlanGenerator สร้างขึ้น แล้วสตรีมผลลัพธ์
 * เป็น NDJSON ทีละบรรทัดพร้อมหน่วงเวลาเล็กน้อยให้เหมือนการพิมพ์คำตอบจริง
 */
final class SimulatedProvider implements AiProvider
{
    public function __construct(
        private readonly string $label = 'AI ของส่วนกลาง',
        private readonly bool $available = true,
        private readonly int $chunkDelayMs = 550,
    ) {
    }

    public function label(): string
    {
        return $this->label;
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function stream(string $systemPrompt, string $userPrompt, string $quality = 'fast'): iterable
    {
        $spec = json_decode($userPrompt, true);
        if (!is_array($spec)) {
            yield json_encode(['error' => 'คำสั่งไม่ถูกต้อง'], JSON_UNESCAPED_UNICODE) . "\n";

            return;
        }

        // แชตทดสอบการเชื่อมต่อ — สตรีมข้อความล้วนทีละคำ ไม่ใช่ NDJSON
        if (($spec['task'] ?? '') === 'chat') {
            yield from $this->chatChunks((string) ($spec['message'] ?? ''));

            return;
        }

        $lines = match ($spec['task'] ?? '') {
            'quiz' => $this->quizLines($spec, $quality),
            'lesson_plan' => $this->lessonPlanLines($spec, $quality),
            'simplify' => $this->simplifyLines($spec),
            default => [json_encode(['error' => 'ไม่รองรับงานนี้'], JSON_UNESCAPED_UNICODE)],
        };

        foreach ($lines as $line) {
            if ($this->chunkDelayMs > 0 && PHP_SAPI !== 'cli') {
                usleep($this->chunkDelayMs * 1000);
            }
            yield $line . "\n";
        }
    }

    /** @return iterable<string> */
    private function chatChunks(string $message): iterable
    {
        $reply = $this->composeChatReply($message);

        // แบ่งเป็นคำ/วรรค แล้วสตรีมทีละชิ้นให้เหมือนการพิมพ์
        $parts = preg_split('/(\s+)/u', $reply, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$reply];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (PHP_SAPI !== 'cli') {
                usleep(random_int(24, 70) * 1000);
            }
            yield $part;
        }
    }

    private function composeChatReply(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'สวัสดีค่ะ ลองพิมพ์คำถามหรือหัวข้อที่อยากให้ช่วย เช่น "ช่วยคิดหัวข้อสอบเรื่องเซนเซอร์" หรือ "อธิบายกฎของโอห์มแบบสั้น ๆ" ได้เลยค่ะ';
        }

        $lower = mb_strtolower($message);
        $isGreeting = (bool) preg_match('/^(สวัสดี|หวัดดี|hello|hi|ทดสอบ|test|เช็ค|check)/u', $lower);

        if ($isGreeting) {
            return 'สวัสดีค่ะ ผู้ช่วย AI เชื่อมต่อได้และพร้อมใช้งานแล้ว 🎉 ลองสั่งงานได้เลย เช่น ให้ช่วยร่างข้อสอบ สรุปใบความรู้ หรือทำแผนการสอน '
                . 'ข้อความนี้สตรีมมาจากเส้นทาง AI ที่ระบบเลือกให้อัตโนมัติ ถ้าเห็นข้อความทยอยขึ้นทีละคำแสดงว่าการเชื่อมต่อทำงานปกติค่ะ';
        }

        // ตอบแบบยืนยันว่าเข้าใจคำถาม + คำแนะนำสั้น ๆ (ตัวจำลอง ไม่ได้ต่อโมเดลจริง)
        $summary = mb_substr($message, 0, 120);

        return "รับทราบคำขอ: \"{$summary}\"\n\n"
            . "นี่เป็นการตอบกลับจากตัวจำลองผู้ช่วย AI เพื่อยืนยันว่าการเชื่อมต่อและการสตรีมข้อความทำงานได้ปกติ "
            . "เมื่อเชื่อมต่อกับเครื่องของส่วนกลางหรือคีย์ของครูจริง ระบบจะส่งคำถามนี้ไปประมวลผลและตอบกลับเนื้อหาที่ใช้งานได้จริง\n\n"
            . "ระหว่างนี้แนะนำให้ใช้เมนู \"ให้ AI ช่วย\" ในหน้ารายวิชา เพื่อร่างข้อสอบหรือแผนการสอนจากบทเรียนของคุณได้เลยค่ะ";
    }

    /** @return list<string> */
    private function quizLines(array $spec, string $quality): array
    {
        $count = max(1, min(30, (int) ($spec['count'] ?? 5)));
        $types = $spec['types'] ?? ['choice'];
        $level = (string) ($spec['level'] ?? 'กลาง');

        $pool = QuestionBank::pick((string) ($spec['course_code'] ?? ''), $spec['lesson_titles'] ?? []);
        $pool = array_values(array_filter(
            $pool,
            static fn (array $q): bool => in_array($q['type'], $types, true)
        )) ?: $pool;

        $keys = ['ก', 'ข', 'ค', 'ง', 'จ'];
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            $src = $pool[$i % count($pool)];
            $question = [
                'no' => $i + 1,
                'type' => $src['type'],
                'question' => $this->levelFlavour($src['question'], $level, $i),
                'explanation' => $src['explanation'],
                'score' => $src['type'] === 'short_answer' ? 2.0 : 1.0,
                'choices' => [],
            ];

            if ($src['type'] === 'choice' && $src['choices'] !== []) {
                foreach ($src['choices'] as $ci => $text) {
                    $question['choices'][] = [
                        'label' => $keys[$ci] ?? chr(65 + $ci),
                        'content' => $text,
                        'is_correct' => $ci === $src['answer'],
                    ];
                }
            }

            $lines[] = json_encode($question, JSON_UNESCAPED_UNICODE);
        }

        $lines[] = json_encode(['done' => true, 'total' => $count], JSON_UNESCAPED_UNICODE);

        return $lines;
    }

    private function levelFlavour(string $question, string $level, int $index): string
    {
        // ไม่แต่งคำถามซ้ำ ๆ ทุกข้อ — สลับเฉพาะบางข้อให้เห็นความต่างของระดับ
        if ($level === 'ยาก' && $index % 3 === 1) {
            return $question . ' (ให้เหตุผลประกอบคำตอบด้วย)';
        }
        if ($level === 'ง่าย' && $index % 3 === 2) {
            return 'พิจารณาข้อความต่อไปนี้: ' . $question;
        }

        return $question;
    }

    /** @return list<string> */
    private function lessonPlanLines(array $spec, string $quality): array
    {
        $unit = (string) ($spec['unit'] ?? 'หน่วยการเรียนรู้');
        $competency = (string) ($spec['competency'] ?? '');
        $hours = (string) ($spec['hours'] ?? '6');
        $style = (string) ($spec['style'] ?? 'ปฏิบัติในโรงฝึกงาน');

        $sections = [
            ['head' => '1. สมรรถนะประจำหน่วย', 'body' => $competency !== '' ? $competency
                : 'ปฏิบัติงานตามหน่วยการเรียนรู้นี้ได้ถูกต้องตามหลักวิชาการและความปลอดภัย'],
            ['head' => '2. จุดประสงค์เชิงพฤติกรรม', 'body' => "2.1 อธิบายหลักการและองค์ประกอบสำคัญของ{$unit}ได้\n2.2 ปฏิบัติงานตามใบงานได้ถูกต้องตามขั้นตอน\n2.3 ตรวจสอบและแก้ไขข้อผิดพลาดเบื้องต้นได้\n2.4 มีวินัย ความรับผิดชอบ และคำนึงถึงความปลอดภัยในการทำงาน"],
            ['head' => '3. สาระการเรียนรู้', 'body' => "3.1 ความรู้พื้นฐานและศัพท์เทคนิคที่เกี่ยวข้องกับ{$unit}\n3.2 เครื่องมือ อุปกรณ์ และการเลือกใช้งาน\n3.3 ขั้นตอนการปฏิบัติงานและข้อควรระวัง\n3.4 การตรวจสอบคุณภาพงานและความปลอดภัย"],
            ['head' => "4. กิจกรรมการเรียนรู้ ({$hours} ชั่วโมง)", 'body' => $this->activityBody($style, $hours)],
            ['head' => '5. สื่อและแหล่งการเรียนรู้', 'body' => "ใบความรู้ประจำหน่วย · ใบงาน · ชุดฝึกในโรงปฏิบัติงาน · คลิปสาธิต · แผ่นภาพ/ของจริงประกอบการสอน"],
            ['head' => '6. การวัดและประเมินผล', 'body' => "ประเมินการปฏิบัติจากใบงาน (50 คะแนน) · แบบทดสอบท้ายหน่วย (20 คะแนน) · สังเกตพฤติกรรมความปลอดภัยและการทำงานกลุ่ม (30 คะแนน) · เกณฑ์ผ่านคือได้คะแนนรวมไม่น้อยกว่าร้อยละ 60"],
            ['head' => '7. บันทึกหลังการสอน', 'body' => '(ครูกรอกหลังจัดการเรียนการสอนจริง)'],
        ];

        $lines = [json_encode(['meta' => ['unit' => $unit, 'hours' => $hours]], JSON_UNESCAPED_UNICODE)];
        foreach ($sections as $s) {
            $lines[] = json_encode($s, JSON_UNESCAPED_UNICODE);
        }
        $lines[] = json_encode(['done' => true], JSON_UNESCAPED_UNICODE);

        return $lines;
    }

    private function activityBody(string $style, string $hours): string
    {
        return match ($style) {
            'บรรยายและสาธิต' => "ขั้นนำ 30 นาที — ทบทวนความรู้เดิมและตั้งคำถามนำเข้าสู่บทเรียน\nขั้นสอน 120 นาที — บรรยายเนื้อหาพร้อมสาธิตของจริงหน้าชั้น ให้นักเรียนจดบันทึกและซักถาม\nขั้นปฏิบัติ 150 นาที — นักเรียนทำใบงานตามที่สาธิต ครูเดินตรวจและให้คำแนะนำรายบุคคล\nขั้นสรุป 60 นาที — สุ่มนักเรียนสรุปสาระสำคัญ ครูเน้นย้ำจุดที่มักผิดพลาด",
            'เรียนรู้จากปัญหา' => "ขั้นนำ 40 นาที — ครูตั้งสถานการณ์ปัญหาจากงานจริง ให้นักเรียนวิเคราะห์เป็นกลุ่ม\nขั้นสืบค้น 120 นาที — แต่ละกลุ่มค้นคว้าและออกแบบแนวทางแก้ปัญหา\nขั้นปฏิบัติ 140 นาที — ลงมือทำตามแนวทางที่วางไว้ ปรับแก้เมื่อพบอุปสรรค\nขั้นสรุป 60 นาที — นำเสนอผลและแลกเปลี่ยนวิธีคิดระหว่างกลุ่ม",
            default => "ขั้นนำ 30 นาที — ให้นักเรียนสังเกตการทำงานของอุปกรณ์จริงในโรงฝึก แล้วตั้งคำถามชวนคิด\nขั้นสอน 90 นาที — สาธิตขั้นตอนและอุปกรณ์ที่โต๊ะปฏิบัติการ พร้อมอธิบายหลักการบนกระดาน\nขั้นปฏิบัติ 180 นาที — แบ่งกลุ่ม 4 คน ปฏิบัติงานตามใบงาน ครูเดินตรวจทีละกลุ่ม\nขั้นสรุป 60 นาที — แต่ละกลุ่มนำเสนอปัญหาที่พบและวิธีแก้ ครูสรุปข้อควรระวังด้านความปลอดภัย",
        };
    }

    /** @return list<string> */
    private function simplifyLines(array $spec): array
    {
        $topic = (string) ($spec['topic'] ?? 'เนื้อหา');

        return [
            json_encode(['paragraph' => "เรื่อง {$topic} อธิบายแบบเข้าใจง่าย"], JSON_UNESCAPED_UNICODE),
            json_encode(['paragraph' => 'ลองนึกถึงสิ่งใกล้ตัวก่อน แล้วค่อย ๆ เชื่อมโยงไปหาหลักการ จะจำได้นานกว่าการท่องศัพท์'], JSON_UNESCAPED_UNICODE),
            json_encode(['paragraph' => 'สรุปสั้น ๆ: จำหลักการหลัก 3 ข้อ แล้วฝึกยกตัวอย่างจากงานจริงด้วยตัวเอง'], JSON_UNESCAPED_UNICODE),
            json_encode(['done' => true], JSON_UNESCAPED_UNICODE),
        ];
    }
}
