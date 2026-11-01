<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AssignmentRepository;
use App\Domain\UnitRepository;
use App\Support\Paths;
use App\Support\PdfPages;
use App\Support\PdfText;
use App\Support\ScannedPdfException;
use App\Support\TextExtract;
use RuntimeException;

/**
 * ฐานความรู้ของหน่วยการเรียนสำหรับผู้ช่วย AI — ให้ออกข้อสอบจากสิ่งที่สอนจริง ไม่เกินเนื้อหา
 *
 * รวมบทนำของหน่วย (สาระสำคัญ จุดประสงค์ สมรรถนะ) ส่วนเนื้อหาแบบข้อความ ข้อความใน PDF และใบงาน เป็น Markdown
 * การอ่าน PDF ช้าที่สุด จึงอ่านครั้งเดียวตอนครูอัปโหลด (prepare()) แล้วเก็บเป็น .md ไว้ที่
 * storage/ai-context/units/{unitId}/section-{id}.md — อ่านไม่ออก (เข้ารหัส/ไฟล์เสีย) เก็บเหตุผลไว้ใน .err ไม่ต้องลองซ้ำทุกครั้ง
 * ไฟล์สแกนเป็นรูปขึ้นคิวไว้ใน .ocr (JSON สถานะ) แล้ว ocr() แปลงหน้าเป็นภาพให้โมเดลอ่านภาพ (บทบาท ocr) ถอดเป็น .md เดียวกัน
 * วีดิโอ/ลิงก์ไม่ได้ดึงเนื้อหามา (ใส่แค่ชื่อและที่อยู่) — ครูสรุปเป็นส่วนข้อความเองถ้าต้องการให้ AI ใช้
 */
final class UnitKnowledge
{
    /** ข้อความจาก PDF หนึ่งไฟล์ที่เก็บไว้ (ก่อนแบ่งงบตามจำนวนหน่วย) */
    private const PDF_CHARS = 40000;

    /** อ่านด้วย OCR ไม่เกินจำนวนหน้านี้ต่อไฟล์ — หน้าละหลายวินาที และงบข้อความต่อหน่วยมีจำกัดอยู่แล้ว */
    public const OCR_PAGES = 30;

    /** สถานะ "กำลังอ่าน" ที่ไม่ขยับนานเกินนี้ (วินาที) ถือว่ากระบวนการตายไปแล้ว */
    private const OCR_STALE = 600;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly AssignmentRepository $assignments,
        private readonly ScanReader $scans,
    ) {
    }

    /**
     * อ่านข้อความของส่วนเนื้อหา PDF แล้วเก็บเป็น .md — เรียกตอนอัปโหลด และเรียกซ้ำได้ (ใช้ของที่เก็บไว้ถ้ายังใหม่กว่าไฟล์)
     * ไฟล์สแกน (ไม่มีชั้นข้อความ) ไม่ถือว่าล้ม แต่ขึ้นคิวรออ่านด้วย AI (state = pending) — ocr() เป็นคนอ่านจริง เพราะช้า
     *
     * @param array<string,mixed> $section แถว unit_sections
     * @return array{ok:bool,chars:int,message:string,state:string,page:int,pages:int}
     *         state: ready | pending (รอ OCR) | running (กำลัง OCR) | failed | none (ไม่ใช่ PDF)
     */
    public function prepare(array $section): array
    {
        if ($section['type'] !== 'pdf' || empty($section['file_path'])) {
            return self::result('none');
        }

        $pdf = Paths::storage('uploads/' . $section['file_path']);
        [$md, $err, $ocr] = $this->cachePaths($section);
        $fresh = static fn (string $f): bool => is_file($f) && (!is_file($pdf) || filemtime($f) >= filemtime($pdf));

        if ($fresh($md)) {
            return self::result('ready', chars: mb_strlen((string) file_get_contents($md)));
        }
        if ($fresh($ocr)) {
            return $this->ocrState($ocr);
        }
        if ($fresh($err)) {
            return self::result('failed', (string) file_get_contents($err));
        }

        $dir = dirname($md);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        try {
            $text = TextExtract::fromFile($pdf, self::PDF_CHARS);
            file_put_contents($md, $text);
            @unlink($err);
            @unlink($ocr);

            return self::result('ready', chars: mb_strlen($text));
        } catch (ScannedPdfException) {
            self::writeState($ocr, ['state' => 'pending']);
            @unlink($err);
            @unlink($md);

            return $this->ocrState($ocr);
        } catch (RuntimeException $e) {
            file_put_contents($err, $e->getMessage());
            @unlink($md);
            @unlink($ocr);

            return self::result('failed', $e->getMessage());
        }
    }

    /**
     * อ่าน PDF สแกนด้วย AI (OCR) แล้วเก็บผลเป็น .md ไฟล์เดียวกับ PDF ปกติ — ช้า (หลายวินาทีต่อหน้า)
     * เรียกจากหน้าแก้ไขหน่วย (POST แยก ไม่ขวางการอัปโหลด) หรือจาก bin/unit-ocr.php
     * ล็อกด้วย flock กันสองคำขออ่านไฟล์เดียวกันพร้อมกัน
     *
     * @param array<string,mixed> $section แถว unit_sections
     * @param int $userId ครูเจ้าของรายวิชา — ใช้เลือกเส้นทาง AI และนับโควตา
     * @param bool $retry อ่านใหม่แม้ครั้งก่อนล้มไปแล้ว
     * @return array{ok:bool,chars:int,message:string,state:string,page:int,pages:int}
     */
    public function ocr(array $section, int $userId, bool $retry = false): array
    {
        $now = $this->prepare($section);
        [$md, $err, $ocr, $lockFile] = $this->cachePaths($section);
        if (!in_array($now['state'], ['pending', 'running', 'failed'], true) || !is_file($ocr)
            || ($now['state'] === 'failed' && !$retry)) {
            return $now;
        }

        $lock = @fopen($lockFile, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $this->ocrState($ocr);   // อีกคำขอกำลังอ่านไฟล์นี้อยู่
        }

        ignore_user_abort(true);
        set_time_limit(0);
        $pdf = Paths::storage('uploads/' . $section['file_path']);

        try {
            self::writeState($ocr, ['state' => 'running', 'page' => 0, 'pages' => 0]);
            $raw = (string) @file_get_contents($pdf);
            if ($raw === '') {
                throw new RuntimeException('ไม่พบไฟล์ PDF ของส่วนนี้');
            }
            $total = PdfText::pageCount($raw);
            $renderer = PdfPages::renderer();
            $pages = PdfPages::render($pdf, self::OCR_PAGES);
            self::writeState($ocr, ['state' => 'running', 'page' => 0, 'pages' => count($pages)]);

            $read = $this->scans->read($userId, $pages, static function (int $done, int $all) use ($ocr): void {
                self::writeState($ocr, ['state' => 'running', 'page' => $done, 'pages' => $all]);
            });

            $text = $read['text'];
            if (preg_match_all('~[\p{L}\p{N}]~u', $text) < 40) {
                throw new RuntimeException('AI อ่านข้อความจากไฟล์นี้ไม่ได้ (ภาพอาจไม่ชัด หรือโมเดล ' . $read['model'] . ' ไม่ได้ตอบเป็นข้อความ)');
            }
            if ($total > count($pages)) {
                $text .= sprintf("\n\n[อ่านด้วย AI เฉพาะ %d หน้าแรกจากทั้งหมด %d หน้า]", count($pages), $total);
            }
            $head = sprintf('<!-- อ่านจากไฟล์สแกนด้วย AI: %s · แปลงหน้าด้วย %s -->', $read['model'] ?: 'ไม่ระบุโมเดล', PdfPages::rendererLabel($renderer));
            $text = $head . "\n" . rtrim(mb_substr($text, 0, self::PDF_CHARS));

            file_put_contents($md, $text);
            @unlink($ocr);
            @unlink($err);

            return self::result('ready', chars: mb_strlen($text));
        } catch (\Throwable $e) {
            // ล้มแล้วเก็บไว้ใน .ocr (ไม่ใช่ .err) เพื่อให้ครูกด "ลองอีกครั้ง" หรือ bin/unit-ocr.php --retry อ่านใหม่ได้
            $message = $e instanceof RuntimeException ? $e->getMessage() : 'อ่านไฟล์ไม่สำเร็จ (' . get_class($e) . ')';
            self::writeState($ocr, ['state' => 'failed', 'message' => $message]);

            return self::result('failed', $message);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockFile);
        }
    }

    /**
     * ส่วนเนื้อหา PDF ที่รอ OCR อยู่ทั้งระบบ (สำหรับ bin/unit-ocr.php) — อ่านจากไฟล์สถานะ ไม่ต้องแตะฐานข้อมูล
     *
     * @return list<array{unit_id:int,section_id:int,state:string}>
     */
    public static function queued(bool $includeFailed): array
    {
        $out = [];
        foreach (glob(Paths::storage('ai-context/units/*/section-*.ocr')) ?: [] as $file) {
            if (!preg_match('~units[/\\\\](\d+)[/\\\\]section-(\d+)\.ocr$~', $file, $m)) {
                continue;
            }
            $state = json_decode((string) @file_get_contents($file), true)['state'] ?? 'pending';
            if ($state !== 'failed' || $includeFailed) {
                $out[] = ['unit_id' => (int) $m[1], 'section_id' => (int) $m[2], 'state' => (string) $state];
            }
        }

        return $out;
    }

    /** ลบข้อความที่เตรียมไว้ของส่วนเนื้อหานี้ (ตอนลบส่วนเนื้อหา) */
    public function forget(array $section): void
    {
        foreach ($this->cachePaths($section) as $f) {
            @unlink($f);
        }
    }

    /**
     * ฐานความรู้ของหลายหน่วยรวมกันไม่เกิน $budget ตัวอักษร — แบ่งงบให้ทุกหน่วยเท่า ๆ กัน หน่วยที่สั้นกว่าส่วนแบ่งยกที่เหลือให้หน่วยอื่น
     *
     * @param list<int> $unitIds
     * @return array{text:string,units:int,pdf_ok:int,pdf_failed:list<string>,pdf_pending:list<string>,videos:int,trimmed:bool}
     */
    public function forUnits(array $unitIds, int $budget): array
    {
        $docs = [];
        $stats = ['units' => 0, 'pdf_ok' => 0, 'pdf_failed' => [], 'pdf_pending' => [], 'videos' => 0, 'trimmed' => false];

        foreach (array_values(array_unique($unitIds)) as $id) {
            $unit = $this->units->find($id);
            if ($unit === null || UnitRepository::isExam($unit)) {
                continue;
            }
            $docs[] = $this->unitMarkdown($unit, $stats);
            $stats['units']++;
        }

        // แบ่งงบ: หน่วยสั้นใช้เท่าที่มี ส่วนที่เหลือเฉลี่ยให้หน่วยที่ยาวกว่า
        $lengths = array_map('mb_strlen', $docs);
        asort($lengths);
        $left = $budget;
        $remaining = count($lengths);
        $limits = [];
        foreach ($lengths as $i => $len) {
            $share = intdiv(max(0, $left), max(1, $remaining));
            $limits[$i] = min($len, $share);
            $left -= $limits[$i];
            $remaining--;
        }

        $parts = [];
        foreach ($docs as $i => $doc) {
            if (mb_strlen($doc) > $limits[$i]) {
                $doc = rtrim(mb_substr($doc, 0, $limits[$i])) . "\n[…ตัดส่วนที่เหลือของหน่วยนี้ออกเพื่อให้พอดีกับโมเดล]";
                $stats['trimmed'] = true;
            }
            $parts[] = $doc;
        }

        return ['text' => trim(implode("\n\n", $parts))] + $stats;
    }

    /**
     * @param array<string,mixed> $unit
     * @param array<string,mixed> $stats
     */
    private function unitMarkdown(array $unit, array &$stats): string
    {
        $md = ['# หน่วย: ' . self::line((string) $unit['title'])];
        foreach (['สาระสำคัญ' => 'key_content', 'จุดประสงค์การเรียนรู้' => 'objectives', 'สมรรถนะประจำหน่วย' => 'competencies'] as $head => $col) {
            $text = trim((string) ($unit[$col] ?? ''));
            if ($text !== '') {
                array_push($md, '', '## ' . $head, $text);
            }
        }

        foreach ($this->units->sectionsFor((int) $unit['id']) as $s) {
            $title = self::line((string) $s['title']);
            if ($s['type'] === 'text') {
                $text = self::plain((string) $s['content']);
                if ($text !== '') {
                    array_push($md, '', '## ' . ($title ?: 'เนื้อหา'), $text);
                }
            } elseif ($s['type'] === 'pdf') {
                // ไฟล์ที่อัปโหลดก่อนมีระบบนี้ จะถูกอ่านครั้งแรกที่นี่แล้วเก็บไว้ · ไฟล์สแกนไม่ OCR ที่นี่ (ช้าเกินไปสำหรับการออกข้อสอบ)
                $ready = $this->prepare($s);
                if ($ready['ok']) {
                    $stats['pdf_ok']++;
                    array_push($md, '', '## ' . ($title ?: 'เอกสารประกอบ') . ' (จากไฟล์ PDF)', (string) file_get_contents($this->cachePaths($s)[0]));
                } elseif (in_array($ready['state'], ['pending', 'running'], true)) {
                    $stats['pdf_pending'][] = $title ?: (string) $s['file_name'];
                } else {
                    $stats['pdf_failed'][] = $title ?: (string) $s['file_name'];
                }
            } elseif ($s['type'] === 'video') {
                $stats['videos']++;
                array_push($md, '', '## ' . ($title ?: 'วีดิโอ') . ' (วีดิโอ — ใช้แค่ชื่อเรื่อง ไม่ได้ดูเนื้อหา)');
            }
        }

        foreach ($this->assignments->forUnit((int) $unit['id']) as $a) {
            $text = self::plain((string) ($a['description'] ?? ''));
            array_push($md, '', '## ใบงาน: ' . self::line((string) $a['title']), $text);
        }

        return implode("\n", $md);
    }

    /** @return array{0:string,1:string,2:string,3:string} [.md, .err, .ocr (สถานะ OCR), .lock] */
    private function cachePaths(array $section): array
    {
        $base = Paths::storage('ai-context/units/' . (int) $section['unit_id'] . '/section-' . (int) $section['id']);

        return [$base . '.md', $base . '.err', $base . '.ocr', $base . '.lock'];
    }

    /** @return array{ok:bool,chars:int,message:string,state:string,page:int,pages:int} */
    private function ocrState(string $file): array
    {
        $s = json_decode((string) @file_get_contents($file), true);
        $s = is_array($s) ? $s : [];
        $state = (string) ($s['state'] ?? 'pending');

        // กำลังอ่านแต่ไฟล์สถานะไม่ขยับนานเกิน (กระบวนการตายกลางทาง) = กลับไปรอคิว
        if ($state === 'running' && time() - (int) @filemtime($file) > self::OCR_STALE) {
            $state = 'pending';
        }
        if (!in_array($state, ['pending', 'running', 'failed'], true)) {
            $state = 'pending';
        }

        return self::result(
            $state,
            $state === 'failed' ? (string) ($s['message'] ?? '') : 'ไฟล์สแกนเป็นรูป — ต้องให้ AI อ่าน (OCR) ก่อน',
            page: (int) ($s['page'] ?? 0),
            pages: (int) ($s['pages'] ?? 0),
        );
    }

    /** @param array<string,mixed> $state */
    private static function writeState(string $file, array $state): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($file, (string) json_encode($state + ['at' => date('c')], JSON_UNESCAPED_UNICODE));
    }

    /** @return array{ok:bool,chars:int,message:string,state:string,page:int,pages:int} */
    private static function result(string $state, string $message = '', int $chars = 0, int $page = 0, int $pages = 0): array
    {
        return ['ok' => $state === 'ready', 'chars' => $chars, 'message' => $message, 'state' => $state, 'page' => $page, 'pages' => $pages];
    }

    private static function line(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** เนื้อหาที่อาจมี HTML → ข้อความล้วน ไม่มีบรรทัดว่างซ้อน */
    private static function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('~<(br|/p|/li|/h\d)\b[^>]*>~i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/u", "\n\n", str_replace(["\r\n", "\r"], "\n", $text)));
    }
}
