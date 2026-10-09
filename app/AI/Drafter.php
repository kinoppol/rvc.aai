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
     * @param array<string,mixed> $spec ข้อมูลงานที่ Prompt::userText() จะแปลงเป็นคำสั่งภาษาไทย
     * @return list<array<string,mixed>> อ็อบเจกต์ที่โมเดลร่างมา (ตัดบรรทัดปิดท้าย done ออกให้แล้ว)
     *
     * @throws AiUnavailableException เมื่อเรียกบริการไม่ได้ รหัสหมดอายุ หรือโควตาเต็ม
     */
    public function objects(int $userId, string $system, array $spec, int $limit): array
    {
        $quality = $this->settings->userQualityPref($userId);
        set_time_limit(0);

        $route = $this->router->route($userId, $quality, false, real: true);
        $payload = (string) json_encode($spec, JSON_UNESCAPED_UNICODE);

        $items = [];
        foreach (JsonStream::objects($route->provider->stream($system, $payload, $quality)) as $obj) {
            if (isset($obj['done'])) {
                break;
            }

            $items[] = $obj;

            if (count($items) >= $limit) {
                break;
            }
        }

        $this->ai->logUsage($userId, null, $route->source, $route->model, $items !== []);

        return $items;
    }
}
