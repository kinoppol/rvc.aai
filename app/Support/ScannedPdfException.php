<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * PDF ที่ไม่มีชั้นข้อความ (สแกนเป็นรูป) — แยกจากความผิดพลาดอื่นเพื่อให้ส่งต่อไปอ่านด้วย AI (OCR) ได้
 */
final class ScannedPdfException extends RuntimeException
{
}
