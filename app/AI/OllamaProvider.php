<?php

declare(strict_types=1);

namespace App\AI;

/**
 * เครื่อง AI ของส่วนกลาง (Ollama) — สตรีมจริงผ่าน /api/chat แบบ NDJSON
 */
final class OllamaProvider implements AiProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly HttpClient $http = new HttpClient(),
    ) {
    }

    public function label(): string
    {
        return 'AI ของส่วนกลาง';
    }

    /** เช็คว่าเครื่องตอบอยู่จริงไหม ใช้ timeout สั้น ๆ เพื่อไม่ให้หน้าเว็บค้าง */
    public function isAvailable(): bool
    {
        if ($this->baseUrl === '') {
            return false;
        }

        try {
            $probe = new HttpClient(connectTimeout: 2, timeout: 4);

            return $probe->request('GET', rtrim($this->baseUrl, '/') . '/api/tags')['status'] < 400;
        } catch (AiUnavailableException) {
            return false;
        }
    }

    public function stream(string $systemPrompt, string $userPrompt, string $quality = 'fast'): iterable
    {
        $body = [
            'model' => $this->model,
            'stream' => true,
            'options' => ['temperature' => $quality === 'quality' ? 0.7 : 0.4],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => Prompt::userText($userPrompt)],
            ],
        ];

        foreach ($this->http->lines(rtrim($this->baseUrl, '/') . '/api/chat', [], $body) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $data = json_decode($line, true);
            $text = $data['message']['content'] ?? null;
            if (is_string($text) && $text !== '') {
                yield $text;
            }
        }
    }
}
