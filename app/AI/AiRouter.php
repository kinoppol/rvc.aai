<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AiRepository;
use App\Domain\SettingsRepository;

/**
 * เลือกเส้นทาง AI ตามลำดับ: คีย์ของครู → เครื่องของส่วนกลาง → แจ้งเตือนเมื่อทั้งสองใช้ไม่ได้
 *
 * งานร่างข้อสอบ/แผนการสอนยังใช้ SimulatedProvider (ยังไม่ได้ออกแบบ prompt ของจริง)
 * ส่วนช่องสนทนาทดสอบเรียกด้วย $real = true จึงต่อ API จริงตามการตั้งค่าของครู:
 * GoogleAiProvider / OpenAiCompatibleProvider สำหรับคีย์ของครู และ OllamaProvider สำหรับเครื่องของส่วนกลาง
 */
final class AiRouter
{
    public function __construct(
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly KeyCipher $cipher,
    ) {
    }

    /**
     * @param 'fast'|'quality' $quality
     * @param bool $enforceQuota ตรวจโควตาก่อนหรือไม่ — ตั้ง false สำหรับการทดสอบการเชื่อมต่อ
     * @param bool $real ต่อ API จริงตามการตั้งค่าของครู (ช่องสนทนาทดสอบ) แทนตัวจำลอง
     */
    public function route(int $userId, string $quality = 'fast', bool $enforceQuota = true, bool $real = false): AiRoute
    {
        $modeLabel = $quality === 'quality' ? 'โหมดคุณภาพสูง' : 'โหมดเร็ว';

        // ครูเลือกได้ว่าจะให้ใช้แหล่งไหนก่อน ถ้าแหล่งนั้นใช้ไม่ได้ค่อยตกไปอีกแหล่งหนึ่ง
        $preferCollege = $this->settings->userRoutePref($userId) === 'college';
        $failure = null;

        foreach ($preferCollege ? ['college', 'byok'] : ['byok', 'college'] as $source) {
            $route = $source === 'byok'
                ? $this->byokRoute($userId, $quality, $modeLabel, $real)
                : $this->collegeRoute($userId, $enforceQuota, $real, $failure, $modeLabel);

            if ($route !== null) {
                return $route;
            }
        }

        throw $failure ?? new AiUnavailableException(
            'college_offline',
            'ตอนนี้เครื่อง AI ของส่วนกลางปิดอยู่ และยังไม่ได้เชื่อม AI ของครู'
        );
    }

    /** เส้นทางผ่านคีย์ของครูเอง — คืน null เมื่อไม่ได้เชื่อมไว้หรือคีย์ใช้ไม่ได้ */
    private function byokRoute(int $userId, string $quality, string $modeLabel, bool $real): ?AiRoute
    {
        if (!$this->settings->bool('allow_teacher_byok', true)) {
            return null;
        }

        $key = $this->ai->activeKeyForUser($userId);
        if ($key === null) {
            return null;
        }

        $provider = $this->byokProvider($key, $quality, $real);
        if (!$provider->isAvailable()) {
            return null;
        }

        $this->ai->touchKeyUsed((int) $key['id']);

        return new AiRoute(
            provider: $provider,
            source: 'byok',
            chip: 'AI ของฉัน · ' . $modeLabel,
            wait: 'ประมาณ 8 วินาที · ไม่ใช้โควตาของส่วนกลาง',
            countsQuota: false,
            model: (string) ($key['model'] ?? $key['provider']),
        );
    }

    /**
     * เส้นทางผ่านเครื่องของส่วนกลาง — คืน null เมื่อเครื่องปิดหรือโควตาหมด
     * โดยเก็บสาเหตุไว้ใน $failure เผื่อไม่มีเส้นทางอื่นเหลือ
     */
    private function collegeRoute(int $userId, bool $enforceQuota, bool $real, ?AiUnavailableException &$failure, string $modeLabel = ''): ?AiRoute
    {
        $endpoint = $this->ai->defaultEndpoint();
        if ($endpoint === null || $endpoint['status'] === 'offline') {
            $failure ??= new AiUnavailableException(
                'college_offline',
                'ตอนนี้เครื่อง AI ของส่วนกลางปิดอยู่ และยังไม่ได้เชื่อม AI ของครู'
            );

            return null;
        }

        $quotaLimit = $this->settings->int('ai_monthly_quota', 60);
        $quota = $this->ai->quota($userId, $this->period(), $quotaLimit);

        if ($enforceQuota && $quota['left'] <= 0) {
            $failure ??= new AiUnavailableException(
                'quota_exhausted',
                sprintf('ใช้โควตาผู้ช่วย AI ครบ %d ครั้งของเดือนนี้แล้ว', $quota['limit'])
            );

            return null;
        }

        $provider = $this->collegeProvider($endpoint, $real);
        if (!$provider->isAvailable()) {
            $failure ??= new AiUnavailableException(
                'college_offline',
                'ต่อกับเครื่อง AI ของส่วนกลางไม่ได้ในตอนนี้'
            );

            return null;
        }

        $queue = max((int) $endpoint['queue_length'], $this->ai->queueLength());
        $waitSeconds = 15 + $queue * 12;

        return new AiRoute(
            provider: $provider,
            source: 'college',
            chip: ($queue > 0 ? sprintf('AI ส่วนกลาง · คิว %d งาน', $queue) : 'AI ส่วนกลาง')
                . ($modeLabel !== '' ? ' · ' . $modeLabel : ''),
            wait: $queue > 5
                ? sprintf('มีครูใช้งานพร้อมกันหลายคน · คิวรออยู่ %d งาน · ประมาณ %s', $queue, $this->humanDuration($waitSeconds))
                : sprintf('มีคิวรออยู่ %d งาน · ประมาณ %s', $queue, $this->humanDuration($waitSeconds)),
            countsQuota: true,
            model: (string) $endpoint['model'],
        );
    }

    private function collegeProvider(array $endpoint, bool $real): AiProvider
    {
        if (!$real) {
            return new SimulatedProvider('AI ของส่วนกลาง', $endpoint['status'] !== 'offline');
        }

        $baseUrl = (string) ($endpoint['base_url'] ?? '');
        $model = (string) ($endpoint['model'] ?? '');
        $kind = (string) ($endpoint['kind'] ?? 'ollama');

        if ($kind === 'ollama') {
            return new OllamaProvider($baseUrl, $model);
        }

        $apiKey = '';
        if (!empty($endpoint['api_key_encrypted'])) {
            try {
                $apiKey = $this->cipher->decrypt((string) $endpoint['api_key_encrypted']);
            } catch (\RuntimeException) {
                $apiKey = '';
            }
        }

        if ($kind === 'google') {
            return new GoogleAiProvider(
                $apiKey,
                $model !== '' ? $model : GoogleAiProvider::DEFAULT_MODEL,
                labelPrefix: 'AI ของส่วนกลาง'
            );
        }

        // openrouter ใช้ที่อยู่ตายตัว ส่วน openai_compatible ใช้ที่อยู่ที่ผู้ดูแลกรอก
        $base = $kind === 'openrouter' ? OpenAiCompatibleProvider::OPENROUTER_BASE : $baseUrl;
        $name = $kind === 'openrouter' ? 'OpenRouter' : '';

        return new OpenAiCompatibleProvider($apiKey, $base, $model, $name, new HttpClient(), 'AI ของส่วนกลาง');
    }

    private function byokProvider(array $key, string $quality, bool $real): AiProvider
    {
        $name = match ($key['provider']) {
            'google' => 'Google AI Studio',
            'openrouter' => 'OpenRouter',
            default => 'บริการของฉัน',
        };

        if (!$real) {
            return new SimulatedProvider('AI ของฉัน · ' . $name, true, 300);
        }

        try {
            $apiKey = $this->cipher->decrypt((string) $key['key_encrypted']);
        } catch (\RuntimeException $e) {
            throw new AiUnavailableException('key_invalid', 'อ่านรหัสเชื่อมต่อของครูไม่ได้ กรุณาวางรหัสใหม่อีกครั้ง');
        }

        $model = self::modelId($key);

        return match ($key['provider']) {
            'google' => new GoogleAiProvider($apiKey, $model),
            'openrouter' => new OpenAiCompatibleProvider(
                $apiKey,
                OpenAiCompatibleProvider::OPENROUTER_BASE,
                $model,
                $name
            ),
            default => new OpenAiCompatibleProvider($apiKey, (string) ($key['base_url'] ?? ''), $model, $name),
        };
    }

    /**
     * ชื่อโมเดลที่ใช้เรียก API จริง — คีย์ที่บันทึกไว้ก่อนหน้านี้เก็บเป็นชื่อสำหรับแสดงผล
     * (เช่น "Gemini 1.5 Flash") จึงต้องถอยไปใช้ค่าเริ่มต้นของผู้ให้บริการแทน
     */
    private static function modelId(array $key): string
    {
        $model = trim((string) ($key['model'] ?? ''));

        if ($model !== '' && !str_contains($model, ' ')) {
            return $model;
        }

        return match ($key['provider']) {
            'google' => GoogleAiProvider::DEFAULT_MODEL,
            'openrouter' => OpenAiCompatibleProvider::OPENROUTER_DEFAULT_MODEL,
            default => $model,
        };
    }

    private function period(): string
    {
        return sprintf('%04d-%02d', $this->settings->int('academic_year', (int) date('Y')), (int) date('n'));
    }

    private function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' วินาที';
        }

        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $s > 0 ? sprintf('%d นาที %d วินาที', $m, $s) : sprintf('%d นาที', $m);
    }
}
