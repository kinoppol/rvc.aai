<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ผู้ให้บริการ AI — เครื่องของส่วนกลาง (OllamaProvider / OpenAiCompatibleProvider)
 * คีย์ของครู (GoogleAiProvider / OpenAiCompatibleProvider) และ SimulatedProvider สำหรับโหมดจำลอง
 */
interface AiProvider
{
    /**
     * สตรีมคำตอบเป็นก้อนข้อความทีละก้อน (generator ของ string)
     *
     * @param 'fast'|'quality' $quality โหมดคุณภาพ
     * @return iterable<string>
     */
    public function stream(string $systemPrompt, string $userPrompt, string $quality = 'fast'): iterable;

    /** ชื่อที่ครูเห็น เช่น "AI ของส่วนกลาง" หรือ "AI ของฉัน · Gemini Flash" */
    public function label(): string;

    /** ตรวจว่าพร้อมใช้งานหรือไม่ (เช็คคิว/สถานะ) */
    public function isAvailable(): bool;
}
