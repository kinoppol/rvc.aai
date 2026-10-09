<?php

declare(strict_types=1);

namespace App\AI;

use RuntimeException;

/**
 * ไม่มีเส้นทาง AI ที่ใช้งานได้ (ทั้งคีย์ของครูและเครื่องของส่วนกลาง)
 * $reason ใช้เลือกข้อความแจ้งเตือนที่หน้าจอ
 */
final class AiUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
