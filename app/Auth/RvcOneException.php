<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/** ข้อผิดพลาดจาก RVC-ONE — ข้อความเป็นภาษาไทยพร้อมแสดงผู้ใช้ · reason: invalid | unavailable */
final class RvcOneException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
