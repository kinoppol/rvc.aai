<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ร่างแผนการจัดการเรียนรู้ตามแบบฟอร์มของ สอศ. จากข้อมูลหน่วยการเรียนรู้ที่ครูกรอก
 */
final class LessonPlanGenerator
{
    /**
     * @param array{unit:string,competency:string,hours:string,week:string,style:string} $params
     * @return iterable<array{event:string,data?:array,message?:string}>
     */
    public function stream(AiRoute $route, array $params, string $quality = 'quality'): iterable
    {
        $spec = json_encode([
            'task' => 'lesson_plan',
            'unit' => $params['unit'],
            'competency' => $params['competency'],
            'hours' => $params['hours'],
            'week' => $params['week'],
            'style' => $params['style'],
        ], JSON_UNESCAPED_UNICODE);

        $system = 'คุณเป็นผู้ช่วยครูอาชีวศึกษา ร่างแผนการจัดการเรียนรู้มุ่งเน้นสมรรถนะตามแบบฟอร์ม สอศ. '
            . 'ตอบกลับเป็น NDJSON หนึ่งหัวข้อต่อหนึ่งบรรทัด';

        $meta = [];

        foreach (JsonStream::objects($route->provider->stream($system, $spec, $quality)) as $obj) {
            if (isset($obj['error'])) {
                yield ['event' => 'error', 'message' => (string) $obj['error']];

                return;
            }
            if (isset($obj['meta']) && is_array($obj['meta'])) {
                $meta = $obj['meta'];

                continue;
            }
            if (!empty($obj['done'])) {
                yield ['event' => 'done', 'data' => ['meta' => $meta]];

                return;
            }
            if (isset($obj['head'], $obj['body'])) {
                yield ['event' => 'section', 'data' => [
                    'head' => (string) $obj['head'],
                    'body' => is_array($obj['body']) ? implode("\n", array_map('strval', $obj['body'])) : (string) $obj['body'],
                ]];
            }
        }

        yield ['event' => 'done', 'data' => ['meta' => $meta]];
    }
}
