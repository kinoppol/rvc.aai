<?php

declare(strict_types=1);

namespace App\Security;

/**
 * อ่านรายชื่อไฟล์ที่ git ติดตามพร้อม hash จาก .git/index โดยตรง (ไม่ต้องเรียกคำสั่ง git บนเซิร์ฟเวอร์)
 * การ deploy ทำ git reset --hard ทุกครั้ง index จึงตรงกับโค้ดที่ส่งขึ้นมาจริง
 * รองรับ index รุ่น 2 และ 3 (รุ่น 4 บีบชื่อไฟล์ — คืน null แล้วให้ผู้เรียกข้ามการเทียบไปเลย)
 */
final class GitIndex
{
    /**
     * @return array<string,string>|null path (ใช้ / คั่น) => sha1 ของ blob · null = อ่านไม่ได้
     */
    public static function read(string $root): ?array
    {
        $file = $root . '/.git/index';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $data = (string) file_get_contents($file);
        if (strlen($data) < 12 || substr($data, 0, 4) !== 'DIRC') {
            return null;
        }
        $version = unpack('N', substr($data, 4, 4))[1];
        if ($version !== 2 && $version !== 3) {
            return null;
        }
        $count = unpack('N', substr($data, 8, 4))[1];

        $files = [];
        $pos = 12;
        $len = strlen($data);
        for ($i = 0; $i < $count; $i++) {
            if ($pos + 62 > $len) {
                return null;
            }
            $start = $pos;
            $sha = bin2hex(substr($data, $pos + 40, 20));
            $flags = unpack('n', substr($data, $pos + 60, 2))[1];
            $pos += 62;
            if ($version === 3 && ($flags & 0x4000)) {
                $pos += 2; // extended flags
            }
            $end = strpos($data, "\0", $pos);
            if ($end === false) {
                return null;
            }
            $path = substr($data, $pos, $end - $pos);
            // แต่ละรายการเติม NUL ให้ยาวเป็นพหุคูณของ 8 ไบต์
            $entryLen = $end - $start + 1;
            $pos = $start + (int) (ceil($entryLen / 8) * 8);
            // stage > 0 = ไฟล์ที่ merge ค้าง ไม่ใช่สถานะปกติ
            if ((($flags >> 12) & 0x3) === 0) {
                $files[$path] = $sha;
            }
        }

        return $files;
    }

    /** hash แบบเดียวกับ git hash-object */
    public static function blobHash(string $file): ?string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }

        return sha1('blob ' . strlen($content) . "\0" . $content);
    }
}
