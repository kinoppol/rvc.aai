<?php

declare(strict_types=1);

namespace App\Install;

use App\Support\Paths;

/**
 * ตรวจความพร้อมของเซิร์ฟเวอร์ก่อนติดตั้ง
 * ทุกข้อความเป็นภาษาไทย และข้อที่ไม่ผ่านต้องบอกวิธีแก้เสมอ
 */
final class Requirements
{
    public const MIN_PHP = '8.1.0';

    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql' => 'เชื่อมต่อฐานข้อมูล MariaDB',
        'mbstring' => 'จัดการข้อความภาษาไทย',
        'json' => 'อ่านและเขียนข้อมูลรูปแบบ JSON',
        'openssl' => 'เข้ารหัสคีย์และสร้างค่าสุ่มที่ปลอดภัย',
        'fileinfo' => 'ตรวจชนิดไฟล์ที่อัปโหลด',
        'session' => 'จำสถานะการเข้าสู่ระบบ',
    ];

    private const OPTIONAL_EXTENSIONS = [
        'curl' => 'เรียกใช้ผู้ช่วย AI ผ่านเครือข่าย',
        'gd' => 'ย่อรูปภาพประกอบบทเรียน',
        'zip' => 'ส่งออกเอกสารและนำเข้าไฟล์เป็นชุด',
        'intl' => 'จัดรูปแบบวันที่และตัวเลขแบบไทย',
    ];

    /** @return array{php:list<array>,extensions:list<array>,optional:list<array>,writable:list<array>,server:list<array>} */
    public static function all(): array
    {
        return [
            'php' => self::phpChecks(),
            'extensions' => self::extensionChecks(),
            'optional' => self::optionalChecks(),
            'writable' => self::writableChecks(),
            'server' => self::serverChecks(),
        ];
    }

    /** ผ่านครบทุกข้อบังคับหรือยัง (ข้อแนะนำไม่นับ) */
    public static function passes(): bool
    {
        $groups = self::all();
        foreach ([...$groups['php'], ...$groups['extensions'], ...$groups['writable']] as $check) {
            if (!$check['ok']) {
                return false;
            }
        }

        return true;
    }

    private static function phpChecks(): array
    {
        $checks = [[
            'label' => 'เวอร์ชัน PHP',
            'value' => PHP_VERSION,
            'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'hint' => 'ต้องการ PHP ' . self::MIN_PHP . ' ขึ้นไป กรุณาอัปเกรด PHP ของเซิร์ฟเวอร์',
        ]];

        $autoload = Paths::vendorAutoload();
        $checks[] = [
            'label' => 'ไลบรารีที่ติดตั้งด้วย Composer',
            'value' => is_file($autoload) ? 'ติดตั้งแล้ว' : 'ยังไม่ได้ติดตั้ง',
            'ok' => is_file($autoload),
            'hint' => 'เปิด Command Prompt ที่โฟลเดอร์ของระบบแล้วสั่ง  composer install',
        ];

        return $checks;
    }

    private static function extensionChecks(): array
    {
        $checks = [];
        foreach (self::REQUIRED_EXTENSIONS as $name => $purpose) {
            $loaded = extension_loaded($name);
            $checks[] = [
                'label' => $name,
                'value' => $loaded ? 'เปิดใช้งานแล้ว' : 'ยังไม่เปิดใช้งาน',
                'ok' => $loaded,
                'hint' => sprintf('จำเป็นสำหรับ%s — เปิดใช้ใน php.ini ด้วยการลบเครื่องหมาย ; หน้าบรรทัด extension=%s แล้วรีสตาร์ท Apache', $purpose, $name),
            ];
        }

        return $checks;
    }

    private static function optionalChecks(): array
    {
        $checks = [];
        foreach (self::OPTIONAL_EXTENSIONS as $name => $purpose) {
            $loaded = extension_loaded($name);
            $checks[] = [
                'label' => $name,
                'value' => $loaded ? 'เปิดใช้งานแล้ว' : 'ยังไม่เปิดใช้งาน',
                'ok' => $loaded,
                'hint' => sprintf('ไม่บังคับ ใช้สำหรับ%s ระบบยังติดตั้งต่อได้', $purpose),
            ];
        }

        return $checks;
    }

    private static function writableChecks(): array
    {
        $targets = [
            Paths::config() => 'โฟลเดอร์ config (เก็บไฟล์ตั้งค่าระบบ)',
            Paths::storage() => 'โฟลเดอร์ storage',
            Paths::storage('logs') => 'โฟลเดอร์ storage/logs (บันทึกการทำงาน)',
            Paths::storage('cache') => 'โฟลเดอร์ storage/cache (แคชหน้าเว็บ)',
            Paths::storage('uploads') => 'โฟลเดอร์ storage/uploads (ไฟล์แนบ)',
        ];

        $checks = [];
        foreach ($targets as $path => $label) {
            $ok = self::canWrite($path);
            $checks[] = [
                'label' => $label,
                'value' => $ok ? 'เขียนได้' : 'เขียนไม่ได้',
                'ok' => $ok,
                'hint' => self::permissionHint($path),
            ];
        }

        return $checks;
    }

    private static function serverChecks(): array
    {
        $rewrite = function_exists('apache_get_modules')
            ? in_array('mod_rewrite', apache_get_modules(), true)
            : null;

        return [
            [
                'label' => 'การเขียน URL ใหม่ (mod_rewrite)',
                'value' => match ($rewrite) {
                    true => 'เปิดใช้งานแล้ว',
                    false => 'ยังไม่เปิดใช้งาน',
                    null => 'ตรวจสอบอัตโนมัติไม่ได้',
                },
                'ok' => $rewrite !== false,
                'hint' => 'ถ้าปิดอยู่ ระบบยังใช้ได้แต่ต้องเข้าผ่าน .../public/ กรุณาเปิด mod_rewrite ใน httpd.conf',
            ],
            [
                'label' => 'เวลาประมวลผลสูงสุด (max_execution_time)',
                'value' => (string) ini_get('max_execution_time') . ' วินาที',
                'ok' => (int) ini_get('max_execution_time') === 0 || (int) ini_get('max_execution_time') >= 60,
                'hint' => 'แนะนำอย่างน้อย 60 วินาที เพราะการปรับปรุงฐานข้อมูลและงาน AI ใช้เวลานาน',
            ],
            [
                'label' => 'หน่วยความจำสูงสุด (memory_limit)',
                'value' => (string) ini_get('memory_limit'),
                'ok' => self::bytes((string) ini_get('memory_limit')) < 0 || self::bytes((string) ini_get('memory_limit')) >= 128 * 1024 * 1024,
                'hint' => 'แนะนำอย่างน้อย 128M',
            ],
            [
                'label' => 'ขนาดไฟล์อัปโหลดสูงสุด (upload_max_filesize)',
                'value' => (string) ini_get('upload_max_filesize'),
                'ok' => self::bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024,
                'hint' => 'แนะนำอย่างน้อย 8M สำหรับไฟล์แนบบทเรียน',
            ],
        ];
    }

    /** ทดสอบด้วยการเขียนไฟล์จริง เพราะ is_writable() บน Windows เชื่อถือไม่ได้ */
    private static function canWrite(string $dir): bool
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $probe = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '.write-test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            return false;
        }

        @unlink($probe);

        return true;
    }

    private static function permissionHint(string $path): string
    {
        return stripos(PHP_OS_FAMILY, 'Windows') !== false
            ? sprintf('สั่งใน Command Prompt แบบผู้ดูแล:  icacls "%s" /grant Users:(OI)(CI)M', $path)
            : sprintf('สั่งใน Terminal:  chmod -R 775 "%s"', $path);
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
