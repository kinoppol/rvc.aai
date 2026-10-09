<?php

declare(strict_types=1);

namespace App\Migration;

/**
 * ตัวรับคำสั่ง SQL ของ migration — มีสองแบบคือรันจริง (LiveRunner)
 * และเก็บคำสั่งไว้ดูอย่างเดียวโดยไม่แตะฐานข้อมูล (DryRunner)
 */
interface Runner
{
    public function exec(string $sql): void;
}
