<?php

declare(strict_types=1);

namespace App\AI;

use SensitiveParameter;

/**
 * บริการที่ใช้หน้าตา API แบบ OpenAI (OpenRouter, vLLM, LM Studio, ฯลฯ)
 * สตรีมจริงผ่าน /chat/completions แบบ stream: true
 */
final class OpenAiCompatibleProvider implements AiProvider
{
    public const OPENROUTER_BASE = 'https://openrouter.ai/api/v1';
    public const OPENROUTER_DEFAULT_MODEL = 'openrouter/auto';

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $name = 'บริการของฉัน',
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $labelPrefix = 'AI ของฉัน',
    ) {
    }

    public function label(): string
    {
        return $this->name === '' ? $this->labelPrefix : $this->labelPrefix . ' · ' . $this->name;
    }

    public function isAvailable(): bool
    {
        // บริการที่ติดตั้งเองอาจไม่ต้องใช้รหัส จึงเช็คแค่ว่ามีที่อยู่ปลายทาง
        return $this->baseUrl !== '';
    }

    public function stream(string $systemPrompt, string $userPrompt, string $quality = 'fast'): iterable
    {
        // ไม่ส่ง max_tokens: เซิร์ฟเวอร์ที่ติดตั้งเอง (LM Studio/vLLM) จะจองหน่วยความจำตามค่านี้
        // ทำให้ตอบช้าลงมาก (วัดได้ 43 วินาที เทียบกับ 2 วินาที เมื่อปล่อยให้ปลายทางกำหนดเอง)
        $body = [
            'model' => $this->model,
            'stream' => true,
            'temperature' => $quality === 'quality' ? 0.7 : 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => Prompt::userText($userPrompt)],
            ],
        ];

        foreach ($this->http->lines($this->url('/chat/completions'), $this->headers(), $body) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $payload = trim(substr($line, 5));
            if ($payload === '' || $payload === '[DONE]') {
                continue;
            }
            $data = json_decode($payload, true);
            $text = $data['choices'][0]['delta']['content'] ?? null;
            if (is_string($text) && $text !== '') {
                yield $text;
            }
        }
    }

    /**
     * ตรวจรหัสจริงด้วยการขอรายชื่อโมเดล
     *
     * @return array{ok:bool,message:string,model?:string,models?:list<string>}
     */
    /**
     * @param bool $verifyKey ยิงขอคำตอบสั้น ๆ หนึ่งครั้งเพื่อพิสูจน์ว่ารหัสใช้ได้จริง
     *                        (บางบริการเช่น OpenRouter เปิดให้ดูรายชื่อโมเดลได้โดยไม่ต้องใช้รหัส)
     */
    public static function probe(
        #[SensitiveParameter] string $apiKey,
        string $baseUrl,
        ?string $preferred = null,
        HttpClient $http = new HttpClient(),
        bool $verifyKey = false,
    ): array {
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '' || !preg_match('~^https?://~i', $baseUrl)) {
            return ['ok' => false, 'message' => 'ที่อยู่ของบริการไม่ถูกต้อง ต้องขึ้นต้นด้วย http:// หรือ https://'];
        }

        $res = $http->request('GET', $baseUrl . '/models', ['Authorization' => 'Bearer ' . $apiKey]);
        if ($res['status'] >= 400) {
            return ['ok' => false, 'message' => HttpClient::describeError($res['status'], $res['body'])];
        }

        $models = [];
        foreach (json_decode($res['body'], true)['data'] ?? [] as $m) {
            if (isset($m['id'])) {
                $models[] = (string) $m['id'];
            }
        }

        if ($models === [] && $preferred === null) {
            return ['ok' => false, 'message' => 'รหัสใช้ได้ แต่บริการนี้ไม่ได้บอกรายชื่อโมเดลที่ใช้ได้'];
        }

        $model = $preferred !== null && ($models === [] || in_array($preferred, $models, true))
            ? $preferred
            : $models[0];

        if ($verifyKey) {
            $trial = $http->request('POST', $baseUrl . '/chat/completions', ['Authorization' => 'Bearer ' . $apiKey], [
                'model' => $model,
                'max_tokens' => 1,
                'messages' => [['role' => 'user', 'content' => 'ping']],
            ]);

            if ($trial['status'] >= 400) {
                return ['ok' => false, 'message' => HttpClient::describeError($trial['status'], $trial['body'])];
            }
        }

        return [
            'ok' => true,
            'message' => 'เชื่อมต่อได้จริง · พบโมเดลที่ใช้ได้ ' . max(count($models), 1) . ' รายการ'
                . ($verifyKey ? ' · ทดลองให้ตอบแล้วสำเร็จ' : ''),
            'model' => $model,
            'models' => $models ?: [$model],
        ];
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . $path;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey];

        // OpenRouter ขอให้ระบุที่มาของแอปเพื่อจัดอันดับการใช้งาน
        if (str_contains($this->baseUrl, 'openrouter.ai')) {
            $headers['HTTP-Referer'] = 'https://rvc.ac.th';
            $headers['X-Title'] = 'RVC Learn';
        }

        return $headers;
    }
}
