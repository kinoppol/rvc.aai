<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AiRepository;
use App\Domain\SettingsRepository;

/**
 * อ่านภาพหน้าเอกสาร (สแกน/รูปถ่าย) ให้เป็นข้อความด้วยโมเดลที่มีบทบาท ocr — ทีละหน้า
 *
 * เส้นทาง AI ผ่าน AiRouter (task 'ocr'): ของส่วนกลางก่อนโดยตรวจโควตา หมดโควตาหรือเครื่องปิดแล้วสลับไปคีย์ของครู
 * ไฟล์หนึ่งไฟล์นับโควตาส่วนกลาง 1 ครั้ง (ไม่ใช่หน้าละครั้ง) และใช้โมเดลตัวเดียวทั้งไฟล์
 */
final class ScanReader
{
    private const INSTRUCTION = <<<TXT
        ถอดข้อความทั้งหมดในภาพหน้าเอกสารนี้ออกมาตามลำดับการอ่าน (ภาษาไทยและภาษาอังกฤษตามต้นฉบับ)
        - ตอบเป็นข้อความ Markdown: หัวข้อใช้ #, รายการใช้ -, ตารางใช้ตาราง Markdown
        - ห้ามแปล ห้ามสรุป ห้ามอธิบายภาพ และห้ามเติมข้อความที่ไม่มีในภาพ
        - ถ้าในภาพไม่มีข้อความเลย ตอบว่างเปล่า
        TXT;

    public function __construct(
        private readonly AiRouter $router,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * @param list<list<array{mime:string,data:string}>> $pages ภาพของแต่ละหน้า
     * @param callable(int,int):void|null $progress เรียกหลังอ่านแต่ละหน้า (หน้าที่อ่านแล้ว, ทั้งหมด)
     * @return array{text:string,model:string,source:string,pages:int,failed:int}
     * @throws AiUnavailableException เมื่อไม่มีโมเดลอ่านภาพที่ใช้ได้ หรือหน้าแรกอ่านไม่ได้เลย (โมเดลไม่รับภาพ/รหัสผิด)
     */
    public function read(int $userId, array $pages, ?callable $progress = null): array
    {
        $route = $this->router->route($userId, 'fast', true, real: true, task: 'ocr', prefer: 'college');
        $provider = $route->provider;
        if (!$provider instanceof VisionProvider) {
            throw new AiUnavailableException('no_model', 'AI ที่เลือกได้ส่งภาพให้อ่านไม่ได้');
        }
        if ($route->countsQuota) {
            $this->ai->consumeQuota($userId, sprintf('%04d-%02d', $this->settings->int('academic_year', (int) date('Y')), (int) date('n')), $this->settings->int('ai_monthly_quota', 60));
        }

        $out = [];
        $failed = 0;
        $total = count($pages);
        foreach ($pages as $i => $images) {
            $text = '';
            if ($images !== []) {
                try {
                    foreach ($provider->readImages(self::INSTRUCTION, $images) as $chunk) {
                        $text .= $chunk;
                    }
                } catch (AiUnavailableException $e) {
                    // หน้าแรกล้ม = โมเดลนี้ใช้ไม่ได้ทั้งไฟล์ (ไม่รับภาพ รหัสผิด) — หยุดเลย ไม่ต้องลองทุกหน้า
                    if ($out === [] && $failed === 0) {
                        $this->ai->logUsage($userId, null, $route->source, $route->model, false, 'ocr');

                        throw $e;
                    }
                    $failed++;
                }
            } else {
                $failed++;
            }

            $text = self::clean($text);
            $out[] = '<!-- หน้า ' . ($i + 1) . ' -->' . "\n" . ($text !== '' ? $text : '[หน้านี้อ่านข้อความไม่ได้]');
            if ($progress !== null) {
                $progress($i + 1, $total);
            }
        }

        $this->ai->logUsage($userId, null, $route->source, $route->model, $failed < $total, 'ocr');

        return [
            'text' => implode("\n\n", $out),
            'model' => $route->model,
            'source' => $route->source,
            'pages' => $total,
            'failed' => $failed,
        ];
    }

    /** ตัดส่วนที่ไม่ใช่เนื้อหา: บล็อกคิดของโมเดลแบบ reasoning และรั้วโค้ดที่ครอบคำตอบทั้งหมด */
    private static function clean(string $text): string
    {
        $text = (string) preg_replace('~<think>.*?</think>~s', '', $text);
        $text = trim($text);
        if (preg_match('~^```[a-z]*\s*\n(.*)\n```$~s', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        return $text;
    }
}
