<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;

final class MigrationFile
{
    private ?Migration $instance = null;

    public function __construct(
        public readonly string $name,
        public readonly string $path,
        private readonly string $prefix,
    ) {
    }

    public function instance(): Migration
    {
        if ($this->instance !== null) {
            return $this->instance;
        }

        $before = get_declared_classes();
        require_once $this->path;
        $new = array_diff(get_declared_classes(), $before);

        $class = null;
        foreach ($new as $candidate) {
            if (is_subclass_of($candidate, Migration::class)) {
                $class = $candidate;
                break;
            }
        }

        // ไฟล์เคยถูก require ไปแล้วในรีเควสต์เดียวกัน จึงไม่มีคลาสใหม่โผล่มา — เดาชื่อจากชื่อไฟล์แทน
        $class ??= self::guessClass($this->name);

        if ($class === null || !class_exists($class) || !is_subclass_of($class, Migration::class)) {
            throw new RuntimeException(sprintf('ไฟล์ %s ไม่มีคลาสที่สืบทอดจาก Migration', basename($this->path)));
        }

        return $this->instance = new $class($this->prefix);
    }

    public function description(): string
    {
        return $this->instance()->description();
    }

    /** 2026_09_10_000101_create_core_tables → CreateCoreTables */
    private static function guessClass(string $name): ?string
    {
        if (preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_(.+)$/', $name, $m) !== 1) {
            return null;
        }

        return str_replace(' ', '', ucwords(str_replace('_', ' ', $m[1])));
    }
}
