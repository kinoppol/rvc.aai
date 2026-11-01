<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Paths;
use RuntimeException;

/**
 * กักไฟล์ที่ผลการตรวจบอกว่าน่าสงสัย — ย้ายออกจากที่เดิมไปไว้ storage/quarantine/{เวลา}/ (นอกรากเว็บ)
 * เติม .quarantine ท้ายชื่อให้รันไม่ได้ และเขียน manifest.txt บอกที่มา ไม่ลบทิ้ง ผู้ดูแลตรวจหรือย้ายคืนเองได้
 * กักได้เฉพาะไฟล์ที่ผลการตรวจล่าสุดติดธง quarantine (ไฟล์ที่ไม่ได้มากับ git หรืออยู่ในโฟลเดอร์อัปโหลด)
 */
final class Quarantine
{
    public static function dir(): string
    {
        return Paths::storage('quarantine');
    }

    /** @return string ที่อยู่ใหม่ของไฟล์ (เทียบกับรากโปรเจกต์) */
    public static function move(string $rel): string
    {
        $root = Paths::root();
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '.git/')) {
            throw new RuntimeException('ที่อยู่ไฟล์ไม่ถูกต้อง');
        }
        $real = realpath($root . '/' . $rel);
        $realRoot = realpath($root);
        if ($real === false || $realRoot === false || !is_file($real)
            || !str_starts_with(str_replace('\\', '/', $real), str_replace('\\', '/', $realRoot) . '/')) {
            throw new RuntimeException('ไม่พบไฟล์นี้แล้ว (อาจถูกลบหรือย้ายไปก่อน)');
        }

        $batch = self::dir() . '/' . date('Ymd-His');
        $target = $batch . '/' . $rel . '.quarantine';
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) {
            throw new RuntimeException('สร้างโฟลเดอร์ storage/quarantine ไม่ได้ ตรวจสิทธิ์เขียนของ storage/');
        }
        if (!@rename($real, $target)) {
            throw new RuntimeException('ย้ายไฟล์ไม่ได้ เว็บเซิร์ฟเวอร์อาจไม่มีสิทธิ์แก้ไฟล์นี้ ให้ผู้ดูแลเซิร์ฟเวอร์ย้ายออกเอง');
        }
        @chmod($target, 0400);
        @file_put_contents($batch . '/manifest.txt', date('c') . "\t" . $rel . "\n", FILE_APPEND);
        // ห้ามเว็บเปิดไฟล์ในนี้ แม้ storage/ จะอยู่นอกรากเว็บอยู่แล้ว
        if (!is_file(self::dir() . '/.htaccess')) {
            @file_put_contents(self::dir() . '/.htaccess', "Require all denied\n");
        }

        return 'storage/quarantine/' . basename($batch) . '/' . $rel . '.quarantine';
    }
}
