<?php

declare(strict_types=1);

namespace App\Integration;

use RuntimeException;

/** ดึงข้อมูลจาก RMS ไม่สำเร็จ — ข้อความเป็นภาษาไทย แสดงให้ผู้ดูแลเห็นได้ตรง ๆ */
final class RmsException extends RuntimeException
{
}
