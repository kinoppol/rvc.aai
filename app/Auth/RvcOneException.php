<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/** ข้อผิดพลาดจาก RVC-ONE — ข้อความเป็นภาษาไทยพร้อมแสดงผู้ใช้ · reason: invalid | unavailable | config · error = รหัส error จาก RVC-ONE (ไม่มีข้อมูลลับ) */
final class RvcOneException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly string $error = '')
    {
        parent::__construct($message);
    }
}
