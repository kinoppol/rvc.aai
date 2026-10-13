<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AiRepository;
use App\Domain\SettingsRepository;

/**
 * เรียกผู้ช่วยแบบรอผลจนจบในคำขอเดียว (ไม่ใช่ SSE) แล้วคืนอ็อบเจกต์ JSON ที่โมเดลร่างมา
 *
 * ใช้กับงานร่างสั้น ๆ ที่ครูกดแล้วรอผลในหน้าเดียว เช่น รายชื่อหน่วยการเรียน เนื้อหาหน่วย และใบงาน
 * งานที่ยาวกว่านั้น (ข้อสอบ แผนการสอน) ยังใช้ SSE เพื่อให้เห็นผลไหลมาทีละชิ้น
 */
final class Drafter
{
    public function __construct(
        private readonly AiRouter $router,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * ก้อนที่ไม่มีหัวเรื่องของตัวเอง แต่มีรายการก้อนย่อยอยู่ข้างใน (units/items/data/…) = ก้อนห่อ → คืนก้อนย่อย
     *
     * @param array<string,mixed> $obj
     * @return list<array<string,mixed>>
     */
    private static function unwrap(array $obj): array
    {
        if (isset($obj['title']) || isset($obj['objective'])) {
            return [$obj];
        }

        foreach ($obj as $value) {
            if (is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0])) {
                return array_values(array_filter($value, 'is_array'));
            }
        }

        return [$obj];
    }

    /**
     * ค่าจากโมเดลเป็นข้อความ — บางโมเดลส่งจุดประสงค์/สมรรถนะมาเป็น array (เคยกลายเป็นคำว่า "Array")
     * array จึงรวมเป็นข้อละบรรทัด ตัดเลขข้อที่โมเดลใส่มาเองออก
     */
    public static function text(mixed $value): string
    {
        if (is_array($value)) {
            $lines = [];
            foreach ($value as $item) {
                $line = trim(self::text($item));
                if ($line !== '') {
                    $lines[] = trim((string) preg_replace('/^\s*(?:[-•*]|\d+[.)]|[๑-๙]+[.)])\s*/u', '', $line));
                }
            }

            return implode("\n", $lines);
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * ตัวเลือก AI สำหรับฟอร์มร่างงาน (_partials/ai-picker.latte) พร้อมตัวที่ครูเลือกไว้ครั้งก่อน
     *
     * @return array{options:list<array<string,mixed>>,chosen:string}
     */
    public function picker(int $userId): array
    {
        return [
            'options' => $this->router->options($userId),
            'chosen' => (string) ($_SESSION['ai_pick'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $spec ข้อมูลงานที่ Prompt::userText() จะแปลงเป็นคำสั่งภาษาไทย
     * @return list<array<string,mixed>> อ็อบเจกต์ที่โมเดลร่างมา (ตัดบรรทัดปิดท้าย done ออกให้แล้ว)
     *
     * @throws AiUnavailableException เมื่อเรียกบริการไม่ได้ รหัสหมดอายุ หรือโควตาเต็ม
     */
    public function objects(int $userId, string $system, array $spec, int $limit, string $target = ''): array
    {
        $quality = $this->settings->userQualityPref($userId);
        set_time_limit(0);

        // AI ที่ครูเลือกในฟอร์ม ('' = ค่าเริ่มต้นของครู) — จำไว้ให้ฟอร์มถัดไปเลือกตัวเดิมให้
        $target = trim($target);
        $_SESSION['ai_pick'] = $target;

        $route = $this->router->route($userId, $quality, false, real: true, target: $target);
        // โมเดลแบบคิดก่อนตอบ (qwen3 / …think) บางครั้งวนคิดจนหมดเวลาโดยไม่ตอบ JSON สักบรรทัด
        // ทดสอบกับ Pathumma qwen3-think: ล้ม 2 ใน 3 รอบ (~170 วินาที) → ใส่ /no_think แล้วผ่าน 3/3 (~25 วินาที)
        if (preg_match('/qwen3|think/i', $route->model) === 1) {
            $spec['no_think'] = true;
        }
        $payload = (string) json_encode($spec, JSON_UNESCAPED_UNICODE);

        // ปล่อยล็อกเซสชันระหว่างรอโมเดล (อาจนานหลายสิบวินาที) — ไม่งั้นหน้าอื่นที่ครูเปิดไว้จะค้างตาม
        // แล้วเปิดกลับมาหลังได้ผล เพราะ controller ยังต้องเขียนผลร่าง/ข้อความแจ้งลงเซสชัน
        $reopen = session_status() === PHP_SESSION_ACTIVE;
        if ($reopen) {
            session_write_close();
        }

        $items = [];
        try {
            foreach (JsonStream::objects($route->provider->stream($system, $payload, $quality)) as $obj) {
                if (isset($obj['done'])) {
                    break;
                }

                // บางโมเดลห่อทุกชิ้นไว้ในก้อนเดียว เช่น {"units":[{…},{…}]} — แกะออกเป็นทีละชิ้น
                foreach (self::unwrap($obj) as $item) {
                    $items[] = $item;
                }

                if (count($items) >= $limit) {
                    $items = array_slice($items, 0, $limit);

                    break;
                }
            }
        } finally {
            if ($reopen && session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
        }

        $this->ai->logUsage($userId, null, $route->source, $route->model, $items !== []);

        return $items;
    }
}
