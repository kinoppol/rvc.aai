<?php

declare(strict_types=1);

namespace App\AI;

/**
 * ดึงอ็อบเจกต์ JSON ออกจากสตรีมข้อความของโมเดล
 *
 * โมเดลเล็ก ๆ มักไม่ทำตามคำสั่ง "หนึ่งบรรทัดหนึ่ง JSON" เป๊ะ ๆ — บางครั้งครอบด้วย ```json
 * บางครั้งจัดย่อหน้าหลายบรรทัด หรือมีคำอธิบายแทรก ตัวช่วยนี้จึงไล่นับวงเล็บปีกกาเอง
 * แล้วคืนอ็อบเจกต์ทันทีที่ปิดครบ ทำให้ยังสตรีมผลทีละชิ้นได้เหมือนเดิม
 *
 * @param iterable<string> $chunks
 * @return iterable<array<string,mixed>>
 */
final class JsonStream
{
    public static function objects(iterable $chunks): iterable
    {
        $buffer = '';
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            for ($i = strlen($buffer) - strlen((string) $chunk); $i < strlen($buffer); $i++) {
                $ch = $buffer[$i];

                if ($inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($ch === '\\') {
                        $escaped = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }

                    continue;
                }

                if ($ch === '"') {
                    $inString = true;

                    continue;
                }

                if ($ch === '{') {
                    if ($depth === 0) {
                        $start = $i;
                    }
                    $depth++;

                    continue;
                }

                if ($ch === '}' && $depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start !== null) {
                        $json = substr($buffer, $start, $i - $start + 1);
                        $obj = json_decode($json, true);
                        if (is_array($obj)) {
                            yield $obj;
                        }

                        // ตัดส่วนที่อ่านจบแล้วทิ้ง เพื่อไม่ให้ buffer โตไม่จำกัด
                        $buffer = substr($buffer, $i + 1);
                        $i = -1;
                        $start = null;
                    }
                }
            }

            // ยังไม่เจอปีกกาเปิดเลย = เป็นคำอธิบายนอก JSON ทิ้งได้ ไม่ต้องสะสมไว้
            if ($depth === 0 && $start === null && strlen($buffer) > 4096) {
                $buffer = substr($buffer, -1024);
            }
        }
    }
}
