<?php

declare(strict_types=1);

namespace App\AI;

use SensitiveParameter;

/**
 * Google AI Studio (Gemini) — สตรีมคำตอบจริงผ่าน streamGenerateContent
 */
final class GoogleAiProvider implements AiProvider
{
    /** ชื่อเล่นที่ Google ชี้ไปยังรุ่น flash ล่าสุดเสมอ — เลี่ยงปัญหารุ่นเก่าถูกปิด */
    public const DEFAULT_MODEL = 'gemini-flash-latest';

    public const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public function __construct(
        #[SensitiveParameter] private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly HttpClient $http = new HttpClient(),
        private readonly string $labelPrefix = 'AI ของฉัน',
    ) {
    }

    public function label(): string
    {
        return $this->labelPrefix . ' · Google AI Studio';
    }

    public function isAvailable(): bool
    {
        return $this->apiKey !== '';
    }

    public function stream(string $systemPrompt, string $userPrompt, string $quality = 'fast'): iterable
    {
        $url = sprintf('%s/models/%s:streamGenerateContent?alt=sse', self::BASE, rawurlencode($this->model));
        $body = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => Prompt::userText($userPrompt)]]]],
            'generationConfig' => [
                'temperature' => $quality === 'quality' ? 0.7 : 0.4,
                'maxOutputTokens' => $quality === 'quality' ? 8192 : 4096,
            ],
        ];

        foreach ($this->http->lines($url, ['x-goog-api-key' => $this->apiKey], $body) as $line) {
            if (!str_starts_with($line, 'data:')) {
                continue;
            }
            $data = json_decode(trim(substr($line, 5)), true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && $part['text'] !== '') {
                    yield (string) $part['text'];
                }
            }
        }
    }

    /**
     * ตรวจว่ารหัสใช้ได้จริงไหม แล้วคืนชื่อโมเดลที่ใช้ได้
     *
     * @return array{ok:bool,message:string,model?:string,models?:list<string>}
     */
    public static function probe(#[SensitiveParameter] string $apiKey, HttpClient $http = new HttpClient()): array
    {
        $res = $http->request('GET', self::BASE . '/models', ['x-goog-api-key' => $apiKey]);
        if ($res['status'] >= 400) {
            return ['ok' => false, 'message' => HttpClient::describeError($res['status'], $res['body'])];
        }

        $models = [];
        foreach (json_decode($res['body'], true)['models'] ?? [] as $m) {
            $name = str_replace('models/', '', (string) ($m['name'] ?? ''));
            if ($name !== '' && in_array('generateContent', $m['supportedGenerationMethods'] ?? [], true)) {
                $models[] = $name;
            }
        }

        if ($models === []) {
            return ['ok' => false, 'message' => 'รหัสใช้ได้ แต่บัญชีนี้ยังไม่มีโมเดลที่เรียกใช้ได้'];
        }

        $model = in_array(self::DEFAULT_MODEL, $models, true) ? self::DEFAULT_MODEL : self::pickFlash($models);

        // บัญชีที่เรียกดูรายชื่อโมเดลได้ อาจยังถูกปิดสิทธิ์สร้างคำตอบ จึงต้องยิงจริงหนึ่งครั้ง
        $trial = $http->request(
            'POST',
            sprintf('%s/models/%s:generateContent', self::BASE, rawurlencode($model)),
            ['x-goog-api-key' => $apiKey],
            [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'ping']]]],
                'generationConfig' => ['maxOutputTokens' => 8],
            ]
        );

        if ($trial['status'] >= 400) {
            return ['ok' => false, 'message' => HttpClient::describeError($trial['status'], $trial['body'])];
        }

        return [
            'ok' => true,
            'message' => 'เชื่อมต่อได้จริง · ทดลองให้ตอบแล้วสำเร็จ · ใช้โมเดล ' . $model,
            'model' => $model,
            'models' => $models,
        ];
    }

    /**
     * เลือกรุ่น flash ที่เป็นข้อความล้วน (ข้ามรุ่นรูปภาพ/เสียง/พรีวิว)
     *
     * @param list<string> $models
     */
    private static function pickFlash(array $models): string
    {
        foreach ($models as $m) {
            if (str_contains($m, 'flash') && !preg_match('/image|tts|audio|preview|embedding/', $m)) {
                return $m;
            }
        }

        return $models[0];
    }
}
