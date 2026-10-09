<?php

declare(strict_types=1);

namespace App\Support;

use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use ZipArchive;

/**
 * ดึงข้อความออกจากไฟล์ที่ครูแนบมาในช่องสนทนา เพื่อส่งเป็นบริบทให้ผู้ช่วย AI
 *
 * รองรับไฟล์ข้อความทั่วไปและ .docx (เปิดด้วย ZipArchive) ส่วน PDF อ่านแบบดีที่สุดเท่าที่ทำได้
 * โดยไม่ต้องพึ่งไลบรารีเพิ่ม — ถ้าอ่านไม่ออกจะบอกครูตรง ๆ ให้แนบไฟล์แบบอื่นแทน
 */
final class TextExtract
{
    /** ชนิดไฟล์ที่รับ (นามสกุล => คำอธิบายสำหรับข้อความแจ้งเตือน) */
    public const ALLOWED = [
        'txt' => 'ข้อความ',
        'md' => 'ข้อความ',
        'csv' => 'ตาราง',
        'json' => 'ข้อมูล',
        'xml' => 'ข้อมูล',
        'html' => 'เว็บ',
        'htm' => 'เว็บ',
        'docx' => 'เอกสาร Word',
        'pdf' => 'เอกสาร PDF',
    ];

    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_CHARS = 20000;

    /** @throws RuntimeException เมื่อไฟล์ใช้ไม่ได้หรืออ่านข้อความไม่ออก */
    public static function fromUpload(UploadedFileInterface $file): string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('อัปโหลดไฟล์ไม่สำเร็จ กรุณาลองใหม่');
        }
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('ไฟล์ใหญ่เกิน 2 MB กรุณาย่อไฟล์หรือคัดลอกเฉพาะส่วนที่ต้องการ');
        }

        $name = (string) $file->getClientFilename();
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (!array_key_exists($ext, self::ALLOWED)) {
            throw new RuntimeException('ยังไม่รองรับไฟล์ .' . $ext . ' · รองรับ ' . implode(', ', array_keys(self::ALLOWED)));
        }

        $raw = (string) $file->getStream();

        $text = match ($ext) {
            'docx' => self::fromDocx($raw),
            'pdf' => self::fromPdf($raw),
            'html', 'htm' => self::fromHtml($raw),
            default => $raw,
        };

        return self::tidy($text, $ext);
    }

    /** .docx คือไฟล์ zip — ข้อความอยู่ใน word/document.xml */
    private static function fromDocx(string $raw): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rvcdocx');
        if ($tmp === false) {
            throw new RuntimeException('อ่านไฟล์เอกสารไม่ได้ในตอนนี้');
        }

        try {
            file_put_contents($tmp, $raw);

            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('เปิดไฟล์ Word ไม่ได้ · ไฟล์อาจเสียหาย');
            }

            $xml = (string) $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === '') {
                throw new RuntimeException('ไม่พบเนื้อหาในไฟล์ Word นี้');
            }

            // แปลงย่อหน้าและการขึ้นบรรทัดให้เป็นข้อความธรรมดา
            $xml = preg_replace('~<w:(?:p|br|tab)[^>]*/?>~', "\n", $xml) ?? $xml;

            return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * อ่าน PDF แบบพื้นฐาน: คลายสตรีมที่บีบด้วย Flate แล้วเก็บข้อความในคำสั่งวาดตัวอักษร
     * ใช้ได้กับ PDF ที่สร้างจากโปรแกรมเอกสารทั่วไป แต่อ่านไฟล์สแกนเป็นรูปไม่ได้
     */
    private static function fromPdf(string $raw): string
    {
        $text = '';

        if (preg_match_all('~stream\r?\n(.*?)\r?\nendstream~s', $raw, $streams)) {
            foreach ($streams[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if ($decoded === false) {
                    $decoded = $stream;
                }

                // เก็บข้อความในวงเล็บของคำสั่ง Tj / TJ
                if (preg_match_all('~\(((?:\\\\.|[^\\\\()])*)\)~s', $decoded, $chunks)) {
                    foreach ($chunks[1] as $chunk) {
                        $text .= stripcslashes($chunk);
                    }
                    $text .= "\n";
                }
            }
        }

        if (mb_strlen(trim($text)) < 40) {
            throw new RuntimeException(
                'อ่านข้อความจาก PDF นี้ไม่ได้ (อาจเป็นไฟล์สแกนเป็นรูป) '
                . 'กรุณาบันทึกเป็น .docx หรือ .txt แล้วแนบใหม่'
            );
        }

        return $text;
    }

    private static function fromHtml(string $raw): string
    {
        $raw = preg_replace('~<(script|style)[^>]*>.*?</\1>~is', ' ', $raw) ?? $raw;
        $raw = preg_replace('~<(br|/p|/div|/tr|/li|/h[1-6])[^>]*>~i', "\n", $raw) ?? $raw;

        return html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** จัดข้อความให้สะอาด ตัดความยาว และตรวจว่าอ่านได้จริง */
    private static function tidy(string $text, string $ext): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'TIS-620, Windows-874, ISO-8859-11, UTF-8');
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('~[ \t]+~u', ' ', $text) ?? $text;
        $text = preg_replace('~\n{3,}~u', "\n\n", $text) ?? $text;
        $text = trim($text);

        if ($text === '') {
            throw new RuntimeException('ไม่พบข้อความในไฟล์ .' . $ext . ' นี้');
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS) . "\n\n[ตัดเนื้อหาส่วนที่เกินออก]";
        }

        return $text;
    }
}
