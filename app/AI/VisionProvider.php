<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ผู้ให้บริการที่ส่งภาพไปให้โมเดลอ่านได้ (OCR / โมเดลมองเห็นภาพ) — ใช้อ่านหน้าเอกสารสแกน
 * โมเดลที่ไม่รับภาพจะตอบผิดพลาดกลับมาเป็น AiUnavailableException ตามปกติ
 */
interface VisionProvider
{
    /**
     * @param list<array{mime:string,data:string}> $images ภาพของหน้าเดียวกัน (ไบต์จริง ยังไม่เข้ารหัส base64)
     * @return iterable<string> ข้อความที่อ่านได้ ทยอยมาทีละก้อน
     */
    public function readImages(string $instruction, array $images): iterable;
}
