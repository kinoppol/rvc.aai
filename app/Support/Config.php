<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class Config
{
    private function __construct(private readonly array $items)
    {
    }

    public static function fromArray(array $items): self
    {
        return new self($items);
    }

    public static function load(?string $file = null): self
    {
        $file ??= Paths::configFile();

        if (!is_file($file)) {
            throw new RuntimeException('ยังไม่พบไฟล์ตั้งค่าระบบ กรุณาติดตั้งระบบก่อน');
        }

        $items = require $file;

        if (!is_array($items)) {
            throw new RuntimeException('ไฟล์ตั้งค่าระบบเสียหาย กรุณาติดตั้งระบบใหม่');
        }

        return new self($items);
    }

    public static function isInstalled(): bool
    {
        return is_file(Paths::configFile()) && is_file(Paths::lockFile());
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function all(): array
    {
        return $this->items;
    }

    /**
     * เขียนไฟล์ตั้งค่าเป็น PHP array — เรียกจากตัวติดตั้งเท่านั้น
     */
    public static function write(array $items, ?string $file = null): void
    {
        $file ??= Paths::configFile();
        $export = var_export($items, true);
        $contents = <<<PHP
        <?php

        /**
         * ไฟล์ตั้งค่าระบบ RVC Learn — สร้างโดยตัวติดตั้ง
         * แก้ไขได้ แต่ห้ามนำขึ้น git และควรตั้งสิทธิ์เป็นอ่านอย่างเดียวหลังติดตั้งเสร็จ
         */

        return {$export};

        PHP;

        if (file_put_contents($file, $contents, LOCK_EX) === false) {
            throw new RuntimeException('เขียนไฟล์ตั้งค่าไม่สำเร็จ: ' . $file);
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
