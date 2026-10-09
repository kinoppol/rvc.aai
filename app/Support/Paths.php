<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ตำแหน่งไฟล์สำคัญของระบบ อ้างอิงจากที่อยู่ของไฟล์นี้เอง
 * เพื่อให้ install.php เรียกใช้ได้ก่อนที่ระบบจะถูกตั้งค่า
 */
final class Paths
{
    public static function root(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 2));
    }

    public static function config(string $sub = ''): string
    {
        return self::join(self::root() . '/config', $sub);
    }

    public static function configFile(): string
    {
        return self::config('config.php');
    }

    public static function storage(string $sub = ''): string
    {
        return self::join(self::root() . '/storage', $sub);
    }

    public static function lockFile(): string
    {
        return self::storage('install.lock');
    }

    public static function migrations(): string
    {
        return self::root() . '/database/migrations';
    }

    public static function views(): string
    {
        return self::root() . '/resources/views';
    }

    public static function vendorAutoload(): string
    {
        return self::root() . '/vendor/autoload.php';
    }

    private static function join(string $base, string $sub): string
    {
        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }
}
