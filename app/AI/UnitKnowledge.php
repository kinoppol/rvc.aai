<?php

declare(strict_types=1);

namespace App\AI;

use App\Domain\AssignmentRepository;
use App\Domain\UnitRepository;
use App\Support\Paths;
use App\Support\TextExtract;
use RuntimeException;

/**
 * ฐานความรู้ของหน่วยการเรียนสำหรับผู้ช่วย AI — ให้ออกข้อสอบจากสิ่งที่สอนจริง ไม่เกินเนื้อหา
 *
 * รวมบทนำของหน่วย (สาระสำคัญ จุดประสงค์ สมรรถนะ) ส่วนเนื้อหาแบบข้อความ ข้อความใน PDF และใบงาน เป็น Markdown
 * การอ่าน PDF ช้าที่สุด จึงอ่านครั้งเดียวตอนครูอัปโหลด (prepare()) แล้วเก็บเป็น .md ไว้ที่
 * storage/ai-context/units/{unitId}/section-{id}.md — อ่านไม่ออก (ไฟล์สแกน) เก็บเหตุผลไว้ใน .err ไม่ต้องลองซ้ำทุกครั้ง
 * วีดิโอ/ลิงก์ไม่ได้ดึงเนื้อหามา (ใส่แค่ชื่อและที่อยู่) — ครูสรุปเป็นส่วนข้อความเองถ้าต้องการให้ AI ใช้
 */
final class UnitKnowledge
{
    /** ข้อความจาก PDF หนึ่งไฟล์ที่เก็บไว้ (ก่อนแบ่งงบตามจำนวนหน่วย) */
    private const PDF_CHARS = 40000;

    public function __construct(
        private readonly UnitRepository $units,
        private readonly AssignmentRepository $assignments,
    ) {
    }

    /**
     * อ่านข้อความของส่วนเนื้อหา PDF แล้วเก็บเป็น .md — เรียกตอนอัปโหลด และเรียกซ้ำได้ (ใช้ของที่เก็บไว้ถ้ายังใหม่กว่าไฟล์)
     *
     * @param array<string,mixed> $section แถว unit_sections
     * @return array{ok:bool,chars:int,message:string}
     */
    public function prepare(array $section): array
    {
        if ($section['type'] !== 'pdf' || empty($section['file_path'])) {
            return ['ok' => false, 'chars' => 0, 'message' => ''];
        }

        $pdf = Paths::storage('uploads/' . $section['file_path']);
        [$md, $err] = $this->cachePaths($section);
        $fresh = static fn (string $f): bool => is_file($f) && (!is_file($pdf) || filemtime($f) >= filemtime($pdf));

        if ($fresh($md)) {
            return ['ok' => true, 'chars' => mb_strlen((string) file_get_contents($md)), 'message' => ''];
        }
        if ($fresh($err)) {
            return ['ok' => false, 'chars' => 0, 'message' => (string) file_get_contents($err)];
        }

        $dir = dirname($md);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        try {
            $text = TextExtract::fromFile($pdf, self::PDF_CHARS);
            file_put_contents($md, $text);
            @unlink($err);

            return ['ok' => true, 'chars' => mb_strlen($text), 'message' => ''];
        } catch (RuntimeException $e) {
            file_put_contents($err, $e->getMessage());
            @unlink($md);

            return ['ok' => false, 'chars' => 0, 'message' => $e->getMessage()];
        }
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
     * @return array{text:string,units:int,pdf_ok:int,pdf_failed:list<string>,videos:int,trimmed:bool}
     */
    public function forUnits(array $unitIds, int $budget): array
    {
        $docs = [];
        $stats = ['units' => 0, 'pdf_ok' => 0, 'pdf_failed' => [], 'videos' => 0, 'trimmed' => false];

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
                $ready = $this->prepare($s);   // ไฟล์ที่อัปโหลดก่อนมีระบบนี้ จะถูกอ่านครั้งแรกที่นี่แล้วเก็บไว้
                if ($ready['ok']) {
                    $stats['pdf_ok']++;
                    array_push($md, '', '## ' . ($title ?: 'เอกสารประกอบ') . ' (จากไฟล์ PDF)', (string) file_get_contents($this->cachePaths($s)[0]));
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

    /** @return array{0:string,1:string} [.md, .err] */
    private function cachePaths(array $section): array
    {
        $base = Paths::storage('ai-context/units/' . (int) $section['unit_id'] . '/section-' . (int) $section['id']);

        return [$base . '.md', $base . '.err'];
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
