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
 * - นับเครื่องหมาย " เฉพาะ "ภายใน" อ็อบเจกต์ — ข้อความอธิบายนอก JSON ที่มี " เป็นจำนวนคี่
 *   (เช่น อ้างชื่อวิชาในเครื่องหมายคำพูด) เคยทำให้ตัวอ่านคิดว่าทั้งสตรีมที่เหลืออยู่ในสตริง แล้วข้ามทุกหน่วย
 * - ข้ามช่วงความคิดของโมเดลแบบ reasoning (<think>…</think>) ทั้งก้อน
 *
 * @param iterable<string> $chunks
 * @return iterable<array<string,mixed>>
 */
final class JsonStream
{
    /**
     * @param bool $skipThink ข้ามช่วง <think>…</think> — ปิดไว้ตอนอ่านซ้ำกรณีที่โมเดลไม่ปิดแท็กคิดเลย
     */
    public static function objects(iterable $chunks, bool $skipThink = true): iterable
    {
        $buffer = '';
        $pos = 0;          // ตำแหน่งถัดไปที่ยังไม่ได้อ่านใน $buffer
        $depth = 0;
        $start = null;
        $inString = false;
        $escaped = false;
        $inThink = false;
        $thinkStart = 0;   // ตำแหน่งเริ่มเนื้อหาช่วงคิด — เผื่อต้องย้อนกลับมาอ่านซ้ำ

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;
            $len = strlen($buffer);

            while ($pos < $len) {
                // ช่วงความคิดของโมเดล — รอจนเจอแท็กปิด (อาจมาใน chunk ถัดไป)
                if ($inThink) {
                    $close = strpos($buffer, '</think>', $pos);
                    if ($close === false) {
                        $pos = max($pos, $len - 7);   // เผื่อแท็กปิดถูกตัดครึ่งระหว่าง chunk

                        break;
                    }
                    $inThink = false;
                    $pos = $close + 8;

                    continue;
                }

                $ch = $buffer[$pos];

                if ($depth > 0 && $inString) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($ch === '\\') {
                        $escaped = true;
                    } elseif ($ch === '"') {
                        $inString = false;
                    }
                    $pos++;

                    continue;
                }

                if ($skipThink && $depth === 0 && $ch === '<') {
                    // แท็ก <think> อาจถูกตัดครึ่งระหว่าง chunk — รอข้อมูลเพิ่มก่อนตัดสิน
                    if ($len - $pos < 7 && str_starts_with('<think>', substr($buffer, $pos))) {
                        break;
                    }
                    if (substr($buffer, $pos, 7) === '<think>') {
                        $inThink = true;
                        $pos += 7;
                        $thinkStart = $pos;

                        continue;
                    }
                }

                if ($depth > 0 && $ch === '"') {
                    $inString = true;
                } elseif ($ch === '{') {
                    if ($depth === 0) {
                        $start = $pos;
                    }
                    $depth++;
                } elseif ($ch === '}' && $depth > 0) {
                    $depth--;
                    if ($depth === 0 && $start !== null) {
                        $obj = json_decode(substr($buffer, $start, $pos - $start + 1), true);
                        if (is_array($obj)) {
                            yield $obj;
                        }

                        // ตัดส่วนที่อ่านจบแล้วทิ้ง เพื่อไม่ให้ buffer โตไม่จำกัด
                        $buffer = substr($buffer, $pos + 1);
                        $len = strlen($buffer);
                        $pos = 0;
                        $start = null;

                        continue;
                    }
                }

                $pos++;
            }

            // ยังไม่เจอปีกกาเปิดเลย = เป็นคำอธิบายนอก JSON ทิ้งได้ ไม่ต้องสะสมไว้
            if ($depth === 0 && $start === null && !$inThink && $pos >= $len && $len > 4096) {
                $buffer = '';
                $pos = 0;
            }
        }

        // สตรีมจบทั้งที่ยังไม่เจอ </think> — โมเดลคิดยาวจนคำตอบจริงอยู่ในช่วงคิดเลย
        // อ่านเนื้อหาหลัง <think> ใหม่อีกรอบโดยไม่ข้าม ไม่งั้นจะได้ศูนย์ชิ้นทั้งที่มี JSON อยู่
        if ($inThink) {
            yield from self::objects([substr($buffer, $thinkStart)], false);
        }
    }
}
