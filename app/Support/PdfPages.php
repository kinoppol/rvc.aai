<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * แปลงหน้า PDF เป็นภาพสำหรับให้ AI อ่าน (OCR) — ใช้กับไฟล์สแกนที่ไม่มีชั้นข้อความ
 *
 * ลองตามลำดับ: pdftoppm (poppler-utils) → Ghostscript → Imagick → ดึงรูปที่ฝังในหน้าออกมาเอง (PdfText::pageImages)
 * ทางสุดท้ายไม่ต้องติดตั้งอะไรเพิ่มและใช้ได้กับไฟล์สแกนส่วนใหญ่ (รูป JPEG หนึ่งรูปต่อหน้า)
 * แต่อ่านไฟล์สแกนขาวดำแบบ CCITT/JBIG2 ไม่ได้ — ถ้าเจอ ให้ติดตั้ง poppler-utils บนเซิร์ฟเวอร์ (apt install poppler-utils)
 */
final class PdfPages
{
    /** ความละเอียดที่ใช้แปลงหน้า — พอให้อ่านตัวอักษรไทยขนาด 14–16 pt ได้ และไฟล์ไม่ใหญ่เกินไปสำหรับ API */
    public const DPI = 150;

    /** เครื่องมือที่ใช้ได้บนเซิร์ฟเวอร์นี้: pdftoppm | ghostscript | imagick | builtin */
    public static function renderer(): string
    {
        if (self::canExec() && self::binary(['pdftoppm']) !== null) {
            return 'pdftoppm';
        }
        if (self::canExec() && self::binary(['gs', 'gswin64c', 'gswin32c']) !== null) {
            return 'ghostscript';
        }
        if (extension_loaded('imagick')) {
            return 'imagick';
        }

        return 'builtin';
    }

    /** ชื่อเครื่องมือภาษาคนสำหรับแสดงผล */
    public static function rendererLabel(string $renderer): string
    {
        return match ($renderer) {
            'pdftoppm' => 'pdftoppm',
            'ghostscript' => 'Ghostscript',
            'imagick' => 'Imagick',
            default => 'ตัวอ่านรูปในไฟล์ของระบบเอง',
        };
    }

    /**
     * @return list<list<array{mime:string,data:string}>> หน้าละรายการภาพ (หนึ่งภาพต่อหน้าเมื่อแปลงด้วยโปรแกรม)
     * @throws RuntimeException เมื่อไม่มีหน้าที่แปลงเป็นภาพได้เลย
     */
    public static function render(string $file, int $maxPages): array
    {
        $renderer = self::renderer();
        $pages = [];

        if ($renderer !== 'builtin') {
            try {
                $pages = match ($renderer) {
                    'pdftoppm', 'ghostscript' => self::byTool($renderer, $file, $maxPages),
                    'imagick' => self::byImagick($file, $maxPages),
                };
            } catch (\Throwable) {
                $pages = [];   // โปรแกรมล้ม — ลองดึงรูปจากไฟล์เองต่อ
            }
        }

        if ($pages === []) {
            $raw = @file_get_contents($file);
            $pages = $raw === false ? [] : PdfText::pageImages($raw, $maxPages);
        }

        if (array_filter($pages) === []) {
            throw new RuntimeException(
                'แปลงหน้า PDF นี้เป็นภาพไม่ได้ (รูปในไฟล์บีบอัดแบบที่ระบบอ่านเองไม่ได้ และเซิร์ฟเวอร์ไม่มี pdftoppm / Ghostscript / Imagick) '
                . '— ผู้ดูแลระบบติดตั้ง poppler-utils บนเซิร์ฟเวอร์ได้ หรือครูเพิ่มเนื้อหาสำคัญเป็นส่วนข้อความแทน'
            );
        }

        return $pages;
    }

    /** @return list<list<array{mime:string,data:string}>> */
    private static function byTool(string $tool, string $file, int $maxPages): array
    {
        $dir = Paths::storage('cache/ocr-' . bin2hex(random_bytes(6)));
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [];
        }

        try {
            $args = $tool === 'pdftoppm'
                ? [self::binary(['pdftoppm']), '-r', (string) self::DPI, '-jpeg', '-jpegopt', 'quality=85', '-f', '1', '-l', (string) $maxPages, $file, $dir . '/p']
                : [self::binary(['gs', 'gswin64c', 'gswin32c']), '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=jpeg', '-dJPEGQ=85',
                    '-r' . self::DPI, '-dFirstPage=1', '-dLastPage=' . $maxPages, '-sOutputFile=' . $dir . '/p-%04d.jpg', $file];
            exec(implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $code);

            $files = glob($dir . '/p-*.jpg') ?: [];
            natsort($files);
            $pages = [];
            foreach ($files as $f) {
                $pages[] = [['mime' => 'image/jpeg', 'data' => (string) file_get_contents($f)]];
            }

            return $code === 0 || $pages !== [] ? $pages : [];
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** @return list<list<array{mime:string,data:string}>> */
    private static function byImagick(string $file, int $maxPages): array
    {
        $im = new \Imagick();
        $im->setResolution(self::DPI, self::DPI);
        $im->readImage($file . '[0-' . ($maxPages - 1) . ']');
        $pages = [];
        foreach ($im as $page) {
            $page->setImageBackgroundColor('white');
            $page = $page->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $page->setImageFormat('jpeg');
            $page->setImageCompressionQuality(85);
            $pages[] = [['mime' => 'image/jpeg', 'data' => $page->getImageBlob()]];
        }
        $im->clear();

        return $pages;
    }

    private static function canExec(): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return function_exists('exec') && !in_array('exec', $disabled, true);
    }

    /** @param list<string> $names */
    private static function binary(array $names): ?string
    {
        $dirs = array_merge(
            explode(PATH_SEPARATOR, (string) getenv('PATH')),
            ['/usr/bin', '/usr/local/bin', '/opt/homebrew/bin']   // PHP-FPM/Apache มักมี PATH สั้นกว่าเชลล์
        );
        $exts = DIRECTORY_SEPARATOR === '\\' ? ['.exe', ''] : [''];
        foreach ($names as $name) {
            foreach ($dirs as $dir) {
                foreach ($exts as $ext) {
                    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $ext;
                    if ($dir !== '' && @is_file($path) && @is_executable($path)) {
                        return $path;
                    }
                }
            }
        }

        return null;
    }
}
