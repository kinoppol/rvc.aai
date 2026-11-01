<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\SettingsRepository;

/**
 * บทบาทของแต่ละโมเดล — โมเดลนี้เหมาะกับงานไหนในการจัดการเรียนการสอน
 *
 * ผู้ให้บริการรายหนึ่งเปิดได้หลายโมเดล บางตัวเก่งงานยาว (แผนการสอน) บางตัวเร็ว (แชต)
 * บางตัวไม่ใช่โมเดลข้อความเลย (OCR, เสียง) เมื่อครูเลือก AI "อัตโนมัติ" AiRouter จะใช้โมเดลที่มีบทบาทตรงกับงานก่อน
 *
 * เก็บใน settings เหมือนชื่อเล่นโมเดล: ai_roles:c{endpointId} (ส่วนกลาง) / ai_roles:k{keyId} (คีย์ของครู)
 * = {ชื่อโมเดล: {roles: [...], note: "..."}} — โมเดลที่ยังไม่ได้กำหนดใช้ guess() จากชื่อ
 * guess() มีสำเนาใน public/assets/js/model-picker.js (guessRoles) — แก้ให้ตรงกันทั้งสองที่
 */
final class ModelRoles
{
    /** บทบาท => ชื่อเต็ม / ชื่อสั้นบนป้าย */
    public const ROLES = [
        'chat' => ['แชตผู้ช่วย ถาม–ตอบ', 'แชต'],
        'quiz' => ['ออกข้อสอบ', 'ข้อสอบ'],
        'plan' => ['แผนการสอน', 'แผน'],
        'content' => ['ร่างหน่วย เนื้อหา ใบงาน', 'เนื้อหา'],
        // ไม่ใช่งานข้อความ: อ่านหน้าเอกสารสแกน/รูปให้เป็นข้อความ (UnitKnowledge) — โมเดลที่มีแค่บทบาทนี้ไม่ถูกเลือกไปทำงานข้อความ
        'ocr' => ['อ่านเอกสารสแกน/รูปภาพ (OCR)', 'OCR'],
    ];

    /** บทบาทที่เป็นงานข้อความ (ทุกบทบาทยกเว้น ocr) */
    public const TEXT_ROLES = ['chat', 'quiz', 'plan', 'content'];

    /** โมเดลเฉพาะทาง OCR — อ่านภาพได้อย่างเดียว */
    private const OCR_ONLY = '/ocr/i';

    /** โมเดลข้อความที่รับภาพได้ด้วย (multimodal) — ได้บทบาท ocr เพิ่ม */
    private const VISION = '/vision|vl\b|-vl|llava|pixtral|gemini|gpt-4o|gpt-4\.1|gpt-5|claude|gemma-?3|internvl|minicpm-v|llama-?4/i';

    /** โมเดลที่ไม่ใช่งานข้อความและอ่านภาพไม่ได้ (เสียง ฝังเวกเตอร์ สร้างภาพ ตรวจเนื้อหา) */
    private const NOT_TEXT = '/tts|whisper|speech|audio|transcri|embed|rerank|image|imagen|dall-?e|diffusion|flux|guard|moderation/i';

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /** งานในระบบ → บทบาทที่ต้องการ ('' = ไม่สนบทบาท) */
    public static function forTask(string $task): string
    {
        return match ($task) {
            'chat' => 'chat',
            'quiz' => 'quiz',
            'lesson_plan', 'plan' => 'plan',
            'unit_outline', 'unit_content', 'assignment', 'course_outcomes', 'model_roles', 'content' => 'content',
            'ocr' => 'ocr',
            default => '',
        };
    }

    /**
     * เดาจากชื่อโมเดล — ใช้จนกว่าครู/ผู้ดูแลจะกำหนดเองหรือให้ AI จัดให้
     *
     * @return list<string>
     */
    public static function guess(string $model): array
    {
        if ($model !== '' && preg_match(self::OCR_ONLY, $model) === 1) {
            return ['ocr'];
        }
        if ($model !== '' && preg_match(self::NOT_TEXT, $model) === 1) {
            return [];
        }

        return preg_match(self::VISION, $model) === 1 ? array_keys(self::ROLES) : self::TEXT_ROLES;
    }

    /** มีบทบาทงานข้อความอย่างน้อยหนึ่งบทบาท (ไม่นับ ocr) — ใช้เป็นโมเดลหลักของงานข้อความได้ */
    public static function isText(array $roles): bool
    {
        return array_intersect($roles, self::TEXT_ROLES) !== [];
    }

    /**
     * โมเดลนี้ใช้กับบทบาท $role ได้ไหม — สำหรับ ocr นับโมเดลชื่อ "ocr" ที่ถูกบันทึกบทบาทว่างไว้ก่อนมีบทบาทนี้ด้วย
     *
     * @param list<string> $roles
     */
    public static function matches(string $role, array $roles, string $model): bool
    {
        return in_array($role, $roles, true)
            || ($role === 'ocr' && $roles === [] && preg_match(self::OCR_ONLY, $model) === 1);
    }

    /** @return array<string,array{roles:list<string>,note:string}> */
    public function stored(string $source): array
    {
        $raw = $this->settings->get('ai_roles:' . $source);
        $map = is_array($raw) ? $raw : json_decode((string) $raw, true);

        return is_array($map) ? self::clean($map) : [];
    }

    /**
     * บทบาทของทุกโมเดลในรายการ (ที่กำหนดไว้ ไม่เช่นนั้นเดาจากชื่อ)
     *
     * @param list<string> $models
     * @return array<string,array{roles:list<string>,note:string}>
     */
    public function forModels(string $source, array $models): array
    {
        $stored = $this->stored($source);
        $out = [];
        foreach ($models as $m) {
            $out[$m] = $stored[$m] ?? ['roles' => self::guess($m), 'note' => ''];
        }

        return $out;
    }

    /** @return list<string> */
    public function rolesOf(string $source, string $model): array
    {
        return ($this->stored($source)[$model] ?? null)['roles'] ?? self::guess($model);
    }

    /**
     * บันทึกจากฟอร์ม (roles_json) — เก็บเฉพาะโมเดลที่เปิดใช้
     *
     * @param list<string> $enabled
     */
    public function savePosted(string $source, string $json, array $enabled): void
    {
        $map = json_decode($json, true);
        if (!is_array($map)) {
            return;   // ฟอร์มเก่าที่ไม่มีช่องบทบาท — คงค่าเดิมไว้
        }

        $this->settings->set('ai_roles:' . $source, array_intersect_key(self::clean($map), array_flip($enabled)), 'json', 'ai');
    }

    /** ป้ายสั้นของบทบาท เช่น "แชต · ข้อสอบ" หรือ "ไม่ใช้กับงานสอน" */
    public static function shortLabel(array $roles): string
    {
        if ($roles === []) {
            return 'ไม่ใช้กับงานสอน';
        }
        if (count($roles) === count(self::ROLES)) {
            return 'ทุกงาน';
        }
        if (array_diff(self::TEXT_ROLES, $roles) === []) {
            return 'ทุกงานข้อความ';
        }

        return implode(' · ', array_map(static fn (string $r): string => self::ROLES[$r][1], $roles));
    }

    /**
     * @param array<mixed> $map
     * @return array<string,array{roles:list<string>,note:string}>
     */
    public static function clean(array $map): array
    {
        $out = [];
        foreach ($map as $model => $v) {
            $roles = is_array($v) ? ($v['roles'] ?? $v) : [];
            $roles = array_values(array_intersect(array_keys(self::ROLES), array_map('strval', array_filter((array) $roles, 'is_scalar'))));
            $note = is_array($v) && is_scalar($v['note'] ?? null) ? mb_substr(trim((string) $v['note']), 0, 160) : '';
            $out[(string) $model] = ['roles' => $roles, 'note' => $note];
        }

        return $out;
    }
}
