<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AiRepository;
use App\Domain\SettingsRepository;

/**
 * เลือกว่างานนี้จะให้ AI ตัวไหนทำ
 *
 * "ตัวเลือก AI" หนึ่งตัว = ผู้ให้บริการหนึ่งราย × โมเดลที่เปิดไว้หนึ่งตัว
 * ทั้งของส่วนกลาง (ผู้ดูแลตั้งได้หลายราย แต่ละรายเปิดได้หลายโมเดล) และของครู (เชื่อมได้หลายคีย์)
 * รหัสตัวเลือกเป็นข้อความ "c{id ผู้ให้บริการ}:{โมเดล}" หรือ "k{id คีย์}:{โมเดล}"
 * — โมเดลของ Ollama มีเครื่องหมาย : ได้ (qwen2.5:14b) จึงตัดแค่ : ตัวแรก
 *
 * ลำดับการลอง: ตัวที่ระบุมากับงาน → ตัวที่ครูตั้งเป็นค่าเริ่มต้น → ตัวหลักของแหล่งที่ครูเลือกไว้ก่อน → แหล่งที่เหลือ
 * ถ้าตัวที่ต้องการใช้ไม่ได้ ระบบสลับไปตัวถัดไปให้อัตโนมัติ และเก็บสาเหตุของตัวแรกที่ล้มไว้แจ้งเมื่อไม่เหลือทางเลือก
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
     * ตัวเลือก AI ทั้งหมดที่ผู้ใช้คนนี้เลือกใช้ได้ — ของครูก่อน ตามด้วยของส่วนกลาง
     *
     * @return list<array{id:string,source:string,group:string,label:string,model:string,online:bool,free:bool}>
     */
    public function options(int $userId): array
    {
        $out = [];

        if ($this->byokAllowed()) {
            foreach ($this->ai->activeKeysForUser($userId) as $key) {
                foreach (AiRepository::enabledModels($key) as $model) {
                    $model = self::keyModel($key, $model);
                    $out[] = [
                        'id' => 'k' . $key['id'] . ':' . $model,
                        'source' => 'byok',
                        'group' => 'AI ของฉัน · ' . self::providerName((string) $key['provider']),
                        'label' => $model !== '' ? $model : 'โมเดลเริ่มต้น',
                        'model' => $model,
                        'online' => true,
                        'free' => true,   // คีย์ของครูเองไม่นับโควตาส่วนกลางอยู่แล้ว
                    ];
                }
            }
        }

        foreach ($this->ai->activeEndpoints() as $endpoint) {
            $unlimited = AiRepository::unlimitedModels($endpoint);
            foreach (AiRepository::enabledModels($endpoint) as $model) {
                $free = in_array($model, $unlimited, true);
                $out[] = [
                    'id' => 'c' . $endpoint['id'] . ':' . $model,
                    'source' => 'college',
                    'group' => (string) ($endpoint['name'] ?: 'AI ของส่วนกลาง'),
                    'label' => ($model !== '' ? $model : 'โมเดลเริ่มต้น') . ($free ? ' · ใช้ได้ไม่จำกัด' : ''),
                    'model' => $model,
                    'online' => $endpoint['status'] !== 'offline',
                    'free' => $free,
                ];
            }
        }

        return $out;
    }

    /** ตัวเลือกที่ครูตั้งเป็นค่าเริ่มต้นไว้ ('' = ให้ระบบเลือกตามลำดับ) */
    public function defaultTarget(int $userId): string
    {
        return trim((string) ($this->settings->get('ai_target:' . $userId) ?? ''));
    }

    /**
     * @param 'fast'|'quality' $quality
     * @param bool $enforceQuota ตรวจโควตาก่อนหรือไม่ — ตั้ง false สำหรับการทดสอบการเชื่อมต่อ
     * @param bool $real ต่อ API จริง (ทุกงานในระบบใช้ true) แทนตัวจำลอง
     * @param string|null $target ตัวเลือก AI ที่ผู้ใช้เลือกมากับงานนี้ — ว่าง = ใช้ค่าเริ่มต้นของครู
     */
    public function route(int $userId, string $quality = 'fast', bool $enforceQuota = true, bool $real = false, ?string $target = null): AiRoute
    {
        $modeLabel = $quality === 'quality' ? 'โหมดคุณภาพสูง' : 'โหมดเร็ว';
        $failure = null;

        foreach ($this->candidates($userId, $target) as [$source, $row, $model]) {
            $route = $source === 'byok'
                ? $this->byokRoute($userId, $row, $model, $quality, $modeLabel, $real)
                : $this->collegeRoute($userId, $row, $model, $enforceQuota, $real, $failure, $modeLabel);

            if ($route !== null) {
                return $route;
            }
        }

        throw $failure ?? new AiUnavailableException(
            'college_offline',
            'ตอนนี้เครื่อง AI ของส่วนกลางปิดอยู่ และยังไม่ได้เชื่อม AI ของครู'
        );
    }

    /**
     * ลำดับตัวเลือกที่จะลอง — ไม่ซ้ำกัน
     *
     * @return list<array{0:string,1:array<string,mixed>,2:string}>
     */
    private function candidates(int $userId, ?string $target): array
    {
        $list = [];
        $seen = [];
        $add = static function (string $source, array $row, string $model) use (&$list, &$seen): void {
            $id = ($source === 'byok' ? 'k' : 'c') . $row['id'] . ':' . $model;
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $list[] = [$source, $row, $model];
            }
        };

        $first = null;
        foreach ([trim((string) $target), $this->defaultTarget($userId)] as $wanted) {
            if ($wanted !== '' && ($resolved = $this->resolve($userId, $wanted)) !== null) {
                $add(...$resolved);
                $first ??= $resolved[0];
            }
        }

        // ที่เหลือเรียงตามแหล่งที่ครูเลือกไว้ก่อน (หรือแหล่งของตัวที่เลือก) แล้วจึงอีกแหล่ง
        $preferCollege = ($first ?? $this->settings->userRoutePref($userId)) === 'college';
        $keys = $this->byokAllowed() ? $this->ai->activeKeysForUser($userId) : [];
        $endpoints = $this->ai->activeEndpoints();

        foreach ($preferCollege ? ['college', 'byok'] : ['byok', 'college'] as $source) {
            foreach ($source === 'byok' ? $keys : $endpoints as $row) {
                $model = AiRepository::enabledModels($row)[0];
                $add($source, $row, $source === 'byok' ? self::keyModel($row, $model) : $model);
            }
        }

        // ทางสุดท้าย: โมเดลไม่จำกัดของส่วนกลาง — ยังใช้ได้แม้ครูใช้โควตาครบเดือนแล้ว
        foreach ($endpoints as $row) {
            foreach (AiRepository::unlimitedModels($row) as $model) {
                if (in_array($model, AiRepository::enabledModels($row), true)) {
                    $add('college', $row, $model);
                }
            }
        }

        return $list;
    }

    /**
     * แปลงรหัสตัวเลือกเป็นแถวผู้ให้บริการ + โมเดล — คืน null ถ้าไม่ใช่ของผู้ใช้คนนี้ ถูกปิดไปแล้ว หรือโมเดลไม่ได้เปิดไว้
     *
     * @return array{0:string,1:array<string,mixed>,2:string}|null
     */
    private function resolve(int $userId, string $target): ?array
    {
        if (!preg_match('/^([ck])(\d+):(.*)$/s', $target, $m)) {
            return null;
        }

        $id = (int) $m[2];
        $model = $m[3];

        if ($m[1] === 'k') {
            $key = $this->byokAllowed() ? $this->ai->keyForUser($userId, $id) : null;
            if ($key === null) {
                return null;
            }
            $models = array_map(static fn (string $x): string => self::keyModel($key, $x), AiRepository::enabledModels($key));

            return in_array($model, $models, true) ? ['byok', $key, $model] : null;
        }

        $endpoint = $this->ai->endpoint($id);
        if ($endpoint === null || (int) $endpoint['is_active'] !== 1) {
            return null;
        }

        return in_array($model, AiRepository::enabledModels($endpoint), true) ? ['college', $endpoint, $model] : null;
    }

    private function byokAllowed(): bool
    {
        return $this->settings->bool('allow_teacher_byok', true);
    }

    /** เส้นทางผ่านคีย์ของครูเอง — คืน null เมื่อคีย์ใช้ไม่ได้ */
    private function byokRoute(int $userId, array $key, string $model, string $quality, string $modeLabel, bool $real): ?AiRoute
    {
        $provider = $this->byokProvider($key, $model, $real);
        if (!$provider->isAvailable()) {
            return null;
        }

        $this->ai->touchKeyUsed((int) $key['id']);

        return new AiRoute(
            provider: $provider,
            source: 'byok',
            chip: 'AI ของฉัน · ' . ($model !== '' ? $model : self::providerName((string) $key['provider'])) . ' · ' . $modeLabel,
            wait: 'ประมาณ 8 วินาที · ไม่ใช้โควตาของส่วนกลาง',
            countsQuota: false,
            model: $model !== '' ? $model : (string) $key['provider'],
            target: 'k' . $key['id'] . ':' . $model,
        );
    }

    /**
     * เส้นทางผ่านผู้ให้บริการของส่วนกลาง — คืน null เมื่อปลายทางปิดหรือโควตาหมด
     * โดยเก็บสาเหตุไว้ใน $failure เผื่อไม่มีเส้นทางอื่นเหลือ
     */
    private function collegeRoute(int $userId, array $endpoint, string $model, bool $enforceQuota, bool $real, ?AiUnavailableException &$failure, string $modeLabel = ''): ?AiRoute
    {
        $name = (string) ($endpoint['name'] ?: 'AI ของส่วนกลาง');

        if ($endpoint['status'] === 'offline') {
            $failure ??= new AiUnavailableException(
                'college_offline',
                sprintf('ตอนนี้ %s ปิดอยู่ และยังไม่มี AI ตัวอื่นที่ใช้ได้', $name)
            );

            return null;
        }

        // โมเดลที่ผู้ดูแลตั้งให้ใช้ได้ไม่จำกัด (เช่น โมเดลฟรี) ไม่ตรวจและไม่ตัดโควตาของครู
        $free = in_array($model, AiRepository::unlimitedModels($endpoint), true);
        $quotaLimit = $this->settings->int('ai_monthly_quota', 60);
        $quota = $this->ai->quota($userId, $this->period(), $quotaLimit);

        if ($enforceQuota && !$free && $quota['left'] <= 0) {
            $failure ??= new AiUnavailableException(
                'quota_exhausted',
                sprintf('ใช้โควตาผู้ช่วย AI ครบ %d ครั้งของเดือนนี้แล้ว', $quota['limit'])
            );

            return null;
        }

        $provider = $this->collegeProvider($endpoint, $model, $real);
        if (!$provider->isAvailable()) {
            $failure ??= new AiUnavailableException(
                'college_offline',
                sprintf('ต่อกับ %s ไม่ได้ในตอนนี้', $name)
            );

            return null;
        }

        $queue = max((int) $endpoint['queue_length'], $this->ai->queueLength());
        $waitSeconds = 15 + $queue * 12;
        $chip = 'AI ส่วนกลาง' . ($model !== '' ? ' · ' . $model : '') . ($free ? ' · ไม่จำกัด' : '');

        return new AiRoute(
            provider: $provider,
            source: 'college',
            chip: ($queue > 0 ? sprintf('%s · คิว %d งาน', $chip, $queue) : $chip)
                . ($modeLabel !== '' ? ' · ' . $modeLabel : ''),
            wait: $queue > 5
                ? sprintf('มีครูใช้งานพร้อมกันหลายคน · คิวรออยู่ %d งาน · ประมาณ %s', $queue, $this->humanDuration($waitSeconds))
                : sprintf('มีคิวรออยู่ %d งาน · ประมาณ %s', $queue, $this->humanDuration($waitSeconds))
                    . ($free ? ' · ไม่ใช้โควตาของครู' : ''),
            countsQuota: !$free,
            model: $model,
            target: 'c' . $endpoint['id'] . ':' . $model,
        );
    }

    private function collegeProvider(array $endpoint, string $model, bool $real): AiProvider
    {
        if (!$real) {
            return new SimulatedProvider('AI ของส่วนกลาง', $endpoint['status'] !== 'offline');
        }

        $baseUrl = (string) ($endpoint['base_url'] ?? '');
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

    private function byokProvider(array $key, string $model, bool $real): AiProvider
    {
        $name = self::providerName((string) $key['provider']);

        if (!$real) {
            return new SimulatedProvider('AI ของฉัน · ' . $name, true, 300);
        }

        try {
            $apiKey = $this->cipher->decrypt((string) $key['key_encrypted']);
        } catch (\RuntimeException $e) {
            throw new AiUnavailableException('key_invalid', 'อ่านรหัสเชื่อมต่อของครูไม่ได้ กรุณาวางรหัสใหม่อีกครั้ง');
        }

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

    public static function providerName(string $provider): string
    {
        return match ($provider) {
            'google' => 'Google AI Studio',
            'openrouter' => 'OpenRouter',
            default => 'บริการของฉัน',
        };
    }

    /**
     * ชื่อโมเดลที่ใช้เรียก API จริงของคีย์ครู — คีย์ที่บันทึกไว้ก่อนหน้านี้เก็บเป็นชื่อสำหรับแสดงผล
     * (เช่น "Gemini 1.5 Flash") จึงต้องถอยไปใช้ค่าเริ่มต้นของผู้ให้บริการแทน
     */
    private static function keyModel(array $key, string $model): string
    {
        $model = trim($model);

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
