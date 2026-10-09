<?php

declare(strict_types=1);

namespace App\Support;

final class Url
{
    private static string $base = '';

    public static function setBase(string $base): void
    {
        self::$base = rtrim($base, '/');
    }

    public static function base(): string
    {
        return self::$base;
    }

    public static function to(string $path = '/'): string
    {
        return self::$base . '/' . ltrim($path, '/');
    }
}
