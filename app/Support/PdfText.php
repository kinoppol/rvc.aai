<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * ดึงข้อความจากไฟล์ PDF โดยไม่ต้องพึ่งไลบรารีภายนอก
 *
 * อ่านอ็อบเจ็กต์ของ PDF (รวมถึงที่ถูกบีบรวมใน object stream ของ PDF 1.5+) ไล่หน้าตามลำดับจริง
 * แล้วถอดรหัสตัวอักษรด้วยตาราง ToUnicode ของแต่ละฟอนต์ — PDF ภาษาไทยที่ส่งออกจาก Word / Google Docs
 * เกือบทั้งหมดเก็บข้อความเป็นรหัสกลิฟ (Identity-H) ซึ่งต้องใช้ตารางนี้จึงจะได้ตัวอักษรที่ถูกต้อง
 *
 * อ่านไม่ได้: ไฟล์สแกนเป็นรูป และไฟล์ที่เข้ารหัสไว้
 *
 * ค่าในไฟล์แทนด้วยอาร์เรย์ติดป้าย: ['D', dict] ['A', list] ['N', name] ['S', bytes] ['R', เลขอ็อบเจ็กต์] ['K', คำสั่ง]
 */
final class PdfText
{
    /** หยุดอ่านเมื่อได้ข้อความพอแล้ว (TextExtract ตัดเหลือ 20,000 ตัวอักษรอยู่ดี) */
    private const ENOUGH_BYTES = 120000;
    private const MAX_PAGES = 500;

    /** ตัวอักษรไทยรูปแบบพิเศษ (Private Use Area) ที่ฟอนต์ไทยของ Windows ใช้วางวรรณยุกต์/สระ */
    private const THAI_PUA = [
        "\u{F700}" => "\u{0E10}", "\u{F701}" => "\u{0E34}", "\u{F702}" => "\u{0E35}", "\u{F703}" => "\u{0E36}",
        "\u{F704}" => "\u{0E37}", "\u{F705}" => "\u{0E48}", "\u{F706}" => "\u{0E49}", "\u{F707}" => "\u{0E4A}",
        "\u{F708}" => "\u{0E4B}", "\u{F709}" => "\u{0E4C}", "\u{F70A}" => "\u{0E48}", "\u{F70B}" => "\u{0E49}",
        "\u{F70C}" => "\u{0E4A}", "\u{F70D}" => "\u{0E4B}", "\u{F70E}" => "\u{0E4C}", "\u{F70F}" => "\u{0E0D}",
        "\u{F710}" => "\u{0E31}", "\u{F711}" => "\u{0E4D}", "\u{F712}" => "\u{0E47}", "\u{F713}" => "\u{0E48}",
        "\u{F714}" => "\u{0E49}", "\u{F715}" => "\u{0E4A}", "\u{F716}" => "\u{0E4B}", "\u{F717}" => "\u{0E4C}",
        "\u{F718}" => "\u{0E38}", "\u{F719}" => "\u{0E39}", "\u{F71A}" => "\u{0E3A}",
        // นิคหิต + สระอา ที่ฟอนต์บางตัวแยกไว้ = สระอำ (ช่องว่างคั่นได้ เพราะ Word ชอบวางเป็นคนละคำสั่ง)
        "\u{0E4D}\u{0E32}" => "\u{0E33}", "\u{0E32}\u{0E4D}" => "\u{0E33}",
        " \u{0E4D}\u{0E32}" => "\u{0E33}", "\u{0E4D} \u{0E32}" => "\u{0E33}",
        self::EMPTY_GLYPH . "\u{0E32}" => "\u{0E33}", "\u{0E32}" . self::EMPTY_GLYPH => "\u{0E33}",
    ];

    /** ตัวแทนกลิฟที่ ToUnicode แมปเป็นค่าว่าง (อยู่ใน Private Use Area จึงถูกลบทิ้งพร้อมอักขระควบคุม) */
    private const EMPTY_GLYPH = "\u{F8FF}";

    /** สระบน/ล่างและวรรณยุกต์ — ห้ามขึ้นบรรทัดใหม่ก่อนตัวเหล่านี้ แม้จะถูกวางคนละตำแหน่ง */
    private const THAI_MARKS = '~^[\x{0E31}\x{0E33}-\x{0E3A}\x{0E47}-\x{0E4E}\x{F701}-\x{F71A}]~u';

    /** @var array<int, int> เลขอ็อบเจ็กต์ => ตำแหน่งเริ่มเนื้อหาในไฟล์ */
    private array $offsets = [];
    /** @var array<int, string> อ็อบเจ็กต์ที่อยู่ใน object stream (ไม่มีสตรีมของตัวเอง) */
    private array $packed = [];
    /** @var array<int, array{0: mixed, 1: ?string}> */
    private array $cache = [];
    /** @var array<int, array{w: int, map: array<string, string>, single: bool, thai: bool}> */
    private array $fonts = [];
    private string $out = '';

    private function __construct(private readonly string $raw)
    {
    }

    /** @throws RuntimeException เมื่อไฟล์เข้ารหัสไว้หรือไม่พบข้อความที่อ่านได้ */
    public static function extract(string $raw): string
    {
        if (!str_starts_with(ltrim(substr($raw, 0, 1024)), '%PDF') && !str_contains(substr($raw, 0, 1024), '%PDF')) {
            throw new RuntimeException('ไฟล์นี้ไม่ใช่ PDF ที่ถูกต้อง');
        }

        $pdf = new self($raw);
        $pdf->index();

        if (preg_match('~/Encrypt\s*(?:\d+\s+\d+\s+R|<<)~', $raw) === 1) {
            throw new RuntimeException(
                'PDF นี้ถูกเข้ารหัส/ล็อกสิทธิ์ไว้ จึงอ่านข้อความไม่ได้ '
                . 'กรุณาบันทึกเป็น PDF ใหม่ (พิมพ์เป็น PDF) หรือบันทึกเป็น .docx แล้วแนบใหม่'
            );
        }

        foreach ($pdf->pages() as [$page, $resources]) {
            $pdf->runContent($pdf->contents($page), $resources, 0);
            $pdf->out .= "\n\n";
            if (strlen($pdf->out) > self::ENOUGH_BYTES) {
                break;
            }
        }

        $text = strtr($pdf->out, self::THAI_PUA);
        $text = str_replace("\u{0E4D}", ' ', $text); // นิคหิตที่เหลือเดี่ยว ๆ คือช่องว่างที่เดาผิด
        // สระอาไม่มีวันขึ้นต้นคำ — "จ านวน" คือนิคหิตที่ฟอนต์แมปเป็นช่องว่าง ต้องเป็น "จำนวน"
        $text = (string) preg_replace('~([\x{0E01}-\x{0E2E}][\x{0E48}-\x{0E4B}]?) \x{0E32}~u', "\$1\u{0E33}", $text);
        $text = (string) preg_replace('~[^\P{C}\n\t]~u', '', $text);

        $readable = preg_match_all('~[\p{L}\p{N}]~u', $text);
        if ($readable < 40) {
            throw new RuntimeException(
                'อ่านข้อความจาก PDF นี้ไม่ได้ (อาจเป็นไฟล์สแกนเป็นรูป) '
                . 'กรุณาบันทึกเป็น .docx หรือ .txt แล้วแนบใหม่'
            );
        }

        return $text;
    }

    /* ---------- โครงสร้างไฟล์ ---------- */

    private function index(): void
    {
        // อ็อบเจ็กต์ที่ประกาศทีหลัง (การแก้ไขแบบต่อท้ายไฟล์) ทับของเดิม
        if (preg_match_all('~(?<![^\s])(\d+)\s+\d+\s+obj\b~', $this->raw, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$tok, $pos]) {
                $this->offsets[(int) $m[1][$i][0]] = $pos + strlen($tok);
            }
        }

        foreach ($this->offsets as $num => $start) {
            if (!str_contains(substr($this->raw, $start, 300), '/ObjStm')) {
                continue;
            }
            [$dict, $data] = $this->object($num);
            if ($data === null || !$this->isName($this->get($dict, 'Type'), 'ObjStm')) {
                continue;
            }
            $n = (int) $this->get($dict, 'N');
            $first = (int) $this->get($dict, 'First');
            $nums = preg_split('~\s+~', trim(substr($data, 0, $first))) ?: [];
            $entries = [];
            for ($k = 0; $k + 1 < count($nums) && $k / 2 < $n; $k += 2) {
                $entries[] = [(int) $nums[$k], $first + (int) $nums[$k + 1]];
            }
            foreach ($entries as $j => [$objNum, $at]) {
                $end = $entries[$j + 1][1] ?? strlen($data);
                if (!isset($this->offsets[$objNum])) {
                    $this->packed[$objNum] = substr($data, $at, $end - $at);
                }
            }
        }
    }

    /** @return array{0: mixed, 1: ?string} [ค่า, ข้อมูลสตรีมที่คลายแล้ว] */
    private function object(int $num): array
    {
        if (isset($this->cache[$num])) {
            return $this->cache[$num];
        }
        $this->cache[$num] = [null, null]; // กันอ้างอิงวนกลับมาที่ตัวเอง

        if (isset($this->packed[$num])) {
            $i = 0;
            return $this->cache[$num] = [$this->parse($this->packed[$num], $i), null];
        }
        if (!isset($this->offsets[$num])) {
            return $this->cache[$num];
        }

        $i = $this->offsets[$num];
        $value = $this->parse($this->raw, $i);
        $data = null;

        $this->skipWs($this->raw, $i);
        if (($value[0] ?? null) === 'D' && substr($this->raw, $i, 6) === 'stream') {
            $i += 6;
            if (($this->raw[$i] ?? '') === "\r") {
                $i++;
            }
            if (($this->raw[$i] ?? '') === "\n") {
                $i++;
            }
            $len = $this->get($value, 'Length');
            $len = is_int($len) || is_float($len) ? (int) $len : -1;
            if ($len < 0 || !preg_match('~^\s*endstream~', substr($this->raw, $i + $len, 20))) {
                $end = strpos($this->raw, 'endstream', $i);
                $len = $end === false ? 0 : $end - $i;
            }
            $data = $this->decode(substr($this->raw, $i, $len), $value);
        }

        return $this->cache[$num] = [$value, $data];
    }

    /** คลายสตรีมตาม /Filter — รองรับ Flate และ ASCIIHex ซึ่งครอบคลุมข้อความเกือบทุกไฟล์ */
    private function decode(string $data, mixed $dict): ?string
    {
        $filters = $this->get($dict, 'Filter');
        $list = match ($filters[0] ?? null) {
            'N' => [$filters[1]],
            'A' => array_map(fn ($f) => $this->resolve($f)[1] ?? '', $filters[1]),
            default => [],
        };

        foreach ($list as $filter) {
            if ($filter === 'FlateDecode' || $filter === 'Fl') {
                $out = @gzuncompress($data);
                if ($out === false) {
                    $out = @gzinflate(substr($data, 2));
                }
                if ($out === false) {
                    $out = @zlib_decode($data);
                }
                if ($out === false) {
                    return null;
                }
                $data = $out;
            } elseif ($filter === 'ASCIIHexDecode' || $filter === 'AHx') {
                $hex = (string) preg_replace('~[^0-9A-Fa-f]~', '', strstr($data, '>', true) ?: $data);
                $data = (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex);
            } else {
                return null; // รูปภาพหรือการบีบอัดแบบอื่น ไม่มีข้อความให้อ่าน
            }
        }

        return $data;
    }

    private function resolve(mixed $v, int $depth = 0): mixed
    {
        while (($v[0] ?? null) === 'R' && $depth++ < 16) {
            $v = $this->object($v[1])[0];
        }

        return $v;
    }

    private function get(mixed $dict, string $key): mixed
    {
        $dict = $this->resolve($dict);
        if (($dict[0] ?? null) !== 'D') {
            return null;
        }

        return $this->resolve($dict[1][$key] ?? null);
    }

    private function isName(mixed $v, string $name): bool
    {
        return ($v[0] ?? null) === 'N' && $v[1] === $name;
    }

    /**
     * หน้าทั้งหมดตามลำดับ พร้อม /Resources ที่สืบทอดมาจากโหนดแม่
     *
     * @return list<array{0: mixed, 1: mixed}>
     */
    private function pages(): array
    {
        $pages = [];
        $root = null;
        if (preg_match_all('~/Root\s+(\d+)\s+\d+\s+R~', $this->raw, $m)) {
            $root = (int) end($m[1]);
        }

        if ($root !== null) {
            $this->walk($this->get(['R', $root], 'Pages'), null, $pages, 0);
        }

        if ($pages === []) {
            // ไม่พบสารบัญหน้า — เก็บทุกอ็อบเจ็กต์ที่เป็นหน้าตามลำดับเลข
            $nums = array_keys($this->offsets + $this->packed);
            sort($nums);
            foreach ($nums as $num) {
                $obj = $this->object($num)[0];
                if ($this->isName($this->get($obj, 'Type'), 'Page')) {
                    $pages[] = [$obj, $this->get($obj, 'Resources')];
                }
            }
        }

        return array_slice($pages, 0, self::MAX_PAGES);
    }

    /** @param list<array{0: mixed, 1: mixed}> $pages */
    private function walk(mixed $node, mixed $resources, array &$pages, int $depth): void
    {
        $node = $this->resolve($node);
        if (($node[0] ?? null) !== 'D' || $depth > 32 || count($pages) >= self::MAX_PAGES) {
            return;
        }
        $resources = $this->get($node, 'Resources') ?? $resources;
        $kids = $this->get($node, 'Kids');

        if (($kids[0] ?? null) === 'A') {
            foreach ($kids[1] as $kid) {
                $this->walk($kid, $resources, $pages, $depth + 1);
            }
        } elseif (!$this->isName($this->get($node, 'Type'), 'Pages')) {
            $pages[] = [$node, $resources];
        }
    }

    private function contents(mixed $page): string
    {
        $raw = $this->resolve($page)[1]['Contents'] ?? null;
        $refs = ($raw[0] ?? null) === 'R' ? [$raw] : [];
        $resolved = $this->resolve($raw);
        if (($resolved[0] ?? null) === 'A') {
            $refs = $resolved[1];
        }

        $out = '';
        foreach ($refs as $ref) {
            if (($ref[0] ?? null) === 'R') {
                $out .= ($this->object($ref[1])[1] ?? '') . "\n";
            }
        }

        return $out;
    }

    /* ---------- ฟอนต์ ---------- */

    /** @return array{w: int, map: array<string, string>, single: bool, thai: bool} */
    private function font(mixed $resources, string $name): array
    {
        $ref = $this->get($resources, 'Font')[1][$name] ?? null;
        $key = ($ref[0] ?? null) === 'R' ? $ref[1] : -crc32($name);
        if (isset($this->fonts[$key])) {
            return $this->fonts[$key];
        }

        $font = $this->resolve($ref);
        $type0 = $this->isName($this->get($font, 'Subtype'), 'Type0');
        $info = ['w' => $type0 ? 2 : 1, 'map' => [], 'single' => !$type0, 'thai' => true];

        // ฟอนต์ไบต์เดียวที่ไม่มีตาราง: ไบต์สูงเป็นอักษรไทย เว้นแต่ประกาศรหัสละตินและชื่อฟอนต์ไม่ใช่ฟอนต์ไทย
        $encoding = $this->get($font, 'Encoding');
        $baseFont = $this->get($font, 'BaseFont')[1] ?? '';
        if (in_array($encoding[1] ?? $this->get($encoding, 'BaseEncoding')[1] ?? '', ['WinAnsiEncoding', 'MacRomanEncoding', 'StandardEncoding'], true)
            && preg_match('~Angsana|Cordia|Browallia|Eucrosia|Freesia|Iris|Jasmine|Kodchiang|Lily|Tahoma|Sarabun|Thai|Leelawad|Niramit|(?:^|[+\-])TH~', (string) $baseFont) !== 1) {
            $info['thai'] = false;
        }

        $toUnicode = $font[1]['ToUnicode'] ?? null;
        if (($toUnicode[0] ?? null) === 'R') {
            $cmap = $this->object($toUnicode[1])[1] ?? '';
            if (preg_match('~begincodespacerange\s*<([0-9A-Fa-f]+)>~', $cmap, $cs)) {
                $info['w'] = max(1, intdiv(strlen($cs[1]), 2));
            }
            $info['map'] = self::fixThaiAm($this->cmap($cmap));
        }

        return $this->fonts[$key] = $info;
    }

    /**
     * ฟอนต์ไทยบางตัวที่ Word ฝังมาใช้กลิฟเดียวกันทั้ง "า" และส่วนหางของ "ำ" แล้วแมปไว้เป็น "ำ"
     * ส่วนนิคหิตแมปเป็นช่องว่าง — "การ" จึงออกมาเป็น "กำร" และ "อำนวย" เป็น "อ ำนวย"
     * แก้เฉพาะฟอนต์ที่มี "ำ" แต่ไม่มี "า": กลิฟนั้นคือ "า" และช่องว่างตัวที่สองคือนิคหิต (รวมเป็น "ำ" ทีหลัง)
     *
     * @param array<string, string> $map
     * @return array<string, string>
     */
    private static function fixThaiAm(array $map): array
    {
        $thai = in_array("\u{0E32}", $map, true) || in_array("\u{0E33}", $map, true);
        if (!$thai) {
            return $map;
        }
        $aaFromAm = !in_array("\u{0E32}", $map, true);

        $spaces = array_keys($map, ' ', true);
        sort($spaces);
        foreach ($map as $code => $char) {
            if ($char === "\u{0E33}" && $aaFromAm) {
                $map[$code] = "\u{0E32}";
            } elseif ($char === ' ' && $code !== ($spaces[0] ?? null)) {
                $map[$code] = "\u{0E4D}";
            } elseif ($char === '') {
                // กลิฟที่แมปเป็นค่าว่าง มักเป็นนิคหิตของ "ำ" — ถ้าไม่ได้อยู่ติด "า" จะถูกลบทิ้งตอนท้าย
                $map[$code] = self::EMPTY_GLYPH;
            }
        }

        return $map;
    }

    /** @return array<string, string> รหัสฐานสิบหก (ตัวใหญ่) => ข้อความ UTF-8 */
    private function cmap(string $cmap): array
    {
        $map = [];
        $utf16 = static fn (string $hex): string => (string) mb_convert_encoding(
            (string) hex2bin(strlen($hex) % 4 ? str_pad($hex, (int) ceil(strlen($hex) / 4) * 4, '0', STR_PAD_LEFT) : $hex),
            'UTF-8',
            'UTF-16BE'
        );

        preg_match_all('~beginbfchar(.*?)endbfchar~s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]*)>~', $block, $pairs, PREG_SET_ORDER);
            foreach ($pairs as [, $src, $dst]) {
                $map[strtoupper($src)] = $utf16($dst);
            }
        }

        preg_match_all('~beginbfrange(.*?)endbfrange~s', $cmap, $blocks);
        foreach ($blocks[1] as $block) {
            preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*(<[0-9A-Fa-f]*>|\[[^\]]*\])~', $block, $ranges, PREG_SET_ORDER);
            foreach ($ranges as [, $lo, $hi, $dst]) {
                $from = hexdec($lo);
                $to = min(hexdec($hi), $from + 65535);
                $width = strlen($lo);
                if ($dst[0] === '[') {
                    preg_match_all('~<([0-9A-Fa-f]*)>~', $dst, $items);
                    foreach ($items[1] as $k => $hex) {
                        if ($from + $k > $to) {
                            break;
                        }
                        $map[strtoupper(str_pad(dechex($from + $k), $width, '0', STR_PAD_LEFT))] = $utf16($hex);
                    }
                    continue;
                }
                $hex = substr($dst, 1, -1);
                $prefix = substr($hex, 0, -4);
                $base = hexdec(substr($hex, -4) ?: '0');
                for ($c = $from; $c <= $to; $c++) {
                    $map[strtoupper(str_pad(dechex($c), $width, '0', STR_PAD_LEFT))] =
                        $utf16($prefix . str_pad(dechex(($base + $c - $from) & 0xFFFF), 4, '0', STR_PAD_LEFT));
                }
            }
        }

        return $map;
    }

    /** @param array{w: int, map: array<string, string>, single: bool, thai: bool} $font */
    private function decodeText(string $bytes, array $font): string
    {
        $hex = strtoupper(bin2hex($bytes));
        $step = $font['w'] * 2;
        $text = '';

        for ($i = 0, $n = strlen($hex); $i + $step <= $n; $i += $step) {
            $code = substr($hex, $i, $step);
            if (isset($font['map'][$code])) {
                $text .= $font['map'][$code];
            } elseif ($font['single']) {
                // ฟอนต์แบบไบต์เดียวที่ไม่มีตาราง: ASCII ตรงตัว ส่วนไบต์สูงถือเป็นรหัสไทย (Windows-874)
                $b = hexdec($code);
                if ($b >= 0x20 && $b < 0x7F) {
                    $text .= chr($b);
                } elseif (!$font['thai']) {
                    $text .= $b === 0x95 ? '•' : ($b >= 0xA0 ? mb_chr($b, 'UTF-8') : ' ');
                } elseif ($b >= 0xA1 && $b <= 0xFB) {
                    $text .= mb_chr(0x0E00 + $b - 0xA0, 'UTF-8'); // TIS-620 เรียงตรงกับ Unicode ช่วงไทย
                }
            }
        }

        return $text;
    }

    /* ---------- สตรีมเนื้อหาของหน้า ---------- */

    private function runContent(string $stream, mixed $resources, int $depth): void
    {
        $font = ['w' => 1, 'map' => [], 'single' => true, 'thai' => true];
        $args = [];
        // ตำแหน่งแนวตั้งของบรรทัดปัจจุบัน — ขึ้นบรรทัดใหม่เมื่อข้อความถัดไปขยับเกินครึ่งความสูงตัวอักษร
        $y = 0.0;
        $scale = 1.0;
        $size = 10.0;
        $leading = 0.0;
        $shownY = null;
        $ctm = [1.0, 0.0]; // [สเกลแนวตั้ง, ระยะเลื่อนแนวตั้ง] ของ cm — ไม่คิดการหมุน
        $saved = [];
        $show = function (string $bytes) use (&$font, &$y, &$scale, &$size, &$shownY, &$ctm): void {
            $text = $this->decodeText($bytes, $font);
            if ($text === '') {
                return;
            }
            $at = $ctm[1] + $y * $ctm[0];
            $height = max(1.0, abs($size * $scale * $ctm[0]));
            if ($shownY !== null && abs($at - $shownY) > $height * 0.5 && preg_match(self::THAI_MARKS, $text) !== 1) {
                $this->newline();
            }
            $this->out .= $text;
            $shownY = $at;
        };
        $num = static fn (mixed $v): float => is_int($v) || is_float($v) ? (float) $v : 0.0;
        $i = 0;
        $n = strlen($stream);

        while ($i < $n) {
            $value = $this->parse($stream, $i);
            if ($value === null && $i >= $n) {
                break;
            }
            if (($value[0] ?? null) !== 'K') {
                $args[] = $value;
                if (count($args) > 64) {
                    array_shift($args);
                }
                continue;
            }

            switch ($value[1]) {
                case 'Tf':
                    $name = $args[count($args) - 2] ?? null;
                    if (($name[0] ?? null) === 'N') {
                        $font = $this->font($resources, $name[1]);
                    }
                    $size = abs($num(end($args))) ?: 10.0;
                    break;
                case 'Tj':
                    $show($this->str(end($args)));
                    break;
                case "'":
                case '"':
                    $y -= $leading * $scale;
                    $show($this->str(end($args)));
                    break;
                case 'TJ':
                    $arr = end($args);
                    foreach (($arr[0] ?? null) === 'A' ? $arr[1] : [] as $part) {
                        if (is_int($part) || is_float($part)) {
                            if ($part < -250 && !str_ends_with($this->out, ' ')) {
                                $this->out .= ' ';
                            }
                        } else {
                            $show($this->str($part));
                        }
                    }
                    break;
                case 'TL':
                    $leading = $num(end($args));
                    break;
                case 'TD':
                    $leading = -$num(end($args));
                    $y += $num(end($args)) * $scale;
                    break;
                case 'Td':
                    $y += $num(end($args)) * $scale;
                    break;
                case 'Tm':
                    $y = $num($args[count($args) - 1] ?? 0);
                    $scale = $num($args[count($args) - 3] ?? 1) ?: 1.0;
                    break;
                case 'BT':
                    $y = 0.0;
                    $scale = 1.0;
                    break;
                case 'q':
                    $saved[] = $ctm;
                    break;
                case 'Q':
                    $ctm = array_pop($saved) ?? $ctm;
                    break;
                case 'cm':
                    if (count($args) >= 6) {
                        $m = array_map($num, array_slice($args, -6));
                        $ctm = [$ctm[0] * ($m[3] ?: 1.0), $ctm[1] + $m[5] * $ctm[0]];
                    }
                    break;
                case 'T*':
                    $y -= $leading * $scale;
                    break;
                case 'Do':
                    $name = end($args);
                    $ref = $this->get($resources, 'XObject')[1][$name[1] ?? ''] ?? null;
                    if ($depth < 3 && ($ref[0] ?? null) === 'R') {
                        [$form, $data] = $this->object($ref[1]);
                        if ($data !== null && $this->isName($this->get($form, 'Subtype'), 'Form')) {
                            $this->runContent($data, $this->get($form, 'Resources') ?? $resources, $depth + 1);
                        }
                    }
                    break;
                case 'ID':
                    // ข้ามข้อมูลรูปภาพที่ฝังในเนื้อหา (BI … ID <ไบนารี> EI)
                    $end = preg_match('~\sEI(?=[\s/\[<(]|$)~', $stream, $em, PREG_OFFSET_CAPTURE, $i) ? $em[0][1] + 3 : $n;
                    $i = $end;
                    break;
            }
            $args = [];
        }
    }

    private function newline(): void
    {
        if ($this->out !== '' && !str_ends_with($this->out, "\n")) {
            $this->out .= "\n";
        }
    }

    private function str(mixed $v): string
    {
        return ($v[0] ?? null) === 'S' ? $v[1] : '';
    }

    /* ---------- ตัวแยกค่าของ PDF ---------- */

    private function skipWs(string $s, int &$i): void
    {
        $n = strlen($s);
        while ($i < $n) {
            $i += strspn($s, " \t\r\n\f\0", $i);
            if (($s[$i] ?? '') !== '%') {
                return;
            }
            $i += strcspn($s, "\r\n", $i);
        }
    }

    private function parse(string $s, int &$i): mixed
    {
        $this->skipWs($s, $i);
        $c = $s[$i] ?? '';

        switch ($c) {
            case '':
                return null;
            case '<':
                if (($s[$i + 1] ?? '') === '<') {
                    $i += 2;
                    $dict = [];
                    while (true) {
                        $this->skipWs($s, $i);
                        if ($i >= strlen($s)) {
                            break;
                        }
                        if ($s[$i] === '>' && ($s[$i + 1] ?? '') === '>') {
                            $i += 2;
                            break;
                        }
                        $key = $this->parse($s, $i);
                        if (($key[0] ?? null) !== 'N') {
                            if ($key === null) {
                                $i++;
                            }
                            continue;
                        }
                        $dict[$key[1]] = $this->parse($s, $i);
                    }
                    return ['D', $dict];
                }
                $end = strpos($s, '>', $i);
                $end = $end === false ? strlen($s) : $end;
                $hex = (string) preg_replace('~[^0-9A-Fa-f]~', '', substr($s, $i + 1, $end - $i - 1));
                $i = $end + 1;
                return ['S', (string) hex2bin(strlen($hex) % 2 ? $hex . '0' : $hex)];
            case '(':
                return ['S', $this->literal($s, $i)];
            case '[':
                $i++;
                $list = [];
                while (true) {
                    $this->skipWs($s, $i);
                    if ($i >= strlen($s)) {
                        break;
                    }
                    if ($s[$i] === ']') {
                        $i++;
                        break;
                    }
                    $item = $this->parse($s, $i);
                    if ($item === null && ($s[$i] ?? '') !== '') {
                        $i++;
                        continue;
                    }
                    $list[] = $item;
                }
                return ['A', $list];
            case '/':
                $i++;
                $len = strcspn($s, " \t\r\n\f\0()<>[]{}/%", $i);
                $name = substr($s, $i, $len);
                $i += $len;
                return ['N', (string) preg_replace_callback('~#([0-9A-Fa-f]{2})~', fn ($m) => chr((int) hexdec($m[1])), $name)];
            case ']':
            case '>':
            case ')':
            case '{':
            case '}':
                $i++;
                return null;
        }

        $len = strcspn($s, " \t\r\n\f\0()<>[]{}/%", $i);
        $tok = substr($s, $i, $len);
        $i += $len;

        if (is_numeric($tok)) {
            if (ctype_digit($tok) && preg_match('~\G\s+(\d+)\s+R(?![^\s()<>\[\]{}/%])~', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                return ['R', (int) $tok];
            }
            return str_contains($tok, '.') ? (float) $tok : (int) $tok;
        }

        return match ($tok) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => ['K', $tok],
        };
    }

    private function literal(string $s, int &$i): string
    {
        $i++; // ข้าม (
        $out = '';
        $depth = 1;
        $n = strlen($s);

        while ($i < $n) {
            $chunk = strcspn($s, '()\\', $i);
            $out .= substr($s, $i, $chunk);
            $i += $chunk;
            $c = $s[$i] ?? '';

            if ($c === '(') {
                $depth++;
                $out .= '(';
                $i++;
            } elseif ($c === ')') {
                $i++;
                if (--$depth === 0) {
                    break;
                }
                $out .= ')';
            } elseif ($c === '\\') {
                $e = $s[$i + 1] ?? '';
                $i += 2;
                if ($e >= '0' && $e <= '7') {
                    $oct = $e;
                    while (strlen($oct) < 3 && ($s[$i] ?? '') >= '0' && ($s[$i] ?? '') <= '7') {
                        $oct .= $s[$i++];
                    }
                    $out .= chr(octdec($oct) & 0xFF);
                } elseif ($e === "\r") {
                    if (($s[$i] ?? '') === "\n") {
                        $i++;
                    }
                } elseif ($e !== "\n") {
                    $out .= match ($e) {
                        'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                        default => $e,
                    };
                }
            }
        }

        return $out;
    }
}
